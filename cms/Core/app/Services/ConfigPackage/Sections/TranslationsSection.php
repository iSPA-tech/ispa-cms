<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use Cms\Core\Models\Translate;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Illuminate\Support\Facades\Artisan;

/**
 * Translations section of the configuration package.
 *
 * Rows are matched by `name` only (never by numeric id). Import creates new keys, updates the wording and flags
 * of existing keys, and restores a soft-deleted key with the same name; it never deletes and never touches the
 * site-local scan data (`is_used`, `used_in`, `last_scanned_at`). Language packs are regenerated afterwards with
 * `cms:translation-generate` only; `cms:translation-sync` would overwrite imported wording from module sidecars.
 */
class TranslationsSection implements SectionAdapter
{
    public const SCOPES = ['web', 'adm', 'app', 'all'];

    public const NAME_MAX_LENGTH = 100;

    public const RESTORE_REASON = 'soft-deleted row with the same name will be restored';

    /** Fields carried by a package row besides `name`. */
    private const CARRIED = ['group_name', 'modules', 'text', 'for_web', 'for_adm', 'for_app', 'status'];

    /**
     * @param string       $scope   Export scope: `web` (default), `adm`, `app` or `all`.
     * @param list<string> $modules Export only rows whose `modules` contain any of these; empty means all modules.
     */
    public function __construct(
        private readonly string $scope = 'web',
        private readonly array $modules = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'translations';
    }

    /**
     * @inheritDoc
     *
     * Non-empty `keyPatterns` of the context additionally restrict the exported names (fnmatch style).
     */
    public function export(ExportContext $c): SectionExport
    {
        $rows = [];

        foreach (Translate::query()->orderBy('name')->get() as $translate) {
            $name = (string) $translate->name;

            if ($name === '' || !$this->inScope($translate) || !$this->inModules($translate)) {
                continue;
            }

            if ($c->keyPatterns !== [] && !$c->matches($name)) {
                continue;
            }

            $rows[] = ['name' => $name] + $this->carried($translate);
        }

        return new SectionExport($rows);
    }

    /**
     * @inheritDoc
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->diffRow(is_array($row) ? $row : [])['item'];
        }

        return new SectionDiff($items);
    }

    /**
     * @inheritDoc
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        $items = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            ['item' => $diff, 'target' => $target] = $this->diffRow($row);
            $name = (string) $diff['key'];
            $outcome = 'skipped';
            $reason = $diff['reason'];

            switch ($diff['action']) {
                case 'blocked':
                    $outcome = 'blocked';
                    break;
                case 'unchanged':
                    $outcome = 'unchanged';
                    break;
                case 'create':
                case 'update':
                    if (!$c->isChosen($name)) {
                        $reason = 'deselected';
                        break;
                    }

                    $translate = $target ?? new Translate(['name' => $name]);

                    if ($translate->trashed()) {
                        $translate->restore();
                    }

                    $translate->fill($this->incoming($row))->save();
                    $outcome = 'updated';
                    break;
            }

            ++$counts[$outcome];
            $items[] = ['key' => $name, 'outcome' => $outcome, 'reason' => $reason];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     *
     * Regenerates the language packs only; never runs `cms:translation-sync` or `cms:export-db`.
     */
    public function afterApply(ApplyContext $c): void
    {
        Artisan::call('cms:translation-generate');
    }

    /**
     * Compare one incoming row with the target (including soft-deleted rows).
     *
     * @param array<string, mixed> $row
     *
     * @return array{item: array<string, mixed>, target: Translate|null}
     */
    private function diffRow(array $row): array
    {
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $item = ['key' => $name, 'action' => 'blocked', 'reason' => null];

        if ($name === '' || trim($name) !== $name) {
            $item['reason'] = 'name is empty or invalid';

            return ['item' => $item, 'target' => null];
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            $item['reason'] = 'name is longer than ' . self::NAME_MAX_LENGTH . ' characters';

            return ['item' => $item, 'target' => null];
        }

        $text = $row['text'] ?? null;

        if (!is_array($text) || $text === []) {
            $item['reason'] = 'text must be a non-empty object keyed by language';

            return ['item' => $item, 'target' => null];
        }

        $target = Translate::withTrashed()->where('name', $name)->first();
        $after = $this->incoming($row);

        if ($target === null) {
            $item['action'] = 'create';
            $item['after'] = $after;

            return ['item' => $item, 'target' => null];
        }

        $before = $this->carried($target);
        $item['before'] = $before;
        $item['after'] = $after;

        if ($target->trashed()) {
            $item['action'] = 'update';
            $item['reason'] = self::RESTORE_REASON;
        } elseif (PackageFormat::canonicalJson($before) === PackageFormat::canonicalJson($after)) {
            $item['action'] = 'unchanged';
        } else {
            $item['action'] = 'update';
        }

        return ['item' => $item, 'target' => $target];
    }

    /**
     * Normalize the carried fields of an incoming row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function incoming(array $row): array
    {
        $modules = $row['modules'] ?? null;

        return [
            'group_name' => isset($row['group_name']) ? (string) $row['group_name'] : null,
            'modules' => is_array($modules) ? array_values($modules) : null,
            'text' => $row['text'],
            'for_web' => (bool) ($row['for_web'] ?? false),
            'for_adm' => (bool) ($row['for_adm'] ?? false),
            'for_app' => (bool) ($row['for_app'] ?? false),
            'status' => isset($row['status']) ? (string) $row['status'] : null,
        ];
    }

    /**
     * Read the carried fields of a stored row, in package order.
     *
     * @return array<string, mixed>
     */
    private function carried(Translate $translate): array
    {
        $modules = $translate->modules;

        return [
            'group_name' => $translate->group_name !== null ? (string) $translate->group_name : null,
            'modules' => is_array($modules) ? array_values($modules) : null,
            'text' => $translate->text,
            'for_web' => (bool) $translate->for_web,
            'for_adm' => (bool) $translate->for_adm,
            'for_app' => (bool) $translate->for_app,
            'status' => $translate->status !== null ? (string) $translate->status : null,
        ];
    }

    /**
     * Tell whether a stored row belongs to the configured export scope.
     */
    private function inScope(Translate $translate): bool
    {
        return match ($this->scope) {
            'all' => true,
            'adm' => (bool) $translate->for_adm,
            'app' => (bool) $translate->for_app,
            default => (bool) $translate->for_web,
        };
    }

    /**
     * Tell whether a stored row belongs to one of the configured modules (always true without a module filter).
     */
    private function inModules(Translate $translate): bool
    {
        if ($this->modules === []) {
            return true;
        }

        $modules = is_array($translate->modules) ? $translate->modules : [];

        return array_intersect($this->modules, $modules) !== [];
    }
}
