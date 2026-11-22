<?php

uses(\Cms\Core\Tests\TestCase::class);

it('provides complete canonical English and Vietnamese admin menu translations', function (): void {
    $rows = require base_path('cms/Core/database/seeders/exported/translates-admin-menu-editor.php');
    $byName = collect($rows)->keyBy('name');
    expect(require base_path('cms/Core/database/seeders/sql/translate-admin-menu-editor.php'))->toBe($rows);
    expect($byName)->toHaveCount(count($rows));
    expect($byName->get('text.menu-expand-all-child')['text'])->toBe([
        'en' => 'Show all child category levels?', 'vi' => 'Hiển thị tất cả danh mục con?',
    ])->and($byName->get('text.menu-expand-child-level')['text'])->toBe([
        'en' => 'Number of child category levels', 'vi' => 'Số cấp danh mục con hiển thị',
    ]);
    foreach ($rows as $row) {
        expect($row['modules'])->toBe(['Core'])
            ->and($row['group_name'])->toBe('text')
            ->and($row['for_adm'])->toBe(1)
            ->and($row['for_web'])->toBe(0)
            ->and($row['for_app'])->toBe(0)
            ->and($row['text']['vi'])->not->toBeEmpty()
            ->and($row['text']['en'])->not->toBeEmpty();
    }
    expect($byName->get('text.menu_editor.preview-help')['text']['vi'])->not->toContain('Preview')
        ->and($byName->get('text.menu_editor.validation-summary')['text']['vi'])->toContain(':count');
});
