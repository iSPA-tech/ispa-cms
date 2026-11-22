<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\SafeExtractor;

uses(Cms\Core\Tests\TestCase::class);

/*
 * a premium download is a per-grant copy of the signed package with `<Name>/LICENSEE.json` and
 * `<Name>/LICENSEE.sig` added. The site accepts those two extra files only when the hub's signed ticket names the copy
 * (its zip SHA-256 and size) and the package's own module.json says `"edition": "premium"`. Every signed file must
 * still match the manifest.
 */

/**
 * Signed files of the "Demo" module with the given edition.
 *
 * @return array<string, string>
 */
function slxFiles(string $edition = 'premium'): array
{
    return [
        'Demo/module.json' => json_encode(['name' => 'Demo', 'version' => '2026.03.0', 'edition' => $edition]),
        'Demo/app/Providers/DemoServiceProvider.php' => "<?php\n\nnamespace Demo;\n",
    ];
}

/**
 * The two licensee files the hub adds.
 *
 * @return array<string, string>
 */
function slxMark(array $override = []): array
{
    return [
        'Demo/LICENSEE.json' => json_encode(array_merge(['schema' => 1, 'module' => 'Demo', 'version' => '2026.03.0', 'grant' => 'g-uuid', 'domain' => 'site.test', 'date' => '2026-11-15T10:00:00+00:00'], $override)),
        'Demo/LICENSEE.sig' => json_encode(['alg' => 'hmac-sha256', 'signature' => base64_encode(str_repeat('x', 32))]),
    ];
}

/**
 * Write a zip of the given entries.
 *
 * @param array<string, string> $entries
 */
function slxZip(string $path, array $entries): string
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString((string) $name, $content);
    }
    $zip->close();

    return $path;
}

/**
 * Manifest of the original (unmarked) package.
 *
 * @param array<string, string> $files
 */
