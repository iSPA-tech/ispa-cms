<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Providers;

use App\Providers\Abstracts\ModuleServiceProvider;
use Cms\Content\Console\Commands\AgentAnswerDigestsCommand;
use Cms\Content\Console\Commands\InstallWeeklyReportWorkflowCommand;
use Cms\Content\Console\Commands\PurgeDeletedPostsCommand;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\ContentAttentionProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;

/**
 * Main service provider for the Content module.
 *
 * Registers the EventServiceProvider, RouteServiceProvider, and AuthServiceProvider
 * as sub-providers. Extends ModuleServiceProvider for standardized module bootstrapping.
 *
 * @see \App\Providers\Abstracts\ModuleServiceProvider
 * @package Cms\Content\Providers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class ContentServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Content';
    }

    protected function getModuleNameLower(): string
    {
        return 'content';
    }

    /**
     * Register the service provider.
     * @return void
     */
    public function register(): void
    {
        $this->app->scoped(ContentCacheVersionService::class);
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(AuthServiceProvider::class);

        // The approval diff of draft proposals made by the content workflow nodes (or any tool proposing a draft).
        $this->callAfterResolving(\Cms\Core\Services\Agentic\AgentApprovalDiffBuilder::class, static function ($builder): void {
            $builder->register(\Cms\Content\Services\Agentic\PostDraftArtifactApplier::ARTIFACT_TYPE, new \Cms\Content\Services\Agentic\PostDraftDiff());
        });
    }

    /**
     * Boot the module: dashboard attention providers, the record type and the admin resource.
     */
    public function boot(): void
    {
        parent::boot();

        ContentAttentionProvider::register($this->app->make(\App\Services\Dashboard\AttentionRegistry::class));
        \Cms\Content\Records\ContentRecordTypes::register($this->app->make(\Cms\Core\Records\RecordRegistry::class));

        $resources = $this->app->make(\Cms\Core\Admin\Resources\ResourceRegistry::class);
        if (! $resources->has('content/contents')) {
            $resources->register('content/contents', \Cms\Content\Admin\Resources\ContentResource::class);
        }

        $this->registerPostComponents();
        $this->registerWorkflowNodes();

        // Widget Site Layout "page" target (no-op while cms/Widget is off); after every provider booted, so a
        // project's `content.widget_page_target` opt-out and cms/Widget's registrar rebind are both in place.
        $this->app->booted(fn () => \Cms\Content\Widget\PageWidgetTargetContextResolver::registerTargetContext($this->app));
    }

    /**
     * Content workflow nodes and the applier of their draft proposals, added to Core's config after every provider
     * has booted (module providers boot before the app's, so an earlier write could be dropped).
     */
    protected function registerWorkflowNodes(): void
    {
        $this->app->booted(static function (): void {
            config([
                'core.workflow_nodes.allowlisted_namespaces' => array_values(array_unique([
                    ...(array) config('core.workflow_nodes.allowlisted_namespaces', []),
                    'Cms\\Content\\Services\\Workflow\\',
                ])),
                'core.agentic.artifact_appliers' => (array) config('core.agentic.artifact_appliers', []) + [
                    \Cms\Content\Services\Agentic\PostDraftArtifactApplier::ARTIFACT_TYPE => \Cms\Content\Services\Agentic\PostDraftArtifactApplier::class,
                ],
            ]);

            if (class_exists(\Cms\Core\Workflows\Nodes\NodeRegistry::class)) {
                \Cms\Core\Workflows\Nodes\NodeRegistry::registerModuleService(\Cms\Content\Services\Workflow\PostWorkflowNodes::class);
            }
            // Designer presets (classify labels from the post categories) from config/workflow_presets.php.
            if (class_exists(\Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog::class)) {
                \Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog::registerModuleConfig('content', 'content.workflow_presets');
            }
        });
    }

    /**
     * Register the 15 post widgets under their original unprefixed Blade tags.
     *
     * Themes keep writing `<x-post-latest>` and the other tags; the `<x-content::...>` form comes from the module
     * component namespace. The views still resolve from `resources/views/components/*` and project overrides.
     */
    protected function registerPostComponents(): void
    {
        $components = [
            'about-us' => \Cms\Content\View\Components\AboutUs::class,
            'best-view-of-post' => \Cms\Content\View\Components\BestViewOfPost::class,
            'document-latest' => \Cms\Content\View\Components\DocumentLatest::class,
            'latest-audio' => \Cms\Content\View\Components\LatestAudio::class,
            'latest-document' => \Cms\Content\View\Components\LatestDocument::class,
            'latest-events' => \Cms\Content\View\Components\LatestEvents::class,
            'latest-glossary' => \Cms\Content\View\Components\LatestGlossary::class,
            'latest-video' => \Cms\Content\View\Components\LatestVideo::class,
            'post-feature' => \Cms\Content\View\Components\PostFeature::class,
            'post-latest' => \Cms\Content\View\Components\PostLatest::class,
            'post-slider' => \Cms\Content\View\Components\PostSlider::class,
            'posts-by-category' => \Cms\Content\View\Components\PostsByCategory::class,
            'related-posts' => \Cms\Content\View\Components\RelatedPosts::class,
            'save-bookmark' => \Cms\Content\View\Components\SaveBookmark::class,
            'static-page-view' => \Cms\Content\View\Components\StaticPageView::class,
            'tags-related' => \Cms\Content\View\Components\TagsRelated::class,
        ];

        foreach ($components as $tag => $class) {
            Blade::component($tag, $class);
        }
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PurgeDeletedPostsCommand::class,
            AgentAnswerDigestsCommand::class,
            InstallWeeklyReportWorkflowCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            // A project that runs its own post purge schedule turns this one off (`content.purge_schedule` false).
            if (config('content.purge_schedule', true) === false) {
                return;
            }
            $this->app->make(Schedule::class)
                ->command('content:purge-deleted-posts --days=30 --limit=500 --apply')
                ->dailyAt('01:30')
                ->withoutOverlapping(120)
                ->onOneServer();
        });
    }
}
