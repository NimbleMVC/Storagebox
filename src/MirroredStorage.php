<?php

namespace NimblePHP\Storagebox;

use JsonException;
use NimblePHP\Framework\Log;
use NimblePHP\Framework\Storage;
use Throwable;

/**
 * Multi-backend storage: writes go synchronously to the highest-priority healthy
 * backend configured in module_storage_backend, the rest are queued in
 * module_storage_file_mirror and replicated in the background by
 * ModuleStorageFileMirrorModel::reconcileCron(). Reads try backends known to hold
 * a synced copy of the file, from highest priority to lowest.
 *
 * If no backend is configured/reachable at all, falls back to the plain local
 * filesystem (like the default 'storage' provider) so the application never loses
 * a file outright - but that fallback write is untracked (no mirror row), since
 * there is no backend record to attribute it to.
 */
class MirroredStorage extends Storage implements StreamableStorageInterface
{

    private string $directory;

    private ModuleStorageBackendModel $backendModel;

    private ModuleStorageFileMirrorModel $mirrorModel;

    /**
     * Lazily-loaded, priority-ordered list of enabled+healthy backends.
     * @var array|null
     */
    private ?array $activeBackends = null;

    public function __construct(string $directory, bool $securePath = true)
    {
        $this->directory = trim($directory, '/');
        $this->backendModel = ModuleStorageBackendModel::make();
        $this->mirrorModel = ModuleStorageFileMirrorModel::make();

        parent::__construct($directory, $securePath);
    }

    /**
     * Build a standalone Storage instance for a single backend record. Shared by
     * MirroredStorage itself and by ModuleStorageBackendModel::healthCheckCron()
     * and ModuleStorageFileMirrorModel::reconcileCron(), which operate on backends
     * outside of any particular MirroredStorage instance.
     *
     * @param array $backend A row from module_storage_backend.
     * @param string $directory
     * @param ModuleStorageBackendModel $backendModel
     * @return Storage
     * @throws JsonException
     */
    public static function buildStorageForBackendRecord(
        array $backend,
        string $directory,
        ModuleStorageBackendModel $backendModel
    ): Storage {
        return match ($backend['type']) {
            StorageProvider::minio->value => new MinioStorage($directory, true, [
                ...$backendModel->getConfig($backend),
                ...$backendModel->getCredentials($backend)
            ]),
            // A local-filesystem backend may pin its own root (e.g. a separate disk/mount)
            // via config.directory; otherwise it shares the caller-supplied directory.
            default => new Storage($backendModel->getConfig($backend)['directory'] ?? $directory),
        };
    }

    /**
     * @param array $backend
     * @return Storage
     * @throws JsonException
     */
    private function buildStorageForBackend(array $backend): Storage
    {
        return self::buildStorageForBackendRecord($backend, $this->directory, $this->backendModel);
    }

    /**
     * @return array
     */
    private function activeBackends(): array
    {
        if ($this->activeBackends === null) {
            try {
                $this->activeBackends = $this->backendModel->getActiveOrderedByPriority();
            } catch (Throwable $exception) {
                Log::log('MirroredStorage activeBackends', 'ERROR', ['exception' => $exception->getMessage()]);
                $this->activeBackends = [];
            }
        }

        return $this->activeBackends;
    }

    /**
     * Backends worth trying to read a file from: those the mirror table records as
     * holding a synced copy, in priority order. Falls back to every active backend
     * when nothing is tracked yet (e.g. a file written before mirroring was enabled,
     * or a race with the write that queued it).
     * @param string $filePath
     * @return array
     */
    private function backendsForRead(string $filePath): array
    {
        try {
            $mirrors = $this->mirrorModel->getStatusForFile($filePath);
        } catch (Throwable $exception) {
            Log::log('MirroredStorage backendsForRead', 'ERROR', ['exception' => $exception->getMessage()]);

            return $this->activeBackends();
        }

        $syncedBackendIds = array_column(
            array_filter($mirrors, static fn(array $mirror): bool => $mirror['status'] === 'synced'),
            'backend_id'
        );

        if ($syncedBackendIds === []) {
            return $this->activeBackends();
        }

        return array_values(array_filter(
            $this->activeBackends(),
            static fn(array $backend): bool => in_array((int)$backend['id'], $syncedBackendIds, true)
        ));
    }

