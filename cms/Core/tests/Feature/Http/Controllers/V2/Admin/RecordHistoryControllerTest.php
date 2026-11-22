<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\AuditLog;
use Cms\Core\Records\RecordRegistry;
use Cms\Core\Records\RecordType;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for the per-record history endpoint, including its privacy guarantees.
 */
class RecordHistoryControllerTest extends TestCase
{
    private const URL = 'https://localhost/api/v2/admin/records/%s/%s/history';

    private const SENTINEL_IP = '203.0.113.77';
    private const SENTINEL_AGENT = 'SentinelAgent/9.9';
    private const SENTINEL_URL = 'https://sentinel.example.test/secret-page';
    private const SENTINEL_OPTION = 'sentinel-option-value';

    /** The record whose history is read. */
    private User $record;

    /** Create the tables and register the fixture record types. */
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->integer('type')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->char('action', 50)->nullable();
            $table->longText('changed')->nullable();
            $table->nullableMorphs('target');
            $table->ipAddress()->nullable();
            $table->tinyText('agent')->nullable();
            $table->string('url', 1000)->nullable();
            $table->text('options')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->record = User::query()->create(['name' => 'Record', 'email' => 'record@example.test', 'password' => 'secret', 'type' => UserType::ADMIN]);

        $registry = app(RecordRegistry::class);
        $registry->register(new RecordType(
            'fixture.users',
            User::class,
            'fixture.view',
            'fixture.manage',
            null,
            ['name', 'type'],
            ['action.update' => 'text.history.action.update'],
        ));
        $registry->register(new RecordType(
            'fixture.hidden',
            User::class,
            'fixture.view',
            'fixture.manage',
            static fn (string $uuid, $user) => null,
            ['name'],
        ));
    }

    /**
     * Create and sign in a non-webmaster admin holding the given permission keys.
     *
     * @param  array<string>  $permissions
     */
    private function signIn(array $permissions): User
    {
        $user = User::query()->create(['name' => 'Actor', 'email' => 'u' . uniqid() . '@example.test', 'password' => 'secret', 'type' => UserType::ADMIN]);
        $actor = new class($permissions) extends User {
            /** @param array<string> $keys */
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

        return $user;
    }

    /**
     * Store an audit row for the given target with sentinel request metadata.
     *
     * @param  array<string, mixed>  $changed
     */
    private function audit(array $changed, ?int $userId = null, string $action = 'action.update', ?string $targetType = null, ?int $targetId = null): AuditLog
    {
        return AuditLog::query()->create([
            'action' => $action,
            'changed' => $changed,
            'target_type' => $targetType ?? User::class,
            'target_id' => $targetId ?? $this->record->id,
            'ip_address' => self::SENTINEL_IP,
            'agent' => self::SENTINEL_AGENT,
            'url' => self::SENTINEL_URL,
            'options' => ['icon' => self::SENTINEL_OPTION],
            'user_id' => $userId,
        ]);
    }

    /** History URL of the fixture record. */
    private function url(string $type = 'fixture.users', ?string $uuid = null, string $query = ''): string
    {
        return sprintf(self::URL, $type, $uuid ?? $this->record->uuid) . $query;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }

    public function test_user_without_view_ability_gets_403(): void
    {
        $this->signIn([]);
        $this->getJson($this->url())->assertStatus(403);
    }

    public function test_unknown_type_returns_404(): void
    {
        $this->signIn(['fixture.view']);
        $this->getJson($this->url('fixture.nope'))->assertStatus(404);
    }

    public function test_unknown_uuid_returns_404(): void
    {
        $this->signIn(['fixture.view']);
        $this->getJson($this->url('fixture.users', (string) Str::uuid()))->assertStatus(404);
    }

    public function test_record_hidden_by_resolver_returns_404(): void
    {
        $this->signIn(['fixture.view']);
        $this->getJson($this->url('fixture.hidden'))->assertStatus(404);
    }

    public function test_route_name_resolves(): void
    {
        $this->assertSame(
            '/api/v2/admin/records/fixture.users/' . $this->record->uuid . '/history',
            route('api.v2.records.history', ['type' => 'fixture.users', 'uuid' => $this->record->uuid], false)
        );
    }

    public function test_response_never_contains_request_metadata(): void
    {
        $actor = $this->signIn(['fixture.view']);
        $this->audit(['from' => ['name' => 'Old'], 'to' => ['name' => 'New']], $actor->id);

        $response = $this->getJson($this->url())->assertOk();
        $raw = (string) $response->getContent();

        foreach ([self::SENTINEL_IP, self::SENTINEL_AGENT, self::SENTINEL_URL, self::SENTINEL_OPTION, 'secret-page'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $raw);
        }
        $item = $response->json('data.0');
        foreach (['ip_address', 'agent', 'url', 'options', 'user_id', 'target_type', 'target_id', 'changed'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        $this->assertSame(['id', 'action', 'label', 'at', 'actor', 'changes'], array_keys($item));
        $this->assertSame('text.history.action.update', $item['label']);
    }

    public function test_only_whitelisted_fields_are_shown(): void
    {
        $this->signIn(['fixture.view']);
        $this->audit([
            'from' => ['name' => 'Old', 'password' => 'old-hash-sentinel', 'email' => 'old@example.test'],
            'to' => ['name' => 'New', 'password' => 'new-hash-sentinel', 'email' => 'new@example.test'],
        ]);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertSame([['field' => 'name', 'from' => 'Old', 'to' => 'New']], $response->json('data.0.changes'));
        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('hash-sentinel', $raw);
        $this->assertStringNotContainsString('@example.test', $raw);
    }

    public function test_per_field_shape_is_read_and_malformed_entries_ignored(): void
    {
        $this->signIn(['fixture.view']);
        $this->audit(['name' => ['from' => 'A', 'to' => 'B'], 'type' => 'not-a-pair', 'password' => ['from' => 'x', 'to' => 'y']]);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.changes', [['field' => 'name', 'from' => 'A', 'to' => 'B']]);
    }

    public function test_event_without_whitelisted_change_is_listed_with_empty_changes(): void
    {
        $this->signIn(['fixture.view']);
        $this->audit(['from' => ['password' => 'a'], 'to' => ['password' => 'b']], null, 'action.sign-in');

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.changes', [])
            ->assertJsonPath('data.0.label', 'text.history.action.unknown');
    }

    public function test_long_strings_are_cut_and_non_scalars_become_null(): void
    {
        $this->signIn(['fixture.view']);
        $long = str_repeat('é', 250);
        $this->audit(['from' => ['name' => $long, 'type' => ['nested' => 1]], 'to' => ['name' => 'Short', 'type' => 2]]);

        $changes = collect($this->getJson($this->url())->assertOk()->json('data.0.changes'))->keyBy('field');

        $this->assertSame(200, mb_strlen($changes['name']['from']));
        $this->assertSame(str_repeat('é', 200), $changes['name']['from']);
        $this->assertSame('Short', $changes['name']['to']);
        $this->assertNull($changes['type']['from']);
        $this->assertSame(2, $changes['type']['to']);
    }

    public function test_rows_of_another_target_type_with_same_id_never_appear(): void
    {
        $this->signIn(['fixture.view']);
        $own = $this->audit(['from' => ['name' => 'A'], 'to' => ['name' => 'B']]);
        $this->audit(['from' => ['name' => 'X'], 'to' => ['name' => 'Y']], null, 'action.update', 'Cms\\Content\\Models\\Post', $this->record->id);
        $this->audit(['from' => ['name' => 'X'], 'to' => ['name' => 'Y']], null, 'action.update', User::class, $this->record->id + 999);

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_cursor_pages_without_overlap_and_ends_with_null(): void
    {
        $this->signIn(['fixture.view']);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->audit(['from' => ['name' => "n{$i}"], 'to' => ['name' => 'n' . ($i + 1)]])->id;
        }
        rsort($ids);

        $first = $this->getJson($this->url(query: '?per_page=2'))->assertOk();
        $this->assertSame(array_slice($ids, 0, 2), array_column($first->json('data'), 'id'));
        $this->assertSame($ids[1], $first->json('meta.next_before'));

        $second = $this->getJson($this->url(query: '?per_page=2&before=' . $ids[1]))->assertOk();
        $this->assertSame(array_slice($ids, 2, 2), array_column($second->json('data'), 'id'));
        $this->assertSame($ids[3], $second->json('meta.next_before'));

        $third = $this->getJson($this->url(query: '?per_page=2&before=' . $ids[3]))->assertOk();
        $this->assertSame([$ids[4]], array_column($third->json('data'), 'id'));
        $this->assertNull($third->json('meta.next_before'));
    }

    public function test_invalid_before_is_rejected(): void
    {
        $this->signIn(['fixture.view']);
        $this->getJson($this->url(query: '?before=0'))->assertStatus(422);
        $this->getJson($this->url(query: '?before=abc'))->assertStatus(422);
    }

    public function test_soft_deleted_rows_do_not_appear(): void
    {
        $this->signIn(['fixture.view']);
        $kept = $this->audit(['from' => ['name' => 'A'], 'to' => ['name' => 'B']]);
        $this->audit(['from' => ['name' => 'B'], 'to' => ['name' => 'C']])->delete();

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id);
    }

    public function test_actor_is_uuid_and_name_only_and_null_without_user(): void
    {
        $actor = $this->signIn(['fixture.view']);
        $this->audit(['from' => ['name' => 'A'], 'to' => ['name' => 'B']]);
        $this->audit(['from' => ['name' => 'B'], 'to' => ['name' => 'C']], $actor->id);

        $data = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(['uuid' => $actor->uuid, 'name' => 'Actor'], $data[0]['actor']);
        $this->assertNull($data[1]['actor']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data[0]['at']);
    }
}
