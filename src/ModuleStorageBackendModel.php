<?php

namespace NimblePHP\Storagebox;

use JsonException;
use NimblePHP\Crypto\Crypto;
use NimblePHP\Framework\Abstracts\AbstractModel;
use NimblePHP\Framework\Attributes\Cron\Cron;
use NimblePHP\Framework\Cron as CronManager;
use NimblePHP\Framework\Exception\DatabaseException;
use Throwable;

/**
 * Configured storage backends used by mirrored (multi-backend) storage.
 * Table: module_storage_backend
 */
class ModuleStorageBackendModel extends AbstractModel
{

    /**
     * Build a ready-to-use instance without going through the framework's
     * controller-bound loadModel() (which sets $name/useTable and calls
     * prepareTableInstance() for you). Needed by MirroredStorage and the
     * mirroring cron, which construct this model outside of that flow.
     * @return self
     */
    public static function make(): self
    {
        $model = new self();
        $model->useTable = 'module_storage_backend';
        $model->prepareTableInstance();

        return $model;
    }

    /**
     * Register a new backend. Non-secret settings go in $config (e.g. bucket, region,
     * endpoint, directory); anything sensitive goes in $credentials and is encrypted
     * at rest via NimblePHP\Crypto before being stored.
     *
     * @param string $name Unique backend identifier, also used as the encryption context.
     * @param string $type StorageProvider value, e.g. 'minio' or 'storage'.
     * @param int $priority Higher is tried first.
     * @param array $config Non-secret settings, stored as plain JSON.
     * @param array $credentials Sensitive settings (e.g. access key/secret), encrypted before storage.
     * @return bool
     * @throws DatabaseException
     * @throws JsonException
     */
    public function createBackend(
        string $name,
        string $type,
        int $priority = 0,
        array $config = [],
        array $credentials = []
    ): bool {
        return $this->create([
            'name' => $name,
            'type' => $type,
            'priority' => $priority,
            'status' => 'up',
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'credentials' => $credentials !== [] ? Crypto::encryptArray($credentials, $this->credentialsContext($name)) : null,
            'enabled' => 1
        ]);
    }

    /**
     * Enabled, healthy backends ordered from highest to lowest priority.
     * @return array
     * @throws DatabaseException
     */
    public function getActiveOrderedByPriority(): array
    {
        $rows = $this->readAll(
            ['module_storage_backend.enabled' => 1, 'module_storage_backend.status' => 'up'],
            null,
            'module_storage_backend.priority DESC'
        );

        return array_column($rows, 'module_storage_backend');
    }

    /**
     * All enabled backends ordered from highest to lowest priority (regardless of health),
     * used by the health-check cron.
     * @return array
     * @throws DatabaseException
     */
    public function getAllEnabledOrderedByPriority(): array
    {
        $rows = $this->readAll(
            ['module_storage_backend.enabled' => 1],
            null,
            'module_storage_backend.priority DESC'
        );

        return array_column($rows, 'module_storage_backend');
    }

    /**
     * @param int $id
     * @return array|null
     * @throws DatabaseException
     */
    public function getById(int $id): ?array
    {
        $row = $this->read(['module_storage_backend.id' => $id]);

        return $row['module_storage_backend'] ?? null;
    }

    /**
     * Mark a backend as reachable.
     * @param int $id
     * @return bool
     * @throws DatabaseException
     */
    public function markUp(int $id): bool
    {
        return $this->setId($id)->update(['status' => 'up', 'last_checked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Mark a backend as unreachable.
     * @param int $id
     * @return bool
     * @throws DatabaseException
     */
    public function markDown(int $id): bool
    {
        return $this->setId($id)->update(['status' => 'down', 'last_checked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Decode the non-secret config JSON stored for a backend record.
     * @param array $record
     * @return array
     * @throws JsonException
     */
    public function getConfig(array $record): array
    {
        if (empty($record['config'])) {
            return [];
        }

        return json_decode($record['config'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decrypt the credentials stored for a backend record.
     * @param array $record
     * @return array
     * @throws JsonException
     */
    public function getCredentials(array $record): array
    {
        if (empty($record['credentials'])) {
            return [];
        }

        return Crypto::decryptArray($record['credentials'], $this->credentialsContext($record['name']));
    }

    /**
     * Encryption context binding a backend's credentials ciphertext to its own name,
     * so it cannot be decrypted after being copied onto another record.
     * @param string $name
     * @return string
     */
    private function credentialsContext(string $name): string
    {
        return 'module_storage_backend:' . $name;
    }

    /**
     * Directory used for the connectivity probe issued by healthCheckCron(). Any
     * directory works since only reachability is being tested, not a real object.
     */
    private const HEALTHCHECK_DIRECTORY = '.storagebox-healthcheck';

    /**
     * Probe every enabled backend and update its up/down status accordingly.
     * @return void
     * @throws DatabaseException
     * @throws JsonException
     */
    #[Cron('*/5 * * * *', CronManager::PRIORITY_MINIMUM)]
    public function healthCheckCron(): void
    {
        foreach ($this->getAllEnabledOrderedByPriority() as $backend) {
            if ($this->probe($backend)) {
                if ($backend['status'] !== 'up') {
                    $this->markUp($backend['id']);
                }
            } elseif ($backend['status'] !== 'down') {
                $this->markDown($backend['id']);
            }
        }
    }

    /**
     * @param array $backend
     * @return bool
     */
    private function probe(array $backend): bool
    {
        try {
            MirroredStorage::buildStorageForBackendRecord($backend, self::HEALTHCHECK_DIRECTORY, $this)
                ->exists('.probe');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

}
