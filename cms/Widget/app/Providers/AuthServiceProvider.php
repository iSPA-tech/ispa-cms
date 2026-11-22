<?php

namespace Cms\Widget\Providers;

use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Policies\WidgetInstancePolicy;
use Cms\Widget\Policies\WidgetLayoutPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Registers Widget module policies for Eloquent models.
 *
 * Chunk 2 Task 14: WidgetInstance/WidgetLayout policy mappings land here.
 * `widget_template.approve`/`widget_template.emergencyDisable` need no
 * registration in this file -- they are dot-form permission-key strings
 * with no backing Eloquent model (no Manifest policy class exists yet;
 * approval logic is Task 15b's scope), so `$user->can('widget_template.approve')`
 * already resolves through the application-wide unified-authorization
 * Gate::before hook in app/Providers/AuthServiceProvider.php (checks
 * `str_contains($ability, '.') && empty($arguments)`, then
 * `$user->hasPermissionResolved($ability)`) -- no per-module Gate::define
 * call is needed for that path.
 *
 * @package Cms\Widget\Providers
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        WidgetInstance::class => WidgetInstancePolicy::class,
        WidgetLayout::class => WidgetLayoutPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
