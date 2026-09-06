<?php

namespace NimblePHP\Storagebox;

use NimblePHP\Framework\Abstracts\AbstractModel;
use NimblePHP\Framework\Attributes\Cron\Cron;
use NimblePHP\Framework\Cron as CronManager;
use NimblePHP\Framework\Exception\DatabaseException;
use Throwable;

/**
 * Per-file, per-backend mirroring status for mirrored (multi-backend) storage.
 * Rows are keyed by the object's hash/key (same value passed to Storage::put/get/delete),
 * not by module_storage_file.id, since the mirror rows are written before the
 * module_storage_file record exists (see ModuleStorageFileModel::write()/storeLocalFile()).
 * Table: module_storage_file_mirror
 */
class ModuleStorageFileMirrorModel extends AbstractModel
{

    /**
     * Build a ready-to-use instance without going through the framework's
     * controller-bound loadModel(). Needed by MirroredStorage, which constructs
     * this model outside of that flow.
     * @return self
     */
    public static function make(): self
    {
        $model = new self();
        $model->useTable = 'module_storage_file_mirror';
        $model->prepareTableInstance();

        return $model;
    }

    /**
     * Register that a file needs to exist on a given backend.
     * @param string $fileHash
     * @param string $directory
     * @param int $backendId
     * @param string $status pending|synced|failed
     * @return bool
     * @throws DatabaseException
     */
    public function queue(string $fileHash, string $directory, int $backendId, string $status = 'pending'): bool
    {
        return $this->create([
            'file_hash' => $fileHash,
            'directory' => $directory,
            'backend_id' => $backendId,
            'status' => $status
        ]);
    }

    /**
     * Mirror status rows for a given file, across all its backends.
     * @param string $fileHash
     * @return array
     * @throws DatabaseException
     */
    public function getStatusForFile(string $fileHash): array
    {
        $rows = $this->readAll(['module_storage_file_mirror.file_hash' => $fileHash]);

        return array_column($rows, 'module_storage_file_mirror');
    }

    /**
     * Pending (or previously failed) mirror rows to (re)process, oldest first.
     * @param int $limit
     * @return array
     * @throws DatabaseException
     */
    public function getPending(int $limit = 100): array
    {
        $rows = $this->readAll(
            ['module_storage_file_mirror.status' => ['pending', 'failed']],
            null,
            'module_storage_file_mirror.id ASC',
            (string)$limit
        );

        return array_column($rows, 'module_storage_file_mirror');
    }

    /**
     * @param int $id
     * @return bool
     * @throws DatabaseException
     */
    public function markSynced(int $id): bool
    {
        return $this->setId($id)->update([
            'status' => 'synced',
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'last_error' => null
        ]);
    }

    /**
     * @param int $id
     * @param string $error
     * @return bool
     * @throws DatabaseException
     */
    public function markFailed(int $id, string $error): bool
    {
        return $this->setId($id)->update([
            'status' => 'failed',
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'last_error' => $error
        ]);
    }

    /**
     * Remove all mirror rows for a file (e.g. once it has been deleted from every backend).
     * @param string $fileHash
     * @return bool
     * @throws DatabaseException
     */
    public function deleteForFile(string $fileHash): bool
    {
        return $this->deleteByConditions(['file_hash' => $fileHash]);
    }

    /**
     * Synced mirror rows sitting on a specific backend (used by drainCron() to find
     * files parked on a 'failover' backend), oldest first.
     * @param int $backendId
     * @param int $limit
     * @return array
     * @throws DatabaseException
     */
    public function getSyncedForBackend(int $backendId, int $limit = 100): array
    {
        $rows = $this->readAll(
            ['module_storage_file_mirror.status' => 'synced', 'module_storage_file_mirror.backend_id' => $backendId],
            null,
            'module_storage_file_mirror.id ASC',
            (string)$limit
        );

        return array_column($rows, 'module_storage_file_mirror');
    }

    /**
     * Remove a single mirror row by id (as opposed to deleteForFile(), which removes
     * every row for a file across all backends).
     * @param int $id
     * @return bool
     * @throws DatabaseException
     */
    public function deleteById(int $id): bool
    {
        return $this->setId($id)->delete();
    }

    /**
     * Maximum number of mirror rows processed by a single reconcileCron() run.
     * @var int
     */
    private const int RECONCILE_BATCH_LIMIT = 100;

