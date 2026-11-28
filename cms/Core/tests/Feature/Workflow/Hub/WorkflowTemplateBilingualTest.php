<?php

declare(strict_types=1);

/**
 * Guard: every workflow template is written in Vietnamese and in
 * English. A template is any console command under `cms/<Module>/app/Console` that creates a workflow project, found by
 * scanning the files, so a new installer is checked without being added here. Each one is run twice, `--locale=vi` and
 * `--locale=en --suffix=-en` (the copies `cms:workflow-bundle-build --translation=en:<slug>` turns into one bundle), and
 * the two workflows must differ only in text ({@see WorkflowTemplateTranslations::derive()}) with every piece of text
 * in both languages: the name, the description, every step name and every config text people read or the AI follows.
 *
 * An installer that only ever runs on one site and never goes to the hub library says so itself, with the reason, in a
 * `SITE_ONLY_WORKFLOW` class constant (its module knows the site, Core does not); the hub refuses to publish a
 * one-language template anyway (WorkflowTemplateCatalogService::publish).
 */

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Hub\WorkflowTemplateTranslations;
use Cms\Core\Services\Workflow\Package\WorkflowPackageExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Step config keys whose text people read (notices, review titles, checks) or the AI follows (instructions, labels). */
const WBL_TEXT_KEYS = ['message', 'instructions', 'title', 'labels', 'subject', 'body', 'prompt', 'system_prompt', 'systemPrompt'];

/**
 * Every console command class in a module that creates a workflow project: class short name => [fully qualified name,
 * the reason of its `SITE_ONLY_WORKFLOW` constant or null for a hub template].
 *
 * @return array<string, array{0: string, 1: ?string}>
 */
function wblScan(): array
{
    $found = [];
    // Repository root from this file (the dataset is built before the application boots).
    foreach (glob(dirname(__DIR__, 6) . '/cms/*/app/Console', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
            if (!str_contains($source, 'WorkflowProject::query()->create(') || preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                continue;
            }
            $short = $file->getBasename('.php');
            $siteOnly = preg_match("/const\\s+SITE_ONLY_WORKFLOW\\s*=\\s*'([^']+)'/", $source, $reason) === 1 ? $reason[1] : null;
            $found[$short] = [$ns[1] . '\\' . $short, $siteOnly];
        }
    }
    ksort($found);

    return $found;
}

/**
 * The hub template installers: class short name => fully qualified name.
 *
 * @return array<string, string>
 */
function wblInstallers(): array
{
    return array_map(static fn (array $entry): string => $entry[0], array_filter(wblScan(), static fn (array $entry): bool => $entry[1] === null));
}

/** Whether a config value holds words outside `{{ … }}` expressions (keys of label lists are values, not text). */
function wblHasWords(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            if ($key !== 'key' && wblHasWords($child)) {
                return true;
            }
        }

        return false;
    }

    return is_string($value) && preg_match('/\p{L}{3,}/u', (string) preg_replace('/\{\{.*?\}\}/s', ' ', $value)) === 1;
}

/** Slugs of the workflow projects that exist now. */
function wblSlugs(): array
{
    return WorkflowProject::withTrashed()->pluck('slug')->all();
}

test('every workflow installer is a bilingual template or a listed site-only installer', function () {
    $installers = wblInstallers();
    // The scan must find a known template (Core's, present in every tree), or it scans the wrong place.
    expect(array_keys($installers))->toContain('InstallContactWorkflowCommand');

    $missing = [];
    foreach ($installers as $short => $class) {
        if (!class_exists($class) || !defined($class . '::TEXT')) {
            $missing[] = $short . ': no TEXT constant with the second language';
        }
    }
    expect($missing)->toBe([]);
    // A site-only installer names its reason.
    foreach (wblScan() as $short => [, $siteOnly]) {
        expect($siteOnly)->not->toBe('', $short);
    }
});

test('each template installer writes every text in Vietnamese and in English', function (string $class) {
    $command = app($class);
    $name = $command->getName();
    if (!array_key_exists($name, Artisan::all())) {
        $this->markTestSkipped("{$name} is not registered: its module is off in this environment.");
    }
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    $before = wblSlugs();
    $this->artisan($name, ['--locale' => 'vi'])->assertSuccessful();
    $created = array_values(array_diff(wblSlugs(), $before));
    $this->artisan($name, ['--locale' => 'en', '--suffix' => '-en'])->assertSuccessful();
    expect($created)->not->toBe([]);

    $exporter = app(WorkflowPackageExporter::class);
    $problems = [];
    foreach ($created as $slug) {
        $vi = $exporter->export($slug)['workflow'];
        $en = $exporter->export($slug . '-en')['workflow'];
        $text = WorkflowTemplateTranslations::derive($vi, $en);

        if (!isset($text['name'])) {
            $problems[] = "{$slug}: the name is the same in both languages";
        }
        if (trim((string) ($vi['description'] ?? '')) !== '' && !isset($text['description'])) {
            $problems[] = "{$slug}: the description is the same in both languages";
        }
        foreach ((array) $vi['steps'] as $step) {
            $code = (string) $step['step_code'];
            if (!isset($text['steps'][$code]['name'])) {
                $problems[] = "{$slug}.{$code}: the step name is the same in both languages";
            }
            foreach (WBL_TEXT_KEYS as $key) {
                if (wblHasWords($step['step_config'][$key] ?? null) && !array_key_exists($key, $text['steps'][$code]['config'] ?? [])) {
                    $problems[] = "{$slug}.{$code}.{$key}: the text is the same in both languages";
                }
            }
        }
    }
    expect($problems)->toBe([]);
})->with(fn (): array => array_map(static fn (string $class): array => [$class], wblInstallers()));
