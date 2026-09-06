<?php

namespace NimblePHP\Storagebox\Tests;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Event\EventDispatcher;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Storagebox\Event\BackendMarkedDownEvent;
use NimblePHP\Storagebox\Event\BackendMarkedUpEvent;
use NimblePHP\Storagebox\Event\MirrorSyncFailedEvent;
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

    public function testFailoverBackendDoesNotReceiveProactiveMirrorCopies(): void
    {
        $this->registerBackend('primary-a', 1000, 'a');
        $this->registerBackend('primary-b', 500, 'b');
        $this->registerBackend('emergency', 0, 'c', 'failover');

        (new MirroredStorage('files'))->put('hash1', 'hello world');

        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/a/hash1'));
        $this->assertFileDoesNotExist($this->projectPath . '/storage/c/hash1');

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['primary-a']['status']);
        $this->assertSame('pending', $statuses['primary-b']['status']);
        $this->assertArrayNotHasKey('emergency', $statuses, 'a failover backend must never get a proactive mirror row');
    }

    public function testWriteFallsBackToFailoverBackendWhenAllPrimariesAreDown(): void
    {
        $primaryId = $this->registerBackend('primary-a', 1000, 'a');
        $this->backendModel()->markDown($primaryId);
        $this->registerBackend('emergency', 0, 'c', 'failover');

        (new MirroredStorage('files'))->put('hash1', 'hello world');

        $this->assertFileDoesNotExist($this->projectPath . '/storage/a/hash1');
        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/c/hash1'));

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['emergency']['status']);
        $this->assertCount(1, $statuses, 'no pending rows should be queued when the write landed on a failover backend');
    }

    public function testDrainCronMovesFileFromFailoverBackendOntoPrimaryOnceHealthy(): void
    {
        $primaryId = $this->registerBackend('primary-a', 1000, 'a');
        $this->backendModel()->markDown($primaryId);
        $this->registerBackend('emergency', 0, 'c', 'failover');

        (new MirroredStorage('files'))->put('hash1', 'hello world');
        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/c/hash1'));

        // The primary recovers (e.g. picked up by healthCheckCron()).
        $this->backendModel()->markUp($primaryId);

        $this->mirrorModel()->drainCron();

        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/a/hash1'));
        $this->assertFileDoesNotExist($this->projectPath . '/storage/c/hash1', 'drainCron() must remove the file from the failover backend once it is safely on a primary');

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['primary-a']['status']);
        $this->assertArrayNotHasKey('emergency', $statuses, 'the failover mirror row must be dropped once drained');
    }

    public function testDrainCronDeletesRedundantCopyWhenBackendIsReclassifiedToFailover(): void
    {
        // "primary-a" starts out as a normal primary, gets a full proactive mirror
        // copy like any other primary, and is only reclassified to 'failover' later
        // (an admin decision, not something that happens through MirroredStorage).
        $primaryAId = $this->registerBackend('primary-a', 1000, 'a');
        $this->registerBackend('primary-b', 500, 'b');

        (new MirroredStorage('files'))->put('hash1', 'hello world');
        $this->mirrorModel()->reconcileCron();

        $this->assertFileExists($this->projectPath . '/storage/a/hash1');
        $this->assertFileExists($this->projectPath . '/storage/b/hash1');

        $this->backendModel()->setId($primaryAId)->update(['role' => 'failover']);

        $this->mirrorModel()->drainCron();

        $this->assertFileDoesNotExist($this->projectPath . '/storage/a/hash1', 'a redundant copy on a newly-failover backend must be deleted, not re-copied');
        $this->assertFileExists($this->projectPath . '/storage/b/hash1', 'the untouched primary copy must be left alone');

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertArrayNotHasKey('primary-a', $statuses, 'the now-failover backend must lose its mirror row');
        $this->assertSame('synced', $statuses['primary-b']['status']);
        $this->assertCount(1, $statuses);
    }

    public function testDrainCronDoesNotCrashWhenTargetBackendAlreadyHasAPendingRowForTheFile(): void
    {
        // "primary-b" already has a *pending* (not yet synced) mirror row for hash1
        // from the original fan-out, because reconcileCron() has not run yet. If
        // "primary-a" (the one that actually holds the synced copy) is reclassified
        // to 'failover' before that reconciliation happens, drainCron() ends up
        // targeting "primary-b" too - queue() must update that existing row instead
        // of colliding with its (file_hash, backend_id) unique key.
        $primaryAId = $this->registerBackend('primary-a', 1000, 'a');
        $this->registerBackend('primary-b', 500, 'b');

        (new MirroredStorage('files'))->put('hash1', 'hello world');
        // Deliberately do NOT run reconcileCron() - primary-b stays "pending".

        $this->backendModel()->setId($primaryAId)->update(['role' => 'failover']);

        $this->mirrorModel()->drainCron();

        $this->assertFileDoesNotExist($this->projectPath . '/storage/a/hash1');
        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/b/hash1'));

        $statuses = $this->mirrorModel()->getStatusForFile('hash1');
        $this->assertCount(1, $statuses, 'the pre-existing pending row must be updated in place, not duplicated');
        $this->assertSame('synced', $statuses[0]['status']);
    }

    public function testBackfillToBackendQueuesEveryKnownFileOnceForANewBackend(): void
    {
        $this->registerBackend('primary-a', 1000, 'a');

        (new MirroredStorage('files'))->put('hash1', 'file one');
        (new MirroredStorage('files'))->put('hash2', 'file two');

        // A brand-new backend, added after both files already existed.
        $newBackendId = $this->registerBackend('primary-b', 500, 'b');

        $queued = $this->mirrorModel()->backfillToBackend($newBackendId);
        $this->assertSame(2, $queued);

        $statusesHash1 = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $statusesHash2 = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash2'));
        $this->assertSame('pending', $statusesHash1['primary-b']['status']);
        $this->assertSame('pending', $statusesHash2['primary-b']['status']);

        // reconcileCron() then picks the backfilled rows up like any other pending mirror.
        $this->mirrorModel()->reconcileCron();

        $this->assertSame('file one', file_get_contents($this->projectPath . '/storage/b/hash1'));
        $this->assertSame('file two', file_get_contents($this->projectPath . '/storage/b/hash2'));

        // Calling it again must not re-queue (and not duplicate) already-handled files.
        $this->assertSame(0, $this->mirrorModel()->backfillToBackend($newBackendId));
    }

    public function testBackfillCronAutomaticallyPicksUpANewlyAddedPrimaryBackendWithoutManualBackfill(): void
    {
        $this->registerBackend('primary-a', 1000, 'a');
        (new MirroredStorage('files'))->put('hash1', 'hello world');

        // Admin adds a second primary later - no call to backfillToBackend() here.
        $this->registerBackend('primary-b', 500, 'b');

        $this->mirrorModel()->backfillCron();
        $this->mirrorModel()->reconcileCron();

        $this->assertSame('hello world', file_get_contents($this->projectPath . '/storage/b/hash1'));

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertSame('synced', $statuses['primary-b']['status']);
    }

    public function testBackfillCronSkipsFailoverBackends(): void
    {
        $this->registerBackend('primary-a', 1000, 'a');
        (new MirroredStorage('files'))->put('hash1', 'hello world');

        $this->registerBackend('emergency', 0, 'c', 'failover');

        $this->mirrorModel()->backfillCron();

        $statuses = $this->indexByBackendName($this->mirrorModel()->getStatusForFile('hash1'));
        $this->assertArrayNotHasKey('emergency', $statuses, 'backfillCron() must not proactively seed a failover backend');
    }

    public function testMarkDownAndMarkUpDispatchEventsOnlyOnActualTransition(): void
    {
        $id = $this->registerBackend('primary-a', 1000, 'a');

        $downEvents = [];
        $upEvents = [];
        Kernel::getEventDispatcher()->addListener(BackendMarkedDownEvent::class, function (BackendMarkedDownEvent $event) use (&$downEvents): void {
            $downEvents[] = $event->backend;
        });
        Kernel::getEventDispatcher()->addListener(BackendMarkedUpEvent::class, function (BackendMarkedUpEvent $event) use (&$upEvents): void {
            $upEvents[] = $event->backend;
        });

        $this->backendModel()->markDown($id);
        $this->assertCount(1, $downEvents);
        $this->assertSame('primary-a', $downEvents[0]['name']);
        $this->assertSame('down', $downEvents[0]['status']);

        // Already down - a redundant markDown() (e.g. another failed write attempt)
        // must not fire a second event.
        $this->backendModel()->markDown($id);
        $this->assertCount(1, $downEvents);

        $this->backendModel()->markUp($id);
        $this->assertCount(1, $upEvents);
        $this->assertSame('up', $upEvents[0]['status']);

        // Already up - no redundant event here either.
        $this->backendModel()->markUp($id);
        $this->assertCount(1, $upEvents);
    }

    public function testMarkFailedDispatchesMirrorSyncFailedEvent(): void
    {
        $backendId = $this->registerBackend('primary-a', 1000, 'a');
        $mirrorModel = $this->mirrorModel();
        $mirrorModel->queue('hash1', 'files', $backendId, 'pending');
        $mirrorRow = $mirrorModel->getStatusForFile('hash1')[0];

        $captured = [];
        Kernel::getEventDispatcher()->addListener(MirrorSyncFailedEvent::class, function (MirrorSyncFailedEvent $event) use (&$captured): void {
            $captured[] = $event;
        });

        $mirrorModel->markFailed((int)$mirrorRow['id'], 'no healthy source');

        $this->assertCount(1, $captured);
        $this->assertSame('no healthy source', $captured[0]->error);
        $this->assertSame('hash1', $captured[0]->mirror['file_hash']);
        $this->assertSame('failed', $captured[0]->mirror['status']);

        // Every failure dispatches again - no built-in throttling.
        $mirrorModel->markFailed((int)$mirrorRow['id'], 'still failing');
        $this->assertCount(2, $captured);
    }

    /**
     * @param string $name
     * @param int $priority
     * @param string $directory
     * @param string $role
     * @return int
     */
    private function registerBackend(string $name, int $priority, string $directory, string $role = 'primary'): int
    {
        $model = $this->backendModel();
        $model->createBackend($name, 'storage', $priority, ['directory' => $directory], [], $role);

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
