<?php

uses(\Cms\Core\Tests\TestCase::class);

const USER_TAG_COPY_KEYS = [
    'text.user-tag.column', 'text.user-tag.placeholder', 'text.user-tag.create_option', 'text.user-tag.match_all', 'text.user-tag.match_any',
    'text.user-tag.selected', 'text.user-tag.attach', 'text.user-tag.detach', 'text.user-tag.select_all_filter', 'text.user-tag.all_selected',
    'text.user-tag.clear_selection', 'text.user-tag.confirm_all', 'text.user-tag.pick_tags', 'text.user-tag.done', 'text.user-tag.failed',
];

it('ships the user tag admin copy in the Core sidecar, with Vietnamese wording and no hand-picked ids', function () {
    $rows = array_column(require base_path('cms/Core/database/seeders/sql/translate-admin-authored.php'), null, 'name');
    foreach (USER_TAG_COPY_KEYS as $key) {
        expect($rows)->toHaveKey($key);
        expect($rows[$key])->not->toHaveKey('id');
        expect($rows[$key]['text']['vi'])->not->toBe('')->and($rows[$key]['text']['en'])->not->toBe('');
    }
});
