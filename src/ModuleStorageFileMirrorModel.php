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
     * Register that a file needs to exist on a given backend. Upserts: a (file_hash,
     * backend_id) pair is unique, and re-queuing an existing pair (e.g. drainCron()
     * targeting a backend that already holds a pending/synced row for this file from
     * an earlier fan-out) must update that row in place rather than violate the
     * unique key by inserting a duplicate.
     * @param string $fileHash
     * @param string $directory
     * @param int $backendId
     * @param string $status pending|synced|failed
     * @return bool
     * @throws DatabaseException
     */
    public function queue(string $fileHash, string $directory, int $backendId, string $status = 'pending'): bool
    {
        $existing = $this->readAll([
            'module_storage_file_mirror.file_hash' => $fileHash,
            'module_storage_file_mirror.backend_id' => $backendId
        ]);

        if ($existing !== []) {
            $row = $existing[0]['module_storage_file_mirror'];

            return $this->setId((int)$row['id'])->update([
                'directory' => $directory,
                'status' => $status,
                'last_attempt_at' => date('Y-m-d H:i:s'),
                'last_error' => null
            ]);
        }

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
     * Queue every currently-known mirrored file onto a backend as 'pending', so
     * reconcileCron() picks them up in the background.
     *
     * MirroredStorage only ever queues a file for the backends that existed at the
     * moment that file was written (see MirroredStorage::recordMirrorState()) - it
     * never retroactively revisits files written before a backend existed. This is
     * what closes that gap. It runs automatically, every minute, for every enabled
     * 'primary' backend via backfillCron() below - a newly added (or re-enabled)
     * backend gets caught up within a minute without any manual step. It stays
     * public so it can also be called directly for an immediate, on-demand backfill
     * instead of waiting for the next cron tick, and so it can be pointed at a
     * 'failover' backend too, which backfillCron() deliberately skips (see there).
     *
     * "Currently-known mirrored file" means any (file_hash, directory) pair that
     * appears in this table for at least one other backend - files written through
     * a plain 'storage'/'minio' provider (never mirrored) are not touched.
     * @param int $backendId
     * @return int number of files newly queued (files already queued/synced on this backend are left untouched)
     * @throws DatabaseException
     */
    public function backfillToBackend(int $backendId): int
    {
        $alreadyQueued = array_column(
            $this->readAll(['module_storage_file_mirror.backend_id' => $backendId], ['module_storage_file_mirror.file_hash']),
            'module_storage_file_mirror'
        );
        $alreadyQueuedHashes = array_column($alreadyQueued, 'file_hash');

        $queued = 0;

        foreach ($this->getDistinctFiles() as $file) {
            if (in_array($file['file_hash'], $alreadyQueuedHashes, true)) {
                continue;
            }

            $this->queue($file['file_hash'], $file['directory'], $backendId, 'pending');
            $queued++;
        }

        return $queued;
    }

    /**
     * Every distinct (file_hash, directory) pair known to mirroring, regardless of
     * which backend(s) it currently sits on.
     * @return array
     * @throws DatabaseException
     */
    private function getDistinctFiles(): array
    {
        $rows = $this->readAll(
            null,
            ['module_storage_file_mirror.file_hash', 'module_storage_file_mirror.directory'],
            null,
            null,
            'module_storage_file_mirror.file_hash, module_storage_file_mirror.directory'
        );

        return array_column($rows, 'module_storage_file_mirror');
    }

    /**
     * Automatically backfill every enabled 'primary' backend with any file it is
     * still missing. Runs before reconcileCron() in the same tick so a newly added
     * backend's backfilled rows get a chance to be reconciled immediately, not a
     * minute later. 'failover' backends are deliberately excluded - they are meant
     * to hold only what genuinely could not go anywhere else (see MirroredStorage),
     * not a full proactive copy of everything; use backfillToBackend() directly if
     * you really want to pre-seed one.
     * @return void
     * @throws DatabaseException
     */
    #[Cron('* * * * *', CronManager::PRIORITY_MINIMUM)]
    public function backfillCron(): void
    {
        $backendModel = ModuleStorageBackendModel::make();

        foreach ($backendModel->getAllEnabledOrderedByPriority() as $backend) {
            if ($backend['role'] !== 'primary') {
                continue;
            }

            $this->backfillToBackend((int)$backend['id']);
        }
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
        // A backend can be reclassified from 'primary' to 'failover' while it still
        // holds a full set of previously-mirrored files. Those are not "stranded" -
        // they are already safe on whatever healthy backend synced them before the
        // reclassification - so there is nothing to drain: just drop the now-redundant
        // copy sitting on this backend instead of needlessly re-copying it elsewhere.
        if ($this->hasHealthySyncedCopyElsewhere($mirror, $backendModel)) {
            $this->removeFromFailoverBackend($mirror, $failoverBackend, $backendModel);

            return;
        }

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

        $this->removeFromFailoverBackend($mirror, $failoverBackend, $backendModel);
    }

    /**
     * Whether some backend OTHER than the one in $mirror already has a confirmed
     * ('synced') and currently reachable (enabled+up) copy of this file.
     * @param array $mirror
     * @param ModuleStorageBackendModel $backendModel
     * @return bool
     */
    private function hasHealthySyncedCopyElsewhere(array $mirror, ModuleStorageBackendModel $backendModel): bool
    {
        foreach ($this->getStatusForFile($mirror['file_hash']) as $candidate) {
            if ($candidate['status'] !== 'synced' || (int)$candidate['id'] === (int)$mirror['id']) {
                continue;
            }

            $candidateBackend = $backendModel->getById((int)$candidate['backend_id']);

            if ($candidateBackend !== null && (bool)$candidateBackend['enabled'] && $candidateBackend['status'] === 'up') {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the object from a backend and, only once that is confirmed, drop its
     * mirror row - never lose track of an object that could still physically exist.
     * @param array $mirror
     * @param array $backend
     * @param ModuleStorageBackendModel $backendModel
     * @return void
     * @throws DatabaseException
     */
    private function removeFromFailoverBackend(array $mirror, array $backend, ModuleStorageBackendModel $backendModel): void
    {
        try {
            $storage = MirroredStorage::buildStorageForBackendRecord($backend, $mirror['directory'], $backendModel);
            $removed = !$storage->exists($mirror['file_hash']) || $storage->delete($mirror['file_hash']);
        } catch (Throwable) {
            $removed = false;
        }

        if ($removed) {
            $this->deleteById((int)$mirror['id']);
        }
    }

}
