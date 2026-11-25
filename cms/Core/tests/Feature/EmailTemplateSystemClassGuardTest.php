<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * (plan §7 item 21b): a `system` mail is sent by workflows with no approval and no switch, so only a
 * Webmaster may make a template `system` — on create or by changing the class. Anyone with `email_template.update`
 * still edits the text, and a row that is already `system` (a module-shipped default) keeps its class.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware();
    etgUser([]); // user id 1 is always a Webmaster; the editors below are not
    // The controller backs the table up to repo files after a save; never in a test.
    Artisan::shouldReceive('call')->andReturn(0);
});

function etgUser(array $permissions, int $level = 50): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'U ' . Str::random(4),
        'username' => 'u' . Str::lower(Str::random(8)),
        'email' => Str::lower(Str::random(8)) . '@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ]);
    $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => $level]]);
    foreach ($permissions as $key) {
        [$group, $ability] = explode('.', $key, 2);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
    }
    $user->roles()->attach($role);

    return $user->fresh();
}

function etgTemplate(string $class): EmailTemplate
{
    return EmailTemplate::query()->create([
        'name' => 'Hello {{ display_name }}',
        'group' => 'workflow',
        'mail_class' => $class,
        'key_name' => 'guard_' . Str::lower(Str::random(6)),
        'content' => '<p>Hi {{ display_name }}</p>',
    ]);
}

const ETG_EDITOR = ['email_template.viewAny', 'email_template.view', 'email_template.create', 'email_template.update'];

it('refuses a non-Webmaster editor who turns a business template into system mail', function (): void {
    $editor = etgUser(ETG_EDITOR);
    expect($editor->isWebmaster())->toBeFalse();
    $row = etgTemplate('business');

    $this->actingAs($editor)->putJson("/api/v2/admin/email-template/{$row->id}", ['mail_class' => 'system'])
        ->assertStatus(403)
        ->assertJsonPath('error_code', 'mail_class_system_webmaster_only');

    expect($row->fresh()->mail_class)->toBe('business');
});

it('refuses a non-Webmaster editor who creates a new system template', function (): void {
    $this->actingAs(etgUser(ETG_EDITOR))->postJson('/api/v2/admin/email-template', [
        'name' => 'Welcome', 'group' => 'workflow', 'key_name' => 'guard_new', 'content' => '<p>Hi</p>', 'mail_class' => 'system',
    ])->assertStatus(403)->assertJsonPath('error_code', 'mail_class_system_webmaster_only');

    expect(EmailTemplate::query()->where('key_name', 'guard_new')->exists())->toBeFalse();
});

it('lets a Webmaster make a template system mail', function (): void {
    $webmaster = etgUser(ETG_EDITOR, 0);
    expect($webmaster->isWebmaster())->toBeTrue();
    $row = etgTemplate('business');

    $this->actingAs($webmaster)->putJson("/api/v2/admin/email-template/{$row->id}", ['mail_class' => 'system'])->assertOk();

    expect($row->fresh()->mail_class)->toBe('system');
});

it('lets a non-Webmaster editor change the text of a system template, which keeps its class, and make it business', function (): void {
    $editor = etgUser(ETG_EDITOR);
    $row = etgTemplate('system');

    $this->actingAs($editor)->putJson("/api/v2/admin/email-template/{$row->id}", ['content' => '<p>Edited</p>', 'mail_class' => 'system'])->assertOk();
    expect($row->fresh())->content->toBe('<p>Edited</p>')->mail_class->toBe('system');

    $this->actingAs($editor)->putJson("/api/v2/admin/email-template/{$row->id}", ['mail_class' => 'business'])->assertOk();
    expect($row->fresh()->mail_class)->toBe('business');
});
