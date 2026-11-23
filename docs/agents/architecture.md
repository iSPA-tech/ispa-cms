# Architecture

Read when a change touches boot order, module activation, projects (themes), settings, cache, queues or the hub
connection. Basics are in the root [AGENTS.md](../../AGENTS.md).

## Boot order

1. `bootstrap/app.php` registers the runtime module autoloader and the release-first module repository before any
   config is loaded (`app/Support/Modules/RuntimeModuleAutoloader.php`).
2. `config/modules.php` picks the module status activator (see below) and scans `cms/` plus the runtime folder.
3. nwidart registers the enabled modules' providers (from each `module.json` `providers`).
4. `app/Providers/AppServiceProvider.php` registers the active project's provider, root singletons and safe default
   bindings; in `boot()` it loads the project routes (non-Default projects only).

### Provider traps

| Trap | Consequence | Do this |
|---|---|---|
| Module providers `register()` before `AppServiceProvider::register()` | A root default bound in `register()` replaces a module binding made in the module's `register()` | Rebind root contracts from the module's `boot()` (Widget does this for `app/Contracts/Widget/`) |
| Module config is merged in the module provider's `boot()` with `array_replace_recursive` (module file wins), and skipped when config is cached | A project that sets a module config key in `register()` is overwritten | Set module config overrides inside `$this->app->booted(...)` |
| Repository bindings from `config('<alias>.bindings')` are bound in `booted()` and only when not bound yet | An earlier binding wins | Bind your own implementation before boot ends, or remove the config entry |
| Module route files load before `routes/web.php` | Module routes win over the root catch-alls | Keep module URLs specific |
| Non-Default project routes load in `AppServiceProvider::boot()`, after module routes | Same method + URI + name replaces the module route | Re-declare a module route in a project only to change its URL or behaviour |

## Module activation

- Module root: `cms/` (`config/modules.php` `paths.modules`).
- Activator choice, in order: `MODULES_ACTIVATOR` in .env; else the marker file storage/app/modules/activator
  (written when a Webmaster turns on module install, value `database`); else `file`
  (`app/Support/Modules/ActivatorMarker.php`).
- File activator reads `modules_statuses.json` (path overridable with `MODULES_STATUSES_FILE`). The database
  activator imports that file once when its table is empty.
- With `config:cache`, the activator is read when the cache is built: rebuild the cache after changing it.

### Runtime modules (installed from the hub)

- Live in a runtime-modules folder at the project root (often a link created by the deploy); when nothing exists
  there, storage/app/runtime-modules is used. Neither ships in the repo.
