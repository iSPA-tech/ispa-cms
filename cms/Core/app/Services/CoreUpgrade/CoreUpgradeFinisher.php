<?php

declare(strict_types=1);

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Services\Toolchain\ProcessRunner;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The steps after the core files of an update are in place: migrations, module permissions, caches, the admin config,
 * translations and language packs, and a local rebuild when the site builds its own assets.
 *
 * They belong to the version being installed. {@see CoreUpgrader} runs them through `cms:core-upgrade-finish` of the
 * new code ({@see \Cms\Core\Console\Commands\CoreUpgradeFinishCommand}), in a process that loads the new files: the
 * updater itself is the old version, loaded before the swap, and a step the new version added would otherwise be
 * skipped (demo.ispa.tech 2026-11-28: the 2026.11.07 updater went to 2026.11.09 without writing its language packs, so
 * the admin showed raw keys). A package without that command gets these steps run by the updater.
 *
 * Every step is its own artisan process, so each boots the code and configuration the previous one left.
 */
final class CoreUpgradeFinisher
{
    /** The new version's entry point; the updater hands the steps over to it. */
    public const COMMAND = 'cms:core-upgrade-finish';

    /** Where a core tree has that command: its presence tells the updater the new version runs its own steps. */
    public const COMMAND_FILE = 'cms/Core/app/Console/Commands/CoreUpgradeFinishCommand.php';

    /**
     * Set on the `cms:admin-config` step: the packs are written by the steps below, so the command does not write them
     * on its own (its bridge for updates run by an older updater, {@see \Cms\Core\Console\Commands\AdminConfigCommand}).
     */
    public const FINISHING_ENV = 'ISPA_CORE_FINISHING';

    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * @param string $root The code folder the update wrote (the new release in a release layout).
     * @param string $base The site folder whose storage holds the local-build marker.
     * @param bool   $deploy A CI-deployed release: config/routes cached and the full language files rebuilt, as the deploy does.
     *
     * @return list<string> Warnings; the update still counts as done.
     *
     * @throws CoreUpgradeException CORE_MIGRATE_FAILED or CORE_PERMISSIONS_FAILED (the updater puts the old files back).
     */
    public function run(string $root, string $base, bool $deploy): array
    {
        $warnings = [];
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $artisan = fn (array $args, array $env = []): int => $this->runner->run(array_merge([$php, 'artisan'], $args), $root, $env, true);

        if ($artisan(['migrate', '--force']) !== 0) {
            throw new CoreUpgradeException('CORE_MIGRATE_FAILED', '`php artisan migrate --force` failed.');
        }
        if ($artisan(['cms:modules-sync-permissions']) !== 0) {
            throw new CoreUpgradeException('CORE_PERMISSIONS_FAILED', 'Syncing module permissions failed.');
        }
        $artisan(['optimize:clear']);
        if ($artisan(['cms:admin-config'], [self::FINISHING_ENV => '1']) !== 0) {
            $warnings[] = 'Could not write public/iPanel/config.js; run `./ispa build` or `php artisan cms:admin-config`.';
        }
        if ($deploy) {
            // What the deploy does after activation: cached config and routes and translations imported from the new
            // sidecars. The admin is rebuilt once the health check passed (CoreUpgrader::rebuildDeployAdmin).
            if ($artisan(['config:cache']) !== 0) {
                $warnings[] = 'Could not cache the configuration; run `php artisan config:cache`.';
            }
            if ($artisan(['route:cache']) !== 0) {
                $artisan(['route:clear']);
                $warnings[] = 'Could not cache the routes; the site runs with uncached routes.';
            }
            if ($artisan(['cms:translation-sync', '--all']) !== 0) {
                $warnings[] = 'Could not import the new translations; run `php artisan cms:translation-sync --all`.';
            }
            // The admin and site language packs (storage/app/public/i18n/<VERSION>/..., lang/*) are built from the
            // translates table, as the deploy does after its sync; without this the new admin texts showed raw keys.
            if ($artisan(['cms:translation-generate']) !== 0) {
                $warnings[] = 'Could not rebuild the language packs; run `php artisan cms:translation-generate`.';
            }
        } else {
            // The admin loads its packs from storage/app/public/i18n/<VERSION>/adm, and VERSION just changed: without
            // packs under the new version every pack request is a 404 and the admin shows humanized keys. Only the
            // packs are written (--public-only), as a module install does: lang/* and the module seeders are core files
            // the next update checks for changes.
            if ($artisan(['cms:translation-sync', '--all']) !== 0) {
                $warnings[] = 'Could not import the new translations; run `php artisan cms:translation-sync --all`.';
            }
            if ($artisan(['cms:translation-generate', '--public-only']) !== 0) {
                $warnings[] = 'Could not write the admin language packs; run `php artisan cms:translation-generate --public-only`.';
            }
        }

        $marker = self::readJson($base.'/storage/app/ispa/custom-build.json');
        $flags = array_values(array_filter([
            ($marker['site'] ?? false) === true ? '--site' : null,
            ($marker['admin'] ?? false) === true ? '--admin' : null,
        ]));
        if ($flags !== []) {
            if ($this->runner->run(['node', '--version'], null, [], true) !== 0) {
                $warnings[] = 'This site was built locally and Node.js was not found: run `./ispa build` to rebuild the assets.';
            } elseif ($artisan(array_merge(['cms:build'], $flags)) !== 0) {
                $warnings[] = 'The local rebuild failed; the packaged build is in place. Run `./ispa build` to see why.';
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }
}
