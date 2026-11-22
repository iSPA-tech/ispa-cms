<?php

/**
 * Gate 0 Task 2 note: `cms:generate-widget-template` no longer writes
 * directly to mutable `storage/app/generated_widgets/{project}/...` paths
 * and no longer performs a cross-project stale sweep of that directory
 * (docs/architecture/adr-widget-artifact-store.md, decisions 1-6). It is now
 * a thin caller over LegacyWidgetArtifactSetPublisher::publish(), which
 * writes generation-versioned artifacts under
 * `storage/app/widget_generations/{generation-id}/{project}/...` and
 * atomically switches a single ACTIVE pointer only after the whole graph is
 * verified. This test was updated in Task 2 to assert that truthful
 * contract instead of the retired direct-write/stale-sweep behavior; the
 * legacy `generated_widgets` directory stays untouched (it survives only as
 * WidgetTemplatePathResolver's characterized legacy fallback).
 */

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

test('generate template blade command publishes widget templates through the active generation', function () {
    $originalDatabaseDefault = config('database.default');
    $originalSqliteDatabase = config('database.connections.sqlite.database');
    $originalStoragePath = storage_path();
    $isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-generation-test-' . uniqid();
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    app()->useStoragePath($isolatedStoragePath);

    Schema::dropAllTables();

    Schema::create(CoreTableConstant::WIDGET, function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->text('js')->nullable();
        // Widget::creating() stamps widget_key since c3b5205c3.
        $table->string('widget_key')->nullable();
        $table->timestamps();
    });

    Schema::create(CoreTableConstant::WIDGET_THEME, function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('widget_id');
        $table->string('name');
        $table->longText('content')->nullable();
        $table->longText('css')->nullable();
        $table->json('project_name')->nullable();
        // WidgetTheme::creating() stamps theme_key.
        $table->string('theme_key')->nullable();
        $table->timestamps();
    });

    $widget = Widget::query()->create([
        'name' => 'Sample Widget',
        'description' => 'desc',
        'js' => "<script>console.log('widget');</script>",
    ]);

    $theme = WidgetTheme::query()->create([
        'widget_id' => $widget->id,
        'name' => 'Sample Theme',
        'content' => "<section><div class=\"content\">Body</div></section>",
        'css' => '.content{color:red;}',
        'project_name' => ['Default', 'TestProject'],
    ]);

    // A pre-existing legacy-path file: the new publication pipeline must
    // leave the old generated_widgets directory completely untouched — it
    // is a read-only fallback (WidgetTemplatePathResolver), never written or
    // swept by the new generation-versioned publisher.
    $legacyStalePath = storage_path('app/generated_widgets/testproject/widget_999_theme_999.blade.php');
    File::ensureDirectoryExists(dirname($legacyStalePath));
    File::put($legacyStalePath, 'stale');

    try {
        $exitCode = Artisan::call('cms:generate-widget-template');
        expect($exitCode)->toBe(0);

        $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
        expect($manifest)->not->toBeNull();

        $fileName = 'widget_' . $widget->id . '_theme_' . $theme->id . '.blade.php';

        $defaultPath = LegacyWidgetArtifactSetPublisher::resolveProjectFilePath($manifest, 'Default', $fileName);
        $projectPath = LegacyWidgetArtifactSetPublisher::resolveProjectFilePath($manifest, 'TestProject', $fileName);

        expect($defaultPath)->not->toBeNull()->and(is_file($defaultPath))->toBeTrue();
        expect($projectPath)->not->toBeNull()->and(is_file($projectPath))->toBeTrue();

        $sectionId = 'sample-theme-w' . $widget->id . '-t' . $theme->id;
        $content = File::get($defaultPath);
        expect($content)->toContain("{{-- Blade template for widget: {$theme->name}")
            ->toContain("<section id=\"{$sectionId}\">")
            ->toContain('@push(\'script\')')
            ->toContain("console.log('widget');");

        // The legacy directory was never touched: no write, no stale sweep.
        expect(File::exists($legacyStalePath))->toBeTrue();
        expect(File::exists(storage_path('app/generated_widgets/default/' . $fileName)))->toBeFalse();
    } finally {
        Schema::dropIfExists(CoreTableConstant::WIDGET_THEME);
        Schema::dropIfExists(CoreTableConstant::WIDGET);
        config()->set('database.default', $originalDatabaseDefault);
        config()->set('database.connections.sqlite.database', $originalSqliteDatabase);
        app()->useStoragePath($originalStoragePath);
        File::deleteDirectory($isolatedStoragePath);
    }
});
