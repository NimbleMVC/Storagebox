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
use NimblePHP\Storagebox\ModuleStorageFileModel;
use NimblePHP\Storagebox\StorageProvider;
use PHPUnit\Framework\TestCase;

/**
 * STB-5: ModuleStorageFileModel::migrateToMirrored() adopts records written under
 * a single-provider driver into mirrored storage without moving the underlying
 * object - it only needs to register a "synced" mirror row and flip the record's
 * provider column.
 */
class ModuleStorageFileModelMigrateToMirroredTest extends TestCase
{

    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/storagebox-migrate-test-' . uniqid('', true);
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
            CREATE TABLE module_storage_file (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type VARCHAR(100),
                file_name VARCHAR(255),
                file_extension VARCHAR(50),
                hash VARCHAR(255) NOT NULL UNIQUE,
                provider VARCHAR(20) DEFAULT 'storage',
                storage_path VARCHAR(1000),
                size INTEGER DEFAULT 0,
                public INTEGER DEFAULT 1,
                auto_delete INTEGER DEFAULT 0,
                download_count INTEGER DEFAULT 0,
                date_auto_delete DATETIME,
                date_created DATETIME,
                date_modify DATETIME
            )
            SQL
        );

        DatabaseManager::$connection->getConnection()->exec(
            <<<SQL
            CREATE TABLE module_storage_backend (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL UNIQUE,
                type VARCHAR(20) DEFAULT 'minio',
                priority INTEGER DEFAULT 0,
                role VARCHAR(20) DEFAULT 'primary',
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
                date_modify DATETIME,
                UNIQUE (file_hash, backend_id)
            )
            SQL
        );
    }

    protected function tearDown(): void
    {
        Kernel::$eventDispatcher = new EventDispatcher();

        $this->removeDirectory($this->projectPath);
    }

    public function testMigrateToMirroredRegistersSyncedMirrorAndFlipsProvider(): void
    {
        $model = $this->createModel();
        $id1 = $model->write('content one', fileName: 'a.txt');
        $id2 = $model->write('content two', fileName: 'b.txt');

        // The backend representing the existing storage location. Using type
        // 'storage' here (rather than 'minio') keeps the test self-contained -
        // migrateToMirrored() never touches the storage driver itself, so the
        // mechanism it exercises is identical for a real minio->mirrored migration.
        $backendModel = ModuleStorageBackendModel::make();
        $backendModel->createBackend(name: 'legacy', type: 'storage', priority: 1000);
        $backendId = $backendModel->getId();

        $migrated = $this->createModel()->migrateToMirrored($backendId, StorageProvider::storage);
        $this->assertSame(2, $migrated);

        $info1 = $this->createModel()->getInfo($id1);
        $info2 = $this->createModel()->getInfo($id2);
        $this->assertSame('mirrored', $info1['provider']);
        $this->assertSame('mirrored', $info2['provider']);

        $mirrorModel = ModuleStorageFileMirrorModel::make();

        $mirror1 = $mirrorModel->getStatusForFile($info1['hash']);
        $this->assertCount(1, $mirror1);
        $this->assertSame('synced', $mirror1[0]['status']);
        $this->assertSame($backendId, (int)$mirror1[0]['backend_id']);

        $mirror2 = $mirrorModel->getStatusForFile($info2['hash']);
        $this->assertCount(1, $mirror2);
        $this->assertSame('synced', $mirror2[0]['status']);

        // No bytes were moved - the file is still readable, now through MirroredStorage,
        // because the "legacy" backend (type 'storage', no config.directory override)
        // resolves to the exact same physical directory the file was originally written to.
        $this->assertSame('content one', $this->createModel()->getFileContent($id1));
        $this->assertSame('content two', $this->createModel()->getFileContent($id2));
    }

    public function testMigrateToMirroredOnlyTouchesMatchingProviderAndIsBatchLimited(): void
    {
        $model = $this->createModel();
        $model->write('one', fileName: 'a.txt');
        $model->write('two', fileName: 'b.txt');
        $model->write('three', fileName: 'c.txt');

        $backendModel = ModuleStorageBackendModel::make();
        $backendModel->createBackend(name: 'legacy', type: 'storage', priority: 1000);
        $backendId = $backendModel->getId();

        $migrated = $this->createModel()->migrateToMirrored($backendId, StorageProvider::storage, limit: 2);
        $this->assertSame(2, $migrated, 'only up to the given limit should be migrated per call');

        // Calling again picks up exactly the remainder (idempotent by construction:
        // already-migrated records no longer match provider = 'storage').
        $migrated = $this->createModel()->migrateToMirrored($backendId, StorageProvider::storage, limit: 2);
        $this->assertSame(1, $migrated);

        $migrated = $this->createModel()->migrateToMirrored($backendId, StorageProvider::storage);
        $this->assertSame(0, $migrated, 'nothing left on provider storage to migrate');
    }

    private function createModel(): ModuleStorageFileModel
    {
        $model = new ModuleStorageFileModel();
        $model->useTable = 'module_storage_file';
        $model->prepareTableInstance();

        return $model;
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
