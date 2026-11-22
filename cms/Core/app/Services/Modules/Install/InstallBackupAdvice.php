<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use Closure;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\ServerNightlyStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether the install dialog should switch the pre-install backup on by default, and when the site last backed up
 * on its own (, the safety backup before an install or upgrade is the installer's
 * choice, because sites already run scheduled backups).
 *
 * Default ON (`recommended = true`) when:
 *  - the version declares irreversible migrations (`irreversible`);
 *  - its migration list is not known (`unknown`: a hub that predates the catalog `migrations` field);
 * - a first install finds tables of an earlier install still in the database (`earlier_data`,): an uninstall
 *    that keeps the data leaves the module's tables and their migration rows, so a `create_<table>_table` the site
 *    has already run and whose table still exists means old records (orders, products) the module will use again;
 *  - a migration this site has not run yet changes a table that already exists (`alters_existing`): anything that is
 *    not `<timestamp>_create_<table>_table` for a table this database does not have yet.
 * On a first install, a `create_<table>_table` the site has NOT run whose table already exists is a conflict
 * (`conflicting_tables`): Laravel's migrator would run that `create` and stop with "table already exists", leaving the
 * module half migrated, so InstallPreflight blocks the install (MODULE_TABLE_EXISTS).
 * Default OFF when the version brings no new migration (`no_new_migrations`) or its new migrations only create the
 * module's own new tables (`own_tables_only`). The verdict reads migration names only (a static check: a
 * `create_<x>_table` file that also alters another table is still read as creating one); the person installing
 * decides in the end. Configuration package imports, purges and uninstalls keep their mandatory backup through BackupGate.
 */
final class InstallBackupAdvice
{
    public const REASON_IRREVERSIBLE = 'irreversible';
    public const REASON_UNKNOWN = 'unknown';
    public const REASON_ALTERS_EXISTING = 'alters_existing';
    public const REASON_EARLIER_DATA = 'earlier_data';
    public const REASON_OWN_TABLES_ONLY = 'own_tables_only';
    public const REASON_NO_NEW_MIGRATIONS = 'no_new_migrations';

    /** Most migration names one catalog entry or manifest is read for. */
    public const MAX_MIGRATIONS = 500;

    /** A package file that is a migration: `[<Module>/]database/migrations/<name>.php`. */
    private const MIGRATION_FILE = '#^(?:[A-Za-z0-9]+/)?database/migrations/([A-Za-z0-9_.\-]{1,200})\.php$#';

    /** A migration that only creates one table: `<yyyy_mm_dd_hhmmss>_create_<table>_table`. */
    private const CREATE_TABLE = '/^\d{4}_\d{2}_\d{2}_\d{6}_create_([a-z0-9_]+)_table$/';

