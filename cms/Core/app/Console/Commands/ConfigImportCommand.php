<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplyRefused;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Illuminate\Console\Command;
use JsonException;

/**
 * Compares a configuration package file with this site (--dry-run) or applies a successful dry run (--apply).
 *
 * The dry run executes synchronously and stores a run record. The output shows the run uuid, per section counts and
 * the keys of required secrets, never any value. --apply takes the dry run uuid (--run), optionally the package file
 * (its checksum must match the dry run) and a JSON choices file (section => item key => true); the applier re-checks
 * Webmaster, age, checksum and drift, takes the backup and applies (inline when the queue is `sync`).
 */
class ConfigImportCommand extends Command
{
    protected $signature = 'cms:config-import
        {file? : Path of the configuration package JSON file (required for --dry-run, optional for --apply)}
        {--dry-run : Compare the package with this site and store the plan; nothing is changed}
        {--apply : Apply a successful dry run (needs --run)}
        {--run= : Uuid of the dry run to apply}
        {--choices= : JSON file of choices: {"section": {"item key": true}}}
        {--user=1 : Id of the user the run is attributed to}';

    protected $description = 'Compare a configuration package with this site (dry run) or apply a dry run.';

    /**
     * Dispatch to the dry run or the apply path.
     *
     * @return int Command exit code.
     */
    public function handle(ConfigPackageDiffer $differ): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('apply')) {
            $this->error('Choose exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            return $this->apply();
        }

        if ($this->argument('file') === null) {
            $this->error('--dry-run needs the package file.');

            return self::FAILURE;
        }

        $package = $this->readJsonFile((string) $this->argument('file'), 'package');

        if ($package === null) {
            return self::FAILURE;
        }

        $run = $differ->dryRun($package, (int) $this->option('user'));
        $this->line('Run: ' . $run->uuid);

        if ($run->status !== ConfigPackageRun::STATUS_SUCCEEDED) {
            $this->error((string) ($run->summary['error'] ?? 'Dry run failed.'));

            foreach ((array) ($run->summary['errors'] ?? []) as $message) {
                $this->line(' - ' . $message);
            }

            return self::FAILURE;
        }

        $plan = (array) $run->plan;

        foreach ((array) $plan['sections'] as $name => $section) {
            $counts = (array) $section['counts'];
            $this->line(sprintf(
                '%s: %s (create %d, update %d, unchanged %d, blocked %d, skipped %d)',
                $name,
                $section['status'],
                $counts['create'] ?? 0,
                $counts['update'] ?? 0,
                $counts['unchanged'] ?? 0,
                $counts['blocked'] ?? 0,
                $counts['skipped'] ?? 0,
            ));
        }

        foreach ((array) $plan['warnings'] as $warning) {
            $this->warn((string) $warning);
        }

        $secrets = (array) $plan['required_secrets'];
        $this->line('Secrets already set here: ' . (implode(', ', $secrets['already_set_here']) ?: '-'));
        $this->line('Secrets missing here: ' . (implode(', ', $secrets['missing_here']) ?: '-'));
        $this->line('Choices: ' . count((array) $plan['choices']));

        return self::SUCCESS;
    }

    /**
     * Apply a successful dry run, after checking the optional package file against it.
     *
     * @return int Command exit code.
     */
    private function apply(): int
    {
        $uuid = (string) ($this->option('run') ?? '');

        if ($uuid === '') {
            $this->error('--apply needs --run=<uuid> of a successful dry run.');

            return self::FAILURE;
        }

        $dryRun = ConfigPackageRun::query()->where('uuid', $uuid)->where('kind', ConfigPackageRun::KIND_DRY_RUN)->first();

        if ($dryRun === null) {
            $this->error('No dry run with this uuid.');

            return self::FAILURE;
        }

        if ($this->argument('file') !== null) {
            $package = $this->readJsonFile((string) $this->argument('file'), 'package');

            if ($package === null) {
                return self::FAILURE;
            }

            if (($package['checksum'] ?? null) !== $dryRun->package_checksum || $dryRun->package_checksum === null) {
                $this->error('The package file checksum does not match the dry run; run the dry run with this file first.');

                return self::FAILURE;
            }
        }

        $choices = [];
        if ($this->option('choices') !== null) {
            $choices = $this->readJsonFile((string) $this->option('choices'), 'choices');

            if ($choices === null) {
                return self::FAILURE;
            }
        }

        try {
            $run = app(ConfigPackageApplier::class)->apply($dryRun, $choices, (int) $this->option('user'));
        } catch (ConfigPackageApplyRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Run: ' . $run->uuid);
        $this->line('Status: ' . $run->status);

        if ($run->status === ConfigPackageRun::STATUS_QUEUED) {
            $this->line('The apply runs in the background.');

            return self::SUCCESS;
        }

        foreach ((array) ($run->summary['sections'] ?? []) as $name => $section) {
            $this->line(sprintf(
                '%s: %s (updated %d, unchanged %d, skipped %d, blocked %d)',
                $name,
                $section['status'] ?? '-',
                $section['updated'] ?? 0,
                $section['unchanged'] ?? 0,
                $section['skipped'] ?? 0,
                $section['blocked'] ?? 0,
            ));
        }

        if ($run->status !== ConfigPackageRun::STATUS_SUCCEEDED) {
            $this->error((string) ($run->summary['error'] ?? 'Apply failed.'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Read a JSON object file; prints an error and returns null when it cannot be read or decoded.
     *
     * @param string $label Word used in the error messages (package, choices).
     *
     * @return array<array-key, mixed>|null
     */
    private function readJsonFile(string $file, string $label): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            $this->error("Cannot read the {$label} file.");

            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The file is not valid JSON.');

            return null;
        }

        if (!is_array($data)) {
            $this->error("The file does not contain a {$label} object.");

            return null;
        }

        return $data;
    }
}
