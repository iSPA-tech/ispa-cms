<?php

namespace App\Services\Tracking;

use App\Events\SaveTracking;
use App\Jobs\ProcessTrackingEvent;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TrackingEventDispatcher
{
    public function __construct(
        private readonly TrackingTargetKeyResolver $targets,
        private readonly TrackingCounterDeltaResolver $counters,
        private readonly TrackingClaimStore $claims,
        private readonly TrackingBufferedCounterStore $buffer,
    ) {
    }

    public function dispatch(SaveTracking $event): bool
    {
        if (!config('tracking.enabled', true) || $this->isBot($event->agent)) {
            return false;
        }

        $claimKeys = [];

        try {
            $target = $this->targets->resolveEvent($event);
            $deltas = $this->counters->resolve($event->tags);
            $throttleKey = $this->throttleKey($event, $target, $deltas);
            if (!$this->claims->claim(
                $throttleKey,
                $event->eventId,
                (int) config('tracking.throttle_seconds', 30)
            )) {
                return false;
            }
            $claimKeys[] = $throttleKey;

            [$deltas, $dedupeClaims] = $this->finalizeDailyDedupe($event, $target, $deltas);
            $claimKeys = [...$claimKeys, ...$dedupeClaims];

            if ($this->canBuffer($deltas)) {
                try {
                    $result = $this->buffer->record([
                        'event_id' => $event->eventId,
                        'occurred_at' => $event->occurredAt,
                        'target_key' => $target['target_key'] ?? null,
                        'target_type' => $event->type,
                        'target_id' => $event->id > 0 ? $event->id : null,
                        'target_kind' => $target['target_kind'] ?? null,
                        'display_label' => $target['display_label'] ?? null,
                        'public_path' => $target['public_path'] ?? null,
                        'is_rankable' => $target['is_rankable'] ?? false,
                        'deltas' => $deltas,
                    ]);
                    if (in_array($result, [
                        TrackingBufferedCounterStore::RESULT_BUFFERED,
                        TrackingBufferedCounterStore::RESULT_DUPLICATE,
                    ], true)) {
                        return true;
                    }
                } catch (Throwable $exception) {
                    Log::warning('tracking.buffer_fallback', [
                        'event_id' => $event->eventId,
                        'exception' => $exception::class,
                    ]);
                }
            }

            $existing = TrackingEventReceipt::query()->where('event_id', $event->eventId)->first();
            if ($existing !== null) {
                $this->dispatchJobAfterCommit($event->eventId);

                return true;
            }

            $dispatchedAt = CarbonImmutable::now('UTC');
            $replayUntil = $dispatchedAt->addDays((int) config('tracking.manual_replay_days', 7));
            $eventDate = $event->occurredAt
                ->setTimezone((string) config('tracking.analytics_timezone'))
                ->toDateString();
            $connection = DB::connection();
            $hasOuterTransaction = $connection->transactionLevel() > 0;
            if ($hasOuterTransaction) {
                $connection->afterRollBack(function () use ($claimKeys, $event): void {
                    $this->releaseClaims($claimKeys, $event->eventId);
                });
            }

            DB::transaction(function () use (
                $event,
                $target,
                $deltas,
                $claimKeys,
                $dispatchedAt,
                $replayUntil,
                $eventDate,
                $hasOuterTransaction
            ): void {
                $state = TrackingRuntimeState::query()->whereKey(1)->sharedLock()->firstOrFail();
                $legacyEnvelope = in_array($state->mode, [
                    TrackingRuntimeState::MODE_LEGACY,
                    TrackingRuntimeState::MODE_DUAL,
                ], true);

                TrackingEventReceipt::query()->create([
                    'event_id' => $event->eventId,
                    'envelope_version' => (int) config('tracking.envelope_version', 1),
                    'write_generation' => $state->write_generation,
                    'generation_mode' => $state->mode,
                    'dispatched_at' => $dispatchedAt,
                    'occurred_at' => $event->occurredAt,
                    'replay_until' => $replayUntil,
                    'event_date' => $eventDate,
                    'target_key' => $target['target_key'] ?? null,
                    'target_type' => $event->type,
                    'target_id' => $event->id > 0 ? $event->id : null,
                    'target_kind' => $target['target_kind'] ?? null,
                    'display_label' => $target['display_label'] ?? null,
                    'public_path' => $target['public_path'] ?? null,
                    'is_rankable' => $target['is_rankable'] ?? false,
                    'counter_deltas' => $deltas,
                    'throttle_contract_version' => (int) config('tracking.throttle_contract_version', 1),
                    'throttle_decided_at' => $dispatchedAt,
                    'status' => TrackingEventReceipt::STATUS_PENDING,
                    'expires_at' => $replayUntil->addHours(
                        (int) config('tracking.receipt_safety_hours', 24)
                    ),
                    'legacy_ip_address' => $legacyEnvelope ? $event->ip : null,
                    'legacy_agent' => $legacyEnvelope ? $event->agent : null,
                    'legacy_url' => $legacyEnvelope ? self::storableUrl($event->url) : null,
                    'legacy_session_id' => $legacyEnvelope ? $event->sessionId : null,
                    'legacy_tags' => $legacyEnvelope ? $event->tags : null,
                ]);

                if (!$hasOuterTransaction) {
                    DB::connection()->afterRollBack(function () use ($claimKeys, $event): void {
                        $this->releaseClaims($claimKeys, $event->eventId);
                    });
                }

                $this->dispatchJobAfterCommit($event->eventId);
            });

            return true;
        } catch (Throwable $exception) {
            $this->releaseClaims($claimKeys, $event->eventId);
            Log::error('tracking.admission_failed', [
                'event_id' => $event->eventId,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** @param array<string, int> $deltas */
    private function canBuffer(array $deltas): bool
    {
        if (!config('tracking.buffer.enabled', false) || DB::transactionLevel() > 0) {
            return false;
        }

        foreach (['scans', 'place_scans', 'item_scans', 'downloads'] as $counter) {
            if (($deltas[$counter] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $target
     * @param array<string, int> $deltas
     */
    private function throttleKey(SaveTracking $event, ?array $target, array $deltas): string
    {
        $identity = $event->sessionId ?: $event->ip ?: 'unidentified';
        $fingerprint = $this->hmac("session:{$identity}");
        $scope = $target['target_key'] ?? 'unclassified';
        $contract = $this->counters->contract($deltas);

        return 'tracking:throttle:' . $this->hmac("{$fingerprint}|{$contract}|{$scope}");
    }

    /**
     * @param array<string, mixed>|null $target
     * @param array<string, int> $deltas
     * @return array{array<string, int>, list<string>}
     */
    private function finalizeDailyDedupe(
        SaveTracking $event,
        ?array $target,
        array $deltas
    ): array {
        $eventDate = $event->occurredAt
            ->setTimezone((string) config('tracking.analytics_timezone'))
            ->toDateString();
        $ttl = (int) config('tracking.daily_dedupe_seconds', 172800);
        $claimKeys = [];
        $scope = $target['target_key'] ?? null;

        $deltas['site_sessions'] = 0;
        $deltas['site_visitors'] = 0;
        $deltas['target_sessions'] = 0;
        $deltas['target_visitors'] = 0;

        if ($event->sessionId) {
            $sessionFingerprint = $this->hmac("session:{$event->sessionId}");
            $siteKey = "tracking:unique:session:{$eventDate}:site:{$sessionFingerprint}";
            if ($this->claims->claim($siteKey, $event->eventId, $ttl)) {
                $deltas['site_sessions'] = 1;
                $claimKeys[] = $siteKey;
            }

            if ($scope !== null) {
                $targetKey = 'tracking:unique:session:' . $eventDate . ':'
                    . $this->hmac($scope) . ":{$sessionFingerprint}";
                if ($this->claims->claim($targetKey, $event->eventId, $ttl)) {
                    $deltas['target_sessions'] = 1;
                    $claimKeys[] = $targetKey;
                }
            }
        }

        if ($event->ip) {
            $visitorFingerprint = $this->hmac("visitor:{$eventDate}:{$event->ip}");
            $siteKey = "tracking:unique:visitor:{$eventDate}:site:{$visitorFingerprint}";
            if ($this->claims->claim($siteKey, $event->eventId, $ttl)) {
                $deltas['site_visitors'] = 1;
                $claimKeys[] = $siteKey;
            }

            if ($scope !== null) {
                $targetKey = 'tracking:unique:visitor:' . $eventDate . ':'
                    . $this->hmac($scope) . ":{$visitorFingerprint}";
                if ($this->claims->claim($targetKey, $event->eventId, $ttl)) {
                    $deltas['target_visitors'] = 1;
                    $claimKeys[] = $targetKey;
                }
            }
        }

        return [$deltas, $claimKeys];
    }

    /**
     * @param list<string> $claimKeys
     */
    private function releaseClaims(array $claimKeys, string $owner): void
    {
        foreach ($claimKeys as $key) {
            try {
                $this->claims->release($key, $owner);
            } catch (Throwable $exception) {
                Log::warning('tracking.claim_release_failed', [
                    'event_id' => $owner,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function isBot(?string $agent): bool
    {
        return is_string($agent)
            && Str::contains(Str::lower($agent), ['bot', 'spider', 'crawl']);
    }

    private function dispatchJobAfterCommit(string $eventId): void
    {
        $enqueue = static function () use ($eventId): void {
            $now = CarbonImmutable::now('UTC');
            $backoff = (int) config('tracking.pending_dispatch.backoff_seconds', 60);

            if (!TrackingEventReceipt::claimPendingDispatch($eventId, $now, $backoff)) {
                return;
            }

            // Unique per event id (ShouldBeUnique): a job that is still queued for this receipt wins.
            ProcessTrackingEvent::dispatch($eventId)->onQueue('tracking');
        };

        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            $enqueue();

            return;
        }

        $connection->afterCommit($enqueue);
    }

    /**
     * The page url without its query string and fragment. The query carries search terms and other visitor input
     * that must not be kept in the tracks table; the target resolver still sees the full url before this.
     *
     * @param string|null $url Raw page url.
     * @return string|null
     */
    private static function storableUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }
}
