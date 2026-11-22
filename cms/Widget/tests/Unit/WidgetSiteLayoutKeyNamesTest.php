<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

uses(TestCase::class);

/**
 * Admin translation rows are sharded by the first segment of `name`, so every row of the
 * `widget_site_layout` group must carry the full `widget_site_layout.` prefix.
 */
it('keeps every widget_site_layout translation row under the group prefix', function () {
    // Rows with no call site in cms/Widget/admin; deliberately left unprefixed.
    $allowList = ['nav.site_layout', 'target_context.page'];

    $rows = require dirname(__DIR__, 2).'/database/seeders/sql/translate.php';

    $offenders = [];

    foreach ($rows as $row) {
        if (($row['group_name'] ?? null) !== 'widget_site_layout') {
            continue;
        }

        if (! str_starts_with($row['name'], 'widget_site_layout.') && ! in_array($row['name'], $allowList, true)) {
            $offenders[] = $row['name'];
        }
    }

    expect($offenders)->toBe([]);
});
