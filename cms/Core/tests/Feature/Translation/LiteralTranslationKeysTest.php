<?php

declare(strict_types=1);

uses(\PHPUnit\Framework\TestCase::class);

use Cms\Core\Tests\Support\LiteralTranslationKeyAudit;

/**
 * a translation key written as a literal in PHP must have a row (vi and en) in a module sidecar or export, or
 * a lang file; otherwise the admin shows the raw key ("Thành công! mgs.module_activated" after turning on a module).
 * The audit class is read-only and pure PHP.
 */
const LTK_ROOT = __DIR__.'/../../../../..';

function ltkTree(array $files): string
{
    $root = sys_get_temp_dir().'/ltk-'.bin2hex(random_bytes(5));
    foreach ($files as $path => $content) {
        @mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $content);
    }

    return $root;
}

function ltkRemove(string $path): void
{
    foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir($path.'/'.$entry) ? ltkRemove($path.'/'.$entry) : unlink($path.'/'.$entry);
        }
    }
    is_dir($path) ? rmdir($path) : null;
}

it('has a vi and an en text for every literal translation key in app/ and cms/*/app', function () {
    $audit = new LiteralTranslationKeyAudit(realpath(LTK_ROOT));

    $format = static fn (array $missing): string => implode("\n", array_map(static fn (string $key, array $places): string => $key.'  '.$places[0], array_keys($missing), $missing));

    expect($audit->missing(false))->toBe([], "No Vietnamese text:\n".$format($audit->missing(false)))
        ->and($audit->missing(true))->toBe([], "No English text:\n".$format($audit->missing(true)));
});

it('finds a literal key that no row or lang file carries, with its place', function () {
    $root = ltkTree([
        'lang/vi/mgs.php' => "<?php\nreturn ['known' => 'Đã biết', 'group' => ['leaf' => 'Lá']];\n",
        'lang/en/mgs.php' => "<?php\nreturn ['known' => 'Known', 'group' => ['leaf' => 'Leaf']];\n",
        'app/A.php' => "<?php\n\$a = __('mgs.known');\n\$b = __('mgs.unknown_one');\n\$c = trans('x::mgs.group.leaf');\n\$d = __('mgs.group');\n",
        'cms/Mod/app/B.php' => "<?php\n\$k = 'mgs.kept_in_variable';\n\$m = __(\$k);\n\$p = __('mgs.prefix_' . \$x);\n\$q = 'not.a.key';\n",
        'cms/Mod/database/seeders/sql/translate-admin-authored.php' => "<?php\nreturn [['group_name' => 'mgs', 'name' => 'mgs.only_vi', 'text' => ['vi' => 'Chỉ có tiếng Việt', 'en' => '']]];\n",
    ]);
    file_put_contents($root.'/app/A.php', file_get_contents($root.'/app/A.php')."\$e = __('mgs.only_vi');\n");

    $audit = new LiteralTranslationKeyAudit($root);
    $vi = $audit->missing(false);
    $en = $audit->missing(true);
    ltkRemove($root);

    expect(array_keys($vi))->toBe(['mgs.kept_in_variable', 'mgs.unknown_one'])
        ->and($vi['mgs.unknown_one'])->toBe(['app/A.php:3'])
        ->and(array_keys($en))->toBe(['mgs.kept_in_variable', 'mgs.only_vi', 'mgs.unknown_one']);
});

it('hints at an existing key that differs only in hyphens and underscores', function () {
    $root = ltkTree([
        'lang/vi/nav.php' => "<?php\nreturn ['a' => ['email-logs' => 'Nhật ký email']];\n",
        'lang/en/nav.php' => "<?php\nreturn ['a' => ['email-logs' => 'Email log']];\n",
        'app/A.php' => "<?php\n\$t = __('nav.a.email_logs');\n",
    ]);
    $audit = new LiteralTranslationKeyAudit($root);
    $hint = $audit->variantText('nav.a.email_logs');
    ltkRemove($root);

    expect($hint)->toBe(['vi' => 'Nhật ký email', 'en' => 'Email log']);
});

it('has the six module toggle messages the module screen reads', function () {
    $sidecar = (string) file_get_contents(LTK_ROOT.'/cms/Core/database/seeders/sql/translate-admin-authored.php');

    foreach (['activated', 'deactivated', 'has_dependents', 'dependencies_missing', 'not_found', 'cannot_disable'] as $name) {
        expect($sidecar)->toContain("'mgs.module_{$name}'");
    }
});
