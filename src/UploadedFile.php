<?php

namespace NimblePHP\Storagebox;

/**
 * Validated PHP upload. Instances can only be created for files accepted by PHP's
 * HTTP upload mechanism.
 */
final class UploadedFile
{

    /** @var array{dev: int, ino: int} */
    private readonly array $identity;

    private function __construct(
        private readonly string $path,
        private readonly ?string $clientFileName
    ) {
        $identity = lstat($path);

        if ($identity === false || !isset($identity['dev'], $identity['ino'])) {
            throw new StorageBoxException('Nie udało się odczytać metadanych przesłanego pliku');
        }

        $this->identity = [
            'dev' => $identity['dev'],
            'ino' => $identity['ino'],
        ];
    }

    /**
     * @param array{error: int, tmp_name: string, name?: string|null} $file
     * @throws StorageBoxException
     */
    public static function fromArray(array $file): self
    {
        $error = $file['error'] ?? null;

        if (!is_int($error)) {
            throw new StorageBoxException('Nieprawidłowy status przesłanego pliku');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new StorageBoxException(self::uploadErrorMessage($error));
        }

        $path = $file['tmp_name'] ?? null;

        if (!is_string($path)) {
            throw new StorageBoxException('Brak prawidłowej ścieżki pliku tymczasowego');
        }

        $clientFileName = $file['name'] ?? null;

        if ($clientFileName !== null && !is_string($clientFileName)) {
            throw new StorageBoxException('Nieprawidłowa nazwa przesłanego pliku');
        }

        return self::fromPath($path, $clientFileName);
    }

    /**
     * Compatibility factory for callers that only have the temporary upload path.
     *
     * @throws StorageBoxException
     */
    public static function fromPath(string $path, ?string $clientFileName = null): self
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new StorageBoxException('Nieprawidłowa ścieżka przesłanego pliku');
        }

        if (!is_uploaded_file($path)) {
            throw new StorageBoxException('Źródło nie jest plikiem przesłanym przez PHP HTTP upload');
        }

        $resolvedPath = realpath($path);

        if ($resolvedPath === false || !self::isInsideUploadDirectory($resolvedPath)) {
            throw new StorageBoxException('Plik znajduje się poza katalogiem uploadów PHP');
        }

        if (is_link($path) || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new StorageBoxException('Przesłane źródło nie jest czytelnym zwykłym plikiem');
        }

        return new self($resolvedPath, $clientFileName);
    }

    /**
     * Revalidates the upload immediately before it is handed to a storage provider.
     *
     * @throws StorageBoxException
     */
    public function getPath(): string
    {
        clearstatcache(true, $this->path);
        $identity = lstat($this->path);

        if (
            $identity === false
            || !is_uploaded_file($this->path)
            || is_link($this->path)
            || !is_file($this->path)
            || !is_readable($this->path)
            || $this->identity['dev'] !== ($identity['dev'] ?? null)
            || $this->identity['ino'] !== ($identity['ino'] ?? null)
        ) {
            throw new StorageBoxException('Przesłany plik nie jest już dostępny lub zmienił typ');
        }

        return $this->path;
    }

    /**
     * Open the validated upload and verify that the handle still points to the
     * same inode. The caller is responsible for closing the returned stream.
     *
     * @return resource
     * @throws StorageBoxException
     */
    public function openStream()
    {
        $stream = @fopen($this->getPath(), 'rb');

        if ($stream === false) {
            throw new StorageBoxException('Nie udało się otworzyć przesłanego pliku');
        }

        $identity = fstat($stream);

        if (
            $identity === false
            || $this->identity['dev'] !== ($identity['dev'] ?? null)
            || $this->identity['ino'] !== ($identity['ino'] ?? null)
        ) {
            fclose($stream);
            throw new StorageBoxException('Przesłany plik został podmieniony przed odczytem');
        }

        return $stream;
    }

    public function getClientFileName(): ?string
    {
        return $this->clientFileName;
    }

    private static function isInsideUploadDirectory(string $path): bool
    {
        $configuredDirectory = trim((string)ini_get('upload_tmp_dir'));
        $uploadDirectory = $configuredDirectory !== '' ? $configuredDirectory : sys_get_temp_dir();
        $resolvedDirectory = realpath($uploadDirectory);

        if ($resolvedDirectory === false || !is_dir($resolvedDirectory)) {
            return false;
        }

        $directoryPrefix = rtrim($resolvedDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $directoryPrefix);
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Przesłany plik przekracza dozwolony rozmiar',
            UPLOAD_ERR_PARTIAL => 'Plik został przesłany tylko częściowo',
            UPLOAD_ERR_NO_FILE => 'Nie przesłano pliku',
            UPLOAD_ERR_NO_TMP_DIR => 'Brak katalogu tymczasowego dla uploadu',
            UPLOAD_ERR_CANT_WRITE => 'Nie udało się zapisać przesłanego pliku',
            UPLOAD_ERR_EXTENSION => 'Upload pliku został zatrzymany przez rozszerzenie PHP',
            default => 'Nieznany błąd uploadu pliku',
        };
    }

}
