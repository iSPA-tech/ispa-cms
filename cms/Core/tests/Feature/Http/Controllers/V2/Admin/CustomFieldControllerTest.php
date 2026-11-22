<?php

use App\Models\User;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Models\MetaAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Opted-in resource used by the custom field API tests. */
class CfApiOrgsResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'core/cf-orgs';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cfapi.orgs.view';
    }

    /** No declared fields. */
    public function fields(): array
    {
        return [];
    }

    /** Opt in. */
    public function supportsCustomFields(): bool
    {
        return true;
    }

    /** List view carrying the title translation key. */
    public function list(): ?ListView
    {
        return ListView::make()->labels(['title' => 'nav.a.cf-orgs']);
    }
}

/** Second opted-in resource (for cross-resource uuid checks). */
class CfApiContactsResource extends CfApiOrgsResource
{
    /** Resource key. */
    public function key(): string
    {
        return 'core/cf-contacts';
    }
}

/** Resource that does not opt in. */
class CfApiPlainResource extends CfApiOrgsResource
{
    /** Resource key. */
    public function key(): string
    {
        return 'core/cf-plain';
    }

    /** Opt out. */
    public function supportsCustomFields(): bool
    {
        return false;
    }
}

/** Opted-in resource of a disabled module. */
class CfApiDisabledResource extends CfApiOrgsResource
{
    /** Resource key. */
    public function key(): string
    {
        return 'gamecore/cf-orgs';
    }
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    $registry = app(ResourceRegistry::class);
    $registry->register('core/cf-orgs', CfApiOrgsResource::class);
    $registry->register('core/cf-contacts', CfApiContactsResource::class);
    $registry->register('core/cf-plain', CfApiPlainResource::class);
    $registry->register('gamecore/cf-orgs', CfApiDisabledResource::class);
});

/**
 * Sign in a non-webmaster admin holding exactly the given permission keys.
 *
 * @param  array<int, string>  $permissions
 */
