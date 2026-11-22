<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Database\Seeders\WidgetSeeder;

/**
 * the shared widget seed files hold only the Default project's themes. Themes made for one client project
 * (and the extra project names on shared themes) live in `database/seeders/Internal/`, which the private repo keeps
 * and the open-source export leaves out; WidgetSeeder merges them back, so existing sites seed the same rows.
 */
it('keeps the shared widget themes for the Default project only', function () {
    $themes = require database_path('seeders/exported/widget_themes.php');

    foreach ($themes as $theme) {
        $projects = json_decode((string) ($theme['project_name'] ?? '') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        expect(array_values(array_diff($projects, ['Default'])))->toBe([], "theme {$theme['id']} names another project");
    }
});

it('keeps the Core copies of the widget seed files equal to the seeded ones', function (string $file) {
    // DeployPreflightCommand checks these copies exist; WidgetSeeder reads database/seeders/exported. The hub publish
    // stamps `hub_version` / `hub_content_sha256` on the seeded catalog only: publish metadata, never seeded into a
    // column (LegacyWidgetDefinitionReconciler writes only the columns it owns), so the comparison leaves it out.
    $withoutPublishMetadata = static fn (array $rows): array => array_map(
        static fn (array $row): array => array_diff_key($row, array_flip(['hub_version', 'hub_content_sha256'])),
        $rows,
    );

    expect($withoutPublishMetadata(require base_path("cms/Core/database/seeders/exported/{$file}")))
        ->toBe($withoutPublishMetadata(require database_path("seeders/exported/{$file}")));
})->with(['widgets.php', 'widget_themes.php', 'widget_inputs.php']);

it('merges the internal widget rows by id and refers only to seeded widgets', function () {
    [$widgets, $themes, $inputs] = WidgetSeeder::sourceRows();
    $widgetIds = array_column($widgets, 'id');

    foreach (['themes' => $themes, 'inputs' => $inputs] as $label => $rows) {
        $ids = array_column($rows, 'id');
        $sorted = $ids;
        sort($sorted);
        expect($ids)->toBe($sorted, "{$label} are not ordered by id")
            ->and(count(array_unique($ids)))->toBe(count($ids), "{$label} repeat an id")
            ->and(array_values(array_diff(array_column($rows, 'widget_id'), $widgetIds)))->toBe([]);
    }

    $overrides = database_path('seeders/Internal/widget_theme_projects.php');
    if (is_file($overrides)) {
        expect(array_values(array_diff(array_keys(require $overrides), array_column($themes, 'id'))))->toBe([]);
    }
});

it('seeds only the shared rows when the internal folder is absent, as in the open-source export', function () {
    $original = database_path();
    $tmp = sys_get_temp_dir().'/widget-seed-'.bin2hex(random_bytes(4));
    mkdir($tmp.'/seeders/exported', 0777, true);
    foreach (['widgets.php', 'widget_themes.php', 'widget_inputs.php'] as $file) {
        copy($original."/seeders/exported/{$file}", $tmp."/seeders/exported/{$file}");
    }

    try {
        app()->useDatabasePath($tmp);
        [$widgets, $themes, $inputs] = WidgetSeeder::sourceRows();
    } finally {
        app()->useDatabasePath($original);
        array_map('unlink', glob($tmp.'/seeders/exported/*.php'));
        rmdir($tmp.'/seeders/exported');
        rmdir($tmp.'/seeders');
        rmdir($tmp);
    }

    expect($widgets)->toBe(require $original.'/seeders/exported/widgets.php')
        ->and($themes)->toBe(require $original.'/seeders/exported/widget_themes.php')
        ->and($inputs)->toBe(require $original.'/seeders/exported/widget_inputs.php');
});
