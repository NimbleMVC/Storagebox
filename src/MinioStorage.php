<?php

namespace NimblePHP\Storagebox;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Exception;
use NimblePHP\Framework\Exception\NimbleException;
use NimblePHP\Framework\Log;
use NimblePHP\Framework\Storage;

class MinioStorage extends Storage
{

    private static array $resolvedAwsRegions = [];

    private S3Client $s3Client;

    private string $bucket;

    private string $directory;

    private bool $isAws = false;

    /**
     * @param string $directory
     * @param bool $securePath
     * @throws NimbleException
     */
    public function __construct(string $directory, bool $securePath = true)
    {
        $this->isAws = $this->getEnvValue('MINIO_HOST') === '';
        $this->directory = trim($directory, '/');
        $this->initializeMinioClient();

        parent::__construct($directory, $securePath);
    }

    /**
     * @return void
     */
    private function initializeMinioClient(): void
    {
        $this->bucket = $this->getEnvValue('MINIO_BUCKET');
        $configuredRegion = $this->getEnvValue('MINIO_REGION');
        $this->s3Client = new S3Client($this->buildClientConfig($configuredRegion));

        if ($this->isAws) {
            $this->synchronizeAwsRegion($configuredRegion);
            return;
        }

        if (!$this->isAws) {
            try {
                if (!$this->s3Client->doesBucketExistV2($this->bucket, true)) {
                    $this->s3Client->createBucket(['Bucket' => $this->bucket]);
                }
            } catch (Exception $e) {
                Log::log('MinioStorage init', 'WARNING', [
                    'message' => 'Bucket check skipped',
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * @param string $configuredRegion
     * @return array<string, mixed>
     */
    private function buildClientConfig(string $configuredRegion): array
    {
        $region = $configuredRegion !== '' ? $configuredRegion : 'us-east-1';
        $config = [
            'version' => 'latest',
            'region' => $region,
            'signature_version' => 'v4',
            'credentials' => [
                'key' => $this->getEnvValue('MINIO_USERNAME'),
                'secret' => $this->getEnvValue('MINIO_PASSWORD'),
            ],
        ];

        if (!$this->isAws) {
            $config['endpoint'] = rtrim($this->getEnvValue('MINIO_HOST'), '/');
            $config['use_path_style_endpoint'] = true;
        }

        return $config;
    }

    /**
     * @param string $configuredRegion
     * @return void
     */
    private function synchronizeAwsRegion(string $configuredRegion): void
    {
        $cachedRegion = self::$resolvedAwsRegions[$this->bucket] ?? null;
        if ($cachedRegion !== null) {
            if ($cachedRegion !== '' && $cachedRegion !== $configuredRegion) {
                $this->s3Client = new S3Client($this->buildClientConfig($cachedRegion));
            }

            return;
        }

        try {
            $resolvedRegion = trim((string)$this->s3Client->determineBucketRegion($this->bucket));
            self::$resolvedAwsRegions[$this->bucket] = $resolvedRegion;

            if ($resolvedRegion !== '' && $resolvedRegion !== $configuredRegion) {
                Log::log('MinioStorage init', 'INFO', [
                    'message' => 'AWS bucket region auto-detected',
                    'bucket' => $this->bucket,
                    'configuredRegion' => $configuredRegion !== '' ? $configuredRegion : 'us-east-1',
                    'resolvedRegion' => $resolvedRegion
                ]);

                $this->s3Client = new S3Client($this->buildClientConfig($resolvedRegion));
            }
        } catch (Exception $e) {
            Log::log('MinioStorage init', 'WARNING', [
                'message' => 'AWS bucket region detection skipped',
                'bucket' => $this->bucket,
                'region' => $configuredRegion !== '' ? $configuredRegion : 'us-east-1',
                'exception' => $e->getMessage()
            ]);
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private function getEnvValue(string $key): string
    {
        $value = trim((string)($_ENV[$key] ?? ''));

        return trim($value, "\"'");
    }

    /**
     * Build the full path including the directory prefix
     * @param string $filePath
     * @return string
     */
    private function buildFullPath(string $filePath): string
    {
        if (empty($this->directory)) {
            return $filePath;
        }

        return $this->directory . '/' . ltrim($filePath, '/');
    }

    /**
     * @param string $filePath
     * @return string|null
     */
    public function get(string $filePath): ?string
    {
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->buildFullPath($filePath),
            ]);

            return $result['Body']->getContents();
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                Log::log('MinioStorage get', 'ERROR', ['exception' => $e->getMessage()]);
            }

            return null;
        }
    }

    /**
     * Copy a PHP HTTP upload to MinIO/S3.
     *
     * This compatibility method deliberately does not interpret a missing local
     * path as an object key. Bucket object keys are not accepted by this API.
     *
     * @param string $sourcePath
     * @param string $destinationPath
     * @return bool
     */
    public function copy(string $sourcePath, string $destinationPath, ?string $contentType = null): bool
    {
        try {
            return $this->copyLocalFile(
                UploadedFile::fromPath($sourcePath),
                $destinationPath,
                $contentType
            );
        } catch (StorageBoxException $exception) {
            Log::log('MinioStorage copy', 'WARNING', ['exception' => $exception->getMessage()]);
            return false;
        }
    }

    /**
     * Upload a validated local source to MinIO/S3.
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
    ): bool {
        $stream = $source->openStream();

        try {
            return $this->uploadFromStream($stream, $destinationPath, $contentType);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Upload a validated stream to MinIO
     * @param resource $stream
     * @param string $destinationPath
     * @param string|null $contentType
     * @return bool
     */
    private function uploadFromStream($stream, string $destinationPath, ?string $contentType = null): bool
    {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $this->buildFullPath($destinationPath),
                'Body' => $stream,
            ];

            if ($contentType !== null) {
                $params['ContentType'] = $contentType;
            }

            $this->s3Client->putObject($params);

            return true;
        } catch (S3Exception $e) {
            Log::log('MinioStorage uploadFromStream', 'ERROR', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param string $filePath
     * @param string $content
     * @param string|null $contentType
     * @return true
     * @throws Exception
     */
    public function put(string $filePath, string $content, ?string $contentType = null): true
    {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $this->buildFullPath($filePath),
                'Body' => $content,
            ];

            if ($contentType !== null) {
                $params['ContentType'] = $contentType;
            }

            $this->s3Client->putObject($params);

            return true;
        } catch (S3Exception $e) {
            Log::log('MinioStorage put', 'ERROR', ['exception' => $e->getMessage()]);

            throw new Exception('Nie udało się zapisać pliku do MinIO: ' . $e->getMessage());
        }
    }

    /**
     * @param string $filePath
     * @return bool
     */
    public function delete(string $filePath): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->buildFullPath($filePath),
            ]);

