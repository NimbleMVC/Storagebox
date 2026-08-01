<?php

namespace NimblePHP\Storagebox\Tests;

use NimblePHP\Storagebox\ModuleStorageFileModel;
use NimblePHP\Storagebox\StorageBoxException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ModuleStorageFileModelSecurityTest extends TestCase
{

    public function testCopyRejectsOrdinaryLocalFileBeforeStorageOrDatabaseAccess(): void
    {
        $model = (new ReflectionClass(ModuleStorageFileModel::class))->newInstanceWithoutConstructor();

        $this->expectException(StorageBoxException::class);
        $this->expectExceptionMessage('nie jest plikiem przesłanym');

        $model->copy('/etc/hosts', fileName: 'hosts.txt');
    }

}
