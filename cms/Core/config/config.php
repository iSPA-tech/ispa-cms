<?php

use Cms\Core\Interfaces\UserInterface;
use Cms\Core\Interfaces\RoleInterface;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Interfaces\EmailInterface;
use Cms\Core\Interfaces\FieldInterface;
use Cms\Core\Interfaces\StorageInterface;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Interfaces\WidgetInterface;
use Cms\Core\Repositories\UserRepository;
use Cms\Core\Repositories\RoleRepository;
use Cms\Core\Interfaces\SettingInterface;
use Cms\Core\Repositories\MenuRepository;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Interfaces\AuditLogInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\MenuItemInterface;
use Cms\Core\Interfaces\MetaDataInterface;
use Cms\Core\Repositories\EmailRepository;
use Cms\Core\Repositories\FieldRepository;
use Cms\Core\Interfaces\TranslateInterface;
use Cms\Core\Repositories\UploadRepository;
use Cms\Core\Interfaces\EnumerateInterface;
use Cms\Core\Interfaces\MetaClassInterface;
use Cms\Core\Repositories\WidgetRepository;
use Cms\Core\Interfaces\FieldItemInterface;
use Cms\Core\Interfaces\PermissionInterface;
use Cms\Core\Repositories\SettingRepository;
use Cms\Core\Repositories\LanguageRepository;
use Cms\Core\Repositories\AuditLogRepository;
use Cms\Core\Repositories\CategoryRepository;
use Cms\Core\Repositories\MenuItemRepository;
use Cms\Core\Repositories\MetaDataRepository;
use Cms\Core\Interfaces\WidgetThemeInterface;
use Cms\Core\Interfaces\WidgetInputInterface;
use Cms\Core\Interfaces\WorkflowProjectInterface;
use Cms\Core\Interfaces\WorkflowStepInterface;
use Cms\Core\Repositories\EmailLogRepository;
use Cms\Core\Repositories\TranslateRepository;
use Cms\Core\Repositories\EnumerateRepository;
use Cms\Core\Repositories\MetaClassRepository;
use Cms\Core\Repositories\FieldItemRepository;
use Cms\Core\Repositories\PermissionRepository;
use Cms\Core\Interfaces\EnumerateTypeInterface;
use Cms\Core\Repositories\StorageRepository;
use Cms\Core\Interfaces\MetaAttributeInterface;
use Cms\Core\Interfaces\MetaValueDateInterface;
use Cms\Core\Interfaces\MetaValueTextInterface;
use Cms\Core\Interfaces\EmailTemplateInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Repositories\WidgetThemeRepository;
use Cms\Core\Repositories\WidgetInputRepository;
use Cms\Core\Interfaces\MetaValueBigNumInterface;
use Cms\Core\Interfaces\MetaValueStringInterface;
use Cms\Core\Repositories\EnumerateTypeRepository;
use Cms\Core\Interfaces\MetaValueBooleanInterface;
use Cms\Core\Repositories\MetaAttributeRepository;
use Cms\Core\Repositories\MetaValueDateRepository;
use Cms\Core\Repositories\MetaValueTextRepository;
use Cms\Core\Repositories\EmailTemplateRepository;
use Cms\Core\Interfaces\MetaValueTinyNumInterface;
use Cms\Core\Repositories\CategoryTargetRepository;
use Cms\Core\Repositories\MetaValueBigNumRepository;
use Cms\Core\Repositories\MetaValueStringRepository;
use Cms\Core\Repositories\MetaValueBooleanRepository;
use Cms\Core\Repositories\MetaValueTinyNumRepository;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;
use Cms\Core\Repositories\Interfaces\SeoInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Cms\Core\Repositories\WorkflowProjectRepository;
use Cms\Core\Repositories\WorkflowStepRepository;
use App\Repositories\Eloquent\SeoRepository;
use App\Repositories\Eloquent\SlugRepository;
use App\Repositories\Eloquent\PasswordResetRepository;
use Cms\Core\Repositories\Interfaces\PasswordResetInterface;

