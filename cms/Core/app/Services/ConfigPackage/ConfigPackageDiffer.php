<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Setting;
use Cms\Core\Services\ConfigPackage\Sections\CustomFieldsSection;
use Cms\Core\Services\ConfigPackage\Sections\MenusSection;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Cms\Core\Services\ConfigPackage\Sections\TablesSection;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Compares an uploaded configuration package with this site (dry run) and stores the plan in a run record.
 *
 * dryRun() creates a queued `dry_run` run holding the package and fills it through dryRunInto(); the admin API
 * creates the queued run itself and a job calls dryRunInto. Nothing but the run row is written: the
 * section diffs execute inside a database transaction that is always rolled back, and no audit event is emitted
 * (an audit row would be another write; the apply step audits).
 *
 * Failure shape: an invalid, tampered, secret-bearing or newer-format package does not throw. The run ends
 * `failed` with `summary = {error, errors[]}` (validator messages name paths, never values), no plan, no payload
 * and no checksum.
 *
 * Plan shape (`plan`): `base_fingerprint`, `sections.<name>` = `{status, counts, total, items (max 500), more,
 * more_text, warnings}`, `warnings`, `required_secrets` = `{already_set_here, missing_here, labels}` (keys plus a key => label map) and
 * `choices` (every `needs_choice` item: section, key, default_choice, reason). Item values are scrubbed with
 * SecretGuard rules before they are stored: values under secret-looking keys and ciphertext become `[redacted]`.
 *
 * base_fingerprint is sha256 of the canonical JSON of every diff item of every section (sorted by section, key and
 * action) reduced to `[key, action, scrubbed before]`, i.e. the target state the diff was computed from. The same
 * package against the same database gives the same fingerprint; a changed target value changes it. Package side
 * `after` values are left out because the package checksum already pins them.
 */
class ConfigPackageDiffer
{
    /** Maximum items kept per section in the plan. */
    public const ITEM_CAP = 500;

    private const REDACTED = '[redacted]';

    /** @var array<string, SectionAdapter>|null Adapters keyed by section key; null means container defaults. */
    private ?array $adapters = null;

    private readonly PackageValidator $validator;

    private readonly SecretGuard $guard;

    /**
     * @param list<SectionAdapter>|null $adapters Adapters to use (keyed by key()); null resolves the six default adapters lazily.
     */
    public function __construct(?array $adapters = null, ?PackageValidator $validator = null, ?SecretGuard $guard = null)
    {
        $this->validator = $validator ?? new PackageValidator();
        $this->guard = $guard ?? new SecretGuard();

        if ($adapters !== null) {
            $this->adapters = [];
            foreach ($adapters as $adapter) {
                $this->adapters[$adapter->key()] = $adapter;
            }
        }
    }

