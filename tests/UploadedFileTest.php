<?php

namespace NimblePHP\Storagebox\Tests;

use NimblePHP\Storagebox\StorageBoxException;
use NimblePHP\Storagebox\UploadedFile;
use PHPUnit\Framework\TestCase;

class UploadedFileTest extends TestCase
{

    public function testRejectsUploadErrorBeforeUsingTemporaryPath(): void
    {
        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('Nie przesłano pliku');

        UploadedFile::fromArray([
            'error' => UPLOAD_ERR_NO_FILE,
            'tmp_name' => '',
            'name' => 'example.txt',
        ]);
    }

    public function testRejectsOrdinaryLocalFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'storagebox-upload-test-');

        if ($path === false) {
            $this->fail('Nie udało się utworzyć pliku tymczasowego');
        }

        try {
            $this->expectException(StorageBoxException::class);
            $this->expectExceptionMessage('nie jest plikiem przesłanym');

            UploadedFile::fromArray([
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $path,
                'name' => 'example.txt',
            ]);
        } finally {
            unlink($path);
        }
    }

    public function testCompatibilityFactoryRejectsSensitiveLocalPath(): void
    {
        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('nie jest plikiem przesłanym');

        UploadedFile::fromPath('/etc/hosts', 'hosts.txt');
    }

    public function testRejectsMalformedUploadStatus(): void
    {
        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('Nieprawidłowy status');

        UploadedFile::fromArray([
            'error' => '0',
            'tmp_name' => '/tmp/example',
        ]);
    }

}
