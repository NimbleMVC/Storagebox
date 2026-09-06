<?php

namespace NimblePHP\Storagebox;

use krzysztofzylka\DatabaseManager\Condition;
use NimblePHP\Framework\Abstracts\AbstractModel;
use NimblePHP\Framework\Attributes\Cron\Cron;
use NimblePHP\Framework\Cron as CronManager;
use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Framework\Exception\NimbleException;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Storage;
use NimblePHP\Storagebox\Event\AfterFileWriteEvent;
use NimblePHP\Storagebox\Event\BeforeFileDeleteEvent;
use Random\RandomException;
use Throwable;

/**
 * Universal file storage manager with metadata stored in the database.
 * Table: module_storage_file
 */
class ModuleStorageFileModel extends AbstractModel
{

    /**
     * Maximum number of files processed by a single deleteCron run
     * @var int
     */
    private const int DELETE_CRON_BATCH_LIMIT = 100;

    /**
     * Maximum number of attempts to store a record when the generated hash is already taken
     * @var int
     */
    private const int HASH_RETRY_LIMIT = 20;

    /**
     * Maximum number of records migrated by a single migrateToMirrored() call
     * @var int
     */
    private const int MIGRATE_TO_MIRRORED_BATCH_LIMIT = 500;

    /**
     * Active storage provider
     * @var StorageProvider
     */
    public StorageProvider $provider = StorageProvider::storage;

    /**
     * Storage directory where files are kept
     * @var string
     */
    public string $directory = 'storage_files';

    /**
     * Write a file from its content
     * @param string $content
     * @param string|null $type
     * @param string|null $fileName
     * @param bool $public
     * @param bool $autoDelete
     * @param string|null $deleteDate
     * @return false|int
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function write(
        string $content,
        ?string $type = null,
        ?string $fileName = null,
        bool $public = true,
        bool $autoDelete = false,
        ?string $deleteDate = null
    ): false|int
    {
        $storageInstance = $this->getStorageInstance($this->provider);
        $contentType = MimeType::fromFileName($fileName);

        for ($attempt = 1; $attempt <= self::HASH_RETRY_LIMIT; $attempt++) {
            $hash = $this->generateHash();

            try {
                $storageInstance->put($hash, $content, $contentType);
            } catch (Throwable $throwable) {
                $this->log('File write error', 'ERR', ['exception' => $throwable->getMessage()]);

                return false;
            }

            $data = [
                'type' => $type,
                'file_name' => $fileName,
                'file_extension' => $fileName ? $this->getExtension($fileName) : null,
                'hash' => $hash,
                'provider' => $this->provider->value,
                'storage_path' => $storageInstance->getFullPath($hash),
                'size' => strlen($content),
                'public' => $public ? 1 : 0,
                'auto_delete' => $autoDelete ? 1 : 0,
                'date_auto_delete' => $deleteDate,
                'download_count' => 0
            ];

            try {
                $this->create($data);
            } catch (Throwable $throwable) {
                $storageInstance->delete($hash);

                if ($this->isHashTaken($throwable) && $attempt < self::HASH_RETRY_LIMIT) {
                    continue;
                }

                $this->log('File write error', 'ERR', ['exception' => $throwable->getMessage()]);

                return false;
            }

            $id = $this->getId();

            $this->dispatchAfterFileWriteEvent($data + ['id' => $id]);

            return $id;
        }

        return false;
    }

    /**
     * Copy a PHP HTTP upload from its temporary path.
     *
     * @deprecated Prefer copyUploadedFile(), which also validates UPLOAD_ERR_OK.
     * @param string $path
     * @param string|null $type
     * @param string|null $fileName
     * @param bool $public
     * @param bool $autoDelete
     * @param string|null $deleteDate
     * @return false|int
     * @throws DatabaseException
     * @throws NimbleException
     * @throws StorageBoxException
     */
    public function copy(
        string $path,
        ?string $type = null,
        ?string $fileName = null,
        bool $public = true,
        bool $autoDelete = false,
        ?string $deleteDate = null
    ): false|int
    {
        $uploadedFile = UploadedFile::fromPath($path, $fileName);

        return $this->copyUploadedFile(
            $uploadedFile,
            $type,
            $fileName,
            $public,
            $autoDelete,
            $deleteDate
        );
    }

    /**
     * Store a validated PHP HTTP upload.
     *
     * @throws DatabaseException
     * @throws NimbleException
     * @throws StorageBoxException
     */
    public function copyUploadedFile(
        UploadedFile $uploadedFile,
        ?string $type = null,
        ?string $fileName = null,
        bool $public = true,
        bool $autoDelete = false,
        ?string $deleteDate = null
    ): false|int {
        return $this->storeLocalFile(
            $uploadedFile,
            $type,
            $fileName ?? $uploadedFile->getClientFileName(),
            $public,
            $autoDelete,
            $deleteDate
        );
    }