    /**
     * Record a dry run of a decoded package and return the finished run (succeeded or failed).
     *
     * @param array<array-key, mixed> $package Decoded package.
     * @param int                     $userId  Acting user id.
     */
    public function dryRun(array $package, int $userId): ConfigPackageRun
    {
        $sections = $package['sections'] ?? null;

        $run = new ConfigPackageRun([
            'kind' => ConfigPackageRun::KIND_DRY_RUN,
            'status' => ConfigPackageRun::STATUS_QUEUED,
            'sections' => is_array($sections) ? array_map('strval', array_keys($sections)) : [],
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        try {
            $run->setPayload($package);
        } catch (\JsonException) {
            // A package that cannot be encoded is reported as invalid by dryRunInto() (no payload stored).
        }

        $run->save();

        return $this->dryRunInto($run, $userId);
    }

    /**
     * Fill an existing queued run: validate its stored package, diff every present section and store the plan.
     *
     * @param ConfigPackageRun             $run       Queued dry run that holds the package payload.
     * @param int                          $userId    Acting user id.
     * @param (callable(string): void)|null $onSection Called with the section name before each section is compared.
     */
    public function dryRunInto(ConfigPackageRun $run, int $userId, ?callable $onSection = null): ConfigPackageRun
    {
        $run->forceFill(['status' => ConfigPackageRun::STATUS_RUNNING])->save();

        try {
            $package = $run->payload();
        } catch (Throwable) {
            return $this->fail($run, 'The stored package could not be read.', []);
        }

        $result = $this->validator->validate($package);
        if (!$result->isValid()) {
            return $this->fail($run, 'The package did not pass validation.', $result->errors(), $package, $result->issues());
        }

        $sections = (array) $package['sections'];

        try {
            $this->guard->assertNoSecrets($sections);
            $this->guard->assertNoSecretSettingRows($sections);
        } catch (InvalidArgumentException $e) {
            return $this->fail($run, 'The package contains secret data.', [$e->getMessage()], $package, [['key' => 'text.config_package.validation.secret_blocked', 'params' => [], 'message' => $e->getMessage()]]);
        }

        try {
            $plan = $this->plan($package, $userId, $onSection);
        } catch (SectionFailure $e) {
            return $this->fail($run, 'Section ' . $e->section . ' could not be compared (' . $e->class . ').', [], $package);
        }

        $run->forceFill([
            'status' => ConfigPackageRun::STATUS_SUCCEEDED,
            'sections' => array_map('strval', array_keys($sections)),
            'package_checksum' => (string) $package['checksum'],
            'summary' => $this->summary($plan),
            'plan' => $plan,
        ])->save();

        return $run;
    }

    /**
     * Build the plan of a validated package. The section diffs run in a transaction that is always rolled back.
     *
     * @param array<array-key, mixed> $package
     *
     * @return array<string, mixed>
     *
     * @throws SectionFailure When an adapter throws.
     */
    private function plan(array $package, int $userId, ?callable $onSection): array
    {
        $context = new DiffContext($userId);
        $sectionPlans = [];
        $warnings = [];
        $choices = [];
        $fingerprintParts = [];

        DB::beginTransaction();

        try {
            foreach ((array) $package['sections'] as $name => $rows) {
                $name = (string) $name;
                $adapter = $this->adapter($name);

                if ($adapter === null) {
                    $warnings[] = "Section {$name} is not supported by this site and was skipped.";
                    $sectionPlans[$name] = $this->skippedSection();

                    continue;
                }

                if ($onSection !== null) {
                    $onSection($name);
                }

                try {
                    $diff = $adapter->diff(is_array($rows) ? $rows : [], $context);
                } catch (Throwable $e) {
                    throw new SectionFailure($name, class_basename($e));
                }

                $items = array_map(fn (array $item): array => $this->scrubItem($item), $diff->items);
                $counts = $diff->counts();
                $sectionWarnings = [];

                if ($counts['blocked'] > 0) {
                    $sectionWarnings[] = "{$counts['blocked']} item(s) are blocked and will not be applied.";
                }

                foreach ($items as $item) {
                    $fingerprintParts[$name][] = [(string) ($item['key'] ?? ''), (string) ($item['action'] ?? ''), $item['before'] ?? null];

                    if (($item['needs_choice'] ?? false) === true) {
                        $choices[] = [
                            'section' => $name,
                            'key' => (string) ($item['key'] ?? ''),
                            'default_choice' => (bool) ($item['default_choice'] ?? false),
                            'reason' => $item['reason'] ?? null,
                        ];
                    }
                }

                $total = count($items);
                $more = max(0, $total - self::ITEM_CAP);

                $sectionPlans[$name] = [
                    'status' => ($counts['create'] + $counts['update']) > 0 ? 'changes' : 'no_changes',
                    'counts' => $counts,
                    'total' => $total,
                    'items' => array_slice($items, 0, self::ITEM_CAP),
                    'more' => $more,
                    'more_text' => $more > 0 ? "and {$more} more" : null,
                    'warnings' => $sectionWarnings,
                ];

                foreach ($sectionWarnings as $warning) {
                    $warnings[] = "{$name}: {$warning}";
                }
            }
        } finally {
            DB::rollBack();
        }

        return [
            'base_fingerprint' => $this->fingerprint($fingerprintParts),
            'sections' => $sectionPlans,
            'warnings' => $warnings,
            'required_secrets' => $this->requiredSecrets((array) ($package['required_secrets'] ?? [])),
            'choices' => $choices,
        ];
    }

    /**
     * Plan entry of a section this site cannot compare.
     *
     * @return array<string, mixed>
     */
    private function skippedSection(): array
    {
        return [
            'status' => 'skipped',
            'counts' => array_fill_keys(SectionDiff::ACTIONS, 0),
            'total' => 0,
            'items' => [],
            'more' => 0,
            'more_text' => null,
            'warnings' => ['Section is not supported by this site.'],
        ];
    }

    /**
     * Split required secret keys into keys that already hold a value on this site and keys that do not.
     *
     * "Has a value" means the row exists and its raw stored value is not an empty string (as SettingController::index
     * does with getRawOriginal). The value is never decrypted or returned.
     *
     * @param array<array-key, mixed> $keys
     *
     * @return array{already_set_here: list<string>, missing_here: list<string>, labels: array<string, string>}
     */
    private function requiredSecrets(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        sort($keys, SORT_STRING);

        $set = [];
        $labels = [];
        if ($keys !== []) {
            foreach (Setting::query()->whereIn('setting_key', $keys)->get(['setting_key', 'name', 'value']) as $setting) {
                // Human name of the setting on this site; keys without a row or name are left out (clients show the key).
                if ($setting->name !== null && trim((string) $setting->name) !== '') {
                    $labels[(string) $setting->setting_key] = (string) $setting->name;
                }
                if ((string) $setting->getRawOriginal('value') !== '') {
                    $set[(string) $setting->setting_key] = true;
                }
            }
        }

        ksort($labels, SORT_STRING);

        return [
            'already_set_here' => array_values(array_filter($keys, static fn (string $key): bool => isset($set[$key]))),
            'missing_here' => array_values(array_filter($keys, static fn (string $key): bool => !isset($set[$key]))),
            'labels' => $labels,
        ];
    }

    /**
     * Deterministic hash of the target state read by the diff.
     *
     * @param array<string, list<array{0: string, 1: string, 2: mixed}>> $parts Per section: [key, action, scrubbed before].
     */
    private function fingerprint(array $parts): string
    {
        ksort($parts, SORT_STRING);

        foreach ($parts as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a[0], $a[1], PackageFormat::canonicalJson($a[2])] <=> [$b[0], $b[1], PackageFormat::canonicalJson($b[2])]);
        }
        unset($list);

        return 'sha256:' . hash('sha256', PackageFormat::canonicalJson($parts));
    }