- They are not in Composer's class map; the runtime autoloader registers their PSR-4 prefixes.
- Validation (`app/Support/Modules/RuntimeModules.php`): folder name equals `module.json` `name`; providers and
  PSR-4 prefixes start with `Cms\<Name>\`; only `psr-4` autoload; paths stay inside the module; Core can never be a
  runtime module; a name that exists in `cms/` is refused unless listed in `runtime_modules.json`.
- `runtime_modules.json` lists modules whose runtime copy may replace the release copy. The runtime copy wins only
  when it was placed by the installer, its version is higher than the release copy, its `requires.core` range
  accepts the installed Core, and it validates (`app/Support/Modules/RuntimePrecedence.php`). Anything else falls
  back to the release copy on the next boot.
- Install permission (`cms/Core/app/Services/Modules/Install/ModuleInstallPolicy.php`): not forbidden by the server
  (`MODULE_INSTALL_ENABLED=false`), not forbidden by the hub, and the Webmaster switch is on (off by default).

## Projects (themes)

| Env var | Config | Meaning |
|---|---|---|
| `VITE_TEMPLATE_FOLDER` | `ispa-cms.folder_name` (default `Default`), `ispa-cms.folder` (`<lower>::`) | Active project folder under `projects/` |
| `VITE_TEMPLATE_VIEWS_PATH` | `ispa-cms.folder_path` | Project views folder |
| `VITE_TEMPLATE_TAILWIND_CSS`, `VITE_TEMPLATE_TAILWIND_CONFIG`, `VITE_TEMPLATE_TAILWIND_OUTPUT` | Vite build | Project Tailwind entry, config, output |

- `projects/` is PSR-4 namespace `PJ\` (root `composer.json`).
- `projects/<Folder>/Provider/CustomServiceProvider.php` is registered when present (Default included; it loads the
  `default::` translations from `projects/Default/lang/`).
- `/` is `PJ\<Folder>\Controllers\HomeController@index` for every project (`routes/web.php`).
- `projects/<Folder>/routes/index.php` is loaded only when the project is not `Default`. To add pages, copy Default
  into your own `projects/<Name>` and set `VITE_TEMPLATE_FOLDER`.
- On a Default site an unknown URL is a 404. Other projects get the `{cate}/{slug}` catch-all (off with
  `ispa-cms.routes.category_navigation = false`) and a fallback route.
- Module view lookup order: projects/<Folder>/views/modules/<alias> (not for Default), then
  resources/views/modules/<alias>, then `cms/<Module>/resources/views` (`getPublishableViewPaths()` in
  `app/Providers/Abstracts/ModuleServiceProvider.php`).

## Settings

| Layer | Where | Read with |
|---|---|---|
| Site switches from .env | `config/ispa-cms.php` and Laravel config | `config('ispa-cms.<key>')` |
| Module switches | `cms/<Module>/config/config.php` (merged as `<alias>`) | `config('<alias>.<key>')` |
| Operator settings (admin Settings screens) | `settings` table, identity `setting_key` | `iSetting('<setting_key>', $default)` |

- `iSetting()` (`app/Helpers/common.php`, `cms/Core/app/Services/CMSSettingUnified.php`) resolves: env-backed
  settings through `env(<name>)`, private settings from the runtime store, public settings from `config('setting.*')`.
  No database read unless `allow_db_fallback` is passed.
- `config/setting.php` is generated by `php artisan cms:setting-initialize` (`app/Console/Commands/GenerateSettingCommand.php`);
  never edit it by hand and never commit site values into it.
- Seeded rows: `cms/Core/database/seeders/exported/settings.php`, imported by
  `cms/Core/database/seeders/SettingTableSeed.php`, matched by `setting_key`. Existing rows keep the operator's
  `value`, `group_name` and `name`; the database allocates `id` (the file's `id` is ignored).
- `iSeo()` (`app/Helpers/SeoHelpers.php`) is only for front-end SEO values.

## Cache and logging

- Store: `CACHE_DRIVER` (`config/cache.php`). Site flags: `CACHE_ENABLE`, `REDIS_ENABLE` (`config/ispa-cms.php`).
- Use the `Cms\Core\Facades\CMSCache` facade (backed by `app/Services/CacheService.php`) for app caches; it supports
  tracked keys and tag/post-type invalidation.
- Use the `Cms\Core\Facades\CMSLogger` facade and the channels in `config/logging.php`; no hard-coded log paths.
- Outside production, lazy relation loads are logged as warnings (`AppServiceProvider::boot()`); fix them with eager
  loading.

## Queues and scheduler

- `QUEUE_CONNECTION` (default `sync`), `QUEUE_RETRY_AFTER` (`config/queue.php`).
- Schedules: `app/Console/Kernel.php`, plus each module provider's `registerCommandSchedules()` (wrap in
  `$this->app->booted(...)`, as in `cms/Core/resources/module-template/app/Providers/SkeletonServiceProvider.php`).
- The server needs the usual `php artisan schedule:run` cron and a queue worker when the connection is not `sync`.

## Hub connection

The site and admin work without the hub. The hub unlocks module install and hub-run services.

- Config: `PLATFORM_HUB_URL` (default `https://ispa.tech`), `CMS_DOMAIN`, `CMS_DOMAIN_SECRET` (`config/ispa-cms.php`).
  Connecting with the one-time setup code printed by `cms:install` is described in [INSTALL.md](../../INSTALL.md).
- Status (`cms/Core/app/Services/Platform/SiteStatusService.php`): signed request to the hub, answer cached 15
  minutes; the last good answer is stored encrypted and served for 7 days while the hub cannot be reached; a hub
  refusal (401/403) ends the connection at once. The browser never calls the hub; only status and message key reach
  the admin, never the token.
- `cms:module-revocation-check` (daily) stores revocations and entitlements of installed module versions. It never
  disables a module; results feed the module list and dashboard.
- Core upgrade: `./ispa upgrade` (`cms:core-upgrade`), checked against `core-manifest.json`.
