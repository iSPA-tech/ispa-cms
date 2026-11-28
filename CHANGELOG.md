# Changelog

Notable changes of each release. Versions follow the calendar: `YYYY.MM.NN` (year, month, release of that month).

## 2026.11.11

- **Ships Core module 2026.11.16** (Content 2026.11.11 and Widget 2026.11.02 unchanged).
- **Local backups no longer pile up after deploys**: the built-in local backup folder was stored with the release path
  of the day (`releases/<n>/storage/backups/local`). Once that release was removed, backups went to a recreated folder
  that the next deploy deleted, and the retention cleanup marked old copies as removed while their files stayed in the
  shared storage. The folder is now stored as the real shared storage path, an update repoints existing sites, a
  folder whose parent is gone is refused instead of recreated, and the cleanup sends an alert when it cannot open a
  backup destination.
- **Core updates on CI-deployed sites**: a site that runs a module updated from the hub, which the core package's own
  module list does not name, is no longer refused with "update these modules first"; a deployed site keeps its own
  list.
- **Stripe webhook test mode**: the webhook picks the test signing secret when the test-mode switch is stored as a
  boolean.

## 2026.11.10

- **Ships Core module 2026.11.15, Content 2026.11.11 and Widget 2026.11.02.**
- **Core updates run the new version's steps**: after the files are replaced, the version being installed runs the
  steps that follow (migrations, permissions, caches, translations, admin language packs). Before, the version doing the
  update chose them, so a step added by the new version was skipped: a site updated from the admin could show raw text
  keys in its admin because the language packs of the new version were never written. An update started from an older
  version now also writes those packs.
- **Core updates over changed files**: when the only thing stopping an update is core files changed on the site (an
  urgent fix applied by hand, for example), the update card offers "Update and overwrite changed files". It lists the
  files, asks for a second confirmation, keeps them in the update's backup and replaces them with the new version's. The
  command shown under a refused update is now the one that works (`./ispa upgrade --overwrite-modified` when files were
  changed) and has a copy button.
- **Admin rate limit**: signed-in admin requests get their own budget of 300 a minute per account, so busy admin screens
  no longer answer "Too Many Attempts".
- **Workflows**: templates from the hub follow the site language (Vietnamese or English), and every bundled template
  ships in both.
- **Widgets**: the published site layout renders page and page-type placements; the standard site layout areas are
  registered once for every project, and widget slots carry the page they belong to.
- **Settings**: password and runtime credential settings stay out of `config/setting.php`; seeded payment, logistics,
  mail and push credentials are private; the Stripe webhook reads its secret from its settings row; a Discord address
  in Settings → Social.
- **Deploys**: `cms:deploy-preflight --build` checks only the build output, so a deploy can check it before running
  migrations.
- **Logging**: writing configuration logs the file, groups and key count, never the values.

## 2026.11.09

- **Ships Core module 2026.11.14, Content 2026.11.10 and Widget 2026.11.01.**
- **Translations**: the translation sync reads every admin translation file of a module again. In 2026.11.08 one file
  dropped the ones read before it, so a fresh install lacked most admin texts and an updated site did not get new
  admin texts (labels such as the new-device notice showed "New notification").
- **Core updates on a deployed site**: an update from the admin now brings the new admin source too, from this very
  update on, and rebuilds the admin only after the site passed its health check.
- **Workflows**: scheduled workflows run by default (the weekly report template needed a setting before);
  `WORKFLOW_SCHEDULER_ENABLED=false` turns every schedule of a site off.

## 2026.11.08

- **Ships Core module 2026.11.13, Content 2026.11.10 and Widget 2026.11.01.**
- **Update everything at once**: the Modules page and the widget and workflow libraries offer one "update all" run.
  Items run one at a time in the order their requirements need, with a progress panel; it can be stopped, never
  stays stuck, and skips versions that cannot be undone at their turn.
- **Workflows**: one click away in the sidebar, with the library, import and credentials as entries.
  - The setup screen asks who is told by name ("Name - username"), suggests the people who may see the record and
    previews the recipients; permissions stay under "advanced".
  - The permission picker names the module, what the permission is about and the action.
  - A site names who reviews workflow changes; the only publisher can put a waiting change live at once.
  - A switched-on workflow takes over the built-in contact mail; newsletter sign-ups start no contact workflow.
  - E-mail templates match a language by code or path and gain the site languages they lack.
  - Installed templates are laid out top to bottom; a weekly report template is included.
