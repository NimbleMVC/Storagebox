<?php

namespace NimblePHP\Storagebox\Tests;

use NimblePHP\Storagebox\StorageProvider;
use PHPUnit\Framework\TestCase;

class StorageProviderTest extends TestCase
{

    public function testGetByKeyResolvesKnownProviders(): void
    {
        $this->assertSame(StorageProvider::storage, StorageProvider::getByKey('storage'));
        $this->assertSame(StorageProvider::minio, StorageProvider::getByKey('minio'));
        $this->assertSame(StorageProvider::mirrored, StorageProvider::getByKey('mirrored'));
    }

    public function testGetByKeyFallsBackToStorage(): void
    {
        $this->assertSame(StorageProvider::storage, StorageProvider::getByKey(null));
        $this->assertSame(StorageProvider::storage, StorageProvider::getByKey(''));
        $this->assertSame(StorageProvider::storage, StorageProvider::getByKey('unknown'));
        $this->assertSame(StorageProvider::storage, StorageProvider::getByKey('MINIO'));
    }

    public function testEnumValues(): void
    {
        $this->assertSame('storage', StorageProvider::storage->value);
        $this->assertSame('minio', StorageProvider::minio->value);
        $this->assertSame('mirrored', StorageProvider::mirrored->value);
    }

}
