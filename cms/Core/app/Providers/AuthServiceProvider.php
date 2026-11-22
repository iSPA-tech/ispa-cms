<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Providers;

use App\Models\User;
use Cms\Core\Models\Role;
use Cms\Core\Models\Menu;
use Cms\Core\Models\Email;
use Cms\Core\Models\Field;
use Cms\Core\Models\Upload;
use Cms\Core\Models\Widget;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Storage;
use Cms\Core\Models\Language;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Category;
use Cms\Core\Models\Enumerate;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Policies\RolePolicy;
use Cms\Core\Policies\UserPolicy;
use Cms\Core\Policies\MenuPolicy;
use Cms\Core\Models\EnumerateType;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Policies\EmailPolicy;
use Cms\Core\Policies\FieldPolicy;
use Cms\Core\Policies\UploadPolicy;
use Cms\Core\Policies\WidgetPolicy;
use Cms\Core\Policies\SettingPolicy;
use Cms\Core\Policies\StoragePolicy;
use Cms\Core\Policies\LanguagePolicy;
use Cms\Core\Policies\AuditLogPolicy;
use Cms\Core\Policies\CategoryPolicy;
use Cms\Core\Policies\EnumeratePolicy;
use Cms\Core\Policies\PermissionPolicy;
use Cms\Core\Policies\WorkflowProjectPolicy;
use Cms\Core\Policies\WorkflowStepPolicy;
use Cms\Core\Policies\EnumerateTypePolicy;
use Cms\Core\Policies\MetaAttributePolicy;
use Cms\Core\Policies\MetaClassPolicy;
use Cms\Core\Policies\EmailTemplatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Registers Core module policies for Eloquent models.
 * @package Cms\Core\Providers
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AuditLog::class => AuditLogPolicy::class,
        Category::class => CategoryPolicy::class,
        Email::class => EmailPolicy::class,
        EmailTemplate::class => EmailTemplatePolicy::class,
        Enumerate::class => EnumeratePolicy::class,
        EnumerateType::class => EnumerateTypePolicy::class,
        Field::class => FieldPolicy::class,
        Language::class => LanguagePolicy::class,
        Menu::class => MenuPolicy::class,
        MetaClass::class => MetaClassPolicy::class,
        MetaAttribute::class => MetaAttributePolicy::class,
        Permission::class => PermissionPolicy::class,
        Role::class => RolePolicy::class,
        Setting::class => SettingPolicy::class,
        Storage::class => StoragePolicy::class,
        Upload::class => UploadPolicy::class,
        User::class => UserPolicy::class,
        Widget::class => WidgetPolicy::class,
        WorkflowProject::class => WorkflowProjectPolicy::class,
        WorkflowStep::class => WorkflowStepPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
