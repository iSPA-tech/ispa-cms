# Admin app (`admin/`)

Read before any change in `admin/` or `cms/<Module>/admin/`. The admin is a Vue 3 + Vite single-page app (Ant Design
Vue, Tailwind, vue-i18n, vue-router) served at `/iPanel/`.

## Layout

| Path | Holds |
|---|---|
| `admin/src/main.js` | App setup and global components |
| `admin/src/ajax.js` | Axios client for the admin API |
| `admin/src/config/base.js` | `apiBase` (`<site>/api/v2/admin/`), `basePath` (`/iPanel/`), locales |
| `admin/src/config/runtime.js` | Site values at run time (from the generated `/iPanel/config.js`, else `VITE_*`) |
| `admin/src/config/admin-modules.js` | Discovers `cms/*/admin/module.js` |
| `admin/src/config/route.js` | Router; permission check on `meta.rule` |
| `admin/src/config/navigation.js` | Sidebar order (`NAV_ORDER`) |
| `admin/src/config/i18n.js` | Loads language packs from the API |
| `admin/src/config/icon-maps.js` | Icon registry; only names listed here render in `<i-icon>` |
| `admin/src/components/` | Shared components (below) |
| `admin/src/hooks/`, `admin/src/libs/` | Shared composables and API helpers for Core screens |
| `admin/src/pages/` | Shell pages only (module screens live in `cms/<Module>/admin/`); enforced by `admin/src/__tests__/config/shell-pages-only.spec.ts` |
| `admin/src/__tests__/`, `admin/tests/` | Vitest specs |
| `cms/Core/admin/` | Core screens (alias `@core-admin`) |
| `cms/<Module>/admin/` | Each module's screens |

## How a module's screens are registered

1. `cms/<Module>/admin/module.js` exports an object with `key` (module alias), `routeConfig`, `navigationConfig` and,
   for any module the admin does not already know, `slot: { after: '<module key>', navAfter: '<menu name>' }`.
   Without `slot` an unknown module gets no routes and no menu entry.
2. `route-config.js`: `{ path, name, load, meta: { rule: '<permission>' }, children: [...] }`; `text` is a
   translation key (`nav.a.<alias>`).
3. `page-loaders.js`: lazy imports, `() => import('./pages/x.vue').then((m) => m.default)`.
4. `navigation-config.js`: `{ name, group, text, rule, module: '<Module>', icon }`. The entry shows only while the
   module is enabled (`useActiveModules`) and the user has `rule`.
5. Rebuild the admin (`./ispa build --admin` or keep `./ispa start` running).

Working example: `cms/Core/resources/module-template/admin/`. Real modules: `cms/Content/admin/`,
`cms/Widget/admin/`.

## API client

- Import `ajax` from `@/ajax`; paths are relative to `api/v2/admin/` (`ajax.get('skeleton/things', { params })`).
- Put a module's calls in its own `cms/<Module>/admin/api.js`; address rows by `uuid`.
- The client sends the bearer token, `ISPA-ADMIN-AUTH-KEY` and `X-Admin-Locale`. A 401 clears the session and
  reloads to the sign-in page.
- A module's admin code never imports another module's admin files; it keeps its own small client.

## i18n

- Texts come from language packs served at `api/<version>/i18n/adm/<locale>/...`, written by
  `php artisan cms:translation-generate --public-only` from the `translates` table. Keys added only in Vue code do not
  exist.
- Use canonical namespaces: `nav.*` (menu, breadcrumbs), `text.<alias>.*` (screen text), `validation.*`. No English
  fallback as the second argument of `t()`.
- Author the key in the module sidecar, then sync and generate: [conventions.md](conventions.md#translations).
- vue-i18n treats `@` as a link: write `{'@'}` for a literal at-sign in a message.

## Components to reuse

| Need | Use |
|---|---|
| Page header with actions | `<breadcrumbs>` (`admin/src/components/breadcrumbs.vue`); actions as `i-button` in its default slot |
| Buttons | `i-button` (`admin/src/components/i-button.vue`); submit buttons need `native-type="submit"` |
| Card | `<i-box>` |
| List filter / bulk actions | `<i-filter>` (`admin/src/components/filter.vue`), `i-bulk-action` |
| KPI tile | `<i-stat-tile>`, `<i-attention-strip>` |
| Files and images | `MediaCenter`, `ModalMediaCenter`, `media-selection` (media center picker) |
| Floating save bar for dirty forms | `admin/src/components/FormActionBar.vue` |
| Modal footer | `admin/src/components/common/AppModalFooter.vue` |
| Icons | `<i-icon name="...">` with a name from `icon-maps.js` |

Breadcrumb, list and form patterns to copy: `cms/Content/admin/pages/manage.vue`, `create.vue`, `update.vue`.

## Screen rules

- No `<h1>` repeating the page title; subtitle in `#description`, counts in `#badge` of the breadcrumbs.
- Lists: `a-table size="small"`, empty text from a translation key, status shown with `a-tag` and a translated label.
- Forms: guard submits with `if (loading.value) return`, `:disabled="loading"`, reset `loading` only on error.
- Forms ask only what a person knows (root AGENTS.md rule 4). Advanced options go in a collapsed section.
- System values (uuid, timestamps) are read-only. Use a status action endpoint instead of an editable status select
  when one exists.
- Colours: pass semantics (`tone`, `variant`) to shared components; no raw colour classes in pages.

## Commands

```bash
./ispa start                                   # site + admin dev servers
pnpm --dir admin exec vitest run src/__tests__/components/i-button.spec.ts   # one spec
pnpm build:admin                               # build into public/iPanel
```
