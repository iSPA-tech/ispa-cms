<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\AssertionFailedError;

uses(Cms\Core\Tests\TestCase::class);

// cms:make-module: validation, dry run, writing a mode's files, enabling the module and the post-copy steps.
// The command is pointed at a temporary module folder, a temporary statuses file and the small fixture template.

beforeEach(function (): void {
    $this->modules = sys_get_temp_dir() . '/mkmod-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->modules);
    $this->statuses = $this->modules . '/modules_statuses.json';
    File::put($this->statuses, json_encode(['Core' => true], JSON_PRETTY_PRINT) . "\n");
    config([
        'modules.paths.modules' => $this->modules,
        'modules.activators.file.statuses-file' => $this->statuses,
        'modules.generator_template' => dirname(__DIR__, 3) . '/Fixtures/module-template-mini',
    ]);
    Process::fake();
});

afterEach(function (): void {
    File::deleteDirectory($this->modules);
});

function ranCommand(string $needle): bool
{
    // assertRan stops at the first matching process, so the callback must be the match itself.
    try {
        Process::assertRan(static function (PendingProcess $process) use ($needle): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($command, $needle);
        });

        return true;
    } catch (AssertionFailedError) {
        return false;
    }
}

it('lists the files of a dry run and writes nothing', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Zoo', '--entity' => 'Post', '--mode' => 'full', '--dry-run' => true])
        ->expectsOutputToContain('app/Models/ZooPost.php')
        ->expectsOutputToContain('admin/pages/posts.vue')
        ->expectsOutputToContain('routes/web.php')
        ->assertSuccessful();

    expect(is_dir($this->modules . '/Zoo'))->toBeFalse();
    Process::assertNothingRan();
});

it('writes the renamed files of the mode and enables the module', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Blog', '--entity' => 'Post'])->assertSuccessful();

    $root = $this->modules . '/Blog';
    $manifest = json_decode(File::get($root . '/module.json'), true);
    expect($manifest['name'])->toBe('Blog')
        ->and($manifest['alias'])->toBe('blog')
        ->and($manifest['providers'])->toBe(['Cms\\Blog\\Providers\\BlogServiceProvider']);

    $model = File::get($root . '/app/Models/BlogPost.php');
    expect($model)->toContain('namespace Cms\\Blog\\Models;')
        ->toContain("'blog_posts'")
        ->toContain("return 'normal';")
        ->not->toContain("return 'lite';")
        ->not->toContain('@mode');

    expect(File::get($root . '/admin/pages/posts.vue'))->toContain('text.blog.posts.title')->not->toContain('<p>full</p>')
        ->and(file_exists($root . '/routes/web.php'))->toBeFalse();

    $migrations = File::files($root . '/database/migrations');
    expect($migrations)->toHaveCount(1)
        ->and($migrations[0]->getFilename())->toStartWith(now()->format('Y_m_d_'))
        ->and($migrations[0]->getFilename())->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_blog_posts_table\.php$/');

    expect(json_decode(File::get($this->statuses), true))->toBe(['Core' => true, 'Blog' => true])
        ->and(ranCommand('composer dump-autoload'))->toBeTrue()
        ->and(ranCommand('migrate'))->toBeFalse();
});

it('refuses a folder that exists, whatever its case', function (): void {
    File::ensureDirectoryExists($this->modules . '/blog');
    File::put($this->modules . '/blog/keep.txt', 'x');

    $this->artisan('cms:make-module', ['name' => 'Blog'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(File::files($this->modules . '/blog'))->toHaveCount(1)
        ->and(json_decode(File::get($this->statuses), true))->toBe(['Core' => true]);
    Process::assertNothingRan();
});

it('refuses reserved and malformed names', function (string $name): void {
    $this->artisan('cms:make-module', ['name' => $name])->assertFailed();

    expect(File::directories($this->modules))->toBe([]);
})->with(['Core', 'Content', 'Widget', 'Skeleton', 'GameFoo', 'blog', 'Blog-Post']);

it('refuses a malformed entity name', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Blog', '--entity' => 'post'])->assertFailed();

    expect(File::directories($this->modules))->toBe([]);
});

it('refuses a module whose alias another module already uses', function (): void {
    File::ensureDirectoryExists($this->modules . '/LegacyAds');
    File::put($this->modules . '/LegacyAds/module.json', json_encode(['name' => 'LegacyAds', 'alias' => 'customads']));

    $this->artisan('cms:make-module', ['name' => 'Customads'])
        ->expectsOutputToContain('customads')
        ->assertFailed();

    expect(File::allFiles($this->modules . '/LegacyAds'))->toHaveCount(1)
        ->and(json_decode(File::get($this->statuses), true))->toBe(['Core' => true]);
});

it('refuses an unknown mode', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Blog', '--mode' => 'huge'])->assertFailed();

    expect(is_dir($this->modules . '/Blog'))->toBeFalse();
});

