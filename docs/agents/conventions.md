# Conventions

Read when you write an endpoint, resource, request, migration, translation key or setting. Each rule has its reason.
The reference code for most of them is `cms/Core/resources/module-template/`.

## API areas

| Prefix (under `/api`) | Middleware | Used by |
|---|---|---|
| `v2/admin/...` | `auth:sanctum`, `api.admin`, throttle (root routes also `api.admin.middleware`: `ISPA-ADMIN-AUTH-KEY` header) | The admin app |
| `v2/public/...` | `api.verify` (`ISPA-AUTH-KEY` header) | Site front ends, apps |
| `v2/client/...` | `api.verify`, `api.log` | Signed-in app users |
| `v3/...` | per group | Newer public and admin endpoints |

Middleware aliases are in `app/Http/Kernel.php`. New admin features go under `v2/admin/<alias>/...` in the module's
`routes/api.php`.

## Identifiers: UUID-first

- Admin routes use `{uuid}` with `->where(['uuid' => '[0-9a-fA-F-]{36}'])`; controllers take `string $uuid` and
  resolve with `Model::query()->where('uuid', $uuid)->firstOrFail()`.
- Models use `Cms\Core\Traits\HasUuid` (fills `uuid` on create) and return `uuid` from `getRouteKeyName()`.
- Resources expose `uuid`; the numeric `id` stays internal. The admin navigates and mutates by `uuid` only.
- Tables without a `uuid` column keep their identifier until a migration adds one.
- Why: numeric ids are guessable and differ between sites, so links, exports and imports would break or leak.

## Controllers, requests, resources, responses

- Controller: check the policy first (`user()?->can('viewAny', Model::class)`) and only then look up the uuid, so
  the API does not reveal which uuids exist; then check the row ability (`view`, `update`, `delete`).
- Return through `ires()` (`cms/Core/app/Responses/AppResponse.php`): `->data(...)`, `->message(__('...'))`,
  `->forbidden(...)`, `->notFound(...)`, `->created(...)`. Why: one response envelope for every admin screen.
- FormRequest in `cms/<Module>/app/Http/Requests/V2/Admin/`: `authorize()` returns true (the controller checks the policy);
  fields `required` on POST and `sometimes` on PUT/PATCH. Never validate a numeric id from the client.
- Resource in `cms/<Module>/app/Http/Resources/V2/Admin/`, plus a collection class for paginated lists.
- List endpoints accept `search` and `pageSize` (clamped, e.g. 1 to 100).
- Business logic in a service; the controller only translates HTTP to service calls. Why: commands, jobs and tests
  reuse the service.

## Permissions

- Names: `<alias>.<alias>_<entity>.<ability>`, abilities `viewAny`, `view`, `create`, `edit`, `update`, `delete`.
- Seeded by the module's permission seeder, synced with `php artisan cms:modules-sync-permissions <Module>`, given
  to roles in the admin (Roles).
- The admin route and menu use the same name in `meta.rule` / `rule`.

## Translations

- Every visible string (admin, public pages, mail, flash and validation messages) is a key with `en` and `vi` text.
- Admin and module labels are authored in `cms/<Module>/database/seeders/sql/translate-admin-authored.php`
  (one `$entry(group, key, en, vi, forWeb, forAdm)` per key, see the template's file). A `translate.php` must sit
  next to it and return an array.
- Website strings (`for_web`) are also mirrored in `cms/<Module>/lang/{en,vi}/text.php` and read as
  `__('<alias>::text.<key>')`. Keep both in step.
- Import and publish: `php artisan cms:translation-sync <Module>` then
  `php artisan cms:translation-generate --public-only`. The sidecar wins for wording: a changed text in the sidecar
  overwrites the database row (`cms/Core/app/Services/TranslationModuleSeederImporter.php`).
- To change wording, edit the existing key's text. Do not add a replacement key. Why: keys are stored in the
  database of every site; a new key leaves the old one orphaned and untranslated elsewhere.
- Do not edit generated `lang/` files or the exported payloads by hand; change the sidecar. Why: generation
  rewrites them.
- Theme strings of a project live in `projects/<Folder>/lang/` under the `<folder>::` namespace
  (`projects/Default/lang/`).

## Database

- A table belongs to one module; its migrations live in `cms/<Module>/database/migrations/`. No root migration
  touches a module table (`tests/Unit/ContentTableMigrationOwnershipTest.php` enforces this for Content).
- Table names: `<alias>_<entities>` (template: `skeleton_things`).
- Never edit an applied migration; add a new one. Drop a column in two releases: first stop reading and writing it,
  then drop it. Why: sites upgrade at different times and may roll back code.
- Migrations must run on SQLite, MySQL/MariaDB and PostgreSQL (tests use SQLite).
- Seeders key rows by their stable business key (settings by `setting_key`), never by a fixed `id`. Why: fixed ids
  break PostgreSQL sequences and clash with operator rows.

## Settings and config

- Never call `env()` outside `config/`. Why: with `config:cache`, `env()` returns null.
- Module switches: `cms/<Module>/config/config.php`, read with `config('<alias>.<key>')`.
- Operator settings: rows in `settings`, read with `iSetting('<setting_key>')`. Details:
  [architecture.md](architecture.md#settings).
- Secrets stay in .env or private settings; never in committed config, seeders or the admin bundle.

## Queues

- New queued jobs and listeners: 3 tries, backoff `[10, 30, 60]` seconds, and idempotent work (safe to retry).
- No sleep loops; use retries or the workflow step retry config.

## Public pages and views

- Declared in `cms/<Module>/routes/web.php`; views in `cms/<Module>/resources/views` extend the project layout
  (`config('ispa-cms.folder') . 'layout.index'`).
- The project key comes from server config (`config('ispa-cms.folder_name')`), never from a route default.
- Tailwind utility classes only; no `<style>` blocks or custom class names.

## Code style

- PHP: `declare(strict_types=1);` in new files, typed signatures, `final` classes where nothing extends them.
- Comments and docs in English. Keep the licence header at the top of the file; add nothing above it.
- Formatting: `.editorconfig` and `.prettierrc` at the root; ESLint config in `admin/`.
