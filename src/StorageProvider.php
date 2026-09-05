<?php

namespace NimblePHP\Storagebox;

enum StorageProvider: string
{

    case storage = 'storage';

    case minio = 'minio';

    case mirrored = 'mirrored';

    /**
     * Get the provider by key
     * @param string|null $key
     * @return self
     */
    public static function getByKey(?string $key): self
    {
        return self::tryFrom((string)$key) ?? self::storage;
    }

}
