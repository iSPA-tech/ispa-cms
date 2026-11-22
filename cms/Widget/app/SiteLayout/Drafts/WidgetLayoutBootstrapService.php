<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Drafts;

use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryContractSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns `PUT /layouts/by-key/{layout_key}`, the only MVP Layout bootstrap
 * (plan Chunk 2 Task 17, lines 1020-1051; architecture.md "Layout
 * bootstrap").
 *
 * Under the server-derived project context, creates the Layout plus its
 * initial mutable Draft with the canonical EMPTY registry baseline, or
 * returns the existing identity untouched. Unique `(project_name, key)`
 * plus insert-then-detect-unique-violation retry/readback makes concurrent
 * calls converge on one identity -- the SAME idiom
 * {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyService::tryInsertClaim()}
 * already established in this module (including wrapping the insert in its
 * own nested `DB::transaction()` so Laravel issues a SAVEPOINT: PostgreSQL
 * aborts the whole enclosing transaction on ANY failed statement, including
 * an expected unique-constraint violation, so a bare try/catch around the
 * insert alone is not safe on that engine -- see that method's own docblock
 * for the full reasoning, reused verbatim here rather than adding a second
 * private copy of the same fix).
 *
 * Deliberately does NOT use {@see \Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder}:
 * that class's own docblock scopes it to actions with an EXISTING aggregate
 * row to lock; Bootstrap either finds an existing Layout (a plain read, no
 * lock needed) or inserts a brand-new one (the unique index IS the
 * concurrency control, exactly like {@see WidgetInstance} create/clone in
 * Task 16, which also never locks anything for its own insert path).
 *
 * Deliberately does NOT use {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyService}:
 * the frozen contract fixture marks this route's `idempotency_scope` as
 * `null` with the explicit note "concurrency-safe via unique (project_name,
 * key) + retry/readback, NOT the idempotency-key header protocol".
 *
 * Deliberately does NOT consult {@see \App\Contracts\Modules\ModuleLifecycleBarrier}:
 * architecture.md reserves that barrier for Instance-Version creation,
 * destructive Definition/Theme mutation, and Safe Import -- Layout/Draft
 * creation is out of its scope (coordinator brief, Task 17).
 */
final class WidgetLayoutBootstrapService
{
    private const STATE_DRAFT = WidgetLayoutVersion::STATE_DRAFT;

    public function __construct(
        private readonly WidgetRegistryContractSnapshot $registrySnapshot,
    ) {
    }

    public function bootstrap(string $projectName, string $key): WidgetLayoutBootstrapResult
    {
        $existing = $this->findByProjectAndKey($projectName, $key);
        if ($existing !== null) {
            return WidgetLayoutBootstrapResult::existing($existing);
        }

        try {
            $layout = DB::transaction(function () use ($projectName, $key): WidgetLayout {
                $layout = WidgetLayout::query()->create([
                    'project_name' => $projectName,
                    'key' => $key,
                ]);

                // Initial Layout creation computes the canonical empty/
                // referenced baseline (plan line 1041): no Placements exist
                // yet, so every reference set is empty.
                $snapshot = $this->registrySnapshot->build();

                $draft = WidgetLayoutVersion::query()->create([
                    'layout_id' => $layout->id,
                    'project_name' => $projectName,
                    'version' => 1,
                    'state' => self::STATE_DRAFT,
                    'lock_version' => 0,
                    'registry_contracts' => $snapshot['contracts'],
                    'registry_contract_hash' => $snapshot['hash'],
                ]);

                $layout->current_draft_version_id = $draft->id;
                $layout->save();

                return $layout->fresh();
            });

            return WidgetLayoutBootstrapResult::created($layout);
        } catch (QueryException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Lost the creation race to a concurrent caller: read back its
            // committed identity (retry/readback convergence).
            $winner = $this->findByProjectAndKey($projectName, $key);
            if ($winner === null) {
                throw $exception;
            }

            return WidgetLayoutBootstrapResult::existing($winner);
        }
    }

    public function findByUuid(string $projectName, string $uuid): ?WidgetLayout
    {
        return WidgetLayout::query()
            ->where('project_name', $projectName)
            ->where('uuid', $uuid)
            ->first();
    }

    private function findByProjectAndKey(string $projectName, string $key): ?WidgetLayout
    {
        return WidgetLayout::query()
            ->where('project_name', $projectName)
            ->where('key', $key)
            ->first();
    }

    /**
     * Cross-engine UNIQUE-violation detector -- the identical pattern
     * {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyService::isUniqueViolation()}
     * uses for `widget_idem_scope_key_unique`, applied here to
     * `widget_layout_project_key_unique`.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed') // SQLite
            || str_contains($message, 'Duplicate entry')          // MySQL
            || str_contains($message, 'duplicate key value')      // PostgreSQL
            || (string) $exception->getCode() === '23505';        // PostgreSQL SQLSTATE
    }
}

/**
 * Outcome of {@see WidgetLayoutBootstrapService::bootstrap()}. Co-located:
 * this task's file list has no separate slot for it, mirroring the
 * established co-location precedent in this module (e.g.
 * {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult}).
 */
final readonly class WidgetLayoutBootstrapResult
{
    private function __construct(
        public bool $created,
        public WidgetLayout $layout,
    ) {
    }

    public static function created(WidgetLayout $layout): self
    {
        return new self(true, $layout);
    }

    public static function existing(WidgetLayout $layout): self
    {
        return new self(false, $layout);
    }
}
