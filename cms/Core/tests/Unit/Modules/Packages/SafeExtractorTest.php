<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\SafeExtractor;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Valid package files of the "Demo" module.
 *
 * @return array<string, string>
 */
function sxFiles(array $moduleJson = []): array
{
    return [
        'Demo/module.json' => json_encode(array_merge(['name' => 'Demo', 'version' => '2026.03.0'], $moduleJson)),
        'Demo/app/Providers/DemoServiceProvider.php' => "<?php\n\nnamespace Demo;\n",
        'Demo/routes/web.php' => "<?php\n",
    ];
}

/**
 * Create a fresh temp folder (real path).
 */
function sxTempDir(): string
{
    $dir = sys_get_temp_dir() . '/sx-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return realpath($dir);
}

/**
 * Remove a temp tree without following symlinks.
 */
function sxRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
        sxRemove($path . '/' . $entry);
    }
    @chmod($path, 0755);
    @rmdir($path);
}

/**
 * Build a zip with ZipArchive; $tweak may change attributes before closing.
 *
 * @param array<string, string> $entries name => content
 */
function sxZip(string $path, array $entries, ?callable $tweak = null): string
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString((string) $name, $content);
    }
    if ($tweak !== null) {
        $tweak($zip);
    }
    $zip->close();

    return $path;
}

/**
 * Write a stored (uncompressed) zip by hand, for names ZipArchive cannot produce (empty, NUL).
 *
 * @param list<array{0: string, 1: string}> $entries [name, content]
 */
function sxRawZip(string $path, array $entries): string
{
    $local = '';
    $central = '';
    foreach ($entries as [$name, $content]) {
        $crc = crc32($content);
        $len = strlen($content);
        $offset = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0) . $name . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, 0, 0, 0, 0x21, $crc, $len, $len,
            strlen($name), 0, 0, 0, 0, 0100644 << 16, $offset) . $name;
    }
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $eocd);

    return $path;
}

/**
 * Write a deflated zip by hand whose headers declare $declared bytes while the stream inflates to the full content.
 *
 * @param list<array{0: string, 1: string, 2: int}> $entries [name, real content, declared uncompressed size]
 */
function sxLyingZip(string $path, array $entries): string
{
    $local = '';
    $central = '';
    foreach ($entries as [$name, $content, $declared]) {
        $crc = crc32($content);
        $comp = gzdeflate($content);
        $offset = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0x21, $crc, strlen($comp), $declared, strlen($name), 0) . $name . $comp;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, 0, 8, 0, 0x21, $crc, strlen($comp), $declared,
            strlen($name), 0, 0, 0, 0, 0100644 << 16, $offset) . $name;
    }
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $eocd);

    return $path;
}

/**
 * Build a manifest binding the zip at $zipPath and listing $files (path => content).
 *
 * @param array<string, string> $files
 */
