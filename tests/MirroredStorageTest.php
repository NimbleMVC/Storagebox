<?php

namespace NimblePHP\Storagebox\Tests;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Event\EventDispatcher;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Storagebox\ModuleStorageBackendModel;
use NimblePHP\Storagebox\ModuleStorageFileMirrorModel;
use NimblePHP\Storagebox\MirroredStorage;
use PHPUnit\Framework\TestCase;

/**
 * STB-5: mirrored (multi-backend) storage - priority failover on write, background
 * replication of the backends that missed the synchronous write, resilient reads
 * that fall through when a "synced" copy has silently gone missing, and delete()
 * only reporting success once every tracked backend has confirmed removal.
 *
 * Backends here are all of type 'storage' (plain local filesystem) pinned to
 * distinct directories via config.directory, so failover/replication between
 * "different backends" can be exercised without a live MinIO/S3 endpoint.
 */
class MirroredStorageTest extends TestCase
{

    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/storagebox-mirrored-test-' . uniqid('', true);
        mkdir($this->projectPath, 0777, true);

        Kernel::$projectPath = $this->projectPath;
        Kernel::$middlewareManager = new MiddlewareManager();
        Kernel::$eventDispatcher = new EventDispatcher();

        Config::set('DATABASE', true);
        Config::set('LOG', false);

        $connect = DatabaseConnect::create()
            ->setType(DatabaseType::sqlite)
            ->setSqlitePath(':memory:');

        (new DatabaseManager())->connect($connect);

        DatabaseManager::$connection->getConnection()->exec(
            <<<SQL
            CREATE TABLE module_storage_backend (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL UNIQUE,
                type VARCHAR(20) DEFAULT 'minio',
                priority INTEGER DEFAULT 0,
                status VARCHAR(10) DEFAULT 'up',
                last_checked_at DATETIME,
                config TEXT,
                credentials TEXT,
                enabled INTEGER DEFAULT 1,
                date_created DATETIME,
                date_modify DATETIME
            )
            SQL
        );

        DatabaseManager::$connection->getConnection()->exec(
            <<<SQL
            CREATE TABLE module_storage_file_mirror (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_hash VARCHAR(255) NOT NULL,
                directory VARCHAR(255) DEFAULT 'storage_files',
                backend_id INTEGER NOT NULL,
                status VARCHAR(10) DEFAULT 'pending',
                last_attempt_at DATETIME,
                last_error TEXT,
                date_created DATETIME,
                date_modify DATETIME
            )
            SQL
        );
    }

    protected function tearDown(): void
    {
        Kernel::$eventDispatcher = new EventDispatcher();

        $this->removeDirectory($this->projectPath);
    }

    public function testPutWritesToHighestPriorityBackendAndQueuesOthersPending(): void
    {
        $this->registerBackend('primary', 1000, 'a');
        $this->registerBackend('secondary', 500, 'b');

        $storage = new MirroredStorage('files');
        $storage->put('hash1', 'hello world');

        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/a/hash1'));
        $this->assertFileDoesNotExist($this->projectPath . '/storage/b/hash1');

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['primary']['status']);
        $this->assertSame('pending', $statuses['secondary']['status']);
    }

    public function testPutSkipsDownBackendAndFallsBackToNextPriority(): void
    {
        $downId = $this->registerBackend('primary', 1000, 'a');
        $this->registerBackend('secondary', 500, 'b');
        $this->backendModel()->markDown($downId);

        (new MirroredStorage('files'))->put('hash1', 'hello world');

        $this->assertFileDoesNotExist($this->projectPath . '/storage/a/hash1');
        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/b/hash1'));

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['secondary']['status']);
        $this->assertArrayNotHasKey('primary', $statuses, 'a down backend must not be queued at all');
    }

    public function testReconcileCronReplicatesPendingMirrorsAndMarksSynced(): void
    {
        $this->registerBackend('primary', 1000, 'a');
        $this->registerBackend('secondary', 500, 'b');

        (new MirroredStorage('files'))->put('hash1', 'hello world');
        $this->assertFileDoesNotExist($this->projectPath . '/storage/b/hash1');

        $this->mirrorModel()->reconcileCron();

        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/b/hash1'));

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['primary']['status']);
        $this->assertSame('synced', $statuses['secondary']['status']);
    }

    public function testGetFallsThroughToNextSyncedBackendWhenPrimaryCopyIsMissing(): void
    {
        $this->registerBackend('primary', 1000, 'a');
        $this->registerBackend('secondary', 500, 'b');

        $storage = new MirroredStorage('files');
        $storage->put('hash1', 'hello world');
        $this->mirrorModel()->reconcileCron();

        // Simulate the primary's copy silently disappearing (disk issue, manual
        // deletion, etc.) without the mirror table being told.
        unlink($this->projectPath . '/storage/a/hash1');

        $this->assertSame('hello world', (new MirroredStorage('files'))->get('hash1'));
    }

    public function testExistsIsTrueWhenAnySyncedBackendHasTheFile(): void
    {
        $this->registerBackend('primary', 1000, 'a');
        $this->registerBackend('secondary', 500, 'b');

        (new MirroredStorage('files'))->put('hash1', 'hello world');

        $this->assertTrue((new MirroredStorage('files'))->exists('hash1'));
        $this->assertFalse((new MirroredStorage('files'))->exists('does-not-exist'));
    }

    public function testDeleteRemovesFileFromEveryTrackedBackendAndClearsMirrorRows(): void
    {
        $this->registerBackend('primary', 1000, 'a');
        $this->registerBackend('secondary', 500, 'b');

        (new MirroredStorage('files'))->put('hash1', 'hello world');
        $this->mirrorModel()->reconcileCron();

        $this->assertFileExists($this->projectPath . '/storage/a/hash1');
        $this->assertFileExists($this->projectPath . '/storage/b/hash1');

        $deleted = (new MirroredStorage('files'))->delete('hash1');

        $this->assertTrue($deleted);
        $this->assertFileDoesNotExist($this->projectPath . '/storage/a/hash1');
        $this->assertFileDoesNotExist($this->projectPath . '/storage/b/hash1');
        $this->assertSame([], $this->mirrorModel()->getStatusForFile('hash1'));
    }

    public function testPutFallsBackToLocalFilesystemWhenNoBackendConfigured(): void
    {
        $storage = new MirroredStorage('files');
        $storage->put('hash1', 'hello world');

        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/files/hash1'));
        $this->assertSame([], $this->mirrorModel()->getStatusForFile('hash1'), 'untracked fallback write must not queue mirror rows');
    }

    /**
     * @param string $name
     * @param int $priority
     * @param string $directory
     * @return int
     */
    private function registerBackend(string $name, int $priority, string $directory): int
    {
        $model = $this->backendModel();
        $model->createBackend($name, 'storage', $priority, ['directory' => $directory]);

        return $model->getId();
    }

    private function backendModel(): ModuleStorageBackendModel
    {
        return ModuleStorageBackendModel::make();
    }

    private function mirrorModel(): ModuleStorageFileMirrorModel
    {
        return ModuleStorageFileMirrorModel::make();
    }

    /**
     * @param array $mirrorRows
     * @return array<string, array>
     */
    private function indexByBackendName(array $mirrorRows): array
    {
        $backendModel = $this->backendModel();
        $indexed = [];

        foreach ($mirrorRows as $row) {
            $backend = $backendModel->getById((int)$row['backend_id']);
            $indexed[$backend['name']] = $row;
        }

        return $indexed;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

}