function cfApiSignIn(array $permissions): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Field Admin', 'username' => 'fa'.Str::random(6),
        'email' => 'fa'.Str::random(8).'@example.test', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);
    $actor = new class($permissions) extends User
    {
        /** @param array<int, string> $keys */
        public function __construct(private array $keys = [])
        {
            parent::__construct();
        }

        public function permissionKeys(): array
        {
            return $this->keys;
        }

        public function loadMissing($relations)
        {
            return $this;
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $actor->setRawAttributes($user->getAttributes(), true);
    $actor->exists = true;
    Sanctum::actingAs($actor);

    return $actor;
}

/** Sign in a custom field manager. */
function cfApiManager(): User
{
    return cfApiSignIn(['custom_field.manage', 'cfapi.orgs.view']);
}

/** Base url of the custom fields of a resource. */
function cfApiUrl(string $resource = 'core/cf-orgs', string $suffix = ''): string
{
    return 'https://localhost/api/v2/admin/resources/'.$resource.'/custom-fields'.$suffix;
}

/**
 * Valid definition payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cfApiPayload(array $overrides = []): array
{
    return array_merge([
        'key' => 'industry',
        'type' => 'text',
        'labels' => ['vi' => 'Ngành', 'en' => 'Industry'],
    ], $overrides);
}

/** Create a field directly through the service. */
function cfApiField(string $key = 'industry', string $resource = 'core/cf-orgs'): MetaAttribute
{
    return app(CustomFieldService::class)->create($resource, cfApiPayload(['key' => $key]), null);
}

/** Current custom field schema epoch of a resource. */
function cfApiEpoch(string $resource = 'core/cf-orgs'): mixed
{
    return Cache::store()->get('resource-schema-epoch:custom:'.$resource);
}

it('rejects requests without login', function () {
    $this->getJson('https://localhost/api/v2/admin/custom-field-resources')->assertStatus(401);
    $this->getJson(cfApiUrl())->assertStatus(401);
    $this->postJson(cfApiUrl(), cfApiPayload())->assertStatus(401);
});

it('lists opted-in resources of enabled modules', function () {
    cfApiSignIn(['custom_field.view', 'cfapi.orgs.view']);

    $response = $this->getJson('https://localhost/api/v2/admin/custom-field-resources')->assertOk();

    $keys = collect($response->json('data'))->pluck('key')->all();
    expect($keys)->toContain('core/cf-orgs', 'core/cf-contacts')
        ->not->toContain('core/cf-plain')
        ->not->toContain('gamecore/cf-orgs');
    $orgs = collect($response->json('data'))->firstWhere('key', 'core/cf-orgs');
    expect($orgs['module'])->toBe('core')
        ->and($orgs['label_key'])->toBe('nav.a.cf-orgs')
        ->and($response->json('meta.can_manage'))->toBeFalse();
});

it('lets a viewer read but not write', function () {
    $field = cfApiField();
    cfApiSignIn(['custom_field.view', 'cfapi.orgs.view']);

    $this->getJson(cfApiUrl())->assertOk()->assertJsonPath('data.0.uuid', $field->uuid);
    $this->getJson(cfApiUrl(suffix: '/'.$field->uuid.'/usage'))->assertOk();
    $this->postJson(cfApiUrl(), cfApiPayload(['key' => 'other']))->assertStatus(403);
    $this->putJson(cfApiUrl(suffix: '/'.$field->uuid), ['is_required' => true])->assertStatus(403);
    $this->postJson(cfApiUrl(suffix: '/reorder'), ['uuids' => [$field->uuid]])->assertStatus(403);
    $this->postJson(cfApiUrl(suffix: '/preview'), cfApiPayload())->assertStatus(403);
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/archive'))->assertStatus(403);
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/restore'))->assertStatus(403);
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/purge'), ['confirm_key' => 'industry'])->assertStatus(403);
    expect(MetaAttribute::query()->custom()->count())->toBe(1);
});

it('forbids a user with neither permission', function () {
    $field = cfApiField();
    cfApiSignIn(['cfapi.orgs.view']);

    $this->getJson('https://localhost/api/v2/admin/custom-field-resources')->assertStatus(403);
    $this->getJson(cfApiUrl())->assertStatus(403);
    $this->getJson(cfApiUrl(suffix: '/'.$field->uuid.'/usage'))->assertStatus(403);
    $this->postJson(cfApiUrl(), cfApiPayload(['key' => 'other']))->assertStatus(403);
});

it('forbids a manager who cannot view the resource', function () {
    cfApiSignIn(['custom_field.manage']);

    $this->getJson(cfApiUrl())->assertStatus(403);
    $this->postJson(cfApiUrl(), cfApiPayload())->assertStatus(403);
});

it('returns 404 for resources that do not opt in, are unknown or whose module is off', function () {
    cfApiManager();

    $this->getJson(cfApiUrl('core/cf-plain'))->assertStatus(404);
    $this->postJson(cfApiUrl('core/cf-plain'), cfApiPayload())->assertStatus(404);
    $this->getJson(cfApiUrl('core/nope'))->assertStatus(404);
    $this->getJson(cfApiUrl('gamecore/cf-orgs'))->assertStatus(404);
    $this->postJson(cfApiUrl('gamecore/cf-orgs'), cfApiPayload())->assertStatus(404);
});

it('creates a field, writes an audit entry and bumps the schema epoch', function () {
    $user = cfApiManager();
    $epoch = cfApiEpoch();

    $response = $this->postJson(cfApiUrl(), cfApiPayload(['is_required' => true, 'rules' => ['max' => 80]]));

    $response->assertCreated()
        ->assertJsonPath('data.key', 'industry')
        ->assertJsonPath('data.type', 'text')
        ->assertJsonPath('data.labels.en', 'Industry')
        ->assertJsonPath('data.is_required', true)
        ->assertJsonPath('data.rules.max', 80)
        ->assertJsonPath('data.archived', false)
        ->assertJsonMissingPath('data.id');
    $uuid = $response->json('data.uuid');
    expect(Str::isUuid($uuid))->toBeTrue()
        ->and(cfApiEpoch())->not->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'custom_field.created')->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses invalid definitions with plain messages', function () {
    cfApiManager();

    $this->postJson(cfApiUrl(), cfApiPayload(['type' => 'colour']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_definition')
        ->assertJsonFragment(['message' => 'The custom field definition is invalid: type must be one of: text, textarea, integer, boolean, date, select, multiselect.']);
    $this->postJson(cfApiUrl(), cfApiPayload(['key' => 'Bad Key']))->assertStatus(422);
    $this->postJson(cfApiUrl(), cfApiPayload(['rules' => ['min' => 10, 'max' => 2]]))
        ->assertStatus(422)
        ->assertJsonPath('errors.definition.0', 'min must not exceed max');
    $this->postJson(cfApiUrl(), cfApiPayload(['labels' => ['vi' => 'Ngành']]))->assertStatus(422);
    $this->postJson(cfApiUrl(), ['type' => 'text'])->assertStatus(422);
    cfApiField();
    $this->postJson(cfApiUrl(), cfApiPayload())->assertStatus(422)->assertJsonPath('reason', 'duplicate_key');
    expect(MetaAttribute::query()->custom()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'custom_field.created')->count())->toBe(0);
});

it('refuses the 31st field', function () {
    for ($i = 1; $i <= 30; $i++) {
        cfApiField('field_'.$i);
    }
    cfApiManager();

    $this->postJson(cfApiUrl(), cfApiPayload(['key' => 'field_31']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'too_many_fields');
    expect(MetaAttribute::query()->custom()->count())->toBe(30);
});

it('updates a field', function () {
    $field = cfApiField();
    cfApiManager();
    $epoch = cfApiEpoch();

    $this->patchJson(cfApiUrl(suffix: '/'.$field->uuid), ['labels' => ['vi' => 'Lĩnh vực', 'en' => 'Sector'], 'show_in_list' => true])
        ->assertOk()
        ->assertJsonPath('data.labels.en', 'Sector')
        ->assertJsonPath('data.show_in_list', true);
    $this->putJson(cfApiUrl(suffix: '/'.$field->uuid), ['key' => 'renamed'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'key_immutable');
    expect(cfApiEpoch())->not->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'custom_field.updated')->count())->toBe(1);
});

it('returns 404 for a numeric id or a uuid of another resource', function () {
    $field = cfApiField();
    $other = cfApiField('phone', 'core/cf-contacts');
    cfApiManager();

    $this->putJson(cfApiUrl(suffix: '/'.$field->id), ['is_required' => true])->assertStatus(404);
    $this->getJson(cfApiUrl(suffix: '/'.$field->id.'/usage'))->assertStatus(404);
    $this->putJson(cfApiUrl(suffix: '/'.$other->uuid), ['is_required' => true])->assertStatus(404);
    $this->postJson(cfApiUrl(suffix: '/'.$other->uuid.'/archive'))->assertStatus(404);
    $this->postJson(cfApiUrl(suffix: '/'.$other->uuid.'/purge'), ['confirm_key' => 'phone'])->assertStatus(404);
    $this->getJson(cfApiUrl(suffix: '/'.Str::uuid().'/usage'))->assertStatus(404);
    expect(MetaAttribute::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('reorders fields', function () {
    $a = cfApiField('alpha');
    $b = cfApiField('beta');
    cfApiManager();
    $epoch = cfApiEpoch();

    $this->postJson(cfApiUrl(suffix: '/reorder'), ['uuids' => [$b->uuid, $a->uuid]])->assertOk()
        ->assertJsonPath('data.0.uuid', $b->uuid)
        ->assertJsonPath('data.1.uuid', $a->uuid);
    $this->postJson(cfApiUrl(suffix: '/reorder'), ['uuids' => [(string) Str::uuid()]])->assertStatus(422);
    expect(cfApiEpoch())->not->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'custom_field.reordered')->count())->toBe(1);
});

it('archives and restores a field', function () {
    $field = cfApiField();
    cfApiManager();

    $epoch = cfApiEpoch();
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/archive'))->assertOk()->assertJsonPath('data.archived', true);
    expect(cfApiEpoch())->not->toBe($epoch);
    $this->getJson(cfApiUrl())->assertOk()->assertJsonPath('data.0.archived', true);

    $epoch = cfApiEpoch();
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/restore'))->assertOk()->assertJsonPath('data.archived', false);
    expect(cfApiEpoch())->not->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'custom_field.archived')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'custom_field.restored')->count())->toBe(1);
});

