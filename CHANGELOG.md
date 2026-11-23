# Changelog

Notable changes of each release. Versions follow the calendar: `YYYY.MM.NN` (year, month, release of that month).

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
