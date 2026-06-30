<?php

use krzysztofzylka\DatabaseManager\Columns\BoolColumn;
use krzysztofzylka\DatabaseManager\Columns\DateCreatedColumn;
use krzysztofzylka\DatabaseManager\Columns\DatetimeColumn;
use krzysztofzylka\DatabaseManager\Columns\DateModifyColumn;
use krzysztofzylka\DatabaseManager\Columns\IdColumn;
use krzysztofzylka\DatabaseManager\Columns\IntColumn;
use krzysztofzylka\DatabaseManager\Columns\VarcharColumn;
use krzysztofzylka\DatabaseManager\CreateTable;
use NimblePHP\Migrations\AbstractMigration;

return new class extends AbstractMigration {

    public function run(): void
    {
        $table = new CreateTable('module_storage_file');
        $table->addColumn(new IdColumn);
        $table->addColumn(new VarcharColumn('type', 100));
        $table->addColumn(new VarcharColumn('file_name', 255));
        $table->addColumn(new VarcharColumn('file_extension', 50));
        $table->addColumn((new VarcharColumn('hash', 255))->setNull(false));
        $table->addColumn((new VarcharColumn('provider', 20, false))->setDefault('storage'));
        $table->addColumn(new VarcharColumn('storage_path', 1000));
        $table->addColumn((new IntColumn('size', false, true))->setDefault(0));
        $table->addColumn(new BoolColumn('public', true));
        $table->addColumn(new BoolColumn('auto_delete', false));
        $table->addColumn((new IntColumn('download_count', false, true))->setDefault(0));
        $table->addColumn(new DatetimeColumn('date_auto_delete'));
        $table->addColumn(new DateCreatedColumn);
        $table->addColumn(new DateModifyColumn);
        $table->execute();

        $this->query("
            ALTER TABLE `module_storage_file`
            ADD UNIQUE KEY `module_storage_file_hash` (`hash`)
        ");
    }

};
