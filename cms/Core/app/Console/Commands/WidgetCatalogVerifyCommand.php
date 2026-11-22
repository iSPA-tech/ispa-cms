<?php

namespace Cms\Core\Console\Commands;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Support\Widget\LegacyWidgetRenderer;
use App\Services\WidgetTemplatePathResolver;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogStatusService;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Verify gate for hub widget catalogs: run before any build or deploy.
 *
 * Per publisher catalog it checks (1) unique keys, (2) no input removed or retyped without a version bump against
 * `catalog.lock.php`, (3) every theme renders with sample values, (4) no test rows, and (5) on authoring
 * environments, that the local database holds no unexported edits; plus (6) optional hub publish metadata (`hub_version`, `hub_content_sha256`) is well formed and (7) optional per-language names (`name_translations`, `description_translations`) are well formed. `--files-only` skips rules 3 and 5. Each failure prints `publisher widget_key: reason`.
 *
 * Usage: php artisan cms:widget-catalog-verify [--publisher=iSPA] [--update-lock]
 */
class WidgetCatalogVerifyCommand extends Command
{
    private const LOCK_FILE = 'catalog.lock.php';

    private const TEST_ROW_PATTERN = '/^(e2e-|.*fixture)/i';

    protected $signature = 'cms:widget-catalog-verify {--publisher=* : Only verify these publishers (default all)} {--update-lock : Rewrite catalog.lock.php after all checks pass} {--files-only : Check the catalog files only (skip the render check and the unexported-edit check, which need the local database)}';

    protected $description = 'Verify hub widget catalogs (keys, lock, theme render, test rows, unexported edits)';

    /** @var list<string> */
    private array $failures = [];

    public function handle(WidgetAuthoringPolicy $policy): int
    {
        // The command instance is reused when called repeatedly in one process.
        $this->failures = [];

        $requested = array_values(array_filter((array) $this->option('publisher')));
        $publishers = $requested === [] ? WidgetCatalogSources::publishers() : $requested;

        $locks = [];
        foreach ($publishers as $publisher) {
            $before = count($this->failures);
            $lock = $this->verifyPublisher($publisher, $policy);
            if ($lock !== null && count($this->failures) === $before) {
                $locks[$publisher] = $lock;
            }
        }

        foreach ($this->failures as $failure) {
            $this->error($failure);
        }

        if ($this->failures !== []) {
            $this->error('Widget catalog verification FAILED (' . count($this->failures) . ' problem(s)).');

            return self::FAILURE;
        }

        if ($this->option('update-lock')) {
            foreach ($locks as $publisher => $lock) {
                $path = WidgetCatalogSources::directory($publisher) . '/' . self::LOCK_FILE;
                file_put_contents($path, "<?php\n\nreturn " . var_export($lock, true) . ";\n");
                $this->info("{$publisher}: lock written to {$path}");
            }
        }

        $this->info('Widget catalog verification PASSED.');

        return self::SUCCESS;
    }

    /**
     * Run all rules for one publisher and return the lock map for it (null when the catalog is absent).
     *
     * @return array<string, array{version: int, inputs: array<string, string>}>|null
     */
    private function verifyPublisher(string $publisher, WidgetAuthoringPolicy $policy): ?array
    {
        $directory = WidgetCatalogSources::directory($publisher);
        if (!is_dir($directory)) {
            $this->line("{$publisher}: no catalog directory, skipped.");

            return null;
        }
        if (!is_file($directory . '/widgets.php')) {
            $this->failures[] = "{$publisher} (catalog): widgets.php is missing in {$directory}";

            return null;
        }

        [$widgets, $themes, $inputs] = WidgetCatalogSources::rows($publisher);
        $catalog = $this->index($publisher, $widgets, $themes, $inputs);
        if ($catalog === null) {
            return null;
        }

        // The lock and rule 2 cover the public files only: the private Internal rows never reach the open-source export.
        $public = $this->publicInputTypes($publisher);

        $this->checkTestRows($publisher, $catalog);
        $this->checkHubMetadata($publisher, $catalog);
        $this->checkTranslations($publisher, $catalog);
        $this->checkLock($publisher, $directory, $catalog, $public);
        if (!$this->option('files-only')) {
            $this->checkRender($publisher, $catalog);
            if ($policy->authoringEnabled()) {
                $this->checkUnexportedEdits($publisher, $catalog);
            }
        }

        $lock = [];
        foreach ($catalog as $key => $entry) {
            $lock[$key] = ['version' => $entry['version'], 'inputs' => $public[$key] ?? []];
        }

        return $lock;
    }

