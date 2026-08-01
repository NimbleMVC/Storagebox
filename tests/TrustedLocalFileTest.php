<?php

namespace NimblePHP\Storagebox\Tests;

use NimblePHP\Storagebox\StorageBoxException;
use NimblePHP\Storagebox\TrustedLocalFile;
use PHPUnit\Framework\TestCase;

class TrustedLocalFileTest extends TestCase
{

    private string $temporaryDirectory;

    private string $allowedRoot;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'storagebox-trusted-file-'
            . bin2hex(random_bytes(8));
        $this->allowedRoot = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'allowed';

        mkdir($this->allowedRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $paths = [
            $this->allowedRoot . DIRECTORY_SEPARATOR . 'link.txt',
            $this->allowedRoot . DIRECTORY_SEPARATOR . 'replacement.txt',
            $this->allowedRoot . DIRECTORY_SEPARATOR . 'replacement-original.txt',
            $this->allowedRoot . DIRECTORY_SEPARATOR . 'source.txt',
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'outside.txt',
        ];

        foreach ($paths as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->allowedRoot)) {
            rmdir($this->allowedRoot);
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    public function testAcceptsReadableRegularFileInsideAllowedRoot(): void
    {
        $path = $this->allowedRoot . DIRECTORY_SEPARATOR . 'source.txt';
        file_put_contents($path, 'safe');

        $source = TrustedLocalFile::fromPath($path, $this->allowedRoot);
        $stream = $source->openStream();

        try {
            $this->assertSame('safe', stream_get_contents($stream));
            $this->assertSame(realpath($path), $source->getPath());
            $this->assertSame('source.txt', $source->getFileName());
        } finally {
            fclose($stream);
        }
    }

    public function testRejectsFileOutsideAllowedRoot(): void
    {
        $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'outside.txt';
        file_put_contents($path, 'secret');

        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('poza dozwolonym katalogiem');

        TrustedLocalFile::fromPath($path, $this->allowedRoot);
    }

    public function testRejectsPathThroughSymbolicLink(): void
    {
        $outsidePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'outside.txt';
        $linkPath = $this->allowedRoot . DIRECTORY_SEPARATOR . 'link.txt';
        file_put_contents($outsidePath, 'secret');

        if (!symlink($outsidePath, $linkPath)) {
            $this->markTestSkipped('Nie udało się utworzyć dowiązania symbolicznego');
        }

        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('dowiązań symbolicznych');

        TrustedLocalFile::fromPath($linkPath, $this->allowedRoot);
    }

    public function testRejectsDirectoryAsSource(): void
    {
        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('zwykłym plikiem');

        TrustedLocalFile::fromPath($this->allowedRoot, $this->allowedRoot);
    }

    public function testDetectsFileReplacementBeforeImport(): void
    {
        $path = $this->allowedRoot . DIRECTORY_SEPARATOR . 'replacement.txt';
        $originalPath = $this->allowedRoot . DIRECTORY_SEPARATOR . 'replacement-original.txt';
        file_put_contents($path, 'first');
        $source = TrustedLocalFile::fromPath($path, $this->allowedRoot);

        rename($path, $originalPath);
        file_put_contents($path, 'second');

        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('został zmieniony');

        $source->getPath();
    }

}
