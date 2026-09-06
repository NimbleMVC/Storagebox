<?php

namespace NimblePHP\Storagebox\Tests;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Event\EventDispatcher;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Storagebox\Event\AfterFileWriteEvent;
use NimblePHP\Storagebox\ModuleStorageFileModel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * STB-H04/STB-H05 regression tests: the file (storage) and its metadata record
 * (module_storage_file) must not diverge when a write listener fails or when
 * removing the storage object fails.
 */
class ModuleStorageFileModelConsistencyTest extends TestCase
{

    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/storagebox-test-' . uniqid('', true);
        mkdir($this->projectPath, 0777, true);

        Kernel::$projectPath = $this->projectPath;
        Kernel::$middlewareManager = new MiddlewareManager();
        Kernel::$eventDispatcher = new EventDispatcher();

        Config::set('DATABASE', true);
        Config::set('LOG', false);

        $connect = DatabaseConnect::create()
            ->setType(DatabaseType::sqlite)
            ->setSqlitePath(':memory:');

        (new DatabaseManager())->connect($connect);

        DatabaseManager::$connection->getConnection()->exec(
            <<<SQL
            CREATE TABLE module_storage_file (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type VARCHAR(100),
                file_name VARCHAR(255),
                file_extension VARCHAR(50),
                hash VARCHAR(255) NOT NULL UNIQUE,
                provider VARCHAR(20) DEFAULT 'storage',
                storage_path VARCHAR(1000),
                size INTEGER DEFAULT 0,
                public INTEGER DEFAULT 1,
                auto_delete INTEGER DEFAULT 0,
                download_count INTEGER DEFAULT 0,
                date_auto_delete DATETIME,
                date_created DATETIME,
                date_modify DATETIME
            )
            SQL
        );
    }

    protected function tearDown(): void
    {
        Kernel::$eventDispatcher = new EventDispatcher();

        $this->removeDirectory($this->projectPath);
    }

    public function testWriteKeepsFileAndRecordConsistentWhenAfterFileWriteEventListenerThrows(): void
    {
        Kernel::getEventDispatcher()->addListener(AfterFileWriteEvent::class, function (): void {
            throw new RuntimeException('thumbnail generation failed');
        });

        $model = $this->createModel();

        $id = $model->write('hello world', 'text', 'hello.txt');

        $this->assertIsInt($id);

        $info = $model->getInfo($id);
        $this->assertNotNull($info, 'record must exist after write()');

        $freshModel = $this->createModel();
        $this->assertSame('hello world', $freshModel->getFileContent($id), 'stored object must still be readable');
    }

    public function testWriteWithoutListenerFailureStillSucceeds(): void
    {
        $model = $this->createModel();

        $id = $model->write('plain content', 'text', 'plain.txt');

        $this->assertIsInt($id);
        $this->assertSame('plain content', $this->createModel()->getFileContent($id));
    }

    public function testDeleteFileKeepsDatabaseRecordWhenStorageDeleteFails(): void
    {
        $model = $this->createModel();
        $id = $model->write('to be orphaned', 'text', 'orphan.txt');
        $this->assertIsInt($id);

        $hash = $model->getInfo($id)['hash'];
        $storedFile = $this->projectPath . '/storage/storage_files/' . $hash;
        $this->assertFileExists($storedFile);

        // Simulate a failed object delete (e.g. permission error / provider outage)
        // by removing the object out from under the model before it tries to delete it.
        unlink($storedFile);

        $deleteModel = $this->createModel();
        $deleteModel->setId($id);

        $result = $deleteModel->deleteFile();

        $this->assertFalse($result, 'deleteFile() must report failure when the object could not be removed');
        $this->assertNotNull($this->createModel()->getInfo($id), 'database record must be kept for retry when the object delete failed');
    }

    public function testDeleteFileRemovesRecordWhenStorageDeleteSucceeds(): void
    {
        $model = $this->createModel();
        $id = $model->write('to be deleted', 'text', 'gone.txt');
        $this->assertIsInt($id);

        $hash = $model->getInfo($id)['hash'];
        $storedFile = $this->projectPath . '/storage/storage_files/' . $hash;
        $this->assertFileExists($storedFile);

        $deleteModel = $this->createModel();
        $deleteModel->setId($id);

        $result = $deleteModel->deleteFile();

        $this->assertTrue($result);
        $this->assertNull($this->createModel()->getInfo($id));
        $this->assertFileDoesNotExist($storedFile);
    }

    public function testDeleteFileOnAlreadyMissingRecordReturnsTrue(): void
    {
        $model = $this->createModel();
        $model->setId(999999);

        $this->assertTrue($model->deleteFile());
    }

    private function createModel(): ModuleStorageFileModel
    {
        $model = new ModuleStorageFileModel();
        $model->useTable = 'module_storage_file';
        $model->prepareTableInstance();

        return $model;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

}
