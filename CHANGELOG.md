# Changelog

Notable changes of each release. Versions follow the calendar: `YYYY.MM.NN` (year, month, release of that month).

## 2026.11.01

The first public release of iSPA CMS.

- **Modules**: Core, Content and Widget: posts and pages, SEO, the AI assistant, automation, permissions, backup and
  page layout.
- **Admin (iPanel)**: the Vue 3 admin, built into `public/iPanel/`; its sources are in `admin/`.
- **Default theme**: a Tailwind site with header and footer menus from the menus edited in the admin, a home page that
  lists the newest posts, and the post, category and news pages of the Content module.
- **Install**: `composer create-project ispa-io/ispa-cms`, or the release zip followed by `composer install`; then
  `php artisan cms:install` creates the tables, the first administrator and the admin settings. Tried on SQLite and
  MySQL.
- **No hub needed**: the site and the admin work on their own. Connect to iSPA Hub later, from Modules, to install more
  modules.
- **`./ispa` command**: `start` runs the development servers, `build` rebuilds the site and the admin, `upgrade`
  upgrades the core.