            return true;
        } catch (S3Exception $e) {
            Log::log('MinioStorage delete', 'ERROR', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param string $filePath
     * @param string $content
     * @param string $append
     * @return true
     * @throws Exception
     */
    public function append(string $filePath, string $content, string $append = PHP_EOL): true
    {
        $existingContent = $this->get($filePath) ?? '';
        $newContent = $existingContent . $append . $content;

        return $this->put($filePath, $newContent);
    }

    /**
     * @param string $filePath
     * @return bool
     */
    public function exists(string $filePath): bool
    {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->buildFullPath($filePath),
            ]);

            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                Log::log('MinioStorage exists', 'ERROR', ['exception' => $e->getMessage()]);
            }

            return false;
        }
    }

    /**
     * @param string $filePath
     * @return string
     */
    public function getFullPath(string $filePath): string
    {
        return $this->s3Client->getObjectUrl($this->bucket, $this->buildFullPath($filePath));
    }

    /**
     * Generate a temporary pre-signed URL for downloading a (private) object.
     * @param string $filePath
     * @param string $expires Lifetime accepted by S3Client::createPresignedRequest, e.g. "+20 minutes".
     * @return string
     */
    public function getPresignedUrl(string $filePath, string $expires = '+20 minutes'): string
    {
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->buildFullPath($filePath),
        ]);

        return (string)$this->s3Client->createPresignedRequest($command, $expires)->getUri();
    }

    /**
     * @param string $sourcePath
     * @param string $destinationPath
     * @return bool
     */
    public function move(string $sourcePath, string $destinationPath): bool
    {
        if (!$this->copy($sourcePath, $destinationPath)) {
            return false;
        }

        return unlink($sourcePath);
    }

    /**
     * @param string $filePath
     * @return array|null
     */
    public function getMetadata(string $filePath): ?array
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->buildFullPath($filePath),
            ]);

            return [
                'size' => $result['ContentLength'] ?? 0,
                'last_modified' => $result['LastModified'] ?? null,
                'content_type' => $result['ContentType'] ?? null,
                'etag' => $result['ETag'] ?? null,
            ];
        } catch (S3Exception $e) {
            Log::log('MinioStorage getMetadata', 'ERROR', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Return the directory name used by this instance
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

}
