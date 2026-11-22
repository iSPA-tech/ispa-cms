<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Generator\ModuleNames;
use Cms\Core\Services\Modules\Generator\ModuleTemplate;
use Illuminate\Support\Facades\File;

uses(Cms\Core\Tests\TestCase::class);

// The real module template: placeholders only as whole tokens, and a manifest that lists every file once.

function realTemplateRoot(): string
{
    return dirname(__DIR__, 4) . '/resources/module-template';
}

it('uses placeholders only as whole tokens', function (): void {
    $whole = (new ModuleNames('Blog', 'Post'))->pattern();
    $problems = [];
    foreach (File::allFiles(realTemplateRoot()) as $file) {
        $relative = ltrim(str_replace(realTemplateRoot(), '', $file->getPathname()), '/');
        if (in_array($relative, ModuleTemplate::OWN_FILES, true)) {
            continue;
        }
        foreach (explode("\n", $relative . "\n" . $file->getContents()) as $number => $line) {
            $stripped = preg_replace($whole, '', $line);
            if (preg_match('/skeleton|thing/i', (string) $stripped) === 1) {
                $problems[] = "{$relative}:{$number}: " . trim($line);
            }
        }
    }

    expect($problems)->toBe([]);
});

it('lists every template file in the manifest exactly once', function (): void {
    $manifest = require realTemplateRoot() . '/manifest.php';
    $listed = array_merge(...array_values($manifest));
    $onDisk = [];
    foreach (File::allFiles(realTemplateRoot()) as $file) {
        $relative = ltrim(str_replace(realTemplateRoot(), '', $file->getPathname()), '/');
        if (! in_array($relative, ModuleTemplate::OWN_FILES, true)) {
            $onDisk[] = $relative;
        }
    }
    sort($onDisk);
    $sortedListed = $listed;
    sort($sortedListed);

    expect($sortedListed)->toBe($onDisk)
        ->and(count($listed))->toBe(count(array_unique($listed)));
});
