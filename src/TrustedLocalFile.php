<?php

namespace NimblePHP\Storagebox;

/**
 * Local file explicitly admitted from an application-controlled directory.
 */
final class TrustedLocalFile
{

    /** @var array{dev: int, ino: int} */
    private readonly array $identity;

    private function __construct(
        private readonly string $path,
        private readonly string $allowedRoot
    ) {
        $identity = lstat($path);

        if ($identity === false || !isset($identity['dev'], $identity['ino'])) {
            throw new StorageBoxException('Nie udało się odczytać metadanych pliku źródłowego');
        }

        $this->identity = [
            'dev' => $identity['dev'],
            'ino' => $identity['ino'],
        ];
    }

    /**
     * @throws StorageBoxException
     */
    public static function fromPath(string $path, string $allowedRoot): self
    {
        if ($path === '' || $allowedRoot === '' || str_contains($path . $allowedRoot, "\0")) {
            throw new StorageBoxException('Nieprawidłowa ścieżka zaufanego importu');
        }

        if (is_link($allowedRoot) || self::containsSymbolicLink($allowedRoot)) {
            throw new StorageBoxException('Katalog dozwolony nie może być dowiązaniem symbolicznym');
        }

        $resolvedRoot = realpath($allowedRoot);

        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new StorageBoxException('Katalog dozwolony nie istnieje');
        }

        if (self::containsSymbolicLink($path)) {
            throw new StorageBoxException('Ścieżka importu nie może zawierać dowiązań symbolicznych');
        }

        $resolvedPath = realpath($path);

        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new StorageBoxException('Źródło nie jest czytelnym zwykłym plikiem');
        }

        if (!self::isWithinRoot($resolvedPath, $resolvedRoot)) {
            throw new StorageBoxException('Plik źródłowy znajduje się poza dozwolonym katalogiem');
        }

        return new self($resolvedPath, $resolvedRoot);
    }

    /**
     * Revalidates the canonical path and file identity immediately before import.
     *
     * @throws StorageBoxException
     */
    public function getPath(): string
    {
        clearstatcache(true, $this->path);

        $resolvedPath = realpath($this->path);
        $identity = lstat($this->path);

        if (
            $resolvedPath !== $this->path
            || $identity === false
            || is_link($this->path)
            || !is_file($this->path)
            || !is_readable($this->path)
            || !self::isWithinRoot($this->path, $this->allowedRoot)
            || $this->identity['dev'] !== ($identity['dev'] ?? null)
            || $this->identity['ino'] !== ($identity['ino'] ?? null)
        ) {
            throw new StorageBoxException('Zaufany plik źródłowy został zmieniony lub usunięty');
        }

        return $this->path;
    }

    /**
     * Open the validated source and verify that the handle still points to the
     * same inode. The caller is responsible for closing the returned stream.
     *
     * @return resource
     * @throws StorageBoxException
     */
    public function openStream()
    {
        $stream = @fopen($this->getPath(), 'rb');

        if ($stream === false) {
            throw new StorageBoxException('Nie udało się otworzyć zaufanego pliku źródłowego');
        }

        $identity = fstat($stream);

        if (
            $identity === false
            || $this->identity['dev'] !== ($identity['dev'] ?? null)
            || $this->identity['ino'] !== ($identity['ino'] ?? null)
        ) {
            fclose($stream);
            throw new StorageBoxException('Zaufany plik źródłowy został podmieniony przed odczytem');
        }

        return $stream;
    }

    public function getFileName(): string
    {
        return basename($this->path);
    }

    private static function isWithinRoot(string $path, string $root): bool
    {
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $rootPrefix);
    }

    private static function containsSymbolicLink(string $path): bool
    {
        $currentWorkingDirectory = getcwd();

        if ($currentWorkingDirectory === false) {
            return true;
        }

        $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $currentWorkingDirectory . DIRECTORY_SEPARATOR . $path;
        $segments = preg_split('~[\\\\/]+~', $absolutePath, -1, PREG_SPLIT_NO_EMPTY);

        if ($segments === false) {
            return true;
        }

        $currentPath = str_starts_with($absolutePath, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';

        foreach ($segments as $segment) {
            $currentPath = rtrim($currentPath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . $segment;

            if (is_link($currentPath)) {
                return true;
            }
        }

        return false;
    }

}
