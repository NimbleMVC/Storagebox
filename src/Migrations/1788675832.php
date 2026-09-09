<?php

use NimblePHP\Migrations\AbstractMigration;

return new class extends AbstractMigration {

    public function run(): void
    {
        // module_storage_backend.status/type: varchar -> enum, and a new "role"
        // column distinguishing normally-mirrored backends from an emergency-only
        // "failover" backend that is never proactively mirrored to - it only
        // receives a write when every "primary" backend is unavailable, and its
        // content is drained back onto a primary once one recovers (drainCron()).
        $this->query("
            ALTER TABLE `module_storage_backend`
            MODIFY `status` ENUM('up','down') NOT NULL DEFAULT 'up'
        ");

        $this->query("
            ALTER TABLE `module_storage_backend`
            MODIFY `type` ENUM('minio','storage') NOT NULL DEFAULT 'minio'
        ");

        $this->query("
            ALTER TABLE `module_storage_backend`
            ADD COLUMN `role` ENUM('primary','failover') NOT NULL DEFAULT 'primary' AFTER `priority`
        ");

        // module_storage_file_mirror.status: varchar -> enum
        $this->query("
            ALTER TABLE `module_storage_file_mirror`
            MODIFY `status` ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending'
        ");
    }

};
