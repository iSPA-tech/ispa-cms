# Contributing to iSPA CMS

Thank you for helping. This page says how to report a problem, set up the project and send a change.

## Before you start

- **A bug**: open an issue with the iSPA CMS version (the `VERSION` file), the modules involved, the steps that
  reproduce it, what you expected and what happened. Add the relevant lines of `storage/logs/laravel.log` with
  passwords, tokens and personal data removed.
- **A larger change or a new module**: open an issue first and describe what you want to do, so we can agree on the
  approach before you spend time on it.
- **A security issue**: follow [SECURITY.md](SECURITY.md). Do not open a public issue.

## Set up the project

You need PHP 8.3 or newer, Composer 2, Node.js 20 or newer and pnpm 10 (`corepack enable`).

```bash
git clone https://github.com/iSPA-io/ispa-cms.git
cd ispa-cms
composer install
cp .env.example .env    # set the database; SQLite is enough for development
php artisan cms:install --admin-email=you@example.com
pnpm install
./ispa start            # the site, its Vite server and the admin's Vite server
```

## Tests

```bash
./vendor/bin/pest                   # PHP tests (SQLite in memory, see phpunit.xml)
pnpm --dir admin exec vitest run    # admin tests
```

Add a test with every fix and feature: a fix comes with a test that fails without it.

## How the code is organised

- **Modules own their code.** A module lives in `cms/<Module>/`: application code, routes, admin screens (`admin/`),
  translations, migrations and seeders. Keep a change inside the module it belongs to and do not move logic across
  modules.
- **Layers.** Controllers stay thin; business logic goes in services, data access in repositories.
- **Admin API.** Records that have a `uuid` column are identified by it in the V2 admin API, never by the numeric id.
- **Public pages.** A module's public pages are declared in the module and render with the active project's layout.
  Templates use Blade and Tailwind utility classes, without hand-written CSS classes.
- **Translations.** To change a wording, change the text of the existing key; do not add a replacement key.
- **New modules.** Start one with `php artisan cms:make-module <Name> --mode=lite|normal|full --entity=<Entity>`: it
  writes `cms/<Name>` with a working sample entity (admin API, permissions and tests; the admin page and translations
  from `normal`; public pages, a scheduled command, a queued mail and events in `full`). What each mode creates and
  the next steps: `cms/Core/resources/module-template/README.md`.
- Comments, documentation and commit messages are written in English.

## Commits and pull requests

- Use conventional commits with the module as scope: `fix(content): ...`, `feat(core): ...`; use `app` for changes
  outside `cms/`. One module per commit.
- Keep a pull request to one topic. Say what changes and why, link the issue, and add screenshots for visible changes.
- Sign off every commit (`git commit -s`). The `Signed-off-by` line certifies the
  [Developer Certificate of Origin](https://developercertificate.org/): you wrote the change or have the right to submit
  it under the project's licence.

## Licence

iSPA CMS is licensed under the GNU Affero General Public License v3.0 only. By contributing, you agree that your
contribution is licensed under the same terms.
