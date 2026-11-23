# Content module

Posts, pages, categories, tags, comments, bookmarks, the editorial dashboard and the public blog pages. Namespace
`Cms\Content\`, alias `content`. Can be disabled. Requires Core (`requires.core` in `cms/Content/module.json`).
Rules for every module: root [AGENTS.md](../../AGENTS.md).

## Entry points

| What | Where |
|---|---|
| Provider | `cms/Content/app/Providers/ContentServiceProvider.php` (+ Route, Auth, Event providers) |
| Admin API | `cms/Content/routes/api.php` (`v2/admin/content`, `post`, `tag`, `dashboard`) |
| Public and client API | `cms/Content/routes/public-api.php` (`v2/public`, `v2/client`, `v3`); same `api` prefix, no `api.` name prefix |
| Public pages | `cms/Content/routes/web.php` (`/blog/...`, `/bai-viet/...`, `/news`, `/about/{slug}`) |
| Controllers | `cms/Content/app/Http/Controllers/` (`V2/Admin`, `V2/Public`, `V2/Client`, `V3`, `Website`) |
| Models | `cms/Content/app/Models/` (`Post`, `Content`, `BookMark`) |
| Repositories | `cms/Content/app/Repositories/` (`PostRepository`, `ContentRepository`, `BookmarkRepository`, `TagRepository`), interfaces in `cms/Content/app/Interfaces/` |
| Services | `cms/Content/app/Services/` |
| Admin screens | `cms/Content/admin/` (reference pages for breadcrumbs, lists and forms: `pages/manage.vue`, `create.vue`, `update.vue`) |
| Tests | `cms/Content/tests/` (`Unit`, `Feature`, `Contract`); each file declares its TestCase |
| Release notes | `cms/Content/CHANGELOG.md` |

Public pages extend the active project's layout. A Default site serves `/news` and the blog pages; a project without
the layout gets a 404 from `BlogController`, not a 500.

## Table ownership

- Every migration that creates or alters `contents`, `posts` and `bookmarks` lives in `cms/Content/database/migrations/`.
  No root migration touches them (`tests/Unit/ContentTableMigrationOwnershipTest.php`).
- Every Content migration is listed in `cms/Content/module.json` under `migrations.irreversible`; add new ones there.
- Code outside Content checks `App\Support\Modules\ModuleStatus::isEnabled('Content')` before reading these tables:
  a fresh install with Content off has none of them.

## Public API of the module

- Classes whose docblock carries `@api` are Content's public API for other modules. Their shape is stored in
  `cms/Content/tests/Contract/content-api.json`; `cms/Content/tests/Unit/ContentApiSnapshotTest.php` fails when it is
  out of date. Regenerate: `CONTENT_API_WRITE=1 ./vendor/bin/pest cms/Content/tests/Unit/ContentApiSnapshotTest.php`.
- Bump `cms/Content/module.json` `version`: next CalVer for added members; removed or changed members only in a
  new major (next year) shipped together with its dependents. Changes outside `@api` classes need no API bump.
- Every module that imports `Cms\Content\` declares a bounded `requires.content` range
  (`cms/Content/tests/Unit/ContentDependentsDeclareTest.php`).
- Content itself depends on Core's surface: see `cms/Core/AGENTS.md` ("Core version = contract for Content").

## Do not put here

- Theme markup or site-specific pages: put them in `projects/<Folder>/` (override a view under
  projects/<Folder>/views/modules/content/ instead of copying a route).
- Platform features (users, settings, translations, media storage): they are Core's.
- Page layout and widget slots: they are Widget's.
- Imports of optional modules' classes.
