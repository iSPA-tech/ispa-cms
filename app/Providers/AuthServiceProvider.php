<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use App\Models\Place;
use App\Models\Banner;
use App\Models\Comment;
use App\Models\Contact;
use App\Models\Gallery;
use App\Models\Province;
use App\Policies\PlacePolicy;
use App\Policies\BannerPolicy;
use App\Policies\CommentPolicy;
use App\Policies\ContactPolicy;
use App\Policies\GalleryPolicy;
use App\Policies\ProvincePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    private const WEBMASTER_MANAGED_PERMISSION_RESOURCES = [
        'permission',
        'role',
    ];

    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Banner::class => BannerPolicy::class,
        Comment::class => CommentPolicy::class,
        Contact::class => ContactPolicy::class,
        Gallery::class => GalleryPolicy::class,
        Place::class => PlacePolicy::class,
        Province::class => ProvincePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Unified authorization: handle string permission keys via DB permissions
        // while preserving policy-based authorization for model checks
        \Illuminate\Support\Facades\Gate::before(function ($user, string $ability, array $arguments = []) {
            if (!($user instanceof \App\Models\User)) {
                return null;
            }

            // Detect if this is a permission key (contains dots) vs a policy method
            $isPermissionKey = str_contains($ability, '.');

            // Only decide for permission-key strings WITHOUT arguments
            // Example: user()->can('education.subject.create')
            if ($isPermissionKey && empty($arguments)) {
                if ($this->shouldBypassForWebmaster($user, $ability)) {
                    return true;
                }

                return $user->hasPermissionResolved($ability);
            }

            // For model checks (has arguments), return null so Policies run
            // Example: user()->can('update', $model) - ownership checks work
            return null;
        });
    }

    private function shouldBypassForWebmaster(\App\Models\User $user, string $ability): bool
    {
        if (!method_exists($user, 'isWebmaster') || !$user->isWebmaster()) {
            return false;
        }

        foreach (self::WEBMASTER_MANAGED_PERMISSION_RESOURCES as $resource) {
            if (preg_match('/(^|\.)' . preg_quote($resource, '/') . '\.[^.]+$/', $ability) === 1) {
                return true;
            }
        }

        return false;
    }
}
