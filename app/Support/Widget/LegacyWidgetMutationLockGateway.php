<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\WidgetMutationLockGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bound default for WidgetMutationLockGateway. Reads/writes a single
 * generic marker column on the given Core-owned table. The marker column
 * does not exist on any table yet (it is added, if at all, by a future
 * Safe Import migration owned by Core, per adr-widget-module-boundary.md
 * decision 11) — until then every check is a safe, side-effect-free
 * `Schema::hasColumn()` guard that always answers "unlocked".
 */
final class LegacyWidgetMutationLockGateway implements WidgetMutationLockGateway
{
    /**
     * Stable, generic Core-owned marker column name. A future Safe Import
     * migration may add this column to widgets/widget_themes/widget_inputs;
     * this gateway is the only place that name is allowed to appear.
     */
    public const MARKER_COLUMN = 'safe_import_locked_at';

    /**
     * The only Core-owned legacy tables this gateway may ever touch. A
     * table name outside this allowlist is never queried or written —
     * the contract's $table parameter must not become an arbitrary-table
     * injection point.
     *
     * @var list<string>
     */
    private const ALLOWED_TABLES = ['widgets', 'widget_themes', 'widget_inputs'];

    public function isLocked(string $table, int $id): bool
    {
        if (!$this->isManagedTable($table)) {
            return false;
        }

        return DB::table($table)->where('id', $id)->whereNotNull(self::MARKER_COLUMN)->exists();
    }

    public function lock(string $table, int $id, string $reason): void
    {
        if (!$this->isManagedTable($table)) {
            return;
        }

        DB::table($table)->where('id', $id)->update([self::MARKER_COLUMN => now()]);
    }

    public function unlock(string $table, int $id): void
    {
        if (!$this->isManagedTable($table)) {
            return;
        }

        DB::table($table)->where('id', $id)->update([self::MARKER_COLUMN => null]);
    }

    private function isManagedTable(string $table): bool
    {
        return in_array($table, self::ALLOWED_TABLES, true)
            && Schema::hasColumn($table, self::MARKER_COLUMN);
    }
}