    /**
     * Replicate files that are only present on some of their configured backends:
     * for every pending/failed row, read the content from any backend that is
     * currently marked synced for that file, then push it onto the target backend
     * (only if that target is itself enabled and up - no point retrying a backend
     * that healthCheckCron() has already marked down).
     * @return void
     * @throws DatabaseException
     */
    #[Cron('* * * * *', CronManager::PRIORITY_MINIMUM)]
    public function reconcileCron(): void
    {
        $backendModel = ModuleStorageBackendModel::make();

        foreach ($this->getPending(self::RECONCILE_BATCH_LIMIT) as $mirror) {
            $targetBackend = $backendModel->getById((int)$mirror['backend_id']);

            if ($targetBackend === null || !(bool)$targetBackend['enabled'] || $targetBackend['status'] !== 'up') {
                continue;
            }

            $content = $this->readFromAnySyncedBackend($mirror, $backendModel);

            if ($content === null) {
                $this->markFailed((int)$mirror['id'], 'No healthy backend currently holds a synced copy of this file');
                continue;
            }

            try {
                MirroredStorage::buildStorageForBackendRecord($targetBackend, $mirror['directory'], $backendModel)
                    ->put($mirror['file_hash'], $content);
                $this->markSynced((int)$mirror['id']);
            } catch (Throwable $exception) {
                $backendModel->markDown((int)$targetBackend['id']);
                $this->markFailed((int)$mirror['id'], $exception->getMessage());
            }
        }
    }

    /**
     * @param array $mirror
     * @param ModuleStorageBackendModel $backendModel
     * @return string|null
     */
    private function readFromAnySyncedBackend(array $mirror, ModuleStorageBackendModel $backendModel): ?string
    {
        foreach ($this->getStatusForFile($mirror['file_hash']) as $candidate) {
            if ($candidate['status'] !== 'synced' || (int)$candidate['id'] === (int)$mirror['id']) {
                continue;
            }

            $sourceBackend = $backendModel->getById((int)$candidate['backend_id']);

            if ($sourceBackend === null) {
                continue;
            }

            try {
                $content = MirroredStorage::buildStorageForBackendRecord($sourceBackend, $mirror['directory'], $backendModel)
                    ->get($mirror['file_hash']);
            } catch (Throwable) {
                continue;
            }

            if ($content !== null) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Maximum number of mirror rows processed by a single drainCron() run.
     * @var int
     */
    private const int DRAIN_BATCH_LIMIT = 100;

    /**
     * Move files parked on a 'failover' backend back onto a 'primary' backend once
     * one is healthy again. A 'failover' backend never receives a proactive mirror
     * copy (see MirroredStorage::recordMirrorState()) - it only ever holds a file
     * because every 'primary' backend was unavailable at write time - so this is
     * the only path that gets such a file back onto normal, mirrored storage.
     * @return void
     * @throws DatabaseException
     */
    #[Cron('* * * * *', CronManager::PRIORITY_MINIMUM)]
    public function drainCron(): void
    {
        $backendModel = ModuleStorageBackendModel::make();
        $failoverBackends = $backendModel->getActiveByRole('failover');

        if ($failoverBackends === []) {
            return;
        }

        $primaryBackends = $backendModel->getActiveByRole('primary');

        if ($primaryBackends === []) {
            return;
        }

        $targetBackend = $primaryBackends[0];

        foreach ($failoverBackends as $failoverBackend) {
            foreach ($this->getSyncedForBackend((int)$failoverBackend['id'], self::DRAIN_BATCH_LIMIT) as $mirror) {
                $this->drainOne($mirror, $failoverBackend, $targetBackend, $backendModel);
            }
        }
    }

    /**
     * @param array $mirror
     * @param array $failoverBackend
     * @param array $targetBackend
     * @param ModuleStorageBackendModel $backendModel
     * @return void
     * @throws DatabaseException
     */
    private function drainOne(array $mirror, array $failoverBackend, array $targetBackend, ModuleStorageBackendModel $backendModel): void
    {
        try {
            $failoverStorage = MirroredStorage::buildStorageForBackendRecord($failoverBackend, $mirror['directory'], $backendModel);
            $content = $failoverStorage->get($mirror['file_hash']);
        } catch (Throwable) {
            return;
        }

        if ($content === null) {
            // Object already gone from the failover backend (e.g. deleted meanwhile
            // via MirroredStorage::delete()) - nothing left to drain, drop the stale row.
            $this->deleteById((int)$mirror['id']);

            return;
        }

        try {
            MirroredStorage::buildStorageForBackendRecord($targetBackend, $mirror['directory'], $backendModel)
                ->put($mirror['file_hash'], $content);
        } catch (Throwable) {
            $backendModel->markDown((int)$targetBackend['id']);

            return;
        }

        $this->queue($mirror['file_hash'], $mirror['directory'], (int)$targetBackend['id'], 'synced');

        $removedFromFailover = false;

        try {
            $removedFromFailover = !$failoverStorage->exists($mirror['file_hash']) || $failoverStorage->delete($mirror['file_hash']);
        } catch (Throwable) {
            $removedFromFailover = false;
        }

        if (!$removedFromFailover) {
            // The file now safely exists on both the failover and the target backend;
            // leave this row in place so the next run retries removing it from the
            // failover backend, matching the "never lose track" rule used everywhere
            // else in this package (STB-4/STB-5).
            return;
        }

        $this->deleteById((int)$mirror['id']);
    }

}