    /**
     * Input key => type per widget key, from the public catalog files only.
     *
     * @return array<string, array<string, string>>
     */
    private function publicInputTypes(string $publisher): array
    {
        [$widgets, , $inputs] = WidgetCatalogSources::rows($publisher, false);
        $keys = [];
        foreach ($widgets as $row) {
            $keys[$row['id'] ?? null] = $row['widget_key'] ?? ('core.widget-' . ($row['id'] ?? '?'));
        }
        $types = array_fill_keys($keys, []);
        foreach ($inputs as $row) {
            $owner = $keys[$row['widget_id'] ?? null] ?? null;
            if ($owner !== null) {
                $types[$owner][$row['input_key'] ?? ('input-' . ($row['id'] ?? '?'))] = (string) ($row['type'] ?? '');
            }
        }

        return $types;
    }

    /**
     * Group rows by widget key and enforce rule 1. Rows that omit a key get the same fallback key the sync uses.
     *
     * @return array<string, array{row: array<string, mixed>, version: int, themes: array<string, array<string, mixed>>, inputs: array<string, array<string, mixed>>}>|null
     */
    private function index(string $publisher, array $widgets, array $themes, array $inputs): ?array
    {
        $before = count($this->failures);
        $catalog = [];
        $byId = [];

        foreach ($widgets as $row) {
            $key = $row['widget_key'] ?? ('core.widget-' . ($row['id'] ?? '?'));
            if (!is_string($key) || $key === '') {
                $this->failures[] = "{$publisher} (widget id " . ($row['id'] ?? '?') . '): missing widget_key';
                continue;
            }
            if (isset($catalog[$key])) {
                $this->failures[] = "{$publisher} {$key}: duplicate widget_key";
                continue;
            }
            $catalog[$key] = ['row' => $row, 'version' => (int) ($row['version'] ?? 1), 'themes' => [], 'inputs' => []];
            $byId[$row['id'] ?? null] = $key;
        }

        foreach ($themes as $row) {
            $owner = $byId[$row['widget_id'] ?? null] ?? null;
            $key = $row['theme_key'] ?? ('theme-' . ($row['id'] ?? '?'));
            if ($owner === null) {
                $this->failures[] = "{$publisher} (theme {$key}): refers to missing widget id " . ($row['widget_id'] ?? '?');
                continue;
            }
            if (!is_string($key) || $key === '') {
                $this->failures[] = "{$publisher} {$owner}: theme without theme_key";
                continue;
            }
            if (isset($catalog[$owner]['themes'][$key])) {
                $this->failures[] = "{$publisher} {$owner}: duplicate theme_key {$key}";
                continue;
            }
            $catalog[$owner]['themes'][$key] = $row;
        }

        foreach ($inputs as $row) {
            $owner = $byId[$row['widget_id'] ?? null] ?? null;
            $key = $row['input_key'] ?? ('input-' . ($row['id'] ?? '?'));
            if ($owner === null) {
                $this->failures[] = "{$publisher} (input {$key}): refers to missing widget id " . ($row['widget_id'] ?? '?');
                continue;
            }
            if (!is_string($key) || $key === '') {
                $this->failures[] = "{$publisher} {$owner}: input without input_key";
                continue;
            }
            if (isset($catalog[$owner]['inputs'][$key])) {
                $this->failures[] = "{$publisher} {$owner}: duplicate input_key {$key}";
                continue;
            }
            $catalog[$owner]['inputs'][$key] = $row;
        }

        return count($this->failures) === $before ? $catalog : null;
    }

