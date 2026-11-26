# Changelog

Notable changes of each release. Versions follow the calendar: `YYYY.MM.NN` (year, month, release of that month).

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