it('keeps the files and names the step when a post-copy step fails', function (): void {
    Process::swap(new Illuminate\Process\Factory());
    Process::fake(['composer*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

    $this->artisan('cms:make-module', ['name' => 'Blog'])
        ->expectsOutputToContain('composer dump-autoload')
        ->assertFailed();

    // Not enabled: a provider that cannot be autoloaded would break every later request.
    expect(file_exists($this->modules . '/Blog/module.json'))->toBeTrue()
        ->and(json_decode(File::get($this->statuses), true))->toBe(['Core' => true]);
});

it('runs the database steps only with --setup', function (string $mode, bool $translations): void {
    $this->artisan('cms:make-module', ['name' => 'Blog', '--mode' => $mode, '--setup' => true])->assertSuccessful();

    expect(ranCommand('artisan migrate'))->toBeTrue()
        ->and(ranCommand('cms:modules-sync-permissions Blog'))->toBeTrue()
        ->and(ranCommand('cms:translation-sync Blog'))->toBe($translations)
        ->and(ranCommand('cms:translation-generate --public-only'))->toBe($translations);
})->with([
    'lite' => ['lite', false],
    'normal' => ['normal', true],
]);

it('refuses full mode when the public URL is already routed, before writing', function (): void {
    // The Content module answers /blog/{slug}; a Blog module's public pages would take over or lose to them.
    $this->artisan('cms:make-module', ['name' => 'Blog', '--mode' => 'full'])
        ->expectsOutputToContain('/blog is already used')
        ->assertFailed();

    expect(is_dir($this->modules . '/Blog'))->toBeFalse();
    Process::assertNothingRan();
});

it('allows lite and normal modes under a routed prefix', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Blog', '--mode' => 'normal'])->assertSuccessful();
});

it('does not complain about a free public URL', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Zoo', '--mode' => 'full'])
        ->doesntExpectOutputToContain('is already used')
        ->assertSuccessful();
});

it('refuses --setup in production, before writing', function (): void {
    app()['env'] = 'production';

    $this->artisan('cms:make-module', ['name' => 'Blog', '--setup' => true])
        ->expectsOutputToContain('production')
        ->assertFailed();

    expect(is_dir($this->modules . '/Blog'))->toBeFalse();
    Process::assertNothingRan();
});

it('migrates without --force', function (): void {
    $this->artisan('cms:make-module', ['name' => 'Blog', '--setup' => true])->assertSuccessful();

    expect(ranCommand('artisan migrate'))->toBeTrue()
        ->and(ranCommand('--force'))->toBeFalse();
});

it('refuses an unreadable statuses file and leaves it as it is', function (): void {
    File::put($this->statuses, '{"Core": true,');

    $this->artisan('cms:make-module', ['name' => 'Blog'])
        ->expectsOutputToContain('modules_statuses.json')
        ->assertFailed();

    expect(File::get($this->statuses))->toBe('{"Core": true,')
        ->and(is_dir($this->modules . '/Blog'))->toBeFalse();
});

it('refuses a module whose sample table already exists', function (): void {
    \Illuminate\Support\Facades\Schema::create('zoo_animals', static function ($table): void {
        $table->id();
    });

    try {
        $this->artisan('cms:make-module', ['name' => 'Zoo', '--entity' => 'Animal'])
            ->expectsOutputToContain('zoo_animals')
            ->assertFailed();

        expect(is_dir($this->modules . '/Zoo'))->toBeFalse();
    } finally {
        \Illuminate\Support\Facades\Schema::dropIfExists('zoo_animals');
    }
});

it('lints only the new module', function (): void {
    // Another module with a broken manifest must not make the new one look broken.
    File::ensureDirectoryExists($this->modules . '/Broken');
    File::put($this->modules . '/Broken/module.json', json_encode(['name' => 'Broken', 'alias' => 'broken', 'version' => 'not-a-version']));

    $this->artisan('cms:make-module', ['name' => 'Blog'])
        ->expectsOutputToContain('✓ cms:manifest-lint')
        ->assertSuccessful();
});
