<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Cms\Core\Services\Import\ImportDefinitionInterface;
use Cms\Core\Services\Template\ImportTemplateGenerator;

/**
 * Generates downloadable import template files for all registered CMS import definitions.
 *
 * Discovers classes implementing ImportDefinitionInterface from cms module directories
 * (app/Import/ and app/Services/Import/) and from app/Services/Import/. For each definition
 * it generates template files in the configured formats (CSV, XLSX, XLS) via the
 * ImportTemplateGenerator service, then creates public symlinks so the templates are
 * accessible via the web. An .htaccess file is also placed to prevent directory listing.
 *
 * Usage: php artisan cms:generate-import-templates
 * Options:
 *   --module       Specific module to generate templates for
 *   --definition   Specific definition class name to generate
 *   --formats      Comma-separated list of formats (default: csv,xlsx,xls)
 *   --force        Overwrite existing template files
 *
 * @see    \Cms\Core\Services\Import\ImportDefinitionInterface
 * @see    \Cms\Core\Services\Template\ImportTemplateGenerator
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GenerateImportTemplatesCommand extends Command
{
    protected $signature = 'cms:generate-import-templates
                            {--module= : Specific module to generate templates for}
                            {--definition= : Specific definition class name}
                            {--formats=csv,xlsx,xls : Comma-separated list of formats}
                            {--force : Overwrite existing files}';

    protected $description = 'Generate import template files for all or specific import definitions';

    protected ImportTemplateGenerator $generator;

    /**
     * Create a new command instance.
     *
     * @param ImportTemplateGenerator $generator Template generation service
     */
    public function __construct(ImportTemplateGenerator $generator)
    {
        parent::__construct();
        $this->generator = $generator;
    }

    /**
     * Execute the console command.
     *
     * Discovers all matching import definitions, calls the generator for each one,
     * creates public symlinks, and prints a summary of generated/skipped/warning counts.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        $this->info('🚀 Generating import templates...');
        $this->newLine();

        $module = $this->option('module');
        $definitionFilter = $this->option('definition');
        $formats = explode(',', $this->option('formats'));
        $force = $this->option('force');

        // Discover definitions from CMS modules and App-level
        $definitions = $this->discoverDefinitions($module, $definitionFilter);
        $definitions = array_merge($definitions, $this->discoverAppDefinitions($definitionFilter));

        if (empty($definitions)) {
            $this->warn('No import definitions found.');
            return self::FAILURE;
        }

        $this->info("Found " . count($definitions) . " definition(s)");
        $this->newLine();

        $totalGenerated = 0;
        $totalSkipped = 0;
        $totalWarnings = 0;
        $modulesProcessed = [];

        foreach ($definitions as $className => $filePath) {
            try {
                // Instantiate definition
                $definition = app($className);

                if (!$definition instanceof ImportDefinitionInterface) {
                    $this->warn("⚠️  {$className} does not implement ImportDefinitionInterface");
                    continue;
                }

                $this->line("📝 Processing: <fg=cyan>{$className}</>");

                // Generate templates
                $result = $this->generator->generate($definition, $formats, $force);

                // Track module for symlink creation
                $templateKey = $definition->templateKey();
                [$moduleName] = explode('/', $templateKey);
                $modulesProcessed[$moduleName] = true;

                // Display results
                foreach ($result['generated'] as $file) {
                    $this->line("   ✅ Generated: <fg=green>{$file}</>");
                    $totalGenerated++;
                }

                foreach ($result['skipped'] as $file) {
                    $this->line("   ⏭️  Skipped: <fg=yellow>{$file}</> (use --force to overwrite)");
                    $totalSkipped++;
                }

                foreach ($result['warnings'] as $warning) {
                    $this->line("   ⚠️  Warning: <fg=red>{$warning}</>");
                    $totalWarnings++;
                }

                $this->newLine();

            } catch (Exception $e) {
                $this->error("   ❌ Error: " . $e->getMessage());
                $this->newLine();
            }
        }

        // Create symlinks for processed modules
        if (!empty($modulesProcessed)) {
            $this->info('🔗 Creating public symlinks...');
            $this->newLine();

            foreach (array_keys($modulesProcessed) as $moduleName) {
                $this->createPublicSymlink($moduleName);
            }

            $this->newLine();
        }

        // Summary
        $this->info('📊 Summary:');
        $this->line("   Generated: <fg=green>{$totalGenerated}</>");
        $this->line("   Skipped: <fg=yellow>{$totalSkipped}</>");
        $this->line("   Warnings: <fg=red>{$totalWarnings}</>");

        return self::SUCCESS;
    }

    /**
     * Discover import definition classes
     *
     * @param string|null $moduleFilter
     * @param string|null $definitionFilter
     *
     * @return array [className => filePath]
     */
    protected function discoverDefinitions(?string $moduleFilter, ?string $definitionFilter): array
    {
        $definitions = [];
        $cmsPath = base_path('cms');

        if (!File::exists($cmsPath)) {
            return $definitions;
        }

        // Get all modules
        $modules = File::directories($cmsPath);

        foreach ($modules as $modulePath) {
            $moduleName = basename($modulePath);

            // Filter by module if specified
            if ($moduleFilter && $moduleName !== $moduleFilter) {
                continue;
            }

            // Check for Import or Services/Import directory
            $importPaths = [
                "{$modulePath}/app/Import",
                "{$modulePath}/app/Services/Import",
            ];

            foreach ($importPaths as $importPath) {
                if (!File::exists($importPath)) {
                    continue;
                }

                // Find all *ImportDefinition.php files
                $files = File::glob("{$importPath}/*ImportDefinition.php");

                foreach ($files as $file) {
                    $className = $this->getClassNameFromFile($file, $moduleName);

                    if (!$className) {
                        continue;
                    }

                    // Filter by definition if specified
                    if ($definitionFilter && !str_contains($className, $definitionFilter)) {
                        continue;
                    }

                    $definitions[$className] = $file;
                }
            }
        }

        return $definitions;
    }

    /**
     * Extract class name from file path
     *
     * @param string $filePath
     * @param string $moduleName
     *
     * @return string|null
     */
    protected function getClassNameFromFile(string $filePath, string $moduleName): ?string
    {
        $fileName = basename($filePath, '.php');

        // Determine namespace based on path
        if (str_contains($filePath, '/app/Import/')) {
            return "Cms\\{$moduleName}\\Import\\{$fileName}";
        } else if (str_contains($filePath, '/app/Services/Import/')) {
            return "Cms\\{$moduleName}\\Services\\Import\\{$fileName}";
        }

        return null;
    }

    /**
     * Discover App-level import definitions in app/Services/Import/
     *
     * @param string|null $definitionFilter
     *
     * @return array [className => filePath]
     */
    protected function discoverAppDefinitions(?string $definitionFilter): array
    {
        $definitions = [];
        $importPath = app_path('Services/Import');

        if (!File::exists($importPath)) {
            return $definitions;
        }

        $files = File::glob("{$importPath}/*ImportDefinition.php");

        foreach ($files as $file) {
            $fileName = basename($file, '.php');
            $className = "App\\Services\\Import\\{$fileName}";

            if ($definitionFilter && !str_contains($className, $definitionFilter)) {
                continue;
            }

            $definitions[$className] = $file;
        }

        return $definitions;
    }

    /**
     * Create public symlink for module templates
     *
     * @param string $moduleName
     *
     * @return void
     */
    protected function createPublicSymlink(string $moduleName): void
    {
        // App-level definitions use storage/template/ instead of cms/{module}/storage/template
        $moduleStoragePath = strtolower($moduleName) === 'app'
            ? storage_path("template/{$moduleName}")
            : base_path("cms/{$moduleName}/storage/template");
        $publicTemplatePath = public_path("template/{$moduleName}");

        // Check if module storage path exists
        if (!File::exists($moduleStoragePath)) {
            $this->warn("   ⚠️  Module storage path not found: {$moduleStoragePath}");
            return;
        }

        // Create public/template directory if it doesn't exist
        $publicTemplateDir = public_path('template');
        if (!File::exists($publicTemplateDir)) {
            File::makeDirectory($publicTemplateDir, 0755, true);
        }

        // Remove existing symlink or directory
        if (File::exists($publicTemplatePath)) {
            if (is_link($publicTemplatePath)) {
                unlink($publicTemplatePath);
            } else {
                File::deleteDirectory($publicTemplatePath);
            }
        }

        // Create a RELATIVE symlink: releases live under a per-deploy directory
        // (releases/N/...), so an absolute target would pin the link to the
        // release that ran this command and dangle after that release is pruned.
        $relativeTarget = strtolower($moduleName) === 'app'
            ? "../../storage/template/{$moduleName}"
            : "../../cms/{$moduleName}/storage/template";

        try {
            symlink($relativeTarget, $publicTemplatePath);
            $this->line("   ✅ Symlink created: <fg=green>public/template/{$moduleName} -> {$relativeTarget}</>");
        } catch (Exception $e) {
            $this->error("   ❌ Failed to create symlink: " . $e->getMessage());
            return;
        }

        // Create .htaccess to block directory listing
        $this->createDirectoryProtection($moduleStoragePath);
    }

    /**
     * Create .htaccess file to block directory listing
     *
     * @param string $path
     *
     * @return void
     */
    protected function createDirectoryProtection(string $path): void
    {
        $htaccessPath = $path . '/.htaccess';

        // Skip if .htaccess already exists
        if (File::exists($htaccessPath)) {
            return;
        }

        $htaccessContent = <<<'HTACCESS'
# Disable directory listing
Options -Indexes

# Allow access to template files only
<FilesMatch "\.(csv|txt|xls|xlsx)$">
    Require all granted
</FilesMatch>

# Deny access to everything else
<FilesMatch "^(?!.*\.(csv|txt|xls|xlsx)$).*$">
    Require all denied
</FilesMatch>
HTACCESS;

        try {
            File::put($htaccessPath, $htaccessContent);
            $this->line("   🔒 Directory protection added: <fg=green>{$htaccessPath}</>");
        } catch (Exception $e) {
            $this->warn("   ⚠️  Failed to create .htaccess: " . $e->getMessage());
        }
    }
}