function sxManifest(string $zipPath, array $files, array $overrides = []): ReleaseManifest
{
    $hashes = [];
    foreach ($files as $path => $content) {
        $hashes[$path] = hash('sha256', $content);
    }

    return ReleaseManifest::fromVerifiedBytes(json_encode(array_merge([
        'format' => 1,
        'name' => 'Demo',
        'version' => '2026.03.0',
        'zip_sha256' => hash_file('sha256', $zipPath),
        'zip_size' => filesize($zipPath),
        'unpacked_size' => array_sum(array_map('strlen', $files)),
        'files' => $hashes,
        'requires' => [],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => str_repeat('a', 16),
    ], $overrides), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Run the extractor and return the PackageVerificationException error code (or null on success).
 */
function sxExtractCode(string $zip, ReleaseManifest $manifest, string $staging, array $limits = []): ?string
{
    try {
        (new SafeExtractor($limits))->extract($zip, $manifest, $staging);
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

/**
 * Entries left in a folder.
 *
 * @return list<string>
 */
function sxEntries(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

beforeEach(function (): void {
    $this->tmp = sxTempDir();
    $this->staging = $this->tmp . '/staging';
    mkdir($this->staging);
    $this->zipPath = $this->tmp . '/pkg.zip';
});

afterEach(function (): void {
    sxRemove($this->tmp);
});

it('extracts a verified package with 0644 files and 0755 folders', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, ['Demo/' => '', 'Demo/app/' => ''] + $files, function (ZipArchive $zip): void {
        $zip->setExternalAttributesName('Demo/', ZipArchive::OPSYS_UNIX, 0040755 << 16);
        $zip->setExternalAttributesName('Demo/app/', ZipArchive::OPSYS_UNIX, 0040755 << 16);
    });
    // addFromString('Demo/') stores a zero-byte entry; that is a valid folder entry.
    $manifest = sxManifest($this->zipPath, $files);

    $dir = (new SafeExtractor())->extract($this->zipPath, $manifest, $this->staging);

    expect($dir)->toBe($this->staging . '/Demo')
        ->and(sxEntries($this->staging))->toBe(['Demo']);
    foreach ($files as $path => $content) {
        $full = $this->staging . '/' . $path;
        expect(file_get_contents($full))->toBe($content)
            ->and(fileperms($full) & 0777)->toBe(0644);
    }
    foreach (['Demo', 'Demo/app', 'Demo/app/Providers', 'Demo/routes'] as $folder) {
        expect(fileperms($this->staging . '/' . $folder) & 0777)->toBe(0755);
    }
});

it('refuses an unsafe entry path', function (string $bad): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files + [$bad => 'x']);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ENTRY_PATH)
        ->and(sxEntries($this->staging))->toBe([]);
})->with([
    'backslash' => ['Demo\\evil.php'],
    'leading slash' => ['/Demo/evil.php'],
    'drive letter' => ['C:/Demo/evil.php'],
    'dot segment' => ['Demo/./evil.php'],
    'dot-dot segment' => ['Demo/../evil.php'],
    'empty segment' => ['Demo//evil.php'],
    'outside the module folder' => ['Other/evil.php'],
    'bare file at the root' => ['evil.php'],
    'more than 20 levels' => ['Demo/' . str_repeat('d/', 19) . 'evil.php'],
]);

it('refuses an entry with an empty name', function (): void {
    $files = sxFiles();
    $entries = [];
    foreach ($files as $name => $content) {
        $entries[] = [$name, $content];
    }
    $entries[] = ['', 'x'];
    sxRawZip($this->zipPath, $entries);
    $manifest = sxManifest($this->zipPath, $files);

    // libzip may already refuse the archive on open; either way nothing is written.
    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBeIn([SafeExtractor::ENTRY_PATH, SafeExtractor::OPEN])
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses an entry name with NUL', function (): void {
    $files = sxFiles();
    $entries = [];
    foreach ($files as $name => $content) {
        $entries[] = [$name, $content];
    }
    $entries[] = ["Demo/evil\0.php", 'x'];
    sxRawZip($this->zipPath, $entries);
    $manifest = sxManifest($this->zipPath, $files);

    // libzip refuses NUL names on open (CHECKCONS); the extractor also checks names itself.
    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBeIn([SafeExtractor::ENTRY_PATH, SafeExtractor::OPEN])
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses names that collide ignoring case', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files + ['Demo/routes/WEB.php' => 'x']);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ENTRY_DUPLICATE)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a file that clashes with a folder of another entry', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files + ['Demo/Routes' => 'x']);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ENTRY_DUPLICATE)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses symlinks and other non-regular entries', function (int $mode): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files + ['Demo/link' => '/etc/passwd'], function (ZipArchive $zip) use ($mode): void {
        $zip->setExternalAttributesName('Demo/link', ZipArchive::OPSYS_UNIX, $mode << 16);
    });
    $manifest = sxManifest($this->zipPath, $files + ['Demo/link' => '/etc/passwd']);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ENTRY_TYPE)
        ->and(sxEntries($this->staging))->toBe([]);
})->with([
    'symlink' => [0120777],
    'fifo' => [0010644],
    'block device' => [0060644],
]);

