<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Workflow\Hub\WorkflowBundle;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleBuilder;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds the unsigned hub bundle of one local workflow: `<out>/<key>-<version>.workflow-bundle.json` (canonical JSON).
 *
 * The version defaults to CalVer `YYYY.MM.NN` stored as the integer YYYYMMNN (app timezone, NN = build of the month), or
 * one above the highest version of the same key already built in the output folder when that is this month's; the hub
 * refuses any version not above its latest. `--hub-version` takes `2026.12.01` or `20261201` (named so because the
 * console owns --version). Next step: `cms:workflow-bundle-sign`.
 */
class WorkflowBundleBuildCommand extends Command
{
    protected $signature = 'cms:workflow-bundle-build {slug : Slug of a workflow in the local database} {--key= : Template key on the hub, e.g. ispa.church-story (never renamed)} {--hub-version= : YYYY.MM.NN (default: automatic CalVer)} {--publisher=iSPA : Publisher} {--name= : Name shown in the library (default: the workflow name)} {--description= : One-line description (default: the workflow description)} {--group= : Library group, e.g. posts, support, notifications} {--notes= : What changed in this version (Vietnamese)} {--notes-en= : What changed in this version (English)} {--out= : Output directory (default storage/app/workflow-bundles)}';

    protected $description = 'Build the unsigned hub bundle of a local workflow template.';

    public function handle(WorkflowBundleBuilder $builder): int
    {
        $key = trim((string) $this->option('key'));
        if ($key === '') {
            return $this->refuse('--key is required (the template key on the hub, e.g. ispa.church-story).');
        }
        $out = rtrim((string) ($this->option('out') ?: storage_path('app/workflow-bundles')), '/');
        if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
            return $this->refuse('Cannot create ' . $out);
        }

        $versionOption = (string) $this->option('hub-version');
        if ($versionOption !== '') {
            $version = WorkflowBundle::parseVersion($versionOption);
            if ($version === null) {
                return $this->refuse('--hub-version must look like 2026.12.01.');
            }
        } else {
            $current = 0;
            foreach ((array) glob($out . '/' . $key . '-*.workflow-bundle.json') as $file) {
                if (preg_match('/-(\d{8})\.workflow-bundle\.json\z/', (string) $file, $m) === 1) {
                    $current = max($current, (int) $m[1]);
                }
            }
            $base = (int) now()->format('Ym') * 100 + 1;
            $version = $current >= $base ? $current + 1 : $base;
            if ($version % 100 === 0) {
                return $this->refuse('Already 99 builds this month; pass --hub-version.');
            }
        }

        try {
            $json = $builder->build(
                (string) $this->argument('slug'),
                $key,
                $version,
                trim((string) $this->option('publisher')) ?: 'iSPA',
                $this->option('name') !== null ? (string) $this->option('name') : null,
                $this->option('description') !== null ? (string) $this->option('description') : null,
                $this->option('group') !== null ? (string) $this->option('group') : null,
                ['vi' => (string) $this->option('notes'), 'en' => (string) $this->option('notes-en')],
            )->toCanonicalJson();
            // Parse our own output back: a bundle the format rejects fails here, not at sign or publish time.
            $bundle = WorkflowBundle::fromJson($json);
        } catch (WorkflowPackageException $e) {
            return $this->refuse('Build refused: ' . $e->getMessage());
        } catch (Throwable $e) {
            return $this->refuse('Build failed: ' . $e->getMessage());
        }

        $path = $out . '/' . $key . '-' . $version . '.workflow-bundle.json';
        $sigPath = $path . '.sig.json';
        if ((file_exists($sigPath) || is_link($sigPath)) && !@unlink($sigPath)) {
            return $this->refuse('Cannot remove the old signature ' . $sigPath . '; nothing written.');
        }
        if (file_put_contents($path, $json) !== strlen($json)) {
            @unlink($path);

            return $this->refuse('Writing ' . $path . ' failed; nothing kept.');
        }

        $this->info(sprintf('Built %s version %s (%d steps, needs modules: %s): %s', $key, WorkflowBundle::versionLabel($version), count($bundle->summary['steps']), implode(', ', $bundle->requires['modules']) ?: 'none', $path));
        $this->line('Next: php artisan cms:workflow-bundle-sign ' . $path . ' --key-file=~/.ispa-release-keys/release-signing.key');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
