<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Generator\ModuleTemplate;

uses(Cms\Core\Tests\TestCase::class);

// The template manifest lists the files each mode adds; modes are cumulative (lite ⊂ normal ⊂ full).

function miniTemplate(): ModuleTemplate
{
    return new ModuleTemplate(dirname(__DIR__, 3) . '/Fixtures/module-template-mini');
}

it('lists files cumulatively per mode', function (): void {
    $template = miniTemplate();

    expect($template->files('lite'))->toBe([
        'module.json',
        'app/Models/SkeletonThing.php',
        'database/migrations/2026_01_01_000000_create_skeleton_things_table.php',
    ])
        ->and($template->files('normal'))->toBe([...$template->files('lite'), 'admin/pages/things.vue'])
        ->and($template->files('full'))->toBe([...$template->files('normal'), 'routes/web.php']);
});

it('keeps marked blocks only for the listed modes', function (): void {
    $template = miniTemplate();
    $source = "a\n// @mode lite\nb\n// @endmode\n// @mode normal,full\nc\n// @endmode\nd";

    expect($template->render($source, 'lite'))->toBe("a\nb\nd")
        ->and($template->render($source, 'normal'))->toBe("a\nc\nd")
        ->and($template->render($source, 'full'))->toBe("a\nc\nd")
        ->and($template->render("x\n<!-- @mode full -->\ny\n<!-- @endmode -->\n", 'normal'))->toBe("x\n");
});

it('refuses an unknown mode', function (): void {
    miniTemplate()->files('huge');
})->throws(InvalidArgumentException::class);
