<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Carbon\CarbonImmutable;
use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TrackingEventReceipt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_QUARANTINED = 'quarantined';

    protected $table = Tables::TRACKING_EVENT_RECEIPTS;

    protected $fillable = [
        'event_id',
        'envelope_version',
        'write_generation',
        'generation_mode',
        'dispatched_at',
        'occurred_at',
        'replay_until',
        'event_date',
        'target_key',
        'target_type',
        'target_id',
        'target_kind',
        'display_label',
        'public_path',
        'is_rankable',
        'counter_deltas',
        'throttle_contract_version',
        'throttle_decided_at',
        'status',
        'quarantine_reason',
        'resolution_status',
        'compensating_event_id',
        'resolution_manifest_id',
        'resolved_at',
        'dispatch_attempts',
        'last_enqueued_at',
        'processed_at',
        'expires_at',
        'legacy_ip_address',
        'legacy_agent',
        'legacy_url',
        'legacy_session_id',
        'legacy_tags',
    ];

    protected $casts = [
        'envelope_version' => 'integer',
        'write_generation' => 'integer',
        'dispatched_at' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
        'replay_until' => 'immutable_datetime',
        'event_date' => 'immutable_date',
        'target_id' => 'integer',
        'is_rankable' => 'boolean',
        'counter_deltas' => 'array',
        'throttle_contract_version' => 'integer',
        'throttle_decided_at' => 'immutable_datetime',
        'dispatch_attempts' => 'integer',
        'last_enqueued_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'legacy_tags' => 'array',
    ];

    /**
     * Fields captured at admission may never be reinterpreted by a retry.
     *
     * @var list<string>
     */
    private const IMMUTABLE_ENVELOPE_FIELDS = [
        'event_id',
        'envelope_version',
        'write_generation',
        'generation_mode',
        'dispatched_at',
        'occurred_at',
        'replay_until',
        'event_date',
        'target_key',
        'target_type',
        'target_id',
        'target_kind',
        'display_label',
        'public_path',
        'is_rankable',
        'counter_deltas',
        'throttle_contract_version',
        'throttle_decided_at',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $receipt): void {
            foreach (self::IMMUTABLE_ENVELOPE_FIELDS as $field) {
                if ($receipt->isDirty($field)) {
                    throw new LogicException("Tracking receipt envelope field [{$field}] is immutable.");
                }
            }
        });
    }

    /**
     * Seconds to wait before re-enqueueing a pending receipt that was already enqueued `$dispatchAttempts` times.
     *
     * The wait doubles per attempt up to `tracking.pending_dispatch.max_backoff_seconds`: a receipt that stays
     * pending because no worker consumes the queue would otherwise be re-enqueued every minute until its replay
     * window ends (about 10,000 duplicate jobs per receipt over 7 days; dev reached 4.7M jobs for 518 receipts).
     * With a healthy worker a receipt is processed after its first enqueue, so nothing changes.
     *
     * @param int $dispatchAttempts Times the receipt was already enqueued.
     * @param int $baseSeconds Base backoff (`tracking.pending_dispatch.backoff_seconds`).
     * @return int
     */
    public static function pendingDispatchBackoffSeconds(int $dispatchAttempts, int $baseSeconds): int
    {
        $base = max(0, $baseSeconds);
        $max = max($base, (int) config('tracking.pending_dispatch.max_backoff_seconds', 3600));
        if ($dispatchAttempts <= 1 || $base === 0) {
            return $base;
        }

        return (int) min($max, $base * (2 ** min(20, $dispatchAttempts - 1)));
    }

    public static function claimPendingDispatch(
        string $eventId,
        CarbonImmutable $now,
        int $backoffSeconds
    ): bool {
        return DB::transaction(
            static function () use ($eventId, $backoffSeconds, $now): bool {
                $receipt = self::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();
                if (
                    $receipt === null
                    || $receipt->status !== self::STATUS_PENDING
                    || (
                        $receipt->last_enqueued_at !== null
                        && $receipt->last_enqueued_at->greaterThan($now->subSeconds(
                            self::pendingDispatchBackoffSeconds((int) $receipt->dispatch_attempts, $backoffSeconds)
                        ))
                    )
                ) {
                    return false;
                }

                return self::query()
                    ->whereKey($receipt->getKey())
                    ->update([
                        'dispatch_attempts' => DB::raw('dispatch_attempts + 1'),
                        'last_enqueued_at' => $now,
                        'updated_at' => $now,
                    ]) === 1;
            },
            3
        );
    }
}
