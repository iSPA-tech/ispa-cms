# iSPA CMS

Websites with their own business workflows, switched on by modules.

iSPA CMS is a modular CMS built on Laravel. The core handles multilingual content, users, permissions and security.
Each further need, such as a digital library, an academic journal, online classes or training management, is a module
you switch on.

[Website](https://ispa.tech) · [Documentation](https://docs.ispa.tech/en/docs) · [Install guide](INSTALL.md) ·
[Changelog](CHANGELOG.md)

## What is in this repository

- The **Core**, **Content** and **Widget** modules: posts and pages, SEO, the AI assistant, automation, permissions,
  backup and page layout.
- The admin (iPanel), a Vue 3 application: its sources are in `admin/`, the built copy in `public/iPanel/`.
- The **Default** theme (`projects/Default/`): a Tailwind site whose header and footer menus come from the menus you
  edit in the admin, and whose home page lists the newest posts.
- The `./ispa` command for development and upgrades.

More modules are installed from the admin (Modules) once the site is connected to iSPA Hub. The site and the admin
work without the hub.

## Requirements

- PHP 8.3 or newer with the usual Laravel extensions and the PDO driver of your database.
- Composer 2.
- MySQL 8, MariaDB 10.6 or PostgreSQL; SQLite for a trial.
- Optional: Node.js 20 or newer and pnpm 10, only for `./ispa start`, `./ispa build` and the admin screens of modules
  installed from the hub.

## Quick start

```bash
composer create-project ispa-tech/ispa-cms my-site
cd my-site
# set DB_CONNECTION, DB_DATABASE, ... and APP_URL in .env
php artisan cms:install --admin-email=you@example.com
php artisan serve
```

The site opens at the printed address and the admin at `/iPanel/`. The installer prints the password of the first
administrator once; change it after signing in. Installing from the release zip, connecting to the hub and running
without it are described in [INSTALL.md](INSTALL.md).

## Development

```bash
pnpm install
./ispa start          # PHP server, site Vite and admin Vite together; Ctrl+C stops all three
./ispa build          # rebuild the site assets and the admin
./vendor/bin/pest     # PHP tests
pnpm --dir admin exec vitest run   # admin tests
```

| Path | What it holds |
|------|---------------|
| `app/`, `config/`, `routes/` | The Laravel application |
| `cms/<Module>/` | Modules: their code, routes, admin screens (`admin/`), translations and migrations |
| `projects/<Name>/` | Site themes; `projects/Default/` ships with the release |
| `admin/` | Sources of the admin |
| `public/iPanel/` | The built admin |

Versions follow the calendar: `YYYY.MM.NN` (year, month, release of that month), for example `2026.11.01`. Modules
version themselves the same way.

## Contributing

Bug reports, fixes and new modules are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request,
and follow the [code of conduct](CODE_OF_CONDUCT.md). Report security issues privately as described in
[SECURITY.md](SECURITY.md), never in a public issue.

## License

iSPA CMS is licensed under the GNU Affero General Public License v3.0 only ([LICENSE](LICENSE)). The licences of the
bundled third-party packages are listed in [NOTICE](NOTICE). Premium modules offered through iSPA Hub are not part of
this repository.
