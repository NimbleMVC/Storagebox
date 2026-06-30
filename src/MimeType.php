<?php

namespace NimblePHP\Storagebox;

/**
 * Resolves MIME content types from file names / extensions.
 */
class MimeType
{

    /**
     * Extension to MIME type map
     * @var array<string, string>
     */
    private const array MAP = [
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tar' => 'application/x-tar',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
    ];

    /**
     * Default MIME type when the extension is unknown.
     * @var string
     */
    public const string DEFAULT = 'application/octet-stream';

    /**
     * Resolve the MIME type from an extension.
     * @param string|null $extension Extension with or without a leading dot, any case.
     * @return string|null The MIME type, or null when the extension is empty/unknown.
     */
    public static function fromExtension(?string $extension): ?string
    {
        if ($extension === null) {
            return null;
        }

        $extension = strtolower(ltrim(trim($extension), '.'));

        if ($extension === '') {
            return null;
        }

        return self::MAP[$extension] ?? null;
    }

    /**
     * Resolve the MIME type from a file name or path.
     * @param string|null $fileName
     * @return string|null The MIME type, or null when it cannot be resolved.
     */
    public static function fromFileName(?string $fileName): ?string
    {
        if ($fileName === null || trim($fileName) === '') {
            return null;
        }

        return self::fromExtension(pathinfo($fileName, PATHINFO_EXTENSION));
    }

}
