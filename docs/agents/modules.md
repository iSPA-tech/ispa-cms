# Modules

Read when you create a module, edit a `module.json`, or wire a module into the app. Boot and activation rules are in
[architecture.md](architecture.md).

## Anatomy

The full, working example is the module template `cms/Core/resources/module-template/` (module `Skeleton`, entity
`Thing`). Copy its patterns.

| Path | Purpose | Template file |
|---|---|---|
| `cms/<Module>/module.json` | Manifest (fields below) | `cms/Core/resources/module-template/module.json` |
| `cms/<Module>/composer.json` | PSR-4 `Cms\<Module>\` => `app/`, seeders namespace; merged into the root by `wikimedia/composer-merge-plugin` | `cms/Core/resources/module-template/composer.json` |
| `cms/<Module>/app/Providers/<Module>ServiceProvider.php` | Extends `App\Providers\Abstracts\ModuleServiceProvider`; registers route and auth providers, commands, schedules | `.../app/Providers/SkeletonServiceProvider.php` |
| `cms/<Module>/app/Providers/RouteServiceProvider.php` | Mounts `routes/api.php` under `api` (name prefix `api.`) and `routes/web.php` under `web` | `.../app/Providers/RouteServiceProvider.php` |
| `cms/<Module>/app/Providers/AuthServiceProvider.php` | Registers policies | `.../app/Providers/AuthServiceProvider.php` |
| `cms/<Module>/app/Http/Controllers/V2/Admin/` | Admin API controllers | `.../SkeletonThingController.php` |
| `cms/<Module>/app/Http/Requests/V2/Admin/`, `cms/<Module>/app/Http/Resources/V2/Admin/` | Validation and JSON shape | `.../SkeletonThingRequest.php`, `.../SkeletonThingResource.php` |
| `cms/<Module>/app/Services/`, `cms/<Module>/app/Repositories/` | Business logic, queries | `.../SkeletonThingService.php` |
| `cms/<Module>/app/Models/` | Eloquent models (`HasUuid`, `getRouteKeyName()` = `uuid`) | `.../SkeletonThing.php` |
| `cms/<Module>/app/Policies/` | Ability checks, permission names `<alias>.<alias>_<entity>.<ability>` | `.../SkeletonThingPolicy.php` |
| `cms/<Module>/config/config.php` | Merged as `config('<alias>')`; other files as `<alias>.<file>` | `.../config/config.php` |
| `cms/<Module>/routes/api.php`, `cms/<Module>/routes/web.php` | Admin API and public pages | `.../routes/api.php`, `.../routes/web.php` |
| `cms/<Module>/database/migrations/` | Tables the module owns (loaded by the base provider) | `.../database/migrations/` |
| `cms/<Module>/database/seeders/` | Permission seeder, database seeder, label sidecar in `sql/` | `.../database/seeders/` |
| `cms/<Module>/lang/{en,vi}/` | Website strings mirror, read as `__('<alias>::text.<key>')` | `.../lang/en/text.php` |
| `cms/<Module>/resources/views/` | Blade views, namespace `<alias>::` | `.../resources/views/public/index.blade.php` |
| `cms/<Module>/admin/` | Admin screens (see [admin.md](admin.md)) | `.../admin/module.js` |
| `cms/<Module>/tests/` | Pest tests (`Feature`, `Unit`, `Fixtures`) | `.../tests/Feature/SkeletonThingAdminApiTest.php` |
| `cms/<Module>/CHANGELOG.md` | Release notes for modules published on the hub | `cms/Content/CHANGELOG.md` |

## Create a module

```bash
php artisan cms:make-module Blog --entity=Post --dry-run   # list files, write nothing
php artisan cms:make-module Blog --entity=Post             # mode normal (default)
php artisan cms:make-module Blog --entity=Post --mode=full --setup   # also migrate, permissions, translations
```

| Mode | Adds |
|---|---|
| `lite` | `module.json`, providers, model + migration, admin API (`/api/v2/admin/<module>/<entities>`, by uuid), request, resource, policy, permission seeder, admin API test |
| `normal` | + service, seeders, label sidecar (en, vi), admin list and form screens |
| `full` | + public pages, enable middleware, enum, data object, event, listener, queued job, mail, scheduled purge command, more tests |

- Names are StudlyCase. Refused: an existing folder, a reserved name (`Core`, `Content`, `Widget`, `Skeleton`),
  an alias already used, an existing sample table, and in `full` mode a public URL another route already answers.
- The command writes `cms/<Name>`, runs `composer dump-autoload`, turns the module on in `modules_statuses.json` and
  runs `cms:manifest-lint`. `--setup` is refused when `APP_ENV` is production.
- Next steps (tests, admin rebuild, giving permissions to a role, labels):
  `cms/Core/resources/module-template/README.md`.

## module.json fields that matter

| Field | Rule |
|---|---|
| `name` | StudlyCase, equals the folder name |
| `alias` | Lowercase key: config key, view and translation namespace, admin module key |
| `version` | CalVer `YYYY.MM.NN` (year, month, release of that month). Guarded by `cms/Core/tests/Unit/Modules/ModuleVersionCalVerTest.php` |
| `edition` | `open`, `premium`, `internal` or `client`; missing means `internal`. An `open` module may require only `open` modules (`cms/Core/app/Services/Modules/ModuleEdition.php`) |
| `distribution` | `online` (installable from the hub at runtime) or `deploy` (ships with its site; then `distribution_reason` is required) |
| `requires` | Object of alias => version range, e.g. `"core": ">=2026.11.01,<2027.00.00"` or `"*"`. The installer refuses a module whose range excludes the installed version |
| `providers` | Provider classes under `Cms\<Name>\` |
| `migrations.irreversible` | Migrations that cannot be rolled back (Content lists all of its own) |

- `php artisan cms:manifest-lint` checks fields, versions, requires, migrations and permissions of every
  `cms/*/module.json`. Unknown fields fail.
- When a module starts using a class or method added in Core (or Content) version `X`, raise its lower bound to
  `>=X` in the same change. Contract rules for Core and Content: `cms/Core/AGENTS.md`, `cms/Content/AGENTS.md`.

## Wiring rules

- Never import another module's classes unless you declare it in `requires`. Code that may run without a module
  checks `App\Support\Modules\ModuleStatus::isEnabled('<Module>')` before touching its tables or classes.
- Root or Core code that needs an optional feature defines a contract in `app/Contracts/` with a null default bound
  in `AppServiceProvider`; the module rebinds it in its `boot()`.
- Module schedules go in `registerCommandSchedules()` inside `$this->app->booted(...)`; commands in
  `registerCommands()`.
- Permissions are rows seeded by the module's permission seeder and synced with
  `php artisan cms:modules-sync-permissions <Module>`.
- Admin code of a module lives only in `cms/<Module>/admin/`; a module's admin code never imports another module's
  admin files.
- `CHANGELOG.md`: one `## <version> - <YYYY-MM-DD>` section per version, newest first, plain words for the operator.
  The package builder reads the section of the version being built.
