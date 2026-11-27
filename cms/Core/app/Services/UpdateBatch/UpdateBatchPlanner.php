<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\UpdateBatch;

use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Http\Controllers\V2\Admin\WorkflowLibraryController;
use Cms\Core\Models\Widget;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstallPolicy;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Hub\WidgetBundleUntrusted;
use Cms\Core\Services\Widget\Hub\WidgetHubCatalog;
use Cms\Core\Services\Widget\Hub\WidgetHubException;
use Cms\Core\Services\Widget\Hub\WidgetHubImpact;
use Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog;
use Cms\Core\Services\Workflow\Hub\WorkflowHubException;

/**
 * What an upgrade-all run would do on this site (spec 2026-11-26, decisions 2 and 3).
 *
 * Items are listed in run order (modules by {@see ModuleUpgradeOrder}, then widgets, then workflow templates, each
 * by label) with state `pending`; everything with a newer hub version that the run will not touch is listed in
 * `left_out` with a reason code. Nothing is preflighted here: each module gets its own preflight at its turn.
 */
final class UpdateBatchPlanner
{
    public const KINDS = ['module', 'widget', 'workflow'];

    public function __construct(
        private readonly ModuleCatalog $modules,
        private readonly ModuleInstallPolicy $policy,
        private readonly WidgetHubCatalog $widgets,
        private readonly WidgetHubImpact $impact,
        private readonly WidgetAuthoringPolicy $authoring,
        private readonly WorkflowHubCatalog $workflows,
        private readonly ModuleUpgradeOrder $order,
    ) {
    }

    /**
     * @param list<string> $kinds any of {@see self::KINDS}
     *
     * @return array{items: list<array<string, mixed>>, left_out: list<array<string, mixed>>}
     *
     * @throws PlatformHubException when the module catalog cannot be read from the hub
     */
    public function plan(User $user, array $kinds): array
    {
        $items = [];
        $leftOut = [];
        if (in_array('module', $kinds, true)) {
            $this->planModules($items, $leftOut);
        }
        if (in_array('widget', $kinds, true)) {
            $this->planWidgets($user, $items, $leftOut);
        }
        if (in_array('workflow', $kinds, true)) {
            $this->planWorkflows($user, $items, $leftOut);
        }

        return ['items' => $items, 'left_out' => $leftOut];
    }

