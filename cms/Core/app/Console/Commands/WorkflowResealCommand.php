<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Illuminate\Console\Command;

/**
 * Re-seal workflow versions with the current APP_KEY after a key rotation (guardrails B2).
 *
 * After APP_KEY changes every seal fails, so every run is refused and the integrity check pauses every active
 * workflow. Run this right after rotating the key:
 *
 *   php artisan cms:workflow-reseal --force --previous-key="base64:<old key>"
 *
 * A version is re-sealed only when its stored graph matches its content hash AND its current seal was made with the
 * current key or one of the previous keys (`--previous-key`, repeatable, plus `app.previous_keys`). That keeps a
 * version someone wrote into the database from being blessed by the reseal. A version that matches no key is skipped
 * and reported (exit 1); `--accept-unverified` re-seals those too, after a person has checked them. Audited.
 *
 * @package Cms\Core\Console\Commands
 * @since   2026-11-24
 */
class WorkflowResealCommand extends Command
{
    public const AUDIT_ACTION = 'workflow.versions_resealed';

    protected $signature = 'cms:workflow-reseal
        {--force : Required: re-seal workflow versions with the current APP_KEY}
        {--previous-key=* : An APP_KEY the versions were sealed with (as in .env, base64: allowed)}
        {--accept-unverified : Also re-seal versions whose seal matches no known key (check them first)}';

    protected $description = 'Re-seal workflow versions with the current APP_KEY after a key rotation.';

    public function handle(): int
    {
        if (!$this->option('force')) {
            $this->error('Refused: pass --force to re-seal workflow versions (see the command help).');

            return self::FAILURE;
        }

        $previousKeys = array_values(array_filter(array_merge(
            (array) $this->option('previous-key'),
            (array) config('app.previous_keys', []),
        ), static fn ($key): bool => is_string($key) && $key !== ''));
        $currentKey = (string) config('app.key');
        $acceptUnverified = (bool) $this->option('accept-unverified');

        $resealed = [];
        $skipped = [];
        $current = 0;

        WorkflowVersion::query()->orderBy('id')->chunkById(200, function ($versions) use (&$resealed, &$skipped, &$current, $previousKeys, $currentKey, $acceptUnverified): void {
            foreach ($versions as $version) {
                $raw = $version->getAttributes()['graph'] ?? null;
                if (!is_string($raw) || !hash_equals((string) $version->content_hash, WorkflowVersionSnapshot::hashText($raw))) {
                    $skipped[] = ['id' => $version->id, 'reason' => 'hash_mismatch'];
                    continue;
                }
                if (WorkflowVersionSeal::sealedWith($version, $currentKey)) {
                    $current++;
                    continue;
                }

                $known = false;
                foreach ($previousKeys as $key) {
                    if (WorkflowVersionSeal::sealedWith($version, $key)) {
                        $known = true;
                        break;
                    }
                }
                if (!$known && !$acceptUnverified) {
                    $skipped[] = ['id' => $version->id, 'reason' => 'seal_unknown'];
                    continue;
                }

                // A query update: the model refuses to change `seal`.
                WorkflowVersion::query()->whereKey($version->id)->update(['seal' => WorkflowVersionSeal::seal($version)]);
                $resealed[] = ['id' => $version->id, 'verified' => $known];
            }
        });

        // Live pointers (review fix 15): same rule, a pointer is re-sealed only when its seal matches a known key.
        $pointers = $this->resealPointers($currentKey, $previousKeys, $acceptUnverified, $skipped);

        event(new AuditLogEvent(
            self::AUDIT_ACTION,
            [
                'resealed' => count($resealed),
                'resealed_unverified' => array_values(array_column(array_filter($resealed, static fn ($r) => !$r['verified']), 'id')),
                'skipped' => $skipped,
                'already_current' => $current,
                'pointers_resealed' => $pointers,
            ],
            WorkflowVersion::class,
            0,
            'fa fa-key',
            'warning',
        ));

        $this->line(sprintf('Re-sealed %d version(s); %d already current; %d skipped.', count($resealed), $current, count($skipped)));
        $this->line(sprintf('Re-sealed %d live pointer(s).', $pointers));
        foreach ($skipped as $row) {
            isset($row['project_id'])
                ? $this->warn("Live pointer of workflow #{$row['project_id']} skipped: {$row['reason']}.")
                : $this->warn("Version #{$row['id']} skipped: {$row['reason']}.");
        }

        return $skipped === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Re-seal `workflow_projects.published_version_seal` with the current key.
     *
     * @param list<string>                      $previousKeys
     * @param list<array<string, mixed>>        $skipped
     */
    protected function resealPointers(string $currentKey, array $previousKeys, bool $acceptUnverified, array &$skipped): int
    {
        if (!WorkflowVersionSeal::pointerSealed()) {
            return 0;
        }

        $resealed = 0;
        WorkflowProject::query()->whereNotNull('published_version_id')->orderBy('id')
            ->chunkById(200, function ($projects) use (&$resealed, &$skipped, $currentKey, $previousKeys, $acceptUnverified): void {
                foreach ($projects as $project) {
                    if (WorkflowVersionSeal::verifyPointer($project, $currentKey)) {
                        continue;
                    }
                    $known = false;
                    foreach ($previousKeys as $key) {
                        if (WorkflowVersionSeal::verifyPointer($project, $key)) {
                            $known = true;
                            break;
                        }
                    }
                    if (!$known && !$acceptUnverified) {
                        $skipped[] = ['project_id' => $project->id, 'reason' => 'pointer_seal_unknown'];
                        continue;
                    }
                    WorkflowProject::query()->whereKey($project->id)->update([
                        'published_version_seal' => WorkflowVersionSeal::signPointer((int) $project->id, (int) $project->published_version_id),
                    ]);
                    $resealed++;
                }
            });

        return $resealed;
    }
}