function slxManifest(string $zipPath, array $files): ReleaseManifest
{
    return ReleaseManifest::fromVerifiedBytes(json_encode([
        'format' => 1,
        'name' => 'Demo',
        'version' => '2026.03.0',
        'zip_sha256' => hash_file('sha256', $zipPath),
        'zip_size' => filesize($zipPath),
        'unpacked_size' => array_sum(array_map('strlen', $files)),
        'files' => array_map(static fn (string $c): string => hash('sha256', $c), $files),
        'requires' => [],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => str_repeat('a', 16),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * The ticket's description of a marked copy.
 *
 * @return array{zip_sha256: string, zip_size: int}
 */
function slxCopy(string $zipPath): array
{
    return ['zip_sha256' => (string) hash_file('sha256', $zipPath), 'zip_size' => (int) filesize($zipPath)];
}

/**
 * Error code of an extraction, or null when it succeeds.
 *
 * @param array{zip_sha256: string, zip_size: int}|null $copy
 */
function slxCode(string $zip, ReleaseManifest $manifest, string $staging, ?array $copy): ?string
{
    try {
        (new SafeExtractor())->extract($zip, $manifest, $staging, $copy);
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir() . '/slx-' . bin2hex(random_bytes(6));
    mkdir($this->tmp . '/staging', 0755, true);
    $this->tmp = realpath($this->tmp);
    $this->staging = $this->tmp . '/staging';
    $files = slxFiles();
    $this->manifest = slxManifest(slxZip($this->tmp . '/original.zip', $files), $files);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->tmp));
});

it('extracts a marked premium copy the ticket names, with every signed file and the two licensee files', function (): void {
    $mark = slxMark();
    $zip = slxZip($this->tmp . '/marked.zip', slxFiles() + $mark);

    $dir = (new SafeExtractor())->extract($zip, $this->manifest, $this->staging, slxCopy($zip));

    expect($dir)->toBe($this->staging . '/Demo');
    foreach (slxFiles() + $mark as $path => $content) {
        expect(file_get_contents($this->staging . '/' . $path))->toBe($content);
    }
});

it('accepts a copy carrying only LICENSEE.json', function (): void {
    $zip = slxZip($this->tmp . '/marked.zip', slxFiles() + ['Demo/LICENSEE.json' => slxMark()['Demo/LICENSEE.json']]);

    expect(slxCode($zip, $this->manifest, $this->staging, slxCopy($zip)))->toBeNull();
});

it('keeps the unmarked package verifying as before without a copy description', function (): void {
    expect(slxCode($this->tmp . '/original.zip', $this->manifest, $this->staging, null))->toBeNull();
});

it('refuses the licensee files when the ticket names no copy', function (): void {
    $zip = slxZip($this->tmp . '/marked.zip', slxFiles() + slxMark());

    // Size and hash then come from the manifest, which the marked copy does not match.
    expect(slxCode($zip, $this->manifest, $this->staging, null))->toBe(SafeExtractor::ZIP_SIZE);
});

it('refuses a copy whose size or hash differs from the ticket', function (): void {
    $zip = slxZip($this->tmp . '/marked.zip', slxFiles() + slxMark());
    $copy = slxCopy($zip);

    expect(slxCode($zip, $this->manifest, $this->staging, ['zip_sha256' => str_repeat('0', 64), 'zip_size' => $copy['zip_size']]))->toBe(SafeExtractor::ZIP_HASH)
        ->and(slxCode($zip, $this->manifest, $this->staging, ['zip_sha256' => $copy['zip_sha256'], 'zip_size' => $copy['zip_size'] + 1]))->toBe(SafeExtractor::ZIP_SIZE);
});

it('refuses licensee files in a package that is not premium', function (string $edition): void {
    $files = slxFiles($edition);
    $manifest = slxManifest(slxZip($this->tmp . '/original-' . $edition . '.zip', $files), $files);
    $zip = slxZip($this->tmp . '/marked-' . $edition . '.zip', $files + slxMark());

    expect(slxCode($zip, $manifest, $this->staging, slxCopy($zip)))->toBe(SafeExtractor::LICENSEE)
        ->and(array_values(array_diff(scandir($this->staging) ?: [], ['.', '..'])))->toBe([]);
})->with(['open', 'internal', 'client']);

it('refuses any other extra file, a missing LICENSEE.json or an oversized licensee file', function (array $extra, string $code): void {
    $zip = slxZip($this->tmp . '/marked.zip', slxFiles() + $extra);

    expect(slxCode($zip, $this->manifest, $this->staging, slxCopy($zip)))->toBe($code);
})->with([
    'extra code file' => [slxMark() + ['Demo/evil.php' => '<?php echo 1;'], SafeExtractor::FILE_SET],
    'only the signature' => [['Demo/LICENSEE.sig' => slxMark()['Demo/LICENSEE.sig']], SafeExtractor::LICENSEE],
    'no extra file at all' => [[], SafeExtractor::LICENSEE],
    'licensee file too large' => [['Demo/LICENSEE.json' => json_encode(['module' => 'Demo', 'version' => '2026.03.0', 'pad' => bin2hex(random_bytes(SafeExtractor::LICENSEE_MAX_BYTES))])], SafeExtractor::LICENSEE],
    'licensee file at the zip root' => [['LICENSEE.json' => slxMark()['Demo/LICENSEE.json']], SafeExtractor::ENTRY_PATH],
]);

it('refuses a LICENSEE.json that names another module or version', function (array $override): void {
    $zip = slxZip($this->tmp . '/marked.zip', slxFiles() + slxMark($override));

    expect(slxCode($zip, $this->manifest, $this->staging, slxCopy($zip)))->toBe(SafeExtractor::LICENSEE);
})->with([
    'other module' => [['module' => 'Other']],
    'other version' => [['version' => '2026.04.0']],
]);

it('still refuses a marked copy whose signed file was changed', function (): void {
    $files = slxFiles();
    $files['Demo/app/Providers/DemoServiceProvider.php'] = "<?php\n\nnamespace Demo;\n// x\n";
    $zip = slxZip($this->tmp . '/marked.zip', $files + slxMark());

    expect(slxCode($zip, $this->manifest, $this->staging, slxCopy($zip)))->toBeIn([SafeExtractor::SIZE_MISMATCH, SafeExtractor::FILE_HASH]);
});