    /**
     * What a confirmation must match: module items with their target version, widget and workflow items by key.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    public function signature(array $items): array
    {
        $out = array_map(static fn (array $item): string => $item['kind'] === 'module'
            ? 'module:' . $item['key'] . '@' . $item['to']
            : $item['kind'] . ':' . $item['key'], $items);
        sort($out);

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $leftOut
     */
    private function planModules(array &$items, array &$leftOut): void
    {
        $this->modules->forget();
        $allowed = $this->policy->allowed();
        $core = $this->modules->installedState('Core')['installed_version'];

        $targets = [];
        $rows = [];
        foreach ($this->modules->entries() as $entry) {
            $name = (string) $entry['name'];
            $installed = $entry['installed_version'] ?? null;
            $eligible = $name !== 'Core' && (($entry['installed'] ?? 'none') === 'runtime' || ($entry['handover'] ?? false) === true);
            if (! $eligible || $installed === null) {
                continue;
            }
            if (($entry['updates_unavailable'] ?? false) === true) {
                $latest = $entry['latest_version'] ?? null;
                if (is_string($latest) && ModuleVersionConstraint::compare($latest, $installed) > 0) {
                    $leftOut[] = $this->row('module', $name, $name, $installed, $latest, 'UPDATES_UNAVAILABLE');
                }

                continue;
            }
            if (($entry['upgrade_available'] ?? false) !== true || ! is_string($entry['upgrade_version'] ?? null)) {
                continue;
            }
            $to = $entry['upgrade_version'];
            $version = collect($entry['versions'])->firstWhere('version', $to) ?? ['requires' => [], 'irreversible' => false];
            $row = $this->row('module', $name, $name, $installed, $to);
            $coreNeed = ModuleVersionConstraint::constraintFor((array) $version['requires'], 'Core');

            $code = match (true) {
                ! $allowed => 'INSTALL_NOT_ALLOWED',
                InstallPreflight::isIrreversible($version['irreversible'] ?? false) => 'IRREVERSIBLE',
                $coreNeed !== null && ($core === null || ! ModuleVersionConstraint::satisfies($core, $coreNeed)) => 'NEEDS_NEWER_CORE',
                default => null,
            };
            if ($code !== null) {
                $leftOut[] = $row + ['reason_code' => $code];

                continue;
            }
            $rows[$name] = $row;
            $targets[$name] = ['version' => $to, 'requires' => (array) $version['requires'], 'installed' => $installed, 'source' => $entry['source'] ?? 'runtime'];
        }

        $installedRequires = [];
        $installedVersions = [];
        foreach ($targets as $name => $target) {
            $installedRequires[$name] = $this->installedRequires($name, (string) $target['source']);
            $installedVersions[$name] = $target['installed'];
        }
        $order = $this->order->sort($targets, $installedRequires, $installedVersions);
        foreach ($order['ordered'] as $name) {
            $items[] = $rows[$name] + $this->pending();
        }
        foreach ($order['cyclic'] as $name) {
            $leftOut[] = $rows[$name] + ['reason_code' => 'REQUIREMENT_CYCLE'];
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $leftOut
     */
    private function planWidgets(User $user, array &$items, array &$leftOut): void
    {
        try {
            $rows = $this->widgets->list();
        } catch (WidgetHubException $e) {
            $leftOut[] = $this->row('widget', '*', '', null, null, $e->errorCode);

            return;
        }
        $denied = ! $user->can(str(CoreTableConstant::WIDGET)->singular() . '.hub');
        $authoring = $this->authoring->authoringEnabled();

        $planned = [];
        foreach ($rows as $entry) {
            $id = $entry['installed_widget_id'] ?? null;
            if ($id === null || (int) $entry['latest_version'] <= (int) ($entry['installed_version'] ?? 0)) {
                continue;
            }
            $widget = Widget::query()->find((int) $id);
            if ($widget === null || ! $widget->isHub()) {
                continue;
            }
            $row = $this->row('widget', (string) $entry['widget_key'], (string) ($entry['display_name'] ?? $entry['widget_key']),
                self::widgetVersion((int) $entry['installed_version']), self::widgetVersion((int) $entry['latest_version'])) + ['widget_id' => (int) $id];
            if ($denied || $authoring) {
                $leftOut[] = $row + ['reason_code' => $denied ? 'NO_PERMISSION' : 'WIDGET_AUTHORING_COPY'];

                continue;
            }
            try {
                $impact = $this->impact->forUpdate((int) $id);
            } catch (WidgetHubException $e) {
                $leftOut[] = $row + ['reason_code' => $e->errorCode];

                continue;
            } catch (WidgetBundleUntrusted) {
                $leftOut[] = $row + ['reason_code' => WidgetBundleUntrusted::ERROR_CODE];

                continue;
            }
            if ($impact['removed_designs_in_use'] !== []) {
                $leftOut[] = $row + ['reason_code' => WidgetHubException::DESIGN_IN_USE];

                continue;
            }
            $planned[] = $row + ['pages' => (int) $impact['pages']] + $this->pending();
        }
        usort($planned, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        array_push($items, ...$planned);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $leftOut
     */
    private function planWorkflows(User $user, array &$items, array &$leftOut): void
    {
        try {
            $rows = $this->workflows->list(true);
        } catch (WorkflowHubException $e) {
            $leftOut[] = $this->row('workflow', '*', '', null, null, $e->errorCode);

            return;
        }
        $denied = ! $user->can(WorkflowLibraryController::INSTALL_ABILITY);

        $planned = [];
        foreach ($rows as $entry) {
            $installed = $entry['installed'] ?? null;
            if (! is_array($installed) || ($entry['update_available'] ?? false) !== true) {
                continue;
            }
            $row = $this->row('workflow', (string) $entry['key'], (string) $entry['name'],
                (string) ($installed['version_label'] ?? ''), (string) ($entry['latest_version_label'] ?? ''))
                + ['project_id' => (int) $installed['project_id'], 'setup_url' => $installed['setup_url'] ?? null];
            $code = match (true) {
                $denied => 'NO_PERMISSION',
                ($installed['modified'] ?? false) === true => 'WORKFLOW_MODIFIED',
                ($entry['available'] ?? true) === false => WorkflowHubException::NOT_AVAILABLE,
                default => null,
            };
            if ($code !== null) {
                $leftOut[] = $row + ['reason_code' => $code];

                continue;
            }
            $planned[] = $row + $this->pending();
        }
        usort($planned, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        array_push($items, ...$planned);
    }

    /**
     * `requires` of the copy of $name that runs on this site (runtime folder when it runs from there, else release).
     *
     * @return array<array-key, mixed>
     */
    private function installedRequires(string $name, string $source): array
    {
        $root = $source === 'runtime' ? RuntimeModules::path() : $this->modules->releasePath();
        $raw = $root === null ? false : @file_get_contents($root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'module.json');
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) && is_array($decoded['requires'] ?? null) ? $decoded['requires'] : [];
    }

    /**
     * One plan row; `reason_code` only for a left-out row.
     *
     * @return array<string, mixed>
     */
    private function row(string $kind, string $key, string $label, ?string $from, ?string $to, ?string $reason = null): array
    {
        $row = ['kind' => $kind, 'key' => $key, 'label' => $label, 'from' => $from, 'to' => $to];

        return $reason === null ? $row : $row + ['reason_code' => $reason];
    }

    /**
     * Run fields of a pending item (the batch result contract).
     *
     * @return array<string, mixed>
     */
    private function pending(): array
    {
        return ['state' => 'pending', 'reason_code' => null, 'install_uuid' => null, 'widget_id' => null, 'pages' => null, 'project_id' => null, 'setup_url' => null];
    }

    /**
     * A widget hub version as the library shows it: YYYYMMNN → "YYYY.MM.NN", older small numbers unchanged.
     */
    public static function widgetVersion(int $version): string
    {
        if ($version <= 0) {
            return '';
        }
        if ($version < 20000101) {
            return (string) $version;
        }
        $digits = (string) $version;

        return substr($digits, 0, 4) . '.' . substr($digits, 4, 2) . '.' . substr($digits, 6, 2);
    }
}
