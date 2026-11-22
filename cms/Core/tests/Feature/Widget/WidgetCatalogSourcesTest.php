<?php

declare(strict_types=1);

use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Illuminate\Support\Facades\File;

uses(Cms\Core\Tests\TestCase::class);

// Publishers are the core catalog plus every project folder that ships one; the core names no project.

beforeEach(function (): void {
    $this->projectsRoot = sys_get_temp_dir() . '/catalog-projects-' . uniqid();
    foreach (['Zeta/Database/Seeders/exported', 'Alpha/Database/Seeders/exported', 'NoCatalog/views'] as $dir) {
        File::ensureDirectoryExists($this->projectsRoot . '/' . $dir);
    }
    config(['ispa-cms.widget_catalog_projects_path' => $this->projectsRoot]);
});

afterEach(function (): void {
    File::deleteDirectory($this->projectsRoot);
});

it('lists the core catalog first, then every project that ships a catalog', function (): void {
    expect(WidgetCatalogSources::publishers())->toBe([WidgetCatalogSources::ISPA, 'Alpha', 'Zeta'])
        ->and(WidgetCatalogSources::directory('Zeta'))->toBe($this->projectsRoot . '/Zeta/Database/Seeders/exported');
});

it('lets a project without a catalog yet receive its first export, and refuses unknown publishers', function (): void {
    expect(WidgetCatalogSources::directory('NoCatalog'))->toBe($this->projectsRoot . '/NoCatalog/Database/Seeders/exported');
    expect(fn () => WidgetCatalogSources::directory('Missing'))->toThrow(InvalidArgumentException::class);
    expect(fn () => WidgetCatalogSources::directory('../Zeta'))->toThrow(InvalidArgumentException::class);
});
