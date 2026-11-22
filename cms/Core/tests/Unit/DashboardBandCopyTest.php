<?php

uses(\Cms\Core\Tests\TestCase::class);

/*
 * Copy of the dashboard band for the iSPA Hub connection. The keys
 * live in Core's authored sidecar, so existing sites get them on their next deploy; `text.dashboard` and
 * `text.dashboard.band` stay branches (a row with exactly those names would turn the other keys into a broken label).
 */

const DASHBOARD_BAND_KEYS = [
    'text.dashboard.band.not_connected', 'text.dashboard.band.connected', 'text.dashboard.band.title',
    'text.dashboard.band.intro', 'text.dashboard.band.step1', 'text.dashboard.band.step2', 'text.dashboard.band.step3',
    'text.dashboard.band.connect', 'text.dashboard.band.docs', 'text.dashboard.band.dev_note', 'text.dashboard.band.hello',
    'text.dashboard.band.title_plain',
];

it('has every band key in the authored sidecar with Vietnamese and English text, no id, group text', function (): void {
    $rows = require base_path('cms/Core/database/seeders/sql/translate-admin-authored.php');
    $band = array_values(array_filter($rows, static fn ($row) => str_starts_with((string) ($row['name'] ?? ''), 'text.dashboard.band.')));

    expect(array_column($band, 'name'))->toEqualCanonicalizing(DASHBOARD_BAND_KEYS);
    foreach ($band as $row) {
        $text = is_string($row['text']) ? json_decode($row['text'], true) : $row['text'];
        expect($row)->not->toHaveKey('id')
            ->and($row['group_name'])->toBe('text')
            ->and(trim((string) ($text['vi'] ?? '')))->not->toBe('')
            ->and(trim((string) ($text['en'] ?? '')))->not->toBe('');
    }
});

it('never defines text.dashboard or text.dashboard.band as a label', function (): void {
    $files = array_merge(
        glob(base_path('cms/*/database/seeders/sql/translate*.php')),
        glob(base_path('cms/*/database/seeders/exported/translates*.php')),
    );

    $leaves = [];
    foreach ($files as $file) {
        foreach ((array) (static fn () => require $file)() as $row) {
            $name = is_array($row) ? (string) ($row['name'] ?? '') : '';
            if (in_array($name, ['text.dashboard', 'text.dashboard.band'], true)) {
                $leaves[] = basename($file).': '.$name;
            }
        }
    }

    expect($leaves)->toBe([]);
});
