# AGENTS.md

Guide for AI coding agents working on iSPA CMS. Read this file first; it is enough for small changes. Open the deeper
docs only when the "Read next" table says so.

## What this is

iSPA CMS is a modular CMS on Laravel 13 and PHP 8.3. Features live in modules under `cms/` (nwidart/laravel-modules,
namespace `Cms\<Module>\`). This repository ships three modules: Core (users, permissions, settings, translations,
modules, backup, automation), Content (posts, pages, categories, tags, comments) and Widget (page layout, widget slots).
More modules come from the iSPA hub and are installed on a site; they are not in this repository. The admin ("iPanel")
is a Vue 3 + Vite app in `admin/`. The public site is rendered by Blade through a project (theme) in `projects/`.

## Directory map

| Path | Holds |
|---|---|
| `app/` | Laravel app: base providers, helpers (`app/Helpers/`), middleware, root models and services |
| `app/Providers/Abstracts/ModuleServiceProvider.php` | Base class of every module provider (config, views, lang, migrations) |
| `app/Support/Modules/` | Module boot rules: runtime modules, release-first precedence, activator choice |
| `cms/Core/`, `cms/Content/`, `cms/Widget/` | The bundled modules; each has its own `AGENTS.md` |
| `cms/<Module>/admin/` | Admin screens of a module (Vue), discovered by the admin build |
| `projects/Default/` | The default theme: layout, home controller, theme translations |
| `admin/` | Admin app source; the build writes it to public/iPanel (not in the repository) |
| `routes/` | Root routes (`api.php`, `web.php`); module routes live in each module |
| `config/` | Laravel config plus `config/ispa-cms.php` (site switches) and `config/modules.php` |
| `lang/` | Root translation files (`en`, `vi`) |
| `database/` | Root migrations and seeders (module tables live in the module) |
| `tests/` | Root Pest tests; module tests live in `cms/<Module>/tests/` |
| `modules_statuses.json` | Which modules are on (file activator) |
| `runtime_modules.json` | Modules whose online-installed copy may replace the release copy |
| `core-manifest.json` | Checksums of the core release, used by the core upgrade |
| `ispa` | CLI shortcuts: `start`, `build`, `upgrade` |

## How a request flows

Admin API (most changes):

1. `admin/` page or `cms/<Module>/admin/` page calls `ajax` (`admin/src/ajax.js`), base URL `<site>/api/v2/admin/`.
2. Module route file `cms/<Module>/routes/api.php`, mounted by the module's `RouteServiceProvider` under `api/`.
3. Middleware: `auth:sanctum`, `api.admin` (Webmaster/Admin accounts only, token checks), throttle.
4. Controller in `cms/<Module>/app/Http/Controllers/V2/Admin/` checks the policy, validates with a FormRequest.
5. Service (`app/Services/`) holds business logic; repository (`app/Repositories/`) holds queries when present.
6. Eloquent model; JSON shaped by a resource in `cms/<Module>/app/Http/Resources/V2/Admin/`, returned through `ires()`.

Public site: `routes/web.php` and `cms/<Module>/routes/web.php` → controller → Blade view that extends the active
project's layout (`projects/<Folder>/views/layout/`).

Reference implementation of the whole admin path: `cms/Core/resources/module-template/` (what `cms:make-module`
copies).

## Module anatomy (short)

```
cms/<Module>/
  module.json          name, alias, version (CalVer YYYY.MM.NN), edition, providers, requires
  composer.json        PSR-4 Cms\<Module>\ => app/ (merged into the root by composer-merge-plugin)
  app/                 Http, Services, Repositories, Models, Providers, Policies, Console, Jobs ...
  routes/api.php       admin API (v2/admin/...), routes/web.php public pages
  admin/               module.js, route-config.js, navigation-config.js, page-loaders.js, pages/
  config/config.php    merged as config('<alias>')
  database/            migrations, seeders, seeders/sql/translate*.php (labels)
  lang/{en,vi}/        PHP translation files, read as __('<alias>::...')
  resources/views/     Blade views, namespace '<alias>::'
  tests/               Pest tests
```

Details: [docs/agents/modules.md](docs/agents/modules.md).

## Hard rules

1. **Module boundaries.** A module's code, routes, admin screens, migrations and labels stay in `cms/<Module>/`.
   Do not move logic across modules. Core and the root never import an optional module's classes; they define a
   contract in `app/Contracts/` with a safe default, and the module rebinds it.
2. **UUID-first admin API.** In `v2/admin/*`, a table with a `uuid` column is addressed by `uuid` only: route
   parameter `{uuid}`, `string $uuid` in the controller, `Model::query()->where('uuid', $uuid)->firstOrFail()`,
   resources return `uuid`. Never accept the numeric `id` from the client. Some older routes still take `{id}`; do not
   copy them.
3. **Every visible string is a translation key** with English and Vietnamese text, authored in the module's sidecar
   (`cms/<Module>/database/seeders/sql/translate-admin-authored.php`). To change wording, edit the text of the
   existing key; do not add a replacement key.
4. **Admin forms ask only what a person knows.** No inputs for ids, paths, disks, MIME types, sizes, checksums or
   JSON. Files come from the media center picker; child rows are edited inside the parent form; slugs and defaults
   are generated.
5. **Module public pages belong to the module**: declared in `cms/<Module>/routes/web.php`, views extend the project
   layout. A project changes the look by overriding a view, not by copying the route.
6. **Blade uses Tailwind utility classes**; no `<style>` blocks or custom class names.
7. **Read runtime switches through `config()`**, never `env()` outside `config/` (cached config makes `env()` null).
8. **Thin controllers.** Logic in services, queries in repositories or model scopes.
9. **Never edit an applied migration.** Fix forward with a new one, in the module that owns the table.
10. **Tests come with every fix and feature**; run only the files you touched (see testing doc).

Contribution rules (commits, sign-off, licence): [CONTRIBUTING.md](CONTRIBUTING.md).

## Commands

```bash
composer install && php artisan cms:install --admin-email=you@example.com   # fresh site (see INSTALL.md)
pnpm install
./ispa start                     # PHP server + site Vite + admin Vite (php artisan cms:dev)
./ispa build                     # build site assets and admin (--site / --admin)
./vendor/bin/pest path/to/SomeTest.php                 # one PHP test file
./vendor/bin/pest cms/Content/tests/Unit --filter=Name # one folder, filtered
pnpm --dir admin exec vitest run src/__tests__/some.spec.ts   # one admin test file
php artisan cms:make-module Blog --entity=Post --mode=normal --dry-run   # new module
php artisan cms:translation-sync <Module>              # import a module's labels into the database
php artisan cms:translation-generate --public-only     # rebuild the admin language packs
php artisan cms:manifest-lint                          # check every module.json
```

## Read next

| Read | When |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Boot order, module activation, projects/themes, settings, cache, queues, hub connection |
| [docs/agents/modules.md](docs/agents/modules.md) | Creating a module, editing `module.json`, versions, dependencies |
| [docs/agents/admin.md](docs/agents/admin.md) | Any change in `admin/` or `cms/<Module>/admin/` |
| [docs/agents/conventions.md](docs/agents/conventions.md) | Writing an API endpoint, resource, request, translation key or setting |
| [docs/agents/testing.md](docs/agents/testing.md) | Running or writing tests, test database, flaky or slow tests |
| [docs/agents/recipes.md](docs/agents/recipes.md) | Step lists for common changes with the exact files |
| `cms/Core/AGENTS.md`, `cms/Content/AGENTS.md`, `cms/Widget/AGENTS.md` | Before changing that module |
| [INSTALL.md](INSTALL.md) | Install, hub connection, running without the hub |
