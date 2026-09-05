<?php

use krzysztofzylka\DatabaseManager\Columns\BoolColumn;
use krzysztofzylka\DatabaseManager\Columns\DateCreatedColumn;
use krzysztofzylka\DatabaseManager\Columns\DatetimeColumn;
use krzysztofzylka\DatabaseManager\Columns\DateModifyColumn;
use krzysztofzylka\DatabaseManager\Columns\IdColumn;
use krzysztofzylka\DatabaseManager\Columns\IntColumn;
use krzysztofzylka\DatabaseManager\Columns\TextColumn;
use krzysztofzylka\DatabaseManager\Columns\VarcharColumn;
use krzysztofzylka\DatabaseManager\CreateTable;
use NimblePHP\Migrations\AbstractMigration;

return new class extends AbstractMigration {

    public function run(): void
    {
        $backendTable = new CreateTable('module_storage_backend');
        $backendTable->addColumn(new IdColumn);
        $backendTable->addColumn((new VarcharColumn('name', 100))->setNull(false));
        $backendTable->addColumn((new VarcharColumn('type', 20, false))->setDefault('minio'));
        $backendTable->addColumn((new IntColumn('priority', false))->setDefault(0));
        $backendTable->addColumn((new VarcharColumn('status', 10, false))->setDefault('up'));
        $backendTable->addColumn(new DatetimeColumn('last_checked_at'));
        $backendTable->addColumn(new TextColumn('config'));
        $backendTable->addColumn(new TextColumn('credentials'));
        $backendTable->addColumn(new BoolColumn('enabled', true));
        $backendTable->addColumn(new DateCreatedColumn);
        $backendTable->addColumn(new DateModifyColumn);
        $backendTable->execute();

        $this->query("
            ALTER TABLE `module_storage_backend`
            ADD UNIQUE KEY `module_storage_backend_name` (`name`)
        ");

        $mirrorTable = new CreateTable('module_storage_file_mirror');
        $mirrorTable->addColumn(new IdColumn);
        $mirrorTable->addColumn((new VarcharColumn('file_hash', 255))->setNull(false));
        $mirrorTable->addColumn((new VarcharColumn('directory', 255, false))->setDefault('storage_files'));
        $mirrorTable->addColumn((new IntColumn('backend_id', false, true)));
        $mirrorTable->addColumn((new VarcharColumn('status', 10, false))->setDefault('pending'));
        $mirrorTable->addColumn(new DatetimeColumn('last_attempt_at'));
        $mirrorTable->addColumn(new TextColumn('last_error'));
        $mirrorTable->addColumn(new DateCreatedColumn);
        $mirrorTable->addColumn(new DateModifyColumn);
        $mirrorTable->execute();

        $this->query("
            ALTER TABLE `module_storage_file_mirror`
            ADD UNIQUE KEY `module_storage_file_mirror_hash_backend` (`file_hash`, `backend_id`)
        ");

        $this->query("
            ALTER TABLE `module_storage_file_mirror`
            ADD KEY `module_storage_file_mirror_status` (`status`)
        ");
    }

};
