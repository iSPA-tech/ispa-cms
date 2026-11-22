<?php

use Tests\Support\TranslationFilesTripwire;

/**
 * Run a git command in a directory and fail the test when it errors.
 */
function tripwireGit(string $dir, string $args): void
{
    exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);
    expect($code)->toBe(0, implode("\n", $out));
}

/**
 * Create a throwaway git repository holding protected and unprotected tracked files.
 */
function tripwireRepo(): string
{
    $dir = sys_get_temp_dir() . '/tripwire-' . bin2hex(random_bytes(4));
    mkdir($dir . '/lang/en', 0775, true);
    mkdir($dir . '/cms/Core/database/seeders/sql', 0775, true);
    file_put_contents($dir . '/lang/en/auth.php', "<?php return ['a' => 'A'];\n");
    file_put_contents($dir . '/cms/Core/database/seeders/sql/translate.php', "<?php return [];\n");
    file_put_contents($dir . '/README.md', "readme\n");
    tripwireGit($dir, 'init -q');
    tripwireGit($dir, 'add -A');
    tripwireGit($dir, '-c user.email=t@t -c user.name=t commit -qm base');

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/tripwire-*') ?: [] as $dir) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

it('snapshots only tracked protected files', function () {
    $dir = tripwireRepo();
    file_put_contents($dir . '/lang/en/untracked.php', "<?php return [];\n");

    $snapshot = TranslationFilesTripwire::snapshot($dir);

    expect(array_keys($snapshot))->toBe(['cms/Core/database/seeders/sql/translate.php', 'lang/en/auth.php']);
});

it('stays quiet when nothing changed', function () {
    $dir = tripwireRepo();
    $before = TranslationFilesTripwire::snapshot($dir);
    file_put_contents($dir . '/README.md', "edited\n");

    expect(TranslationFilesTripwire::changedFiles($before, TranslationFilesTripwire::snapshot($dir)))->toBe([]);
});

it('flags a modified and a deleted protected file without touching them', function () {
    $dir = tripwireRepo();
    $before = TranslationFilesTripwire::snapshot($dir);
    file_put_contents($dir . '/lang/en/auth.php', "<?php return ['a' => 'testver'];\n");
    unlink($dir . '/cms/Core/database/seeders/sql/translate.php');

    $changed = TranslationFilesTripwire::changedFiles($before, TranslationFilesTripwire::snapshot($dir));

    expect($changed)->toBe(['cms/Core/database/seeders/sql/translate.php', 'lang/en/auth.php']);
    expect(file_get_contents($dir . '/lang/en/auth.php'))->toContain('testver');
    expect(TranslationFilesTripwire::message($changed))
        ->toContain('lang/en/auth.php')
        ->toContain('git checkout -- <file>')
        ->toContain('never regenerate');
});

it('skips silently when the directory is not a git work tree', function () {
    $dir = sys_get_temp_dir() . '/tripwire-nogit-' . bin2hex(random_bytes(4));
    mkdir($dir);

    expect(TranslationFilesTripwire::snapshot($dir))->toBeNull();
    expect(TranslationFilesTripwire::changedFiles(['lang/en/a.php' => 'x'], null))->toBe([]);
});