    /**
     * Import a local file explicitly admitted from an application-controlled root.
     *
     * @throws DatabaseException
     * @throws NimbleException
     * @throws StorageBoxException
     */
    public function importTrustedLocalFile(
        TrustedLocalFile $trustedFile,
        ?string $type = null,
        ?string $fileName = null,
        bool $public = true,
        bool $autoDelete = false,
        ?string $deleteDate = null
    ): false|int {
        return $this->storeLocalFile(
            $trustedFile,
            $type,
            $fileName ?? $trustedFile->getFileName(),
            $public,
            $autoDelete,
            $deleteDate
        );
    }

    /**
     * @param UploadedFile|TrustedLocalFile $source
     * @throws DatabaseException
     * @throws NimbleException
     * @throws StorageBoxException
     */
    private function storeLocalFile(
        UploadedFile|TrustedLocalFile $source,
        ?string $type,
        ?string $fileName,
        bool $public,
        bool $autoDelete,
        ?string $deleteDate
    ): false|int {
        $storageInstance = $this->getStorageInstance($this->provider);
        $contentType = MimeType::fromFileName($fileName);

        for ($attempt = 1; $attempt <= self::HASH_RETRY_LIMIT; $attempt++) {
            $hash = $this->generateHash();

            $copied = $storageInstance instanceof StreamableStorageInterface
                ? $storageInstance->copyLocalFile($source, $hash, $contentType)
                : $this->copyLocalSourceToStorage($storageInstance, $source, $hash);

            if (!$copied) {
                throw new StorageBoxException('Wystąpił błąd podczas kopiowania pliku');
            }

            $metadata = $storageInstance->getMetadata($hash);

            $data = [
                'type' => $type,
                'file_name' => $fileName,
                'file_extension' => $fileName !== null ? $this->getExtension($fileName) : null,
                'hash' => $hash,
                'provider' => $this->provider->value,
                'storage_path' => $storageInstance->getFullPath($hash),
                'size' => $metadata['size'] ?? 0,
                'public' => $public ? 1 : 0,
                'auto_delete' => $autoDelete ? 1 : 0,
                'date_auto_delete' => $deleteDate,
                'download_count' => 0
            ];

            try {
                $this->create($data);
            } catch (Throwable $throwable) {
                $storageInstance->delete($hash);

                if ($this->isHashTaken($throwable) && $attempt < self::HASH_RETRY_LIMIT) {
                    continue;
                }

                $this->log('File copy error', 'ERR', ['exception' => $throwable->getMessage()]);

                return false;
            }

            $id = $this->getId();

            $this->dispatchAfterFileWriteEvent($data + ['id' => $id]);

            return $id;
        }

        return false;
    }

    /**
     * Dispatch AfterFileWriteEvent without letting a listener failure undo an
     * already-persisted file+record (STB-H04): the write already succeeded at
     * this point, so a listener exception must not trigger the create()-failure
     * rollback path (which would delete the object but leave the DB record).
     * @param array $eventData
     * @return void
     */
    private function dispatchAfterFileWriteEvent(array $eventData): void
    {
        try {
            Kernel::dispatchEvent(new AfterFileWriteEvent($eventData));
        } catch (Throwable $throwable) {
            $this->log('AfterFileWriteEvent listener error', 'ERR', [
                'exception' => $throwable->getMessage(),
                'id' => $eventData['id'] ?? null
            ]);
        }
    }