- **Notifications**: each notification shows what it says under its label; the new-device sign-in and step-up lock
  notices are named and say which device.
- **Core updates**:
  - The admin reloads itself after a Core update it watched finish.
  - The dashboard and the site report show the installed version after an admin update.
  - An update installed from the release zip imports the new translations and writes the admin language packs.
  - The Modules page reload also refreshes the update card.
- **Settings**:
  - The settings are the source of the environment-bound application settings; the theme folder is read-only in the
    admin and changes when the site is deployed.
  - Saving settings refreshes only the settings caches, and Redis cache stores flush only their own prefix, so a
    shared Redis database is never emptied.
- **Fixes**:
  - Images served as `application/octet-stream` are accepted when the bytes are an image.
  - The system sender name follows the site brand.
  - Widget seeding on PostgreSQL moves the id sequences past older rows.
  - Leftover unprefixed status rows are retired.
  - A workflow run without a resume step starts at its trigger.

## 2026.11.07

- **Ships Core module 2026.11.11.**
- **Core updates**: an update started from the admin no longer rolls back with a failed health check on servers where
  the web server and the code owner are different users; the health check now compiles views in a private temporary
  folder.
- **Core update card**: the card shows each state clearly: update available, in progress (with a progress bar), done,
  failed, refused and up to date.
- **Modules page**: the switch that allows module installs reads as its own setting.

## 2026.11.06

- **Ships Core module 2026.11.10.**
- **Core updates on sites deployed by CI**: a site whose code is deployed by a CI pipeline can now update Core from
  the admin. The update replaces only the Core build and keeps the site's own files (`VERSION`, the composer files
  and the list of runtime modules) as the deploy left them. Views compiled during the update's health check are
  cleared afterwards.
- **Module install rate limit**: starting a module install, upload or uninstall is allowed 10 times per 10 minutes.
  When the limit is reached, the Modules page says how long to wait before trying again.
- **Settings**: the Environment admin page is removed. A stored secret (password, API key) can now be cleared from
  its field on the settings screens.
- **Runtime modules**: a module installed online and also shipped with the site no longer logs an error on every
  request; the copy shipped with the site is used, as before.

## 2026.11.05

- **Ships Core module 2026.11.08 and Content module 2026.11.09.**
- **Module installs from the admin**: when the queue's `retry_after` is not longer than the install job's time limit,
  an install or upgrade runs through the every-minute scheduler instead of a queue worker, so it is not started twice
  or cut off. Install errors are worded plainly.
- **Core updates**: the core update button works on servers where the web server and the code owner are different
  users.
  - An update started from the admin takes its database backup through the site's scheduler, as the web server user,
    and the upgrade runner reuses it.
  - Backup folders are created as `0770` and backup files as `0660`, so the access lists (ACLs) INSTALL.md sets on
    `storage/` keep working for both users; other users still have no access.
  - `./ispa upgrade` stops before changing anything when it cannot write the backup folders, and says how to fix it.
  - On simple hosting with no cron, the update request starts the backup and the runner itself; the core update card
    gives a one-line ownership fix and a Check again button.
- **Version order**: older version numbers whose month is above 12 (for example `2026.16.2`) sort below every
  calendar version, so install and core upgrade decisions treat them as older.
- **Email templates**: a Settings screen lists the email templates by group and language, with their mail class,
  allowed layouts and a preview in a sandboxed frame. Stored templates are filled in safely, without being compiled
  as Blade, and only a Webmaster can mark a template as a system mail. Workflows can send a managed template.
