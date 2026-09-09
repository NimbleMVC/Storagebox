<?php

namespace NimblePHP\Storagebox\Tests;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use NimblePHP\Crypto\Services\EncryptionService;
use NimblePHP\Crypto\Services\KeyRepository;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Container\ServiceContainer;
use NimblePHP\Framework\Event\EventDispatcher;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Storagebox\ModuleStorageBackendModel;
use PHPUnit\Framework\TestCase;

/**
 * STB-5: backend credentials must round-trip through NimblePHP\Crypto encryption
 * and never be readable as plaintext in the stored row, and active backends must
 * come back ordered by priority.
 */
class ModuleStorageBackendModelTest extends TestCase
{

    protected function setUp(): void
    {
        Kernel::$middlewareManager = new MiddlewareManager();
        Kernel::$eventDispatcher = new EventDispatcher();
        Kernel::$serviceContainer = new ServiceContainer();

        $keys = new KeyRepository();
        Kernel::$serviceContainer->set('crypto.encryption', new EncryptionService($keys));

        Config::set('DATABASE', true);
        Config::set('LOG', false);
        Config::set('ENCRYPTION_KEY_CURRENT', 1);
        Config::set('ENCRYPTION_KEY_1', bin2hex(random_bytes(32)));

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
    }

    public function testCredentialsAreEncryptedAtRestAndDecryptCorrectly(): void
    {
        $model = $this->createModel();

        $created = $model->createBackend(
            name: 'primary-minio',
            type: 'minio',
            priority: 1000,
            config: ['host' => 'https://minio.local', 'bucket' => 'files'],
            credentials: ['username' => 'access-key', 'password' => 'super-secret']
        );

        $this->assertTrue($created);

        $row = $this->createModel()->getById($model->getId());
        $this->assertNotNull($row);

        $this->assertStringNotContainsString('super-secret', (string)$row['credentials']);
        $this->assertStringNotContainsString('access-key', (string)$row['credentials']);

        $credentials = $this->createModel()->getCredentials($row);
        $this->assertSame(['username' => 'access-key', 'password' => 'super-secret'], $credentials);

        $config = $this->createModel()->getConfig($row);
        $this->assertSame(['host' => 'https://minio.local', 'bucket' => 'files'], $config);
    }

    public function testBackendWithoutCredentialsHasNoCiphertext(): void
    {
        $model = $this->createModel();
        $model->createBackend(name: 'local-fallback', type: 'storage', priority: 0);

        $row = $this->createModel()->getById($model->getId());

        $this->assertNull($row['credentials']);
        $this->assertSame([], $this->createModel()->getCredentials($row));
    }

    public function testGetActiveOrderedByPriorityExcludesDisabledAndDownBackends(): void
    {
        $model = $this->createModel();
        $model->createBackend(name: 'minio-primary', type: 'minio', priority: 1000);
        $secondaryModel = $this->createModel();
        $secondaryModel->createBackend(name: 's3-secondary', type: 'minio', priority: 500);
        $disabledModel = $this->createModel();
        $disabledModel->createBackend(name: 'disabled-backend', type: 'storage', priority: 10);
        $disabledModel->setId($disabledModel->getId())->update(['enabled' => 0]);

        $downModel = $this->createModel();
        $downModel->createBackend(name: 'down-backend', type: 'storage', priority: 5000);
        $downModel->markDown($downModel->getId());

        $active = $this->createModel()->getActiveOrderedByPriority();

        $this->assertSame(['minio-primary', 's3-secondary'], array_column($active, 'name'));
    }

    public function testGetActiveByRoleFiltersOutTheOtherRole(): void
    {
        $primaryModel = $this->createModel();
        $primaryModel->createBackend(name: 'primary-minio', type: 'minio', priority: 1000, role: 'primary');

        $failoverModel = $this->createModel();
        $failoverModel->createBackend(name: 'emergency-local', type: 'storage', priority: 0, role: 'failover');

        $this->assertSame(['primary-minio'], array_column($this->createModel()->getActiveByRole('primary'), 'name'));
        $this->assertSame(['emergency-local'], array_column($this->createModel()->getActiveByRole('failover'), 'name'));
    }

    public function testCreateBackendDefaultsToPrimaryRole(): void
    {
        $model = $this->createModel();
        $model->createBackend(name: 'default-role', type: 'storage', priority: 0);

        $row = $this->createModel()->getById($model->getId());

        $this->assertSame('primary', $row['role']);
    }

    private function createModel(): ModuleStorageBackendModel
    {
        return ModuleStorageBackendModel::make();
    }

}
