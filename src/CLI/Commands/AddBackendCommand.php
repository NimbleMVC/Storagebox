<?php

namespace NimblePHP\Storagebox\CLI\Commands;

use Krzysztofzylka\Console\Form;
use NimblePHP\Framework\CLI\AbstractCommand;
use NimblePHP\Framework\CLI\Attributes\ConsoleCommand;
use NimblePHP\Storagebox\ModuleStorageBackendModel;
use Throwable;

/**
 * Register a mirrored-storage backend without ever putting its credentials in a
 * committed file (a migration, a seeder, ...). Non-secret settings can be passed
 * as options; credentials are either read from an already-set environment
 * variable (--username-env/--password-env, so the secret itself never touches
 * argv/shell history) or typed interactively with echo disabled.
 */
#[ConsoleCommand(
    'storage:add-backend',
    'Register a mirrored-storage backend (module_storage_backend)',
    help: 'Adds a backend used by StorageProvider::mirrored. Credentials are never accepted as a plain '
        . 'command-line value: pass --username-env/--password-env naming an environment variable that '
        . 'already holds the secret (e.g. one loaded from .env), or leave them out to be prompted '
        . 'interactively with input hidden. A "storage" (local filesystem) backend needs no credentials.',
    usage: 'php vendor/bin/nimble storage:add-backend <name> [options]',
    arguments: [
        ['name' => 'name', 'description' => 'Unique backend name (also the encryption context for its credentials).'],
    ],
    options: [
        ['name' => '--type', 'description' => "'minio' or 'storage' (default: minio)."],
        ['name' => '--role', 'description' => "'primary' or 'failover' (default: primary)."],
        ['name' => '--priority', 'description' => 'Higher is tried first within the same role (default: 0).'],
        ['name' => '--host', 'description' => 'MinIO endpoint; omit for AWS S3.'],
        ['name' => '--bucket', 'description' => 'Bucket name (minio).'],
        ['name' => '--region', 'description' => 'Region (minio).'],
        ['name' => '--directory', 'description' => "Override root directory (storage backends only)."],
        ['name' => '--username-env', 'description' => 'Name of an env var already holding the access key/username.'],
        ['name' => '--password-env', 'description' => 'Name of an env var already holding the secret/password.'],
    ],
    examples: [
        [
            'command' => 'php vendor/bin/nimble storage:add-backend minio-primary --priority=1000 '
                . '--host=https://minio.local:9000 --bucket=files --username-env=MINIO_USERNAME --password-env=MINIO_PASSWORD',
            'description' => 'Register a MinIO backend, credentials pulled from already-loaded env vars.',
        ],
        [
            'command' => 'php vendor/bin/nimble storage:add-backend s3-backup --priority=500 --bucket=files-backup',
            'description' => 'Register an AWS S3 backend (no --host); prompts for credentials interactively.',
        ],
        [
            'command' => 'php vendor/bin/nimble storage:add-backend local-emergency --type=storage --role=failover --priority=0',
            'description' => 'Register a local-filesystem emergency backend - no credentials needed.',
        ],
    ]
)]
class AddBackendCommand extends AbstractCommand
{

    private const array VALID_TYPES = ['minio', 'storage'];

    private const array VALID_ROLES = ['primary', 'failover'];

    public function handle(): int
    {
        $name = trim((string)$this->argument('name', ''));

        if ($name === '') {
            $this->output()->error('Missing backend name. Usage: storage:add-backend <name> [options]');

            return 1;
        }

        $type = (string)$this->option('type', 'minio');

        if (!in_array($type, self::VALID_TYPES, true)) {
            $this->output()->error('Invalid --type "' . $type . '" - expected one of: ' . implode(', ', self::VALID_TYPES));

            return 1;
        }

        $role = (string)$this->option('role', 'primary');

        if (!in_array($role, self::VALID_ROLES, true)) {
            $this->output()->error('Invalid --role "' . $role . '" - expected one of: ' . implode(', ', self::VALID_ROLES));

            return 1;
        }

        $priority = (int)$this->option('priority', 0);

        $config = array_filter([
            'host' => $this->stringOption('host'),
            'bucket' => $this->stringOption('bucket'),
            'region' => $this->stringOption('region'),
            'directory' => $this->stringOption('directory'),
        ], static fn(?string $value): bool => $value !== null);

        $credentials = $type === 'minio' ? $this->resolveCredentials() : [];

        $backends = ModuleStorageBackendModel::make();

        try {
            $created = $backends->createBackend(
                name: $name,
                type: $type,
                priority: $priority,
                config: $config,
                credentials: $credentials,
                role: $role
            );
        } catch (Throwable $exception) {
            if ($this->isDuplicateName($exception)) {
                $this->output()->error('A backend named "' . $name . '" already exists.');
            } else {
                $this->output()->error('Failed to create backend: ' . $exception->getMessage());
            }

            return 1;
        }

        if (!$created) {
            $this->output()->error('Failed to create backend.');

            return 1;
        }

        $this->output()->success('Backend "' . $name . '" created (id ' . $backends->getId() . ', type ' . $type . ', role ' . $role . ').');

        if ($credentials === [] && $type === 'minio') {
            $this->output()->warning('No credentials were set - this backend has no username/password stored.');
        }

        return 0;
    }

    /**
     * Read the access key/secret from an env var named via --username-env/--password-env,
     * falling back to an interactive, echo-hidden prompt. Never accepted as a plain
     * --username=/--password= value, so a secret can't end up in shell history or `ps`.
     * @return array{username?: string, password?: string}
     */
    private function resolveCredentials(): array
    {
        $username = $this->readSecret('username-env', 'Access key / username');
        $password = $this->readSecret('password-env', 'Secret / password');

        return array_filter(
            ['username' => $username, 'password' => $password],
            static fn(string $value): bool => $value !== ''
        );
    }

    /**
     * @param string $envOption
     * @param string $promptLabel
     * @return string
     */
    private function readSecret(string $envOption, string $promptLabel): string
    {
        $envName = $this->stringOption($envOption);

        if ($envName !== null) {
            $value = $_ENV[$envName] ?? getenv($envName);

            if ($value === false || $value === null) {
                $this->output()->warning('Environment variable "' . $envName . '" is not set - leaving this credential empty.');

                return '';
            }

            return trim((string)$value);
        }

        return Form::secretInput($promptLabel . ':');
    }

    /**
     * @param string $name
     * @return string|null
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param Throwable $exception
     * @return bool
     */
    private function isDuplicateName(Throwable $exception): bool
    {
        $current = $exception;

        while ($current !== null) {
            // DatabaseException/DatabaseManagerException redact getMessage() to a generic
            // "System error"/"Database error" outside DEBUG mode - the real SQL error text
            // (e.g. "UNIQUE constraint failed") only lives behind getHiddenMessage().
            $message = method_exists($current, 'getHiddenMessage')
                ? $current->getHiddenMessage()
                : $current->getMessage();

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

}