it('refuses more entries than max_files', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging, ['max_files' => 2]))->toBe(SafeExtractor::TOO_MANY_FILES)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('reads the max_files limit from the core.module_packages config when no explicit limits are given', function (): void {
    config(['core.module_packages.max_files' => 2]);
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::TOO_MANY_FILES)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a file above max_file_bytes', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging, ['max_file_bytes' => 20]))->toBe(SafeExtractor::FILE_TOO_LARGE)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a package unpacking above max_unpacked_bytes', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging, ['max_unpacked_bytes' => 60]))->toBe(SafeExtractor::UNPACKED_TOO_LARGE)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a compression ratio above max_ratio', function (): void {
    $files = sxFiles() + ['Demo/bomb.txt' => str_repeat('a', 100000)];
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging, ['max_ratio' => 10]))->toBe(SafeExtractor::RATIO)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a file the manifest does not list', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files + ['Demo/extra.php' => '<?php']);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::FILE_SET)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a package missing a manifest file', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files + ['Demo/missing.php' => '<?php']);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::FILE_SET)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a file whose hash differs from the manifest', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, ['Demo/routes/web.php' => 'tampered'] + $files, [
        'unpacked_size' => array_sum(array_map('strlen', $files)),
    ]);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::FILE_HASH)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('stops an entry that streams past its declared size and leaves nothing staged', function (): void {
    $real = str_repeat('abcdefgh', 1000);
    $files = sxFiles();
    $entries = [];
    foreach ($files as $name => $content) {
        $entries[] = [$name, $content, strlen($content)];
    }
    $entries[] = ['Demo/big.txt', $real, 10];
    sxLyingZip($this->zipPath, $entries);
    $manifest = sxManifest($this->zipPath, $files + ['Demo/big.txt' => $real], [
        'unpacked_size' => array_sum(array_map('strlen', $files)) + 10,
    ]);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ENTRY_TOO_LARGE)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a package whose unpacked size differs from the manifest', function (int $delta): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files, ['unpacked_size' => array_sum(array_map('strlen', $files)) + $delta]);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::SIZE_MISMATCH)
        ->and(sxEntries($this->staging))->toBe([]);
})->with([
    'one byte more' => [1],
    'one byte less' => [-1],
]);

it('refuses a zip whose sha256 differs from the manifest', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files, ['zip_sha256' => hash('sha256', 'other')]);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ZIP_HASH)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a zip whose size differs from the manifest', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files, ['zip_size' => filesize($this->zipPath) + 1]);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::ZIP_SIZE)
        ->and(sxEntries($this->staging))->toBe([]);
});

it('refuses a module.json whose name or version differs from the manifest', function (array $moduleJson): void {
    $files = sxFiles($moduleJson);
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::MODULE_JSON)
        ->and(sxEntries($this->staging))->toBe([]);
})->with([
    'name' => [['name' => 'Other']],
    'version' => [['version' => '2026.04.0']],
]);

it('refuses a staging folder that is not empty and leaves it as it was', function (): void {
    $files = sxFiles();
    sxZip($this->zipPath, $files);
    $manifest = sxManifest($this->zipPath, $files);
    file_put_contents($this->staging . '/keep.txt', 'keep');

    expect(sxExtractCode($this->zipPath, $manifest, $this->staging))->toBe(SafeExtractor::STAGING)
        ->and(sxEntries($this->staging))->toBe(['keep.txt'])
        ->and(file_get_contents($this->staging . '/keep.txt'))->toBe('keep');
});

it('removes trees without following symlinks and refuses paths outside the root', function (): void {
    $outside = $this->tmp . '/outside';
    mkdir($outside);
    file_put_contents($outside . '/victim.txt', 'safe');
    mkdir($this->staging . '/Demo');
    symlink($outside, $this->staging . '/Demo/link');

    expect(SafeExtractor::removeTree($outside, $this->staging))->toBeFalse()
        ->and(SafeExtractor::removeTree($this->staging . '/../outside', $this->staging))->toBeFalse()
        ->and(SafeExtractor::removeTree($this->staging . '/Demo/link/victim.txt', $this->staging))->toBeFalse()
        ->and(SafeExtractor::removeTree($this->staging . '/Demo', $this->staging))->toBeTrue()
        ->and(sxEntries($this->staging))->toBe([])
        ->and(file_get_contents($outside . '/victim.txt'))->toBe('safe');
});