    /**
     * Hub publish metadata (spec C5): optional, but when present `hub_version` is a whole number of at least 1 and
     * `hub_content_sha256` a lowercase sha256 hex digest, both together.
     */
    private function checkHubMetadata(string $publisher, array $catalog): void
    {
        foreach ($catalog as $key => $entry) {
            $row = $entry['row'];
            if (!array_key_exists('hub_version', $row) && !array_key_exists('hub_content_sha256', $row)) {
                continue;
            }
            $version = $row['hub_version'] ?? null;
            if (!is_int($version) || $version < 1) {
                $this->failures[] = "{$publisher} {$key}: hub_version must be a whole number of at least 1 (run cms:export-widgets)";
            }
            if (!is_string($row['hub_content_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $row['hub_content_sha256']) !== 1) {
                $this->failures[] = "{$publisher} {$key}: hub_content_sha256 must be a sha256 hex digest (run cms:export-widgets)";
            }
        }
    }

    /**
     * Rule 7: per-language names (optional) are null or JSON text of a non-empty `{en?, vi?}` map of trimmed strings
     * within the column limits ({@see WidgetNameTranslations::problem()}).
     */
    private function checkTranslations(string $publisher, array $catalog): void
    {
        $limits = ['name_translations' => WidgetNameTranslations::NAME_MAX, 'description_translations' => WidgetNameTranslations::DESCRIPTION_MAX];
        foreach ($catalog as $key => $entry) {
            $rows = [['widget', $entry['row']]];
            foreach ($entry['themes'] as $themeKey => $theme) {
                $rows[] = ["theme {$themeKey}", $theme];
            }
            foreach ($rows as [$label, $row]) {
                foreach ($limits as $column => $max) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $decoded = is_string($value) ? json_decode($value, true) : null;
                    $problem = $decoded === null ? 'must be JSON text' : WidgetNameTranslations::problem($decoded, $max);
                    if ($problem !== null) {
                        $this->failures[] = "{$publisher} {$key}: {$label} {$column} {$problem}";
                    }
                }
            }
        }
    }

    /** Rule 4. */
    private function checkTestRows(string $publisher, array $catalog): void
    {
        foreach ($catalog as $key => $entry) {
            $name = (string) ($entry['row']['name'] ?? '');
            if (preg_match(self::TEST_ROW_PATTERN, $name) === 1 || preg_match(self::TEST_ROW_PATTERN, $key) === 1) {
                $this->failures[] = "{$publisher} {$key}: test row must not ship (name \"{$name}\")";
            }
        }
    }

    /** Rule 2. A missing lock treats every widget as new. */
    /** @param array<string, array<string, string>> $public */
    private function checkLock(string $publisher, string $directory, array $catalog, array $public): void
    {
        $path = $directory . '/' . self::LOCK_FILE;
        if (!is_file($path)) {
            $this->line("{$publisher}: no catalog.lock.php yet, every widget is treated as new.");

            return;
        }
        $lock = require $path;
        if (!is_array($lock)) {
            $this->failures[] = "{$publisher} (catalog): catalog.lock.php must return an array";

            return;
        }

        foreach ($lock as $key => $locked) {
            $lockedVersion = (int) ($locked['version'] ?? 1);
            $current = $catalog[$key] ?? null;
            if ($current === null) {
                $this->failures[] = "{$publisher} {$key}: widget removed from catalog (listed in catalog.lock.php)";
                continue;
            }
            if ($current['version'] < $lockedVersion) {
                $this->failures[] = "{$publisher} {$key}: version lowered below lock ({$current['version']} < {$lockedVersion})";
            }
            foreach ((array) ($locked['inputs'] ?? []) as $inputKey => $type) {
                $now = $public[$key][$inputKey] ?? null;
                $problem = $now === null
                    ? "input {$inputKey} removed"
                    : ($now !== $type ? "input {$inputKey} retyped ({$type} -> {$now})" : null);
                if ($problem !== null && $current['version'] <= $lockedVersion) {
                    $this->failures[] = "{$publisher} {$key}: {$problem}; raise version above {$lockedVersion}";
                }
            }
        }
    }

    /**
     * Rule 3, rendered against the local database rows of the catalog's widget keys.
     *
     * Only themes for Default or the current project are rendered (client-project themes need routes and data that
     * exist only in that project). Empty output passes (data-driven widgets render nothing without matching data);
     * a missing generated template or a render exception ('-n-') fails. Alerting is silenced meanwhile, because the
     * query service reports render exceptions to the alert channel.
     */
    private function checkRender(string $publisher, array $catalog): void
    {
        $original = app('CMSLogger');
        CMSLogger::swap($this->silentLogger());
        try {
            $renderer = app(LegacyWidgetRenderer::class);
            $resolver = new WidgetTemplatePathResolver();
            $project = (string) config('ispa-cms.folder_name', 'Default');
            foreach ($catalog as $key => $entry) {
                $widget = DB::table('widgets')->where('widget_key', $key)->first();
                if ($widget === null) {
                    $this->failures[] = "{$publisher} {$key}: not in local database (run cms:sync-widgets)";
                    continue;
                }
                if (($widget->blocked_at ?? null) !== null) {
                    $this->warn("{$publisher} {$key}: blocked in local database, render check skipped.");
                    continue;
                }
                $values = $this->sampleValues($entry['inputs']);
                foreach ($entry['themes'] as $themeKey => $themeRow) {
                    if (!$this->themeInScope($themeRow['project_name'] ?? null, $project)) {
                        $this->line("{$publisher} {$key}: theme {$themeKey} skipped (client-project theme).");
                        continue;
                    }
                    $theme = DB::table('widget_themes')->where('widget_id', $widget->id)->where('theme_key', $themeKey)->first();
                    if ($theme === null) {
                        $this->failures[] = "{$publisher} {$key}: theme {$themeKey} not in local database (run cms:sync-widgets)";
                        continue;
                    }
                    if ($resolver->resolve((int) $widget->id, (int) $theme->id) === null) {
                        $this->failures[] = "{$publisher} {$key}: theme {$themeKey} has no generated template (run the widget artifact publish)";
                        continue;
                    }
                    try {
                        $result = $renderer->render(
                            new WidgetRenderSpec((int) $widget->id, (int) $theme->id, $values),
                            new WidgetRenderContext(projectName: 'Default'),
                        );
                        if (!$result->ok) {
                            $this->failures[] = "{$publisher} {$key}: theme {$themeKey} failed to render ({$result->errorCode})";
                        } elseif (trim($result->html) === '-n-') {
                            $this->failures[] = "{$publisher} {$key}: theme {$themeKey} threw while rendering (template error)";
                        }
                    } catch (Throwable $e) {
                        $this->failures[] = "{$publisher} {$key}: theme {$themeKey} threw while rendering: " . $e->getMessage();
                    }
                }
            }
        } catch (Throwable $e) {
            $this->failures[] = "{$publisher} (render check): local database unavailable: " . $e->getMessage();
        } finally {
            CMSLogger::swap($original);
        }
    }

    /** A theme is in scope when it lists Default or the current project, or lists no project at all. */
    private function themeInScope(mixed $projects, string $project): bool
    {
        if (is_string($projects)) {
            $projects = json_decode($projects, true);
        }
        if (!is_array($projects) || $projects === []) {
            return true;
        }

        return in_array('Default', $projects, true) || in_array($project, $projects, true);
    }

    /** A logger that drops everything, so verify runs never send alerts. */
    private function silentLogger(): object
    {
        return new class {
            public function __call(string $name, array $arguments): void
            {
            }
        };
    }

    /**
     * Rule 5: hub rows in the local database must equal the exported catalog ({@see WidgetCatalogStatusService}).
     */
    private function checkUnexportedEdits(string $publisher, array $catalog): void
    {
        try {
            foreach (app(WidgetCatalogStatusService::class)->unexportedEdits($publisher, $catalog) as $key => $reasons) {
                foreach ($reasons as $reason) {
                    $this->failures[] = "{$publisher} {$key}: {$reason}";
                }
            }
        } catch (Throwable $e) {
            $this->failures[] = "{$publisher} (unexported edits): local database unavailable: " . $e->getMessage();
        }
    }

    /**
     * Sample values built from the input types; keys are the input names the renderer allowlists.
     *
     * @param array<string, array<string, mixed>> $inputs
     * @return array<string, mixed>
     */
    private function sampleValues(array $inputs): array
    {
        $values = [];
        foreach ($inputs as $key => $input) {
            $options = $input['options'] ?? null;
            if (is_string($options)) {
                $options = json_decode($options, true);
            }
            $options = is_array($options) ? $options : [];
            $values[(string) ($input['name'] ?? $key)] = $this->sample((string) ($input['type'] ?? 'text'), $options);
        }

        return $values;
    }

    /** @param array<string, mixed> $options */
    private function sample(string $type, array $options): mixed
    {
        return match ($type) {
            'text' => 'Sample',
            'textarea' => '<p>Sample</p>',
            'image', 'upload' => '',
            'repeater' => [$this->repeaterItem((array) ($options['fields'] ?? []))],
            'select', 'radio' => $this->firstOption($options),
            default => '',
        };
    }

    /** @param array<int, mixed> $fields */
    private function repeaterItem(array $fields): array
    {
        $item = [];
        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $item[$field['name']] = $this->sample((string) ($field['type'] ?? 'text'), $field);
            }
        }

        return $item;
    }

    /** @param array<string, mixed> $options */
    private function firstOption(array $options): string
    {
        $list = $options['options'] ?? $options['items'] ?? $options;
        $first = is_array($list) ? reset($list) : false;
        if (is_array($first)) {
            $first = $first['value'] ?? reset($first);
        }

        return is_scalar($first) ? (string) $first : '';
    }
}