it('reports usage', function () {
    $field = cfApiField();
    cfApiSignIn(['custom_field.view', 'cfapi.orgs.view']);

    $this->getJson(cfApiUrl(suffix: '/'.$field->uuid.'/usage'))->assertOk()
        ->assertJsonPath('data.uuid', $field->uuid)
        ->assertJsonPath('data.records', 0);
});

it('previews a definition without writing', function () {
    cfApiManager();
    $epoch = cfApiEpoch();

    $this->postJson(cfApiUrl(suffix: '/preview'), cfApiPayload([
        'type' => 'select',
        'is_required' => true,
        'options' => [['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']]],
    ]))->assertOk()
        ->assertJsonPath('data.field.field', 'custom_fields.industry')
        ->assertJsonPath('data.field.rules.required', true);
    $this->postJson(cfApiUrl(suffix: '/preview'), cfApiPayload(['rules' => ['max' => 0]]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_definition');
    expect(MetaAttribute::query()->custom()->count())->toBe(0)
        ->and(cfApiEpoch())->toBe($epoch)
        ->and(AuditLog::query()->count())->toBe(0);
});

/** Bind a backup gate stand-in; it returns a stored backup, or throws when $fail is set. Returns the call counter. */
function cfApiGate(bool $fail = false): ArrayObject
{
    $calls = new ArrayObject();
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturnUsing(function (string $reason) use ($calls, $fail): Backup {
        expect($reason)->toBe(Backup::REASON_PRE_PURGE);
        $calls[] = true;
        if ($fail) {
            throw new BackupRequiredException('BACKUP_ALL_DESTINATIONS_FAILED');
        }
        $dest = BackupDestination::query()->firstOrCreate(['name' => 'cfapi-local'], ['driver' => 'local', 'config' => ['disk' => 'local']]);
        $backup = Backup::query()->create([
            'reason' => $reason, 'status' => Backup::STATUS_SUCCEEDED, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz',
        ]);
        BackupCopy::query()->create(['backup_id' => $backup->id, 'destination_id' => $dest->id, 'path' => 'cfapi/b.gz', 'status' => BackupCopy::STATUS_STORED]);

        return $backup;
    });
    app()->instance(BackupGate::class, $gate);

    return $calls;
}

it('refuses to purge when no backup can be taken and deletes nothing', function () {
    $field = cfApiField();
    cfApiManager();
    $calls = cfApiGate(true);
    $epoch = cfApiEpoch();

    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/purge'), ['confirm_key' => 'industry'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'backup_required');

    expect($calls)->toHaveCount(1)
        ->and(MetaAttribute::query()->whereKey($field->id)->exists())->toBeTrue()
        ->and(cfApiEpoch())->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'custom_field.purged')->count())->toBe(0);
});

it('purges only with the right confirm key', function () {
    $field = cfApiField();
    cfApiManager();
    $calls = cfApiGate();

    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/purge'), ['confirm_key' => 'Industry'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'confirm_mismatch');
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/purge'), [])->assertStatus(422);
    expect(MetaAttribute::query()->whereKey($field->id)->exists())->toBeTrue();

    $epoch = cfApiEpoch();
    $this->postJson(cfApiUrl(suffix: '/'.$field->uuid.'/purge'), ['confirm_key' => 'industry'])
        ->assertOk()
        ->assertJsonPath('data.removed_values', 0)
        ->assertJsonPath('data.backup', Backup::query()->value('uuid'));
    expect($calls)->toHaveCount(1)
        ->and(MetaAttribute::query()->whereKey($field->id)->exists())->toBeFalse()
        ->and(cfApiEpoch())->not->toBe($epoch)
        ->and(AuditLog::query()->where('action', 'custom_field.purged')->count())->toBe(1);
    $this->getJson(cfApiUrl(suffix: '/'.$field->uuid.'/usage'))->assertStatus(404);
});
