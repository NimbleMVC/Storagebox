<?php

namespace NimblePHP\Storagebox\Tests;

use NimblePHP\Storagebox\MinioStorage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MinioStorageSecurityTest extends TestCase
{

    public function testCopyDoesNotTreatArbitraryStringAsBucketObjectKey(): void
    {
        $storage = (new ReflectionClass(MinioStorage::class))->newInstanceWithoutConstructor();

        $this->assertFalse($storage->copy('unregistered/object-key', 'destination'));
    }

}