- **Workflows**:
  - A visual designer with flow, utility, AI, fetch, messaging, human (approval, notify, ask and wait) and trigger
    nodes (manual, schedule, model event, app event, webhook), sub-workflows and error workflows.
  - A workflow project can run by hand or on a cron schedule. The workflows page has a run panel, schedule presets and
    a step log.
  - Published versions are sealed; drafts, publishing with a check of sensitive changes, version history, rollback,
    a pause-all switch and step-up for credentials.
  - Approvals: an inbox in the sidebar, assignees and review deadlines, reject reasons, rewrite rounds, undo for
    reversible changes, and auto-publish after a calibrated AI review.
  - Workflow packages and a template library from the hub, with a setup screen for installed templates and in-place
    updates of unchanged installs. A contact template announces new messages, including from returning senders.
  - An agent proposal can be approved and applied in one step.
  - Content adds workflow nodes to read, draft, publish and classify posts.
  - Fixes and hardening:
    - an instance runs once, and only while its project is active;
    - loops advance and delays pause the run;
    - steps call only allowlisted classes, commands, models and app events, and keep their runner type on MySQL;
    - outbound HTTP pins the checked address, credentials are bound to their allowed hosts, signed webhooks are
      refused when old or replayed;
    - approvals check the reviewer's permission and assignment;
    - run history needs the workflow permission;
    - reasoning prompts treat tool output and context as untrusted data;
    - one invalid node no longer hides the other nodes of its module.
- **Module packages**: packages built from source leave out developer documentation, reports, notes and seed SQL
  dumps.
- **Fixes**: the queued image crop job only raises the PHP memory limit, never lowers it; the dashboard works when an
  installed Ecommerce module lacks the draft order bucket.

## 2026.11.04

- **Core module 2026.11.03**: `./ispa upgrade` (and `php artisan cms:core-upgrade`) does nothing when the core is
  already on the newest version, and says so, instead of starting an upgrade to the same version.
- **Guide for coding agents**: `the module notes`, `the module notes`, `docs/agents/` and an `the module notes` for Core, Content and Widget
  describe the layout, the request flow, the rules and the test commands, for contributors who work with a coding
  agent. `CONTRIBUTING.md` points to them.
- **Download**: each release also carries `ispa-cms.zip`, so
  `https://github.com/iSPA-tech/ispa-cms/releases/latest/download/ispa-cms.zip` always gives the newest release.

## 2026.11.03

- **Security updates**: the npm dependencies of the site and the admin are updated past every open advisory: Vite 8.1.6,
  Axios 1.21, Vue 3.5.46, Vue I18n 11.4.23, Vitest 4.1.12 and Tiptap 3.35, plus minimum versions for transitive packages
  (undici, follow-redirects, form-data, shell-quote, brace-expansion, postcss, immutable, nanoid, glob, minimatch and
  others). The unused `cms/Core/package.json` is removed.
- **Core module 2026.11.02**: carries the Core changes below.
- **Branded mail**: a shared mail layout with an optional logo and a `[Brand] - ` subject prefix; the verify-email,
  new-device and admin notice mails are translated.
- **Admin sign-in**: Admin accounts can sign in to the admin with their password.
- **Modules**: an uninstall is refused when the module refuses to be switched off.
- **Starter packs**: the translation-sync step writes only the public translation files.
- **Fixes**: the queued new-device notice can be restored by the queue worker; the tracking buffer flush skips Redis
  while its counter gate is closed; shared breadcrumb links have a 44 px touch target on phones.

## 2026.11.02

- **Package name**: the Composer package is `ispa-tech/ispa-cms` (`composer create-project ispa-tech/ispa-cms my-site`),
  matching the repository at https://github.com/iSPA-tech/ispa-cms.
- **Prices**: the short units of large prices ("1,5 tỷ", "250 tr"; "1.5 b", "250 m") show their words instead of the
  translation key.

## 2026.11.01

The first public release of iSPA CMS.

- **Modules**: Core, Content and Widget: posts and pages, SEO, the AI assistant, automation, permissions, backup and
  page layout.
- **Admin (iPanel)**: the Vue 3 admin, built into `public/iPanel/`; its sources are in `admin/`.
- **Default theme**: a Tailwind site with header and footer menus from the menus edited in the admin, a home page that
  lists the newest posts, and the post, category and news pages of the Content module.
- **Install**: `composer create-project ispa-tech/ispa-cms`, or the release zip followed by `composer install`; then
  `php artisan cms:install` creates the tables, the first administrator and the admin settings. Tried on SQLite and
  MySQL.
- **No hub needed**: the site and the admin work on their own. Connect to iSPA Hub later, from Modules, to install more
  modules.
- **`./ispa` command**: `start` runs the development servers, `build` rebuilds the site and the admin, `upgrade`
  upgrades the core.
