# Core module

Always on. Owns the platform: users, roles and permissions, settings, languages and translations, media and uploads,
module install and packages, hub connection, core upgrade, backup, import and bulk actions, workflows (automation),
the AI assistant layer and the install/dev/build commands. Namespace `Cms\Core\`, alias `core`. Rules for every
module: root [AGENTS.md](../../AGENTS.md).

## Entry points

| What | Where |
|---|---|
| Provider | `cms/Core/app/Providers/CoreServiceProvider.php` (registers Route, Auth, Event, CMSCache, CMSLogger providers) |
| Routes | `cms/Core/routes/api.php` (admin API under `v2/admin`), `cms/Core/routes/web.php` |
| Admin controllers | `cms/Core/app/Http/Controllers/V2/Admin/` |
| Response helper | `cms/Core/app/Responses/AppResponse.php` (returned by `ires()`) |
| Commands | `cms/Core/app/Console/`, `cms/Core/app/Console/Commands/` (`cms:install`, `cms:dev`, `cms:build`, `cms:make-module`, `cms:manifest-lint`, `cms:translation-generate`, `cms:translation-sync`, `cms:core-upgrade`) |
| Config | `cms/Core/config/` (merged as `core.*`, e.g. `core.workflow`) |
| Module template | `cms/Core/resources/module-template/` (source of `cms:make-module`) |

## Key services and registries

| Area | Where |
|---|---|
| Settings resolver | `cms/Core/app/Services/CMSSettingUnified.php` (behind `iSetting()`) |
| Translation import | `cms/Core/app/Services/TranslationModuleSeederImporter.php` |
| Module install, packages, generator | `cms/Core/app/Services/Modules/` (`Install/`, `Packages/`, `Generator/`, `ModuleEdition.php`) |
| Hub connection | `cms/Core/app/Services/Platform/` (`SiteStatusService.php`, `PlatformHubClient.php`) |
| Core upgrade | `cms/Core/app/Services/CoreUpgrade/` |
| Backup | `cms/Core/app/Services/Backup/` |
| Imports | `cms/Core/app/Services/Import/ImportDefinitionRegistry.php`, `cms/Core/app/Services/Import/Parser/ParserRegistry.php` |
| Bulk actions | `cms/Core/app/Services/Bulk/BulkRegistry.php` |
| Workflow steps | `cms/Core/app/Workflows/Runtime/StepRunnerRegistry.php`, `cms/Core/config/workflow.php` |
| AI assistant tools | `cms/Core/app/Agents/Tools/AgentToolRegistry.php`, `cms/Core/config/agentic.php` |
| Models | `cms/Core/app/Models/` (`Setting`, `Permission`, `Language`, `Translate`, ...) |

Before adding an import, bulk action, workflow step or assistant tool, register it in the matching registry instead
of adding a new mechanism.

## Admin and tests

- Admin screens: `cms/Core/admin/` (`module.js`, `route-config.js`, `navigation-config.js`, `pages/`), alias
  `@core-admin` in the admin build.
- Tests: `cms/Core/tests/` (`Unit`, `Feature`, `Integration`, `Contract`). `cms/Core/tests/Pest.php` binds
  `Cms\Core\Tests\TestCase`. See [testing.md](../../docs/agents/testing.md).

## Core version = contract for Content

- `cms/Core/module.json` `version` (CalVer `YYYY.MM.NN`) is the version of the platform surface Content is built
  against. Content declares the accepted range in `requires.core`.
- The surface is stored in `cms/Core/tests/Contract/content-contract.json`: every `App\` and `Cms\Core\` symbol that
  Content imports, with its public members. `cms/Core/tests/Feature/Modules/ContentContractTest.php` regenerates it
  and fails on a difference:
  `CONTENT_CONTRACT_WRITE=1 ./vendor/bin/pest cms/Core/tests/Feature/Modules/ContentContractTest.php`.
- Contract file unchanged: no version bump. Entries added or changed: next CalVer in the same commit. A member
  removed from a symbol Content still uses (or a narrower signature): only in the next year's version, together
  with a Content release whose range accepts it.
- Class names built from strings (not imports) are not covered; keep model class names stable.

## Do not put here

- Code that names an optional module's classes or tables. Use a contract in `app/Contracts/` with a null default,
  or check `App\Support\Modules\ModuleStatus::isEnabled()` first.
- Content tables or Content logic (posts, pages, tags, bookmarks): they belong to `cms/Content/`.
- Site-specific or theme-specific behaviour: it belongs in `projects/<Folder>/`.
- New mechanisms that duplicate a registry listed above.