    /**
     * Stream a validated source into local storage without reopening a raw path.
     *
     * @throws StorageBoxException
     */
    private function copyLocalSourceToStorage(
        Storage $storage,
        UploadedFile|TrustedLocalFile $source,
        string $destinationPath
    ): bool {
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
     * Get file content by id (increments the download counter)
     * @param int $id
     * @return string|null
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function getFileContent(int $id): ?string
    {
        $file = $this->read(['module_storage_file.id' => $id], ['module_storage_file.hash', 'module_storage_file.provider', 'module_storage_file.download_count']);

        if (!$file) {
            return null;
        }

        $storageInstance = $this->getStorageInstance(StorageProvider::getByKey($file['module_storage_file']['provider']));
        $content = $storageInstance->get($file['module_storage_file']['hash']);

        if ($content === null) {
            return null;
        }

        $this->setId($id)->updateValue('download_count', (int)$file['module_storage_file']['download_count'] + 1);

        return $content;
    }

    /**
     * Get file content by hash (increments the download counter)
     * @param string $hash
     * @return string|null
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function getFileContentByHash(string $hash): ?string
    {
        $file = $this->read(
            ['module_storage_file.hash' => $hash],
            ['module_storage_file.id', 'module_storage_file.hash', 'module_storage_file.provider', 'module_storage_file.download_count']
        );

        if (!$file) {
            return null;
        }

        $storageInstance = $this->getStorageInstance(StorageProvider::getByKey($file['module_storage_file']['provider']));
        $content = $storageInstance->get($file['module_storage_file']['hash']);

        if ($content === null) {
            return null;
        }

        $this->setId((int)$file['module_storage_file']['id'])
            ->updateValue('download_count', (int)$file['module_storage_file']['download_count'] + 1);

        return $content;
    }

    /**
     * Get the file metadata record by id (without loading file content)
     * @param int $id
     * @return array|null
     * @throws DatabaseException
     */
    public function getInfo(int $id): ?array
    {
        $file = $this->read(['module_storage_file.id' => $id]);

        return $file['module_storage_file'] ?? null;
    }

    /**
     * Get the file metadata record by hash (without loading file content)
     * @param string $hash
     * @return array|null
     * @throws DatabaseException
     */
    public function getByHash(string $hash): ?array
    {
        $file = $this->read(['module_storage_file.hash' => $hash]);

        return $file['module_storage_file'] ?? null;
    }

    /**
     * Get a download URL for the file. For private MinIO/S3 objects a temporary
     * pre-signed URL is returned; otherwise the stored public path/URL.
     * @param int $id
     * @param string $expires Lifetime of the pre-signed URL for private MinIO/S3 objects.
     * @return string|null
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function getUrl(int $id, string $expires = '+20 minutes'): ?string
    {
        $file = $this->read(
            ['module_storage_file.id' => $id],
            ['module_storage_file.hash', 'module_storage_file.provider', 'module_storage_file.public', 'module_storage_file.storage_path']
        );

        if (!$file) {
            return null;
        }

        $record = $file['module_storage_file'];
        $provider = StorageProvider::getByKey($record['provider']);

        if ($provider === StorageProvider::minio && !(bool)$record['public']) {
            $storageInstance = $this->getStorageInstance($provider);

            if ($storageInstance instanceof MinioStorage) {
                return $storageInstance->getPresignedUrl($record['hash'], $expires);
            }
        }

        return $record['storage_path'] ?? null;
    }

    /**
     * Duplicate a file by its hash
     * @param string $hash
     * @param bool $autoDelete
     * @param string|null $deleteDate
     * @return false|string
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function duplicate(string $hash, bool $autoDelete = false, ?string $deleteDate = null): false|string
    {
        $file = $this->read(['module_storage_file.hash' => $hash]);

        if (!$file) {
            return false;
        }

        $storageInstance = $this->getStorageInstance(StorageProvider::getByKey($file['module_storage_file']['provider']));
        $content = $storageInstance->get($hash);

        if ($content === null) {
            return false;
        }

        $id = $this->write(
            $content,
            $file['module_storage_file']['type'],
            $file['module_storage_file']['file_name'],
            (bool)$file['module_storage_file']['public'],
            $autoDelete,
            $deleteDate
        );

        if ($id === false) {
            return false;
        }

        $new = $this->read(['module_storage_file.id' => $id], ['module_storage_file.hash']);

        return $new['module_storage_file']['hash'] ?? false;
    }

    /**
     * Adopt existing records written under a single-provider driver ('storage' or
     * 'minio') into mirrored storage, without moving or re-uploading a single byte.
     *
     * $backendId must be a module_storage_backend row describing the SAME physical
     * location these records were originally written to (e.g. the same MinIO/S3
     * bucket and credentials for a 'minio' migration). This method does not verify
     * that the object is actually reachable there - it trusts the caller, consistent
     * with the rest of this package not enforcing foreign keys between these tables.
     * A mismatched $backendId will make the migrated records unreadable.
     *
     * For each matching record it registers a 'synced' mirror row (so MirroredStorage
     * knows where to find/delete the object) and flips the record's `provider` to
     * `mirrored`. Once migrated, adding further backends is picked up automatically
     * the same way as any other mirrored file - see
     * ModuleStorageFileMirrorModel::backfillCron().
     *
     * @param int $backendId Existing module_storage_backend row for the current location of these files.
     * @param StorageProvider $fromProvider Only records currently on this provider are migrated.
     * @param int $limit Max records migrated per call (batch-friendly - call again for more).
     * @return int Number of records migrated.
     * @throws DatabaseException
     */
    public function migrateToMirrored(
        int $backendId,
        StorageProvider $fromProvider = StorageProvider::minio,
        int $limit = self::MIGRATE_TO_MIRRORED_BATCH_LIMIT
    ): int {
        $mirrorModel = ModuleStorageFileMirrorModel::make();

        $records = $this->readAll(
            ['module_storage_file.provider' => $fromProvider->value],
            ['module_storage_file.id', 'module_storage_file.hash'],
            'module_storage_file.id ASC',
            (string)$limit
        );

        $migrated = 0;

        foreach ($records as $record) {
            $file = $record['module_storage_file'];

            $mirrorModel->queue($file['hash'], $this->directory, $backendId, 'synced');
            $this->setId((int)$file['id'])->update(['provider' => StorageProvider::mirrored->value]);

            $migrated++;
        }

        return $migrated;
    }

    /**
     * Check whether a file exists (both in the database and in storage)
     * @param string $hash
     * @return bool
     * @throws DatabaseException
     */
    public function exists(string $hash): bool
    {
        $file = $this->read(['module_storage_file.hash' => $hash], ['module_storage_file.hash', 'module_storage_file.provider']);

        if (!$file) {
            return false;
        }

        return $this->getStorageInstance(StorageProvider::getByKey($file['module_storage_file']['provider']))
            ->exists($file['module_storage_file']['hash']);
    }

    /**
     * Delete the file (storage + database) for the current id.
     *
     * The database record is only removed once the storage object has actually
     * been deleted (STB-H05): if the object delete fails, the record is kept so
     * a subsequent deleteCron()/manual retry can attempt it again instead of
     * silently leaving an orphaned, possibly still-public object with no
     * remaining trace in the database.
     * @return bool true if the record no longer exists afterwards (deleted now, or was already gone)
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function deleteFile(): bool
    {
        $file = $this->read(['module_storage_file.id' => $this->id]);

        if (!$file) {
            return true;
        }

        $record = $file['module_storage_file'];

        Kernel::dispatchEvent(new BeforeFileDeleteEvent($record));

        $deleted = $this->getStorageInstance(StorageProvider::getByKey($record['provider']))
            ->delete($record['hash']);

        if (!$deleted) {
            $this->log('File delete error: object not removed, keeping database record', 'ERR', [
                'id' => $this->id,
                'hash' => $record['hash']
            ]);

            return false;
        }

        return $this->delete();
    }

    /**
     * Disable automatic deletion of a file
     * @param int $id
     * @return bool
     * @throws DatabaseException
     */
    public function disableAutoDelete(int $id): bool
    {
        if (!$this->isset(['module_storage_file.id' => $id])) {
            return false;
        }

        return $this->setId($id)->update(['auto_delete' => 0, 'date_auto_delete' => null]);
    }

    /**
     * Enable automatic deletion of a file
     * @param int $id
     * @param string $dateAutoDelete
     * @return bool
     * @throws DatabaseException
     */
    public function enableAutoDelete(int $id, string $dateAutoDelete): bool
    {
        if (!$this->isset(['module_storage_file.id' => $id])) {
            return false;
        }

        return $this->setId($id)->update(['auto_delete' => 1, 'date_auto_delete' => $dateAutoDelete]);
    }

    /**
     * Cron job cleaning up files marked for automatic deletion
     * @return void
     * @throws DatabaseException
     * @throws NimbleException
     */
    #[Cron('* * * * *', CronManager::PRIORITY_MINIMUM)]
    public function deleteCron(): void
    {
        $toDeleteList = $this->readAll(
            [
                'module_storage_file.auto_delete' => 1,
                new Condition('module_storage_file.date_auto_delete', '<=', date('Y-m-d H:i:s'))
            ],
            ['module_storage_file.id'],
            null,
            (string)self::DELETE_CRON_BATCH_LIMIT
        );

        foreach ($toDeleteList as $file) {
            $this->setId($file['module_storage_file']['id']);
            $this->deleteFile();
        }
    }

    /**
     * Storage instance for the given provider
     * @param StorageProvider $provider
     * @return Storage
     * @throws NimbleException
     */
    private function getStorageInstance(StorageProvider $provider): Storage
    {
        return match ($provider) {
            StorageProvider::minio => new MinioStorage($this->directory),
            StorageProvider::mirrored => new MirroredStorage($this->directory),
            default => new Storage($this->directory),
        };
    }

    /**
     * Determine whether a thrown exception is a unique-constraint violation on the hash column,
     * i.e. the generated hash was already taken (a race that slipped past generateHash()).
     * @param Throwable $throwable
     * @return bool
     */
    private function isHashTaken(Throwable $throwable): bool
    {
        $current = $throwable;

        while ($current !== null) {
            $message = $current->getMessage();

            if (
                str_contains($message, 'SQLSTATE[23000]')
                || str_contains($message, '1062')
                || stripos($message, 'Duplicate entry') !== false
                || stripos($message, 'UNIQUE constraint failed') !== false
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * Generate a unique hash
     * @return string
     * @throws RandomException
     */
    private function generateHash(): string
    {
        do {
            $hash = bin2hex(random_bytes(32));
        } while ($this->isset(['module_storage_file.hash' => $hash]));

        return $hash;
    }

    /**
     * File extension from a name/path
     * @param string $fileName
     * @return string|null
     */
    private function getExtension(string $fileName): ?string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : null;
    }

}
