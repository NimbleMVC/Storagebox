<?php

namespace NimblePHP\Storagebox;

/**
 * Storage backends that cannot be written to via a plain local filesystem path
 * (getFullPath() does not return an fopen()-able path) and instead require
 * validated sources to be streamed in directly.
 */
interface StreamableStorageInterface
{

    /**
     * Upload a validated local source (PHP HTTP upload or trusted local file).
     *
     * @param UploadedFile|TrustedLocalFile $source
     * @param string $destinationPath
     * @param string|null $contentType
     * @return bool
     * @throws StorageBoxException
     */
    public function copyLocalFile(
        UploadedFile|TrustedLocalFile $source,
        string $destinationPath,
        ?string $contentType = null
    ): bool;

}