    /**
     * Record which backend now definitely holds the file (synced) and which
     * others should still receive a copy (pending, picked up by reconcileCron()).
     * @param string $filePath
     * @param int $primaryBackendId
     * @return void
     */
    private function recordMirrorState(string $filePath, int $primaryBackendId): void
    {
        try {
            foreach ($this->activeBackends() as $backend) {
                $this->mirrorModel->queue(
                    $filePath,
                    $this->directory,
                    (int)$backend['id'],
                    (int)$backend['id'] === $primaryBackendId ? 'synced' : 'pending'
                );
            }
        } catch (Throwable $exception) {
            Log::log('MirroredStorage recordMirrorState', 'ERROR', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * @param string $filePath
     * @param string $content
     * @param string|null $contentType
     * @return true
     */
    public function put(string $filePath, string $content, ?string $contentType = null): true
    {
        $primary = null;

        foreach ($this->activeBackends() as $backend) {
            if ($this->writeContentToBackend($backend, $filePath, $content, $contentType)) {
                $primary = $backend;
                break;
            }

            $this->backendModel->markDown((int)$backend['id']);
        }

        if ($primary === null) {
            parent::put($filePath, $content);

            return true;
        }

        $this->recordMirrorState($filePath, (int)$primary['id']);

        return true;
    }

    /**
     * @param array $backend
     * @param string $filePath
     * @param string $content
     * @param string|null $contentType
     * @return bool
     */
    private function writeContentToBackend(array $backend, string $filePath, string $content, ?string $contentType): bool
    {
        try {
            $storage = $this->buildStorageForBackend($backend);

            if ($storage instanceof MinioStorage) {
                $storage->put($filePath, $content, $contentType);
            } else {
                $storage->put($filePath, $content);
            }

            return true;
        } catch (Throwable $exception) {
            Log::log('MirroredStorage put', 'WARNING', [
                'backend' => $backend['name'] ?? $backend['id'],
                'exception' => $exception->getMessage()
            ]);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function copyLocalFile(
        UploadedFile|TrustedLocalFile $source,
        string $destinationPath,
        ?string $contentType = null
    ): bool {
        $primary = null;

        foreach ($this->activeBackends() as $backend) {
            if ($this->writeSourceToBackend($backend, $source, $destinationPath, $contentType)) {
                $primary = $backend;
                break;
            }

            $this->backendModel->markDown((int)$backend['id']);
        }

        if ($primary === null) {
            return $this->copyLocalSourceToLocalFallback($source, $destinationPath);
        }

        $this->recordMirrorState($destinationPath, (int)$primary['id']);

        return true;
    }

    /**
     * @param array $backend
     * @param UploadedFile|TrustedLocalFile $source
     * @param string $destinationPath
     * @param string|null $contentType
     * @return bool
     */
    private function writeSourceToBackend(
        array $backend,
        UploadedFile|TrustedLocalFile $source,
        string $destinationPath,
        ?string $contentType
    ): bool {
        try {
            $storage = $this->buildStorageForBackend($backend);

            if ($storage instanceof StreamableStorageInterface) {
                return $storage->copyLocalFile($source, $destinationPath, $contentType);
            }

            return $this->copyLocalSourceToStorage($storage, $source, $destinationPath);
        } catch (Throwable $exception) {
            Log::log('MirroredStorage copyLocalFile', 'WARNING', [
                'backend' => $backend['name'] ?? $backend['id'],
                'exception' => $exception->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Last-resort write straight to the local filesystem, untracked, used only
     * when no backend is configured/reachable at all.
     * @param UploadedFile|TrustedLocalFile $source
     * @param string $destinationPath
     * @return bool
     */
    private function copyLocalSourceToLocalFallback(UploadedFile|TrustedLocalFile $source, string $destinationPath): bool
    {
        return $this->copyLocalSourceToStorage($this, $source, $destinationPath);
    }

    /**
     * Stream a validated source directly into a Storage instance's local path.
     * @param Storage $storage
     * @param UploadedFile|TrustedLocalFile $source
     * @param string $destinationPath
     * @return bool
     */
    private function copyLocalSourceToStorage(Storage $storage, UploadedFile|TrustedLocalFile $source, string $destinationPath): bool
    {
        $sourceStream = $source->openStream();
        $destinationStream = @fopen($storage->getFullPath($destinationPath), 'xb');

        if ($destinationStream === false) {
            fclose($sourceStream);

            return false;
        }

        $copied = false;

        try {
            $copied = stream_copy_to_stream($sourceStream, $destinationStream) !== false;
        } finally {
            fclose($sourceStream);
            fclose($destinationStream);

            if (!$copied) {
                $storage->delete($destinationPath);
            }
        }

        return $copied;
    }

    /**
     * @param string $filePath
     * @return string|null
     */
    public function get(string $filePath): ?string
    {
        foreach ($this->backendsForRead($filePath) as $backend) {
            try {
                $content = $this->buildStorageForBackend($backend)->get($filePath);
            } catch (Throwable $exception) {
                Log::log('MirroredStorage get', 'ERROR', ['exception' => $exception->getMessage()]);
                continue;
            }

            if ($content !== null) {
                return $content;
            }
        }

        return parent::get($filePath);
    }

    /**
     * @param string $filePath
     * @param string $content
     * @param string $append
     * @return true
     */
    public function append(string $filePath, string $content, string $append = PHP_EOL): true
    {
        $existingContent = $this->get($filePath) ?? '';
        $newContent = $existingContent . $append . $content;

        return $this->put($filePath, $newContent);
    }

    /**
     * @param string $filePath
     * @return bool
     */
    public function exists(string $filePath): bool
    {
        foreach ($this->backendsForRead($filePath) as $backend) {
            try {
                if ($this->buildStorageForBackend($backend)->exists($filePath)) {
                    return true;
                }
            } catch (Throwable $exception) {
                Log::log('MirroredStorage exists', 'ERROR', ['exception' => $exception->getMessage()]);
            }
        }

        return parent::exists($filePath);
    }

    /**
     * Delete the file from every backend it is known (or suspected) to be on.
     * Only reports success, and only drops the mirror rows, once every targeted
     * backend has confirmed the object is gone - matching STB-4/STB-5's rule that
     * the database must never lose track of an object that could still exist.
     * @param string $filePath
     * @return bool
     */
    public function delete(string $filePath): bool
    {
        try {
            $mirrors = $this->mirrorModel->getStatusForFile($filePath);
        } catch (Throwable $exception) {
            Log::log('MirroredStorage delete', 'ERROR', ['exception' => $exception->getMessage()]);

            return parent::delete($filePath);
        }

        if ($mirrors === []) {
            return parent::exists($filePath) ? parent::delete($filePath) : true;
        }

        $allDeleted = true;

        foreach ($mirrors as $mirror) {
            $backend = $this->backendModel->getById((int)$mirror['backend_id']);

            if ($backend === null) {
                continue;
            }

            if (!$this->deleteFromBackend($backend, $filePath)) {
                $allDeleted = false;
            }
        }

        if ($allDeleted) {
            $this->mirrorModel->deleteForFile($filePath);
        }

        return $allDeleted;
    }

    /**
     * @param array $backend
     * @param string $filePath
     * @return bool
     */
    private function deleteFromBackend(array $backend, string $filePath): bool
    {
        try {
            $storage = $this->buildStorageForBackend($backend);

            return !$storage->exists($filePath) || $storage->delete($filePath);
        } catch (Throwable $exception) {
            Log::log('MirroredStorage deleteFromBackend', 'ERROR', [
                'backend' => $backend['name'] ?? $backend['id'],
                'exception' => $exception->getMessage()
            ]);

            return false;
        }
    }

    /**
     * @param string $filePath
     * @return string
     */
    public function getFullPath(string $filePath): string
    {
        $candidates = $this->backendsForRead($filePath);
        $backend = $candidates[0] ?? ($this->activeBackends()[0] ?? null);

        if ($backend === null) {
            return parent::getFullPath($filePath);
        }

        try {
            return $this->buildStorageForBackend($backend)->getFullPath($filePath);
        } catch (Throwable $exception) {
            Log::log('MirroredStorage getFullPath', 'ERROR', ['exception' => $exception->getMessage()]);

            return parent::getFullPath($filePath);
        }
    }

    /**
     * @param string $filePath
     * @return array|null
     */
    public function getMetadata(string $filePath): ?array
    {
        foreach ($this->backendsForRead($filePath) as $backend) {
            try {
                $metadata = $this->buildStorageForBackend($backend)->getMetadata($filePath);
            } catch (Throwable $exception) {
                Log::log('MirroredStorage getMetadata', 'ERROR', ['exception' => $exception->getMessage()]);
                continue;
            }

            if ($metadata !== null) {
                return $metadata;
            }
        }

        return parent::getMetadata($filePath);
    }

    /**
     * Compatibility method for callers holding a raw local path. Prefer copyLocalFile()
     * with a validated UploadedFile/TrustedLocalFile.
     * @param string $sourcePath
     * @param string $destinationPath
     * @param string|null $contentType
     * @return bool
     */
    public function copy(string $sourcePath, string $destinationPath, ?string $contentType = null): bool
    {
        try {
            return $this->copyLocalFile(UploadedFile::fromPath($sourcePath), $destinationPath, $contentType);
        } catch (StorageBoxException $exception) {
            Log::log('MirroredStorage copy', 'WARNING', ['exception' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

}
