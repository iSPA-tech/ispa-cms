<?php

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Apply only additive tracking storage changes.
     */
    public function up(): void
    {
        $this->createTargetDailyTable();
        $this->extendTrackingDaily();
        $this->extendTrackingTop();
        $this->createSiteTotals();
        $this->createTargetTotals();
        $this->createTargetCatalog();
        $this->createRuntimeState();
        $this->createGenerationRuns();
        $this->createEventReceipts();
    }

    /**
     * Remove only structures introduced by this migration.
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::TRACKING_EVENT_RECEIPTS);
        Schema::dropIfExists(Tables::TRACKING_GENERATION_RUNS);
        Schema::dropIfExists(Tables::TRACKING_RUNTIME_STATE);
        Schema::dropIfExists(Tables::TRACKING_TARGET_CATALOG);
        Schema::dropIfExists(Tables::TRACKING_TARGET_TOTALS);
        Schema::dropIfExists(Tables::TRACKING_SITE_TOTALS);

        if (
            Schema::hasTable(Tables::TRACKING_TOP)
            && Schema::hasColumn(Tables::TRACKING_TOP, 'target_key')
            && Schema::hasIndex(Tables::TRACKING_TOP, 'tracking_top_target_key_views_idx')
        ) {
            Schema::table(Tables::TRACKING_TOP, static function (Blueprint $table): void {
                $table->dropIndex('tracking_top_target_key_views_idx');
                $table->dropColumn('target_key');
            });
        }

        if (Schema::hasTable(Tables::TRACKING_DAILY)) {
            $columns = array_values(array_filter([
                'is_closed',
                'aggregate_version',
                'aggregated_at',
                'last_applied_at',
                'late_event_count',
            ], static fn (string $column): bool => Schema::hasColumn(Tables::TRACKING_DAILY, $column)));

            if ($columns !== []) {
                Schema::table(Tables::TRACKING_DAILY, static function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }

        Schema::dropIfExists(Tables::TRACKING_TARGET_DAILY);
    }

    private function createTargetDailyTable(): void
    {
        if (Schema::hasTable(Tables::TRACKING_TARGET_DAILY)) {
            return;
        }

        Schema::create(Tables::TRACKING_TARGET_DAILY, static function (Blueprint $table): void {
            $table->id();
            $table->date('date')->nullable();
            $table->string('target_key', 191)->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->bigInteger('total_views')->default(0);
            $table->bigInteger('total_sessions')->default(0);
            $table->bigInteger('total_visitors')->default(0);
            $table->bigInteger('total_scans')->default(0);
            $table->bigInteger('total_downloads')->default(0);
            $table->unsignedSmallInteger('aggregate_version')->default(1);
            $table->timestamps();

            $table->index(['target_key', 'date'], 'tracking_target_daily_key_date_idx');
            $table->index(['target_type', 'target_id', 'date'], 'tracking_target_daily_morph_date_idx');
        });
    }

    private function extendTrackingDaily(): void
    {
        if (!Schema::hasTable(Tables::TRACKING_DAILY)) {
            return;
        }

        $existingColumns = Schema::getColumnListing(Tables::TRACKING_DAILY);
        $additiveCounters = [
            'views',
            'visitors',
            'place_scan',
            'item_scan',
            'scans',
            'new_register',
            'login',
            'logout',
            'subscribe',
            'unsubscribe',
            'new_order',
            'income',
            'comments',
            'download',
            'sessions',
            'payments',
        ];

        Schema::table(Tables::TRACKING_DAILY, static function (Blueprint $table) use (
            $existingColumns,
            $additiveCounters
        ): void {
            foreach ($additiveCounters as $column) {
                if (in_array($column, $existingColumns, true)) {
                    $table->bigInteger($column)->default(0)->change();
                }
            }

            if (!in_array('is_closed', $existingColumns, true)) {
                $table->boolean('is_closed')->default(false);
            }
            if (!in_array('aggregate_version', $existingColumns, true)) {
                $table->unsignedSmallInteger('aggregate_version')->default(1);
            }
            if (!in_array('aggregated_at', $existingColumns, true)) {
                $table->timestamp('aggregated_at')->nullable();
            }
            if (!in_array('last_applied_at', $existingColumns, true)) {
                $table->timestamp('last_applied_at')->nullable();
            }
            if (!in_array('late_event_count', $existingColumns, true)) {
                $table->bigInteger('late_event_count')->default(0);
            }
        });
    }

    private function extendTrackingTop(): void
    {
        if (!Schema::hasTable(Tables::TRACKING_TOP)) {
            return;
        }

        $hasTargetKey = Schema::hasColumn(Tables::TRACKING_TOP, 'target_key');
        $hasTotalViews = Schema::hasColumn(Tables::TRACKING_TOP, 'total_views');

        Schema::table(Tables::TRACKING_TOP, static function (Blueprint $table) use (
            $hasTargetKey,
            $hasTotalViews
        ): void {
            if ($hasTotalViews) {
                $table->bigInteger('total_views')->default(0)->change();
            }

            if (!$hasTargetKey) {
                $table->string('target_key', 191)->nullable();
                $table->index(['target_key', 'total_views'], 'tracking_top_target_key_views_idx');
            }
        });
    }

    private function createSiteTotals(): void
    {
        if (!Schema::hasTable(Tables::TRACKING_SITE_TOTALS)) {
            Schema::create(Tables::TRACKING_SITE_TOTALS, static function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->bigInteger('baseline_views')->default(0);
                $table->bigInteger('live_views')->default(0);
                $table->bigInteger('baseline_scans')->default(0);
                $table->bigInteger('live_scans')->default(0);
                $table->string('baseline_quality', 24)->default('unavailable');
                $table->string('scans_quality', 24)->default('unavailable');
                $table->string('baseline_manifest_id')->nullable();
                $table->char('baseline_manifest_checksum', 64)->nullable();
                $table->timestamp('baseline_as_of')->nullable();
                $table->date('accurate_from_date')->nullable();
                $table->timestamps();
            });
        }

        DB::table(Tables::TRACKING_SITE_TOTALS)->insertOrIgnore([
            'id' => 1,
            'baseline_views' => 0,
            'live_views' => 0,
            'baseline_scans' => 0,
            'live_scans' => 0,
            'baseline_quality' => 'unavailable',
            'scans_quality' => 'unavailable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTargetTotals(): void
    {
        if (Schema::hasTable(Tables::TRACKING_TARGET_TOTALS)) {
            return;
        }

        Schema::create(Tables::TRACKING_TARGET_TOTALS, static function (Blueprint $table): void {
            $table->id();
            $table->string('target_key', 191)->unique();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->bigInteger('baseline_views')->default(0);
            $table->bigInteger('live_views')->default(0);
            $table->bigInteger('baseline_scans')->default(0);
            $table->bigInteger('live_scans')->default(0);
            $table->string('baseline_quality', 24)->default('unavailable');
            $table->string('scans_quality', 24)->default('unavailable');
            $table->string('baseline_manifest_id')->nullable();
            $table->char('baseline_manifest_checksum', 64)->nullable();
            $table->timestamp('baseline_as_of')->nullable();
            $table->date('accurate_from_date')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id'], 'tracking_target_totals_morph_idx');
        });
    }

    private function createTargetCatalog(): void
    {
        if (Schema::hasTable(Tables::TRACKING_TARGET_CATALOG)) {
            return;
        }

        Schema::create(Tables::TRACKING_TARGET_CATALOG, static function (Blueprint $table): void {
            $table->id();
            $table->string('target_key', 191)->unique();
            $table->string('target_kind', 16);
            $table->string('display_label')->nullable();
            $table->text('public_path')->nullable();
            $table->boolean('is_rankable')->default(false);
            $table->timestamps();

            $table->index(['is_rankable', 'target_kind'], 'tracking_catalog_rankable_kind_idx');
        });
    }

    private function createRuntimeState(): void
    {
        if (!Schema::hasTable(Tables::TRACKING_RUNTIME_STATE)) {
            Schema::create(Tables::TRACKING_RUNTIME_STATE, static function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('write_generation')->default(1);
                $table->string('mode', 16)->default('legacy');
                $table->string('processing_state', 16)->default('open');
                $table->string('direct_counter_owner', 16)->default('legacy');
                $table->date('first_direct_write_date')->nullable();
                $table->timestamp('generation_opened_at')->nullable();
                $table->string('changed_by')->nullable();
                $table->text('change_reason')->nullable();
                $table->timestamps();
            });
        }

        DB::table(Tables::TRACKING_RUNTIME_STATE)->insertOrIgnore([
            'id' => 1,
            'write_generation' => 1,
            'mode' => 'legacy',
            'processing_state' => 'open',
            'direct_counter_owner' => 'legacy',
            'generation_opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createGenerationRuns(): void
    {
        if (Schema::hasTable(Tables::TRACKING_GENERATION_RUNS)) {
            return;
        }

        Schema::create(Tables::TRACKING_GENERATION_RUNS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('rotation_id')->unique();
            $table->unsignedTinyInteger('active_rotation_key')->nullable()->unique();
            $table->string('status', 32);
            $table->unsignedBigInteger('closed_generation');
            $table->string('closed_mode', 16);
            $table->unsignedBigInteger('first_ledger_id')->nullable();
            $table->unsignedBigInteger('maximum_ledger_id')->nullable();
            $table->unsignedBigInteger('next_held_generation');
            $table->dateTime('barrier_started_at');
            $table->string('manifest_state', 24)->default('pending');
            $table->string('manifest_id')->nullable();
            $table->char('manifest_checksum', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'closed_generation'], 'tracking_generation_runs_status_idx');
        });
    }

    private function createEventReceipts(): void
    {
        if (Schema::hasTable(Tables::TRACKING_EVENT_RECEIPTS)) {
            return;
        }

        Schema::create(Tables::TRACKING_EVENT_RECEIPTS, static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id', 36)->unique();
            $table->unsignedSmallInteger('envelope_version');
            $table->unsignedBigInteger('write_generation');
            $table->string('generation_mode', 16);
            $table->dateTime('dispatched_at');
            $table->dateTime('occurred_at');
            $table->dateTime('replay_until');
            $table->date('event_date')->nullable();
            $table->string('target_key', 191)->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_kind', 16)->nullable();
            $table->string('display_label')->nullable();
            $table->text('public_path')->nullable();
            $table->boolean('is_rankable')->default(false);
            $table->json('counter_deltas');
            $table->unsignedSmallInteger('throttle_contract_version');
            $table->dateTime('throttle_decided_at');
            $table->string('status', 16)->default('pending');
            $table->string('quarantine_reason')->nullable();
            $table->string('resolution_status', 32)->nullable();
            $table->string('compensating_event_id', 36)->nullable();
            $table->string('resolution_manifest_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('last_enqueued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->dateTime('expires_at');
            $table->ipAddress('legacy_ip_address')->nullable();
            $table->text('legacy_agent')->nullable();
            $table->text('legacy_url')->nullable();
            $table->string('legacy_session_id')->nullable();
            $table->json('legacy_tags')->nullable();
            $table->timestamps();

            $table->index(
                ['write_generation', 'status', 'id'],
                'tracking_receipts_generation_status_idx'
            );
            $table->index(
                ['status', 'last_enqueued_at', 'id'],
                'tracking_receipts_pending_scan_idx'
            );
            $table->index(['status', 'replay_until'], 'tracking_receipts_replay_idx');
            $table->index(['status', 'expires_at'], 'tracking_receipts_expiry_idx');
            $table->index('compensating_event_id', 'tracking_receipts_compensation_idx');
        });
    }
};
