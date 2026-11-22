<?php

declare(strict_types=1);

use Cms\Core\Models\Setting;
use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigTableRegistry;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Registry whose enabled modules are the given name => config_export map.
 *
 * @param array<string, mixed> $modules
 */
function cerRegistry(array $modules, ?ConfigTableRegistry $tables = null): ConfigExportRegistry
{
    return new ConfigExportRegistry(static fn (): array => $modules, $tables ?? new ConfigTableRegistry());
}

it('includes Core built-in groups and the patterns of enabled modules only', function (): void {
    $enabledOnly = cerRegistry(['Helpdesk' => ['settings' => ['helpdesk.*'], 'tables' => []]]);
    $none = cerRegistry([]);

    expect($enabledOnly->settingsPatterns())->toContain('helpdesk.*', 'email.*', 'order.*')
        ->and($none->settingsPatterns())->toContain('email.*')->not->toContain('helpdesk.*')
        ->and($none->matches('helpdesk.sla.urgent_minutes'))->toBeFalse();
});

it('matches helpdesk keys through helpdesk.* and no email key', function (): void {
    $registry = cerRegistry(['Helpdesk' => ['settings' => ['helpdesk.*']]]);
    $helpdeskOnly = new ExportContext(array_values(array_filter(
        $registry->settingsPatterns(),
        static fn (string $p): bool => str_starts_with($p, 'helpdesk.')
    )));

    expect($registry->matches('helpdesk.sla.urgent_minutes'))->toBeTrue()
        ->and($helpdeskOnly->matches('helpdesk.sla.urgent_minutes'))->toBeTrue()
        ->and($helpdeskOnly->matches('email.MAIL_HOST'))->toBeFalse()
        ->and($registry->matches('unknown.key'))->toBeFalse();
});

it('lets a module claim a Core group and accepts exact keys', function (): void {
    $registry = cerRegistry(['Ecommerce' => ['settings' => ['order.*', 'acme_rates.v1']]]);

    expect(array_count_values($registry->settingsPatterns())['order.*'])->toBe(1)
        ->and($registry->matches('order.shipping'))->toBeTrue()
        ->and($registry->matches('acme_rates.v1'))->toBeTrue()
        ->and($registry->matches('acme_rates.v2'))->toBeFalse();
});

it('ignores malformed patterns and validates the pattern shape', function (): void {
    foreach (['*', 'a.*.b', '', 'a b.*', '.*', 'a*', 'a..b', '**', 'a.*.*'] as $bad) {
        expect(ConfigExportRegistry::isValidPattern($bad))->toBeFalse("pattern '{$bad}'");
    }
    foreach (['helpdesk.*', 'helpdesk.sla.*', 'email.MAIL_HOST', 'plain'] as $good) {
        expect(ConfigExportRegistry::isValidPattern($good))->toBeTrue("pattern '{$good}'");
    }
    expect(ConfigExportRegistry::isValidPattern(5))->toBeFalse();

    $registry = cerRegistry(['Bad' => ['settings' => ['*', 'a.*.b', 7, 'bad.*']]]);

    expect($registry->settingsPatterns())->toContain('bad.*')->not->toContain('*')->not->toContain('a.*.b');
});

it('lists only registered tables of Core and enabled modules', function (): void {
    $tables = new ConfigTableRegistry();
    $tables->register('email_templates', ['key_name']);
    $tables->register('alpha_rules', ['code']);
    $tables->register('beta_rules', ['code']);

    $registry = cerRegistry([
        'Alpha' => ['tables' => ['alpha_rules', 'ghost_table']],
    ], $tables);

    expect($registry->tables())->toBe(['email_templates', 'alpha_rules']);
});

it('never exports an isEnv row even when a pattern matches it (guard wins)', function (): void {
    if (!Schema::hasTable('settings')) {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('type', 20)->default('text');
            $table->string('group_name', 50)->nullable();
            $table->string('name', 50)->nullable();
            $table->longText('value')->nullable();
            $table->unsignedBigInteger('enumerate_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->text('input_setting')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } else {
        DB::table('settings')->truncate();
    }

    foreach ([
        'helpdesk.title' => ['isEnv' => false, 'isPublic' => true],
        'helpdesk.env_value' => ['isEnv' => true, 'isPublic' => true],
    ] as $key => $input) {
        Setting::create(['setting_key' => $key, 'type' => 'text', 'group_name' => 'helpdesk', 'name' => $key, 'value' => 'v', 'input_setting' => $input]);
    }

    $registry = cerRegistry(['Helpdesk' => ['settings' => ['helpdesk.*', 'helpdesk.env_value']]]);
    $export = (new SettingsSection())->export(new ExportContext($registry->settingsPatterns()));

    expect($registry->matches('helpdesk.env_value'))->toBeTrue()
        ->and(array_column($export->rows, 'key'))->toBe(['helpdesk.title'])
        ->and($export->requiredSecrets)->toBe(['helpdesk.env_value']);
});

it('binds both registries as singletons with email_templates registered', function (): void {
    $tables = app(ConfigTableRegistry::class);

    expect(app(ConfigTableRegistry::class))->toBe($tables)
        ->and(app(ConfigExportRegistry::class))->toBe(app(ConfigExportRegistry::class))
        ->and($tables->get('email_templates'))->toMatchArray([
            'key_columns' => ['key_name'],
            'foreign_keys' => ['language_id'],
        ])
        ->and($tables->get('email_templates')['exclude_columns'])->toContain('user_id');
});
