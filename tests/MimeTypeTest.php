<?php

namespace NimblePHP\Storagebox\Tests;

use NimblePHP\Storagebox\MimeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MimeTypeTest extends TestCase
{

    public function testFromExtensionResolvesKnownTypes(): void
    {
        $this->assertSame('image/jpeg', MimeType::fromExtension('jpg'));
        $this->assertSame('image/jpeg', MimeType::fromExtension('jpeg'));
        $this->assertSame('application/pdf', MimeType::fromExtension('pdf'));
        $this->assertSame('application/json', MimeType::fromExtension('json'));
    }

    public function testFromExtensionIsCaseInsensitiveAndTrimsDot(): void
    {
        $this->assertSame('image/png', MimeType::fromExtension('PNG'));
        $this->assertSame('image/png', MimeType::fromExtension('.png'));
        $this->assertSame('image/png', MimeType::fromExtension('  .PnG '));
    }

    #[DataProvider('unresolvableExtensions')]
    public function testFromExtensionReturnsNullWhenUnresolvable(?string $extension): void
    {
        $this->assertNull(MimeType::fromExtension($extension));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unresolvableExtensions(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'unknown' => ['xyz'],
        ];
    }

    public function testFromFileNameUsesExtension(): void
    {
        $this->assertSame('image/jpeg', MimeType::fromFileName('photo.JPG'));
        $this->assertSame('application/pdf', MimeType::fromFileName('/tmp/path/to/report.pdf'));
        $this->assertSame('text/plain', MimeType::fromFileName('archive.tar.txt'));
    }

    public function testFromFileNameReturnsNullWhenNoUsableExtension(): void
    {
        $this->assertNull(MimeType::fromFileName(null));
        $this->assertNull(MimeType::fromFileName(''));
        $this->assertNull(MimeType::fromFileName('README'));
        $this->assertNull(MimeType::fromFileName('archive.unknownext'));
    }

    public function testDefaultConstant(): void
    {
        $this->assertSame('application/octet-stream', MimeType::DEFAULT);
    }

}
