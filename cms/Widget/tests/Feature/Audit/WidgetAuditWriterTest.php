<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Audit;

use App\Models\User;
use Cms\Core\Models\AuditLog;
use Cms\Widget\Audit\WidgetAuditWriter;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionClassConstant;
use RuntimeException;
use Tests\TestCase;

/**
 * Chunk 2 Task 14: WidgetAuditWriter durability, explicitness, and payload
 * shape proof (plan lines 833-835; docs/architecture/adr-widget-audit-durability.md).
 *
 * Covers the ADR's "measurable acceptance evidence" table: same-transaction
 * durability (G-007's executable proof), explicit dispatch (no Widget model
 * auto-audits because AbstractObserver::audit() stays commented out), and
 * payload field completeness/exclusion.
 */
final class WidgetAuditWriterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetArtifactBlobRepositoryTest::beforeRefreshingDatabase() (Task
     * 13b) for the full explanation. Short version: the Widget module stays
     * disabled by design (no modules_statuses.json entry), so its migration
     * path must be registered manually before RefreshDatabase's migrate:fresh
     * runs; and RefreshDatabaseState::$migrated is a process-wide static
     * flag, so this hook also resets it first, forcing a real migrate:fresh
     * for THIS class regardless of what any earlier-running, non-Widget-
     * scoped RefreshDatabase test left that flag set to (Laravel checks the
     * flag AFTER calling this hook). Additive to the default paths
     * (Illuminate\Database\Migrations\Migrator::path() merges, and this
     * class never overrides migrateFreshUsing()), so Core's audit_logs/users
     * tables remain available alongside Widget's own tables.
     */
    protected function beforeRefreshingDatabase(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    private function layout(): WidgetLayout
    {
        return WidgetLayout::query()->create(['project_name' => 'Default']);
    }

    /**
     * audit_logs.user_id is a real foreign key to users (cascade-on-delete;
     * see adr-widget-audit-durability.md's cited migration columns), so
     * every writeDurable() call in these tests needs a real actor row --
     * an arbitrary int would fail the FK constraint on any real database.
     */
    private function actorId(): int
    {
        return User::factory()->create()->id;
    }

    // -----------------------------------------------------------------
    // Explicit dispatch (ADR decision 4)
    // -----------------------------------------------------------------

    public function test_creating_a_widget_model_does_not_automatically_write_an_audit_row(): void
    {
        $this->layout();

        $this->assertSame(
            0,
            AuditLog::query()->count(),
            'No observer wires audit automatically for Widget models (AbstractObserver::audit() is commented out).'
        );
    }

    public function test_write_durable_explicitly_creates_exactly_one_audit_row(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $writer->writeDurable('widget_layout.publish', [
            'actor_id' => $this->actorId(),
            'project' => 'Default',
            'target' => $layout,
            'destination_release' => 2,
        ]);

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_write_durable_accepts_explicit_target_type_and_id_without_a_model_instance(): void
    {
        $writer = new WidgetAuditWriter();

        $writer->writeDurable('widget_template.approve', [
            'actor_id' => $this->actorId(),
            'project' => 'Default',
            'target_type' => WidgetArtifactManifest::class,
            'target_id' => 42,
        ]);

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame(WidgetArtifactManifest::class, $row->target_type);
        $this->assertSame(42, $row->target_id);
    }

    // -----------------------------------------------------------------
    // Same-transaction durability (ADR decision 3, G-007)
    // -----------------------------------------------------------------

    public function test_audit_row_is_rolled_back_together_with_the_domain_mutation_on_transaction_failure(): void
    {
        $layout = $this->layout();
        $actorId = $this->actorId();
        $writer = new WidgetAuditWriter();

        try {
            DB::transaction(function () use ($writer, $layout, $actorId): void {
                $writer->writeDurable('widget_layout.publish', [
                    'actor_id' => $actorId,
                    'project' => 'Default',
                    'target' => $layout,
                    'destination_release' => 2,
                ]);

                throw new RuntimeException('Simulated domain failure after the audit dispatch.');
            });
            $this->fail('Expected the simulated domain failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated domain failure after the audit dispatch.', $exception->getMessage());
        }

        $this->assertSame(
            0,
            AuditLog::query()->count(),
            'A rolled-back transaction must roll back its audit row too (same-transaction durability).'
        );
    }

    public function test_audit_row_survives_a_committed_transaction(): void
    {
        $layout = $this->layout();
        $actorId = $this->actorId();
        $writer = new WidgetAuditWriter();

        DB::transaction(function () use ($writer, $layout, $actorId): void {
            $writer->writeDurable('widget_layout.publish', [
                'actor_id' => $actorId,
                'project' => 'Default',
                'target' => $layout,
                'destination_release' => 2,
            ]);
        });

        $this->assertSame(1, AuditLog::query()->count());
    }

    // -----------------------------------------------------------------
    // Payload shape: required fields + safe metadata in "options"
    // -----------------------------------------------------------------

    public function test_audit_row_stores_the_frozen_payload_shape(): void
    {
        $layout = $this->layout();
        $actorId = $this->actorId();
        $writer = new WidgetAuditWriter();

        $idempotencyHash = hash('sha256', 'client-idempotency-key');

        $correlationId = $writer->writeDurable('widget_layout.rollback', [
            'actor_id' => $actorId,
            'project' => 'Default',
            'target' => $layout,
            'source_release' => 5,
            'destination_release' => 6,
            'note' => 'Rolled back due to a bad publish.',
            'correlation_id' => 'corr-fixed-1',
            'idempotency_key_hash' => $idempotencyHash,
            'metadata' => ['manifest_hash' => 'abc123'],
        ]);

        $this->assertSame('corr-fixed-1', $correlationId);

        $row = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame('action.widget_layout.rollback', $row->action);
        $this->assertSame(WidgetLayout::class, $row->target_type);
        $this->assertSame($layout->id, $row->target_id);
        $this->assertSame($actorId, $row->user_id);

        $this->assertSame($actorId, $row->changed['actor_id']);
        $this->assertSame('Default', $row->changed['project']);
        $this->assertSame('widget_layout.rollback', $row->changed['action']);
        $this->assertSame(5, $row->changed['source_release']);
        $this->assertSame(6, $row->changed['destination_release']);
        $this->assertSame('Rolled back due to a bad publish.', $row->changed['note']);
        $this->assertSame('corr-fixed-1', $row->changed['correlation_id']);
        $this->assertSame($idempotencyHash, $row->changed['idempotency_key_hash']);
        $this->assertSame(['manifest_hash' => 'abc123'], $row->changed['options']);

        // Never store values/full HTML anywhere in the persisted payload.
        $this->assertArrayNotHasKey('values', $row->changed);
        $this->assertArrayNotHasKey('html', $row->changed);
    }

    public function test_idempotency_key_hash_is_a_first_class_top_level_slot_defaulting_to_null(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        // ADR decision 5 groups "correlation ID and idempotency-key hash"
        // together as first-class frozen payload fields -- Tasks 20/21 must
        // pass the hash through this dedicated context key, never smuggle it
        // through metadata.
        $writer->writeDurable('widget_layout.publish', [
            'actor_id' => $this->actorId(),
            'project' => 'Default',
            'target' => $layout,
        ]);

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertArrayHasKey('idempotency_key_hash', $row->changed, 'The slot must exist even when the caller supplies no hash.');
        $this->assertNull($row->changed['idempotency_key_hash']);
    }

    public function test_correlation_id_is_server_minted_when_the_caller_does_not_supply_one(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $correlationId = $writer->writeDurable('widget_layout.publish', [
            'actor_id' => $this->actorId(),
            'project' => 'Default',
            'target' => $layout,
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $correlationId,
            'An auto-minted correlation id must be a UUID.'
        );
    }

    // -----------------------------------------------------------------
    // Banned content rejection (ADR decision 6; fixture
    // idempotency.banned_retained_fields)
    // -----------------------------------------------------------------

    public function test_banned_metadata_keys_are_rejected_and_no_row_is_written(): void
    {
        $layout = $this->layout();
        $actorId = $this->actorId();
        $writer = new WidgetAuditWriter();

        try {
            $writer->writeDurable('widget_layout.publish', [
                'actor_id' => $actorId,
                'project' => 'Default',
                'target' => $layout,
                'metadata' => ['values' => ['secret_field' => 'should never be stored']],
            ]);
            $this->fail('Expected banned key "values" to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('values', $exception->getMessage());
        }

        $this->assertSame(0, AuditLog::query()->count(), 'A rejected write must not leave a partial audit row.');
    }

    public function test_every_banned_key_constant_entry_is_rejected_wherever_nested(): void
    {
        $layout = $this->layout();
        $actorId = $this->actorId();
        $writer = new WidgetAuditWriter();

        // Driven by reflection over the writer's own BANNED_KEYS constant so
        // this loop can never drift from the production list: every entry --
        // present and future -- is exercised structurally, no hand copy.
        /** @var list<string> $bannedKeys */
        $bannedKeys = (new ReflectionClassConstant(WidgetAuditWriter::class, 'BANNED_KEYS'))->getValue();

        $this->assertNotEmpty($bannedKeys, 'Sanity: BANNED_KEYS must not be empty.');
        $this->assertContains('values', $bannedKeys, 'Sanity: the canonical "values" ban must be present.');

        foreach ($bannedKeys as $bannedKey) {
            try {
                $writer->writeDurable('widget_layout.publish', [
                    'actor_id' => $actorId,
                    'project' => 'Default',
                    'target' => $layout,
                    'metadata' => ['nested' => [$bannedKey => 'irrelevant']],
                ]);
                $this->fail("Expected banned key '{$bannedKey}' to be rejected even when nested.");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(0, AuditLog::query()->count(), 'No banned-key attempt may leave any audit row behind.');
    }

    public function test_banned_key_matching_is_case_insensitive(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);

        $writer->writeDurable('widget_layout.publish', [
            'actor_id' => 1,
            'project' => 'Default',
            'target' => $layout,
            'metadata' => ['VALUES' => 'case must not evade the ban'],
        ]);
    }

    // -----------------------------------------------------------------
    // Required field validation
    // -----------------------------------------------------------------

    public function test_missing_actor_id_is_rejected(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);

        $writer->writeDurable('widget_layout.publish', [
            'project' => 'Default',
            'target' => $layout,
        ]);
    }

    public function test_missing_project_is_rejected(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);

        $writer->writeDurable('widget_layout.publish', [
            'actor_id' => $this->actorId(),
            'target' => $layout,
        ]);
    }

    public function test_missing_target_identity_is_rejected(): void
    {
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);

        $writer->writeDurable('widget_layout.publish', [
            'actor_id' => $this->actorId(),
            'project' => 'Default',
        ]);
    }

    public function test_empty_action_is_rejected(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);

        $writer->writeDurable('', [
            'actor_id' => $this->actorId(),
            'project' => 'Default',
            'target' => $layout,
        ]);
    }

    public function test_action_exceeding_the_audit_column_length_after_prefixing_is_rejected(): void
    {
        $layout = $this->layout();
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);

        // audit_logs.action is char(50) and AuditLogHandler prepends
        // "action." (7 chars), so a 44-char action string overflows to 51.
        $writer->writeDurable(str_repeat('a', 44), [
            'actor_id' => 1,
            'project' => 'Default',
            'target' => $layout,
        ]);
    }

    public function test_target_model_with_a_non_integer_key_gets_a_dedicated_error_not_the_generic_fallthrough(): void
    {
        $writer = new WidgetAuditWriter();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-integer primary key');

        // An unsaved model has a null key -- the writer must say exactly
        // that (a Model WAS passed but its key is unusable), not the
        // misleading generic "requires target identity" message reserved
        // for the no-target-at-all case.
        $writer->writeDurable('widget_layout.publish', [
            'actor_id' => 1,
            'project' => 'Default',
            'target' => new WidgetLayout(),
        ]);
    }
}
