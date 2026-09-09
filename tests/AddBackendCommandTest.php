<?php

namespace NimblePHP\Storagebox\Tests;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use NimblePHP\Crypto\Services\EncryptionService;
use NimblePHP\Crypto\Services\KeyRepository;
use NimblePHP\Framework\CLI\Input;
use NimblePHP\Framework\CLI\Output;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Container\ServiceContainer;
use NimblePHP\Framework\Event\EventDispatcher;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Storagebox\CLI\Commands\AddBackendCommand;
use NimblePHP\Storagebox\ModuleStorageBackendModel;
use PHPUnit\Framework\TestCase;

/**
 * STB-5: storage:add-backend must never need a plain --username=/--password= value
 * (so credentials can't end up in shell history or a committed script) - it only
 * accepts them via --username-env/--password-env (an already-set env var) or an
 * interactive, echo-hidden prompt. Every test here supplies both *-env options for
 * a 'minio' backend so the interactive prompt path (which blocks on STDIN) is never
 * exercised - that path belongs to the underlying console library, not this command.
 */
class AddBackendCommandTest extends TestCase
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

    public function testMissingNameFails(): void
    {
        $exitCode = $this->runCommand([]);

        $this->assertSame(1, $exitCode);
        $this->assertSame([], $this->backendModel()->getActiveOrderedByPriority());
    }

    public function testInvalidTypeFails(): void
    {
        $exitCode = $this->runCommand(['name' => 'x', 'type' => 'ftp']);

        $this->assertSame(1, $exitCode);
    }

    public function testInvalidRoleFails(): void
    {
        $exitCode = $this->runCommand(['name' => 'x', 'role' => 'backup']);

        $this->assertSame(1, $exitCode);
    }

    public function testCreatesStorageBackendWithoutAnyCredentials(): void
    {
        $exitCode = $this->runCommand([
            'name' => 'local-emergency',
            'type' => 'storage',
            'role' => 'failover',
            'priority' => '0',
        ]);

        $this->assertSame(0, $exitCode);

        $backend = $this->onlyBackend();
        $this->assertSame('local-emergency', $backend['name']);
        $this->assertSame('storage', $backend['type']);
        $this->assertSame('failover', $backend['role']);
        $this->assertNull($backend['credentials']);
    }

    public function testCreatesMinioBackendWithCredentialsFromEnvVars(): void
    {
        $_ENV['TEST_STORAGE_USERNAME'] = 'access-key';
        $_ENV['TEST_STORAGE_PASSWORD'] = 'super-secret';

        $exitCode = $this->runCommand([
            'name' => 'minio-primary',
            'type' => 'minio',
            'priority' => '1000',
            'host' => 'https://minio.local:9000',
            'bucket' => 'files',
            'username-env' => 'TEST_STORAGE_USERNAME',
            'password-env' => 'TEST_STORAGE_PASSWORD',
        ]);

        unset($_ENV['TEST_STORAGE_USERNAME'], $_ENV['TEST_STORAGE_PASSWORD']);

        $this->assertSame(0, $exitCode);

        $backendModel = $this->backendModel();
        $backend = $this->onlyBackend();

        $this->assertSame('primary', $backend['role']);
        $this->assertStringNotContainsString('super-secret', (string)$backend['credentials']);

        $config = $backendModel->getConfig($backend);
        $this->assertSame('https://minio.local:9000', $config['host']);
        $this->assertSame('files', $config['bucket']);

        $credentials = $backendModel->getCredentials($backend);
        $this->assertSame(['username' => 'access-key', 'password' => 'super-secret'], $credentials);
    }

    public function testUnsetEnvVarLeavesCredentialEmptyWithoutPrompting(): void
    {
        $exitCode = $this->runCommand([
            'name' => 'minio-no-creds',
            'type' => 'minio',
            'username-env' => 'TEST_STORAGE_DOES_NOT_EXIST_USERNAME',
            'password-env' => 'TEST_STORAGE_DOES_NOT_EXIST_PASSWORD',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertNull($this->onlyBackend()['credentials']);
    }

    public function testDuplicateNameFailsWithFriendlyError(): void
    {
        $first = $this->runCommand(['name' => 'dup', 'type' => 'storage']);
        $this->assertSame(0, $first);

        $second = $this->runCommand(['name' => 'dup', 'type' => 'storage']);
        $this->assertSame(1, $second);

        // Only the first registration should have gone through.
        $rows = $this->backendModel()->getAllEnabledOrderedByPriority();
        $this->assertCount(1, $rows);
    }

    /**
     * @param array<string, string> $options Option name (without leading "-") => value.
     *                                        Include 'name' for the positional backend name.
     * @return int
     */
    private function runCommand(array $options): int
    {
        $parsedArguments = $options;

        if (array_key_exists('name', $parsedArguments)) {
            $parsedArguments = [0 => $parsedArguments['name']] + array_diff_key($parsedArguments, ['name' => true]);
        }

        $input = new Input('storage:add-backend', [], $parsedArguments, [['name' => 'name']]);
        $output = new Output();

        return (new AddBackendCommand())->run($input, $output);
    }

    private function backendModel(): ModuleStorageBackendModel
    {
        return ModuleStorageBackendModel::make();
    }

    private function onlyBackend(): array
    {
        $rows = $this->backendModel()->getAllEnabledOrderedByPriority();
        $this->assertCount(1, $rows);

        return $rows[0];
    }

}
