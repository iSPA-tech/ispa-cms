# Widget module

Site layout: widget slots, widget instances and their versions, layout drafts, publish, release and rollback,
placements, rendering and signed preview sessions. Namespace `Cms\Widget\`, alias `widget`. Requires Core and Content
(`cms/Widget/module.json`). Rules for every module: root [AGENTS.md](../../AGENTS.md).

## Entry points

| What | Where |
|---|---|
| Provider | `cms/Widget/app/Providers/WidgetServiceProvider.php` (+ Event, Route, Auth providers) |
| Admin API | `cms/Widget/routes/api.php` (`v2/admin/widget/...`, `auth:sanctum` + `api.admin`; permissions checked in the controllers) |
| Controllers | `cms/Widget/app/Http/Controllers/V2/Admin/` |
| Layout runtime | `cms/Widget/app/SiteLayout/` (drafts, publishing, slots, targets, resolution) |
| Rendering | `cms/Widget/app/Rendering/` |
| Instances | `cms/Widget/app/Instances/` |
| Preview | `cms/Widget/app/Preview/` (signed preview sessions, Redis store) |
| Models | `cms/Widget/app/Models/` (`WidgetInstance`, `WidgetLayout`, versions, artifacts) |
| Config | `cms/Widget/config/config.php` |
| Admin screens | `cms/Widget/admin/` (admin module key `site-layout`; `pages/index.vue`, `pages/placements.vue`) |
| Tests | `cms/Widget/tests/` (`Unit`, `Feature`, `Fakes`, `Support`) |

## Boundary with the root app

- The root owns the contracts in `app/Contracts/Widget/` and binds safe legacy or null defaults in
  `app/Providers/AppServiceProvider.php` `register()`.
- `WidgetServiceProvider` rebinds them in `boot()`, never in `register()`: module providers register before the
  root provider, so a binding made in `register()` would be overwritten.
- The preview middleware is pushed onto the existing `web` group from this module's provider; the root kernel does
  not import `Cms\Widget`.
- Disabling Widget is guarded by its module disable guard; the site falls back to the legacy renderer.
- `cms/Widget/tests/Feature/WidgetModuleBoundaryTest.php` checks the boundary; run it after moving code.

## Tests

- Run files or folders, never the whole module at once without a reason:
  `php -d memory_limit=256M vendor/bin/pest cms/Widget/tests/Unit`.
- Preview and cache tests need a throwaway Redis on `127.0.0.1:6399` (databases 13 to 15 are flushed); without it
  they are skipped. Commands to start one: `cms/Widget/README.md`.

## Do not put here

- Root code that imports `Cms\Widget\` classes: use the `app/Contracts/Widget/` contracts.
- Content data (posts, categories): read it through Content's `@api` classes.
- Bindings in `register()` for root contracts.