    /**
     * Migration names of a signed manifest's file list (keys are package paths); null (unknown) when there are more
     * than MAX_MIGRATIONS.
     *
     * @param array<string, string> $files relative path => sha256
     *
     * @return list<string>|null
     */
    public static function migrationsFromFiles(array $files): ?array
    {
        $names = [];
        foreach (array_keys($files) as $path) {
            if (preg_match(self::MIGRATION_FILE, (string) $path, $m) === 1) {
                $names[] = $m[1];
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        // A cut list would hide the newest (not yet run) migrations: read it as unknown instead.
        return count($names) > self::MAX_MIGRATIONS ? null : $names;
    }

    /**
     * Migration names of a hub catalog `migrations` value, or null (unknown) when the hub sent none, too many or an invalid entry.
     *
     * @return list<string>|null
     */
    public static function migrationsFromCatalog(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_MIGRATIONS) {
            return null;
        }
        foreach ($value as $name) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9_.\-]{1,200}$/', $name) !== 1) {
                // One unreadable entry makes the list untrustworthy: unknown, so the switch defaults on.
                return null;
            }
        }

        return array_values($value);
    }

    /**
     * The default of the backup switch, why, and what the version changes in the database (owner addendum
     * 2026-11-14: the drawer warns before anything is downloaded).
     *
     * `changes_database` is false only when the migration list is known and nothing new runs; `altered_tables` names
     * existing tables the new migrations change, read from Laravel migration names (`add_x_to_<table>_table`,
     * `..._in|on|from_<table>_table`, `alter|update|change|modify|drop|rename_<table>_table`, or `create_<table>_table`
     * for a table that already exists); a name that says nothing about its table adds none.
     * On a first install only: `earlier_tables` names existing tables whose `create_<table>_table` the site
     * has already run (data kept from an earlier install), `conflicting_tables` existing tables whose `create` has not
     * run (the install would fail). Both are empty for an upgrade, where the tables are the module's own live data.
     *
     * @param bool               $irreversible the version declares irreversible migrations
     * @param list<string>|null  $migrations   migration names the version ships, null when not known
     * @param list<string>       $ran          migration names this site has run
     * @param (Closure(string): bool)|null $hasTable table check (default: Schema::hasTable)
     * @param bool               $firstInstall the module is not installed yet: a migration that changes a table the same
     * package creates now is never an alteration, and tables left by an
     * earlier install are reported. The store's technical sheet keeps
     *                                         describing the package alone; only this advice reads the site's database.
     *
     * @return array{recommended: bool, reason: string, new_migrations: int, changes_database: bool, altered_tables: list<string>, earlier_tables: list<string>, conflicting_tables: list<string>}
     */
    public static function advise(bool $irreversible, ?array $migrations, array $ran, ?Closure $hasTable = null, bool $firstInstall = false): array
    {
        $hasTable ??= static function (string $table): bool {
            try {
                return Schema::hasTable($table);
            } catch (Throwable) {
                return true;
            }
        };
        $new = $migrations === null ? [] : array_values(array_diff($migrations, $ran));

        // Tables this package creates now: a later migration of the same package that changes them alters nothing old.
        $own = [];
        foreach ($new as $name) {
            if (preg_match(self::CREATE_TABLE, $name, $m) === 1 && ! $hasTable($m[1])) {
                $own[] = $m[1];
            }
        }

        // First install over a database that already has tables the package creates.
        $earlier = [];
        $conflicts = [];
        if ($firstInstall && $migrations !== null) {
            foreach ($migrations as $name) {
                if (preg_match(self::CREATE_TABLE, $name, $m) !== 1 || ! $hasTable($m[1])) {
                    continue;
                }
                if (in_array($name, $ran, true)) {
                    $earlier[] = $m[1];
                } else {
                    $conflicts[] = $m[1];
                }
            }
        }
        $earlier = array_values(array_unique($earlier));
        sort($earlier, SORT_STRING);
        $conflicts = array_values(array_unique($conflicts));
        sort($conflicts, SORT_STRING);

        $alters = false;
        $tables = [];
        foreach ($new as $name) {
            if (preg_match(self::CREATE_TABLE, $name, $m) === 1 && in_array($m[1], $own, true)) {
                continue;
            }
            $table = self::tableOf($name);
            if ($table !== null && in_array($table, $own, true)) {
                continue;
            }
            $alters = true;
            if ($table !== null && $hasTable($table)) {
                $tables[] = $table;
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);

        $facts = [
            'new_migrations' => count($new),
            'changes_database' => $irreversible || $migrations === null || $new !== [],
            'altered_tables' => $tables,
            'earlier_tables' => $earlier,
            'conflicting_tables' => $conflicts,
        ];
        $reason = match (true) {
            $irreversible => self::REASON_IRREVERSIBLE,
            $migrations === null => self::REASON_UNKNOWN,
            $earlier !== [] => self::REASON_EARLIER_DATA,
            $new === [] => self::REASON_NO_NEW_MIGRATIONS,
            $alters => self::REASON_ALTERS_EXISTING,
            default => self::REASON_OWN_TABLES_ONLY,
        };

        $on = [self::REASON_IRREVERSIBLE, self::REASON_UNKNOWN, self::REASON_EARLIER_DATA, self::REASON_ALTERS_EXISTING];

        return ['recommended' => in_array($reason, $on, true), 'reason' => $reason] + $facts;
    }

    /**
     * Table a migration name points at, or null when the name does not say.
     */
    private static function tableOf(string $name): ?string
    {
        $base = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
        foreach ([
            '/_(?:to|in|on|from|of)_([a-z0-9_]+?)_table$/',
            '/^(?:create|alter|update|change|modify|drop|rename)_([a-z0-9_]+?)_table$/',
        ] as $pattern) {
            if (preg_match($pattern, $base, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The site's own last successful backup: the newer of the last scheduled application backup (status succeeded or
     * partial) and the server's nightly backup (state ok). `at` is ISO-8601, `source` is `scheduled` or `nightly`;
     * both null when neither exists. Never throws.
     *
     * @return array{at: ?string, source: ?string}
     */
    public static function lastBackup(): array
    {
        $candidates = [];
        try {
            $backup = Backup::query()
                ->where('reason', Backup::REASON_SCHEDULED)
                ->whereIn('status', [Backup::STATUS_SUCCEEDED, Backup::STATUS_PARTIAL])
                ->whereNotNull('finished_at')
                ->orderByDesc('finished_at')
                ->first();
            if ($backup !== null && $backup->finished_at !== null) {
                $candidates[] = ['at' => Carbon::parse($backup->finished_at), 'source' => 'scheduled'];
            }
        } catch (Throwable) {
            // No backups table yet: nothing to show.
        }

        try {
            $nightly = app(ServerNightlyStatus::class)->read();
            if ($nightly['state'] === 'ok' && is_string($nightly['finished_at'])) {
                $candidates[] = ['at' => Carbon::parse($nightly['finished_at']), 'source' => 'nightly'];
            }
        } catch (Throwable) {
            // Unreadable status file: nothing to show.
        }

        if ($candidates === []) {
            return ['at' => null, 'source' => null];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return ['at' => $candidates[0]['at']->toIso8601String(), 'source' => $candidates[0]['source']];
    }
}
