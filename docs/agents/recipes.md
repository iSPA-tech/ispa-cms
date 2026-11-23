# Recipes

Short step lists for common changes. `<Module>` is the folder name (StudlyCase), `<alias>` its lowercase alias. Paths
under `cms/Core/resources/module-template/` show the pattern to copy.

## 1. Admin API endpoint with its admin page

1. Model: `cms/<Module>/app/Models/<Entity>.php` with `HasUuid` and `getRouteKeyName()` = `uuid` (`cms/Core/resources/module-template/app/Models/SkeletonThing.php`).
2. Route: `cms/<Module>/routes/api.php`, inside `Route::prefix('v2/admin')->middleware(['auth:sanctum', 'api.admin', 'throttle:500,1'])`,
   `Route::apiResource('<entities>', Controller::class)->parameters(['<entities>' => 'uuid'])` (`cms/Core/resources/module-template/routes/api.php`).
3. Request and resource: `cms/<Module>/app/Http/Requests/V2/Admin/`, `cms/<Module>/app/Http/Resources/V2/Admin/` (`cms/Core/resources/module-template/app/Http/Requests/V2/Admin/`, `cms/Core/resources/module-template/app/Http/Resources/V2/Admin/`).
4. Controller: `app/Http/Controllers/V2/Admin/<Entity>Controller.php`; policy check, uuid lookup, service call,
   `ires()` response (`cms/Core/resources/module-template/app/Http/Controllers/V2/Admin/SkeletonThingController.php`).
5. Policy and permissions: `app/Policies/`, register in `app/Providers/AuthServiceProvider.php`, add abilities to the
   permission seeder, run `php artisan cms:modules-sync-permissions <Module>`.
6. Admin client: `cms/<Module>/admin/api.js` with `ajax` from `@/ajax`.
7. Page: `cms/<Module>/admin/pages/<page>.vue`; loader in `page-loaders.js`; child route in `route-config.js` with
   `meta.rule`; menu entry in `navigation-config.js` if needed (`cms/Core/resources/module-template/admin/`).
8. Labels: recipe 3. Test: copy `cms/Core/resources/module-template/tests/Feature/SkeletonThingAdminApiTest.php`. Rebuild the admin.

## 2. Migration

1. Find the module that owns the table; create the file in `cms/<Module>/database/migrations/`
   (`php artisan make:migration` writes to the root, so move it, or write it by hand).
2. Never edit an applied migration; add a new one. Drop columns in a later release than the code that stops using them.
3. If the module lists `migrations.irreversible` in `module.json` (Content does), add the new file name there.
4. Check it on SQLite through the module's tests; avoid engine-specific SQL or guard it by driver.
5. `php artisan migrate`.

## 3. Translation key

1. Add `$entry('<group>', '<key>', '<English>', '<Vietnamese>')` to
   `cms/<Module>/database/seeders/sql/translate-admin-authored.php` (`cms/Core/resources/module-template/database/seeders/sql/translate-admin-authored.php`).
   Use `nav.a.<alias>...` for menu and breadcrumb names, `text.<alias>....` for screen text. Website text: pass
   `true, false` for `forWeb, forAdm` and mirror it in `cms/<Module>/lang/{en,vi}/text.php`.
2. `php artisan cms:translation-sync <Module>`
3. `php artisan cms:translation-generate --public-only`
4. Use it: `t('text.<alias>.x')` in Vue, `__('<alias>::text.x')` in Blade.
5. Changing wording: edit the text of the existing entry and repeat steps 2 and 3. Never add a replacement key.

## 4. Public page in a module

1. Route in `cms/<Module>/routes/web.php` (mounted with `web` middleware by the module's `RouteServiceProvider`),
   named `<alias>.public.<page>` (`cms/Core/resources/module-template/routes/web.php`).
2. Controller in `cms/<Module>/app/Http/Controllers/Public/`; take the project from `config('ispa-cms.folder_name')`.
3. View in `cms/<Module>/resources/views/public/` extending the project layout; Tailwind utility classes only
   (`cms/Core/resources/module-template/resources/views/public/index.blade.php`).
4. Strings as website keys (recipe 3).
5. A project changes the look by adding projects/<Folder>/views/modules/<alias>/public/<view>.blade.php; it does not
   copy the route.
6. Test that the page resolves on a project with nothing in its own route file (`cms/Core/resources/module-template/tests/Feature/SkeletonPublicPagesTest.php`).

## 5. Setting

Module switch (developer or server decides):
1. Add the key with an `env()` default to `cms/<Module>/config/config.php` (`cms/Core/resources/module-template/config/config.php`).
2. Read it with `config('<alias>.<key>')` everywhere; never `env()` in code.

Operator setting (edited in the admin Settings screens, Core-owned):
1. Add a row with a unique `setting_key`, `type`, `group_name`, `name`, default `value` and `input_setting` to
   `cms/Core/database/seeders/exported/settings.php`; no secret values.
2. `php artisan db:seed --class="Cms\Core\Database\Seeders\SettingTableSeed"` (matches by `setting_key`, keeps
   existing values), then `php artisan cms:setting-initialize`.
3. Read with `iSetting('<setting_key>', $default)`. Label keys in `input_setting` follow recipe 3.

## 6. New module

1. `php artisan cms:make-module <Name> --entity=<Entity> --dry-run`, then without `--dry-run`
   (`--mode=lite|normal|full`).
2. Fill `module.json`: `description`, `edition`, `requires` (`cms/Core/AGENTS.md` explains the Core range).
3. `php artisan migrate`, `php artisan cms:modules-sync-permissions <Name>`, recipe 3 steps 2 and 3 (or run the
   generator with `--setup` on a development database).
4. Rebuild the admin; give the new permissions to a role.
5. `./vendor/bin/pest cms/<Name>/tests` and `php artisan cms:manifest-lint`.
More: [modules.md](modules.md).

## 7. Scheduled command in a module

1. Command class in `cms/<Module>/app/Console/Commands/`, signature `<alias>:<action>`.
2. Register it in the module provider's `registerCommands()`.
3. Schedule it in `registerCommandSchedules()` inside `$this->app->booted(...)`
   (`cms/Core/resources/module-template/app/Providers/SkeletonServiceProvider.php`).
4. Test it (`cms/Core/resources/module-template/tests/Feature/PurgeTrashedSkeletonThingsCommandTest.php`).

## 8. Override a module template in a project

1. Copy Default into your own `projects/<Name>/` and set `VITE_TEMPLATE_FOLDER=<Name>` (view overrides are not read
   for `Default`).
2. Add projects/<Name>/views/modules/<alias>/<same relative path>.blade.php.
3. Clear compiled views: `php artisan view:clear`.
