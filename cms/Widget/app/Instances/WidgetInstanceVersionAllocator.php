<?php

declare(strict_types=1);

namespace Cms\Widget\Instances;

use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;

/**
 * Owns the "recheck expected state, then allocate MAX(version)+1" step for
 * an EXISTING Instance (plan Chunk 2 Task 16 checklist; architecture.md
 * "Instance create, clone, and update" step 5: "Lock the owning
 * widget_instances row FOR UPDATE, recheck the expected current Version,
 * and allocate MAX(version)+1 under that lock").
 *
 * {@see WidgetAggregateLockOrder} deliberately implements no action-specific
 * race behavior ("Expected-state recheck and actual mutation are the
 * calling action's own job" -- its own class docblock); this class is that
 * job for the Instance-update/repin family. It is used ONLY for
 * existing-Instance updates/repin, never for the atomic create/clone
 * Version-1 insert, which has no existing Instance row to lock in the
 * first place (architecture.md "Create and clone have no target Instance
 * row to lock").
 */
final class WidgetInstanceVersionAllocator
{
    public function __construct(private readonly WidgetAggregateLockOrder $lockOrder)
    {
    }

    /**
     * Lock the Instance row, recheck its CURRENT VERSION NUMBER (never the
     * internal `current_version_id` row id -- `widget_instance_versions`
     * carries no uuid of its own, so the version number scoped to its
     * Instance is the only client-facing identifier, matching this
     * codebase's UUID-first admin-API rule of never requiring a raw
     * internal row id from a caller) against `$expectedCurrentVersion`,
     * and -- only when it matches -- allocate the next version number and
     * invoke `$whileLocked` with the locked Instance and that number, all
     * still inside the same row lock/transaction. A stale expectation or a
     * missing Instance never invokes the callback and never allocates a
     * number, so a caller that inserts its new Version row only inside
     * `$whileLocked` naturally inserts nothing on either failure path.
     *
     * @param callable(WidgetInstance, int): mixed $whileLocked
     */
    public function allocate(int $instanceId, ?int $expectedCurrentVersion, callable $whileLocked): WidgetInstanceVersionAllocationResult
    {
        return $this->lockOrder->withLocks([], [$instanceId], function () use ($instanceId, $expectedCurrentVersion, $whileLocked): WidgetInstanceVersionAllocationResult {
            $instance = WidgetInstance::query()->find($instanceId);

            if ($instance === null) {
                return WidgetInstanceVersionAllocationResult::instanceNotFound();
            }

            $actualCurrentVersion = $instance->current_version_id === null
                ? null
                : WidgetInstanceVersion::query()->whereKey($instance->current_version_id)->value('version');
            $actualCurrentVersion = $actualCurrentVersion === null ? null : (int) $actualCurrentVersion;

            if ($actualCurrentVersion !== $expectedCurrentVersion) {
                return WidgetInstanceVersionAllocationResult::stale();
            }

            $nextVersion = $this->lockOrder->allocateNextInstanceVersion($instanceId);
            $value = $whileLocked($instance, $nextVersion);

            return WidgetInstanceVersionAllocationResult::allocated($instance, $nextVersion, $value);
        });
    }
}

/**
 * Outcome of {@see WidgetInstanceVersionAllocator::allocate()}. Co-located
 * because this task's allowed_paths has no separate slot for it, mirroring
 * the established co-location precedent in this module (e.g.
 * {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult},
 * co-located inside WidgetIdempotencyService.php for the identical reason).
 */
final readonly class WidgetInstanceVersionAllocationResult
{
    private const STATUS_ALLOCATED = 'allocated';

    private const STATUS_STALE = 'stale';

    private const STATUS_NOT_FOUND = 'not_found';

    private function __construct(
        public string $status,
        public ?WidgetInstance $instance = null,
        public ?int $version = null,
        public mixed $value = null,
    ) {
    }

    public static function allocated(WidgetInstance $instance, int $version, mixed $value): self
    {
        return new self(self::STATUS_ALLOCATED, $instance, $version, $value);
    }

    public static function stale(): self
    {
        return new self(self::STATUS_STALE);
    }

    public static function instanceNotFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
    }

    public function isAllocated(): bool
    {
        return $this->status === self::STATUS_ALLOCATED;
    }

    public function isStale(): bool
    {
        return $this->status === self::STATUS_STALE;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }
}
