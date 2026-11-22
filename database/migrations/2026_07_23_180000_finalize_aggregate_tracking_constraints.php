<?php

use App\Constants\Tables;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->normalizeTrackingDaily();
        $this->assertCleanData();

        Schema::table(Tables::TRACKING_DAILY, static function (Blueprint $table): void {
            $table->date('date')->nullable(false)->change();
            $table->unique('date', 'tracking_daily_date_unique');
        });
        Schema::table(Tables::TRACKING_TARGET_DAILY, static function (Blueprint $table): void {
            $table->date('date')->nullable(false)->change();
            $table->string('target_key', 191)->nullable(false)->change();
            $table->unique(
                ['date', 'target_key'],
                'tracking_target_daily_date_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table(Tables::TRACKING_TARGET_DAILY, static function (Blueprint $table): void {
            $table->dropUnique('tracking_target_daily_date_key_unique');
            $table->date('date')->nullable()->change();
            $table->string('target_key', 191)->nullable()->change();
        });
        Schema::table(Tables::TRACKING_DAILY, static function (Blueprint $table): void {
            $table->dropUnique('tracking_daily_date_unique');
            $table->date('date')->nullable()->change();
        });
    }

    private function assertCleanData(): void
    {
        if (DB::table(Tables::TRACKING_DAILY)->whereNull('date')->exists()) {
            throw new RuntimeException('null tracking_daily dates remain');
        }
        if ($this->hasDuplicate(Tables::TRACKING_DAILY, ['date'])) {
            throw new RuntimeException('duplicate tracking_daily dates remain');
        }
        if (DB::table(Tables::TRACKING_TARGET_DAILY)->whereNull('date')->exists()) {
            throw new RuntimeException('null canonical target daily dates remain');
        }
        if (DB::table(Tables::TRACKING_TARGET_DAILY)->whereNull('target_key')->exists()) {
            throw new RuntimeException('null canonical target keys remain');
        }
        if ($this->hasDuplicate(Tables::TRACKING_TARGET_DAILY, ['date', 'target_key'])) {
            throw new RuntimeException('duplicate canonical target daily keys remain');
        }
    }

    private function normalizeTrackingDaily(): void
    {
        if (!Schema::hasTable(Tables::TRACKING_DAILY) || !Schema::hasColumn(Tables::TRACKING_DAILY, 'date')) {
            return;
        }

        DB::transaction(function (): void {
            $columns = array_flip(Schema::getColumnListing(Tables::TRACKING_DAILY));
            $rows = DB::table(Tables::TRACKING_DAILY)->orderBy('id')->get();
            $groups = [];

            foreach ($rows as $row) {
                $date = $this->resolveDate($row);
                $groups[$date][] = $row;
            }

            foreach ($groups as $date => $group) {
                $rowsForDate = collect($group);
                $keeper = $rowsForDate->first(
                    static fn (object $row): bool => $row->date !== null
                ) ?? $rowsForDate->first();
                $updates = ['date' => $date];

                foreach ([
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
                    'late_event_count',
                ] as $counter) {
                    if (isset($columns[$counter])) {
                        $updates[$counter] = $rowsForDate->sum(
                            static fn (object $row): int => (int) ($row->{$counter} ?? 0)
                        );
                    }
                }

                if (isset($columns['is_closed'])) {
                    $updates['is_closed'] = $rowsForDate->contains(
                        static fn (object $row): bool => (bool) ($row->is_closed ?? false)
                    );
                }
                if (isset($columns['aggregate_version'])) {
                    $updates['aggregate_version'] = $rowsForDate->max(
                        static fn (object $row): int => (int) ($row->aggregate_version ?? 1)
                    );
                }
                foreach (['aggregated_at', 'last_applied_at'] as $timestamp) {
                    if (isset($columns[$timestamp])) {
                        $value = $this->latestTimestamp($rowsForDate, $timestamp);
                        if ($value !== null) {
                            $updates[$timestamp] = $value;
                        }
                    }
                }
                if (isset($columns['created_at'])) {
                    $createdAt = $this->earliestTimestamp($rowsForDate, 'created_at');
                    if ($createdAt !== null) {
                        $updates['created_at'] = $createdAt;
                    }
                }
                if (isset($columns['updated_at'])) {
                    $updates['updated_at'] = now();
                }

                DB::table(Tables::TRACKING_DAILY)
                    ->where('id', $keeper->id)
                    ->update($updates);

                $duplicateIds = $rowsForDate
                    ->reject(static fn (object $row): bool => (int) $row->id === (int) $keeper->id)
                    ->pluck('id')
                    ->all();
                if ($duplicateIds !== []) {
                    DB::table(Tables::TRACKING_DAILY)->whereIn('id', $duplicateIds)->delete();
                }
            }
        });
    }

    private function resolveDate(object $row): string
    {
        $value = $row->date ?? $row->updated_at ?? $row->created_at ?? null;
        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException(
                'tracking_daily row '.$row->id.' has no date, updated_at, or created_at to normalize'
            );
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function latestTimestamp(Collection $rows, string $column): ?string
    {
        $values = $rows
            ->pluck($column)
            ->filter(static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        return $values === [] ? null : max($values);
    }

    private function earliestTimestamp(Collection $rows, string $column): ?string
    {
        $values = $rows
            ->pluck($column)
            ->filter(static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        return $values === [] ? null : min($values);
    }

    /**
     * @param list<string> $columns
     */
    private function hasDuplicate(string $table, array $columns): bool
    {
        return DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();
    }
};
