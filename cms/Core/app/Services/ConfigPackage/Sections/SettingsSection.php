<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use App\Constants\CacheConstants;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Illuminate\Support\Facades\Artisan;

/**
 * Settings section of the configuration package.
 *
 * Only existing, public, non-secret, non-media settings are exported and updated. Rows are matched by
 * `setting_key`; settings are never created, deleted, or re-typed, and the TARGET row's own flags are always
 * re-checked so a forged package cannot change a secret or private row.
 */
class SettingsSection implements SectionAdapter
{
    public const MISSING_REASON = 'row does not exist on this site; enable the module or run seeders';

    /** Setting types whose value holds numeric upload ids. */
    public const MEDIA_TYPES = ['image', 'images', 'file', 'files', 'document', 'archived'];

    public function __construct(private readonly SecretGuard $guard = new SecretGuard())
    {
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'settings';
    }

    /**
     * @inheritDoc
     */
    public function export(ExportContext $c): SectionExport
    {
        $rows = [];
        $skipped = [];
        $secrets = [];

        foreach (Setting::query()->with('language')->orderBy('setting_key')->get() as $setting) {
            $key = (string) $setting->setting_key;

            if (!$c->matches($key)) {
                continue;
            }

            $reason = $this->excludeReason($setting);

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                if ($reason !== 'media') {
                    $secrets[] = $key;
                }

                continue;
            }

            $rows[] = [
                'key' => $key,
                'type' => (string) $setting->type,
                'group_name' => $setting->group_name,
                'name' => $setting->name,
                'language' => $setting->language !== null
                    ? (string) ($setting->language->path_alias ?? $setting->language->path)
                    : null,
                'value' => $setting->value,
            ];
        }

        ksort($skipped);

        return new SectionExport($rows, $skipped, $secrets);
    }

    /**
     * @inheritDoc
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->diffRow(is_array($row) ? $row : []);
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
            $diff = $this->diffRow($row);
            $key = (string) $diff['key'];
            $outcome = null;
            $reason = $diff['reason'];

            switch ($diff['action']) {
                case 'blocked':
                    $outcome = 'blocked';
                    break;
                case 'unchanged':
                    $outcome = 'unchanged';
                    break;
                case 'update':
                    if (!$c->isChosen($key)) {
                        $outcome = 'skipped';
                        $reason = 'deselected';
                        break;
                    }

                    $setting = Setting::query()->where('setting_key', $key)->first();

                    if ($setting === null || $this->excludeReason($setting) !== null) {
                        $outcome = 'blocked';
                        $reason = self::MISSING_REASON;
                        break;
                    }

                    $setting->fill(['value' => $row['value'] ?? null])->save();
                    $outcome = 'updated';
                    break;
                default:
                    $outcome = 'skipped';
            }

            ++$counts[$outcome];
            $items[] = ['key' => $key, 'label' => $diff['label'] ?? null, 'outcome' => $outcome, 'reason' => $reason];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     *
     * Mirrors SettingController::synchronizeSettingFile without `cms:export-db`.
     */
    public function afterApply(ApplyContext $c): void
    {
        Artisan::call('cms:setting-initialize --updateEnv=false');

        $this->clearSettingsCache();

        app(CMSSettingRuntime::class)->forget();
    }

    /**
     * Drop the cached public settings payload (seam for tests).
     */
    protected function clearSettingsCache(): void
    {
        RedisHelper::getInstance()->del(CacheConstants::CACHE_SETTINGS);
    }

    /**
     * Compare one incoming row with the target.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function diffRow(array $row): array
    {
        $key = is_string($row['key'] ?? null) ? $row['key'] : '';
        $item = ['key' => $key, 'label' => null, 'action' => 'blocked', 'reason' => null];

        $setting = $key === '' ? null : Setting::query()->with('language')->where('setting_key', $key)->first();

        if ($setting === null) {
            $item['reason'] = self::MISSING_REASON;

            return $item;
        }

        // Human name of the setting on this site; the key stays the stable identifier.
        $item['label'] = $setting->name !== null && $setting->name !== '' ? (string) $setting->name : null;

        $excluded = $this->excludeReason($setting);

        if ($excluded === 'media') {
            $item['action'] = 'skipped';
            $item['reason'] = 'media';

            return $item;
        }

        if ($excluded !== null) {
            $item['reason'] = $excluded;

            return $item;
        }

        $language = $setting->language !== null ? (string) ($setting->language->path_alias ?? $setting->language->path) : null;

        if (($row['language'] ?? null) !== $language) {
            $item['reason'] = 'language does not match the row on this site';

            return $item;
        }

        $before = $setting->value;
        $after = $row['value'] ?? null;
        $item['before'] = $before;
        $item['after'] = $after;

        if ($this->sameValue($before, $after)) {
            $item['action'] = 'unchanged';
        } else {
            $item['action'] = 'update';
        }

        return $item;
    }

    /**
     * Whether two setting values are the same on every driver: the cast hands back numbers and booleans for typed rows,
     * the package carries strings, so scalars compare by their text (a boolean by its 1/0 form).
     */
    private function sameValue(mixed $before, mixed $after): bool
    {
        if (is_scalar($before) && is_scalar($after)) {
            return $this->scalarText($before) === $this->scalarText($after);
        }

        return PackageFormat::canonicalJson($before) === PackageFormat::canonicalJson($after);
    }

    /**
     * Text form of a scalar setting value; booleans and their `true`/`false` words read as 1/0.
     */
    private function scalarText(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        return match (strtolower($text)) {
            'true' => '1',
            'false' => '0',
            default => $text,
        };
    }

    /**
     * Reason a target row must never be exported or changed, or null when it is eligible.
     */
    private function excludeReason(Setting $setting): ?string
    {
        $input = is_array($setting->input_setting) ? $setting->input_setting : [];
        $type = strtolower(trim((string) $setting->type));

        if ($this->guard->isSecretSetting(['type' => $type, 'input_setting' => $input])) {
            if ($type === 'password') {
                return 'secret';
            }

            return filter_var($input['isEnv'] ?? $input['is_env'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'env' : 'non_public';
        }

        return in_array($type, self::MEDIA_TYPES, true) ? 'media' : null;
    }
}
