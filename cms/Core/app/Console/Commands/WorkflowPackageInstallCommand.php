<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInstaller;
use Illuminate\Console\Command;

/**
 * Install a workflow package as a new, inactive, unscheduled workflow.
 *
 * Usage: php artisan cms:workflow-package-install file.workflow.json --user=<uuid or email> [--bind=user_1=<uuid>]
 *        [--as=new-slug]
 * The user must hold `workflow_project.create`; node permissions are checked against them. An existing slug is
 * refused: pass --as to install a copy under a new slug.
 *
 * @package Cms\Core\Console\Commands
 * @since   2026-11-24
 */
class WorkflowPackageInstallCommand extends Command
{
    protected $signature = 'cms:workflow-package-install
        {file : Workflow package file}
        {--user= : UUID or email of the installing user}
        {--bind=* : Binding values, name=value}
        {--as= : Install as a copy under this slug}';

    protected $description = 'Install a workflow package (add-only, inactive, no schedule).';

    public function handle(WorkflowPackageInstaller $installer): int
    {
        $package = json_decode((string) @file_get_contents((string) $this->argument('file')), true);
        if (!is_array($package)) {
            $this->error('The file must hold a JSON workflow package.');

            return self::FAILURE;
        }

        $user = WorkflowPackageCheckCommand::user((string) $this->option('user'));
        if ($user === null || !$user->can('workflow_project.create')) {
            $this->error('Pass --user=<uuid or email> of a user who may create workflows.');

            return self::FAILURE;
        }

        try {
            $result = $installer->install($package, $user, WorkflowPackageCheckCommand::bindings((array) $this->option('bind')),
                $this->option('as') ? (string) $this->option('as') : null);
        } catch (WorkflowPackageException $e) {
            $this->error($e->getMessage());
            foreach (['problems', 'binding_problems', 'slug_problems'] as $list) {
                foreach ((array) ($e->report[$list] ?? []) as $problem) {
                    $this->line("- {$problem['code']}: {$problem['message']}");
                }
            }

            return self::FAILURE;
        }

        $this->info("Installed workflow {$result['slug']} (inactive, no schedule). Review it in the workflow editor before enabling it.");

        return self::SUCCESS;
    }
}