return [
    'name' => 'Core',
    'bindings' => [
        AuditLogInterface::class => AuditLogRepository::class,
        CategoryInterface::class => CategoryRepository::class,
        CategoryTargetInterface::class => CategoryTargetRepository::class,
        EmailLogInterface::class => EmailLogRepository::class,
        EmailInterface::class => EmailRepository::class,
        EmailTemplateInterface::class => EmailTemplateRepository::class,
        EnumerateInterface::class => EnumerateRepository::class,
        EnumerateTypeInterface::class => EnumerateTypeRepository::class,
        FieldInterface::class => FieldRepository::class,
        FieldItemInterface::class => FieldItemRepository::class,
        LanguageInterface::class => LanguageRepository::class,
        MenuInterface::class => MenuRepository::class,
        MenuItemInterface::class => MenuItemRepository::class,
        MetaAttributeInterface::class => MetaAttributeRepository::class,
        MetaClassInterface::class => MetaClassRepository::class,
        MetaDataInterface::class => MetaDataRepository::class,
        MetaValueBooleanInterface::class => MetaValueBooleanRepository::class,
        MetaValueDateInterface::class => MetaValueDateRepository::class,
        MetaValueBigNumInterface::class => MetaValueBigNumRepository::class,
        MetaValueStringInterface::class => MetaValueStringRepository::class,
        MetaValueTextInterface::class => MetaValueTextRepository::class,
        MetaValueTinyNumInterface::class => MetaValueTinyNumRepository::class,
        PermissionInterface::class => PermissionRepository::class,
        PasswordResetInterface::class => PasswordResetRepository::class,
        RoleInterface::class => RoleRepository::class,
        SeoInterface::class => SeoRepository::class,
        SettingInterface::class => SettingRepository::class,
        SlugInterface::class => SlugRepository::class,
        StorageInterface::class => StorageRepository::class,
        TranslateInterface::class => TranslateRepository::class,
        UploadInterface::class => UploadRepository::class,
        UserInterface::class => UserRepository::class,
        WidgetInterface::class => WidgetRepository::class,
        WidgetInputInterface::class => WidgetInputRepository::class,
        WidgetThemeInterface::class => WidgetThemeRepository::class,
        WorkflowProjectInterface::class => WorkflowProjectRepository::class,
        WorkflowStepInterface::class => WorkflowStepRepository::class,
        \Cms\Core\Agents\Contracts\AgentToolBindingResolverInterface::class => \Cms\Core\Agents\Tools\DefaultAgentToolBindingResolver::class,
        \Cms\Core\Agents\Contracts\ContextAssemblerInterface::class => \Cms\Core\Agents\Runtime\DefaultContextAssembler::class,
        // ReasoningEngineInterface is bound conditionally in CoreServiceProvider (driver-aware).
        \Cms\Core\Agents\Contracts\RunBudgetGuardInterface::class => \Cms\Core\Agents\Runtime\DefaultRunBudgetGuard::class,
        \Cms\Core\Agents\Contracts\ToolPolicyResolverInterface::class => \Cms\Core\Agents\Tools\DefaultToolPolicyResolver::class,
    ],

    'public_menu' => [
        /*
         * How long the content fingerprint that names the menu-group cache key
         * stays valid.
         *
         * The fingerprint gives instant invalidation, but computing it reads
         * four tables (count, max id, max updated_at, max deleted_at and a row
         * digest each) plus a dozen schema lookups — measured at roughly 45
         * queries, more than the query it protects. Caching it briefly keeps
         * invalidation near-instant while paying that cost once per window.
         * Set to 0 to compute it on every request.
         */
        'fingerprint_ttl' => (int) env('CORE_PUBLIC_MENU_FINGERPRINT_TTL', 60),

        /*
         * Expiry for the shared `cache:static:main-menu` / `:footer-menu` Redis
         * keys the website reads directly.
         *
         * These used to be written with no expiry, so a missed invalidation
         * froze the site's menu indefinitely. A ceiling means the worst case is
         * stale for this long rather than forever.
         */
        'shared_payload_ttl' => (int) env('CORE_PUBLIC_MENU_PAYLOAD_TTL', 3600),
    ],

    'hub' => [
        /*
         * Address of the iSPA Hub portal (ispa.tech) the one-tap connect sends the browser to. Empty = the scheme and
         * host of ispa-cms.platform_hub_url (the hub serves its portal and API on the same host).
         */
        'portal_url' => env('CORE_HUB_PORTAL_URL'),
    ],
];
