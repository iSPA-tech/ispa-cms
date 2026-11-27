<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Cms\Core\Services\Widget\LegacyWidgetDefinitionReconciler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the catalog widgets of every publisher.
 *
 * By default (deploys: `cms:modules-sync`, CoreTranslateTableSeeder, db:seed) it is insert-only, like
 * a project-specific widget template seeder: a catalog widget is created, with its designs and inputs, only when no row has its
 * widget_key and no site copy has it as `cloned_from_widget_key`. Existing rows and their version are never updated;
 * hub widgets change on a site only when an admin presses "Cập nhật". The operator command `cms:sync-widgets` opts
 * into reconcileExisting() to upsert every catalog row by stable key.
 */
class WidgetSeeder extends Seeder
{
    private bool $reconcileExisting = false;

    /** Upsert existing catalog rows too (cms:sync-widgets only). */
    public function reconcileExisting(): static
    {
        $this->reconcileExisting = true;

        return $this;
    }

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $this->syncIdSequences();

        foreach (WidgetCatalogSources::publishers() as $publisher) {
            if (!is_dir(WidgetCatalogSources::directory($publisher))) {
                continue;
            }

            $this->syncPublisher($publisher);
        }
    }

    /**
     * Seed one publisher's catalog (insert-only unless reconcileExisting()); outside local, only the themes for this project (or Default) and their
     * widgets and inputs are synced.
     */
    private function syncPublisher(string $publisher): void
    {
        [$widgets, $themes, $inputs] = WidgetCatalogSources::rows($publisher);

        if (!app()->environment('local')) {
            $project = config('ispa-cms.folder_name', 'Default');
            $themes = array_filter($themes, static function (array $theme) use ($project): bool {
                $projects = json_decode($theme['project_name'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
                return in_array($project, $projects, true) || in_array('Default', $projects, true);
            });
            $allowedWidgetIds = array_unique(array_column($themes, 'widget_id'));
            $widgets = array_filter($widgets, static fn (array $row): bool => in_array($row['id'], $allowedWidgetIds, true));
            $inputs = array_filter($inputs, static fn (array $row): bool => in_array($row['widget_id'], $allowedWidgetIds, true));
        }

        $widgets = array_map(static function (array $row): array {
            $row['widget_key'] ??= 'core.widget-' . $row['id'];
            return $row;
        }, array_values($widgets));
        $themes = array_map(static function (array $row): array {
            $row['theme_key'] ??= 'theme-' . $row['id'];
            return $row;
        }, array_values($themes));
        $inputs = array_map(static function (array $row): array {
            $row['input_key'] ??= 'input-' . $row['id'];
            return $row;
        }, array_values($inputs));

        if ($this->reconcileExisting) {
            app(LegacyWidgetDefinitionReconciler::class)->reconcile($widgets, $themes, $inputs, $publisher);
            $this->command?->info("Widget definitions reconciled ({$publisher}).");

            return;
        }

        $keys = array_column($widgets, 'widget_key');
        $present = array_flip(array_map('mb_strtolower', [
            ...Widget::query()->whereIn('widget_key', $keys)->pluck('widget_key')->all(),
            ...Widget::query()->whereIn('cloned_from_widget_key', $keys)->pluck('cloned_from_widget_key')->all(),
        ]));
        $missing = array_values(array_filter(
            $widgets,
            static fn (array $row): bool => !isset($present[mb_strtolower((string) $row['widget_key'])]),
        ));
        if ($missing === []) {
            $this->command?->info("Widget definitions: nothing missing ({$publisher}).");

            return;
        }

        $sourceIds = array_flip(array_column($missing, 'id'));
        $ofMissing = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($sourceIds[$row['widget_id']]),
        ));
        app(LegacyWidgetDefinitionReconciler::class)
            ->reconcile($missing, $ofMissing($themes), $ofMissing($inputs), $publisher);
        $this->command?->info('Widget definitions added: ' . count($missing) . " ({$publisher}).");
    }

    /**
     * PostgreSQL only: move the id sequences of widgets, widget_themes and widget_inputs up to MAX(id).
     *
     * Older seeders inserted these rows with explicit ids and never touched the sequences, so on sites seeded then the
     * sequences stay behind and the reconciler's first insert fails on `widgets_pkey`. Runs on every seed, before any
     * insert, and only moves a sequence forward (never back onto ids of deleted rows).
     */
    private function syncIdSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([Widget::class, WidgetTheme::class, WidgetInput::class] as $model) {
            $table = (new $model())->getTable();
            $sequenceName = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS sequence_name', [$table, 'id'])
                ->sequence_name ?? null;
            $maxId = DB::table($table)->max('id');
            if (!is_string($sequenceName) || $sequenceName === '' || !is_numeric($maxId)) {
                continue;
            }

            DB::statement(
                'SELECT setval(?::regclass, GREATEST(?, COALESCE(pg_sequence_last_value(?::regclass), 0)), true)',
                [$sequenceName, (int) $maxId, $sequenceName],
            );
        }
    }

    /**
     * The iSPA catalog rows (exported plus the private Internal merge), in id order.
     *
     * Kept for existing callers; see WidgetCatalogSources::rows().
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    public static function sourceRows(): array
    {
        return WidgetCatalogSources::rows(WidgetCatalogSources::ISPA);
    }
}
