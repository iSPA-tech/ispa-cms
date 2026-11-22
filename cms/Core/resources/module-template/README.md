# Module template (`php artisan cms:make-module`)

This folder is the template the `cms:make-module` command copies to create a new module. It is a real module written
with placeholder names, the module `Skeleton` and its sample entity `Thing`; the command renames both. Nothing loads
this folder as a module.

## Create a module

```bash
php artisan cms:make-module Blog --entity=Post               # normal mode (default)
php artisan cms:make-module Blog --entity=Post --mode=lite
php artisan cms:make-module Blog --entity=Post --mode=full --setup
php artisan cms:make-module Blog --mode=full --dry-run       # list the files, write nothing
```

Names are StudlyCase words (`KnowledgeBase`, `PostCategory`, `CmsBlog`; not `CMSBlog`, which would become
`c-m-s-blog`). Before writing anything the command refuses a folder that already exists, a reserved name (`Core`,
`Content`, `Widget`, `Skeleton`, `Game*`), an alias another module already uses, a sample table that already exists,
an unreadable `modules_statuses.json`, and in `full` mode a public URL `/<module>` that another route already answers
(for example `/blog`, used by the Content module). It writes `cms/<Name>`, runs `composer dump-autoload`, then
turns the module on in `modules_statuses.json` and runs `cms:manifest-lint`.

The database is only touched with `--setup` (refused when `APP_ENV` is production), which also runs `migrate` (the
module's migrations),
`cms:modules-sync-permissions <Name>` and, from `normal` on, `cms:translation-sync <Name>` and
`cms:translation-generate --public-only`. Without it the command prints those steps.

## What each mode creates

Modes add up: `normal` has everything in `lite`, `full` everything in `normal`. The sample entity has a name, a
description and a status (draft or published), a uuid, timestamps and soft deletes.

| Mode | Files |
|---|---|
| lite | `module.json`, `composer.json`, config, providers (module, routes, policies), model + migration, admin API controller (`/api/v2/admin/<module>/<entities>`, by uuid), request, resource + paginated collection, policy and permission seeder (`<module>.<module>_<entity>.viewAny/view/create/edit/update/delete`), admin API test |
| normal | service layer, database seeder, translation seeder and sidecar (`database/seeders/sql/translate-admin-authored.php`, English and Vietnamese), admin screens (`admin/`: menu entry, list with search and pagination, create and edit form, delete confirm) |
| full | public pages (`/<module>`, `/<module>/{uuid}`, published only, inside the project layout), `Ensure<Module>Enabled` middleware, URL helper, status enum, data object, saved event + listener + queued job + mail (sent when `<module>.notify_email` is set), `<module>:purge-trashed` command scheduled daily, exception, status tag component, demo seeder, `lang/{en,vi}/text.php`, unit tests and fixtures, public page, mail and command tests |

## After creating

- Run the module's tests: `php artisan test cms/<Name>/tests`.
- Rebuild the admin (`pnpm build`, or keep `./ispa start` running) so the menu entry appears. It sits after Site layout;
  move it with `slot` in `admin/module.js` (base-admin shows a module it does not name only when `slot` is set).
- Give the new permissions to a role in the admin (Roles), unless you work in the local environment, where policies
  let everything through.
- Change labels in the translation sidecar, then run `cms:translation-sync <Name>` and
  `cms:translation-generate --public-only`. Website strings (`for_web`) are mirrored in `lang/{en,vi}/text.php`.

## Editing the template

- Every file is listed once in `manifest.php`, under the first mode that writes it.
- A part that belongs to some modes only sits between marker lines in the file's own comment syntax:
  `// @mode normal,full` ... `// @endmode` (PHP, JS), `<!-- @mode full -->` ... `<!-- @endmode -->` (Vue templates),
  `{{-- @mode full --}}` ... `{{-- @endmode --}}` (Blade). The stored file keeps every part, so it stays valid code.
- Placeholders are whole tokens: `Skeleton`, `Thing` and their combined forms (`SkeletonThing`, `skeletonThings`,
  `skeleton_thing`, `skeleton-things`, `SKELETON_THING`). A standalone lowercase `skeleton` or `thing` becomes the
  kebab form, so use it only in URLs, translation keys and file names; PHP and JS identifiers use the combined camel or
  snake forms, or start with the capitalised token (`SkeletonAdminModule`). Do not give a loop item the singular name
  of a list (`$skeletonThings as $skeletonThing`): for `News` both names are the same. Never let a placeholder touch other letters ("something", "Skeletons"):
  `ModuleTemplateLintTest` fails on it.
- The generator tests (`cms/Core/tests/{Unit,Feature}/Modules/Generator`) build every mode and run its API, public
  pages, mail and command; the admin page is mounted by `base-admin/src/__tests__/modules/module-template-things.spec.ts`.