    /**
     * Values-free summary of a plan: counts, warnings and secret key counts.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>
     */
    private function summary(array $plan): array
    {
        $sections = [];
        foreach ($plan['sections'] as $name => $section) {
            $sections[$name] = ['status' => $section['status'], 'counts' => $section['counts'], 'total' => $section['total']];
        }

        return [
            'sections' => $sections,
            'warnings' => count($plan['warnings']),
            'choices' => count($plan['choices']),
            'required_secrets' => [
                'already_set_here' => count($plan['required_secrets']['already_set_here']),
                'missing_here' => count($plan['required_secrets']['missing_here']),
            ],
        ];
    }

    /**
     * Mark the run failed: no plan, no payload, no checksum; only a safe message and path-only validator errors.
     *
     * @param list<string>             $errors
     * @param array<array-key, mixed>  $package
     * @param list<array<string, mixed>> $issues Structured validator issues (key, params, message).
     */
    private function fail(ConfigPackageRun $run, string $message, array $errors, array $package = [], array $issues = []): ConfigPackageRun
    {
        $sections = $package['sections'] ?? null;

        $run->setAttribute('payload', null);
        $run->forceFill([
            'status' => ConfigPackageRun::STATUS_FAILED,
            'sections' => is_array($sections) ? array_map('strval', array_keys($sections)) : (array) ($run->sections ?? []),
            'package_checksum' => null,
            'plan' => null,
            'summary' => ['error' => $message, 'errors' => array_slice($errors, 0, 200), 'issues' => array_slice($issues, 0, 200)],
        ])->save();

        return $run;
    }

    /**
     * Resolve the adapter of a section, or null when the section is unknown.
     */
    private function adapter(string $section): ?SectionAdapter
    {
        if ($this->adapters !== null) {
            return $this->adapters[$section] ?? null;
        }

        return match ($section) {
            'settings' => app(SettingsSection::class),
            // Translation diff does not depend on the export scope; `all` keeps any future scope filter permissive.
            'translations' => new TranslationsSection('all'),
            'roles' => app(RolesSection::class),
            'menus' => app(MenusSection::class),
            'workflows' => app(WorkflowsSection::class),
            'custom_fields' => app(CustomFieldsSection::class),
            'tables' => app(TablesSection::class),
            default => null,
        };
    }

    /**
     * Redact secret-looking keys and ciphertext values anywhere inside a diff item.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function scrubItem(array $item): array
    {
        return (array) $this->scrub($item);
    }

    /**
     * Recursively replace secret-keyed values and ciphertext strings with a placeholder.
     */
    private function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->guard->looksLikeCiphertext($value) ? self::REDACTED : $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $child) {
            $clean[$key] = is_string($key) && $this->guard->isSecretKey($key) ? self::REDACTED : $this->scrub($child);
        }

        return $clean;
    }
}

/**
 * Internal signal that one section adapter threw while diffing; carries only the section and exception class names.
 */
final class SectionFailure extends \RuntimeException
{
    /**
     * @param string $section Section name.
     * @param string $class   Short class name of the original exception (never its message).
     */
    public function __construct(public readonly string $section, public readonly string $class)
    {
        parent::__construct("Section {$section} failed.");
    }
}
