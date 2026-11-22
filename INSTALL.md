# Installing iSPA CMS

This guide installs a fresh site. You get a working site on the `Default` project, the admin at `/iPanel/`, a first
administrator account and a one-time hub setup code. The site and the admin come already built, so you need PHP and a
database only. You sign in to the admin right away; connecting the site to the iSPA hub is optional and can be done
later, from inside the admin.

## What is in the download

The download has the basics: posts and pages, SEO, the AI assistant, automation, permissions and backup, and page
layout (the Core, Content and Widget modules). Other modules are installed for free from the admin, under Modules
("Quản lý module"), after you connect the site to the hub (see "Connecting to the hub" below).

Installing a module online does not need Node.js: the module is installed and runs. Most hub modules (all but
Account and SocialAuth) also bring their own admin screens, and those have to be built into the admin. When the server
has no Node.js, the install still succeeds and shows the warning `MODULE_ADMIN_BUILD_NOT_DEPLOYED` ("the admin
screens cannot be rebuilt on this site"); the module's admin screens appear after a rebuild, in one of two ways:

- install Node.js 20 and pnpm on the server, then run `./ispa build --admin`; or
- on another machine, set up the same site code with the same modules, run `./ispa build --admin` there, and copy its
  `public/iPanel` folder to the server (keep the server's own `public/iPanel/config.js`, or run
  `php artisan cms:admin-config` on the server after copying).

## What you need

- PHP 8.3 or newer with the usual Laravel extensions (`ctype`, `curl`, `fileinfo`, `json`, `mbstring`, `openssl`,
  `pdo`, `tokenizer`, `xml`) and the PDO driver of your database (`pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`).
- Composer 2.
- A database: MySQL 8 or MariaDB 10.6 (the `.env.example` default), PostgreSQL, or SQLite for a trial. Create an
  empty database and a user first. The installer creates the tables.
- A web server that serves the `public/` folder (nginx, Apache, Laravel Herd, or `php artisan serve` for a trial).
- Node.js 20 or newer and pnpm 10 (`corepack enable` gives you pnpm) are optional. Installing and running the site
  and installing modules online work without them. You need them only for `./ispa start` (development servers),
  `./ispa build` (rebuild the site and admin from source), and to show the admin screens of a module you install from
  the hub (see "What is in the download" above for the other way).

## Steps

1. Create the site.

   ```bash
   composer create-project ispa-io/ispa-cms my-site
   cd my-site
   ```

   Downloaded the zip of a release instead? It holds the core with the site and admin already built, but no
   `vendor/`. Unzip it into an empty folder, then:

   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   ```

   and continue at step 2.

2. Open `.env` (Composer creates it from `.env.example`) and set the database.

   Set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. For SQLite set
   `DB_CONNECTION=sqlite` and `DB_DATABASE` to the absolute path of an empty file. Set `APP_DOMAIN` and `APP_URL` to
   the address you will open in the browser. Leave `APP_KEY` empty; the installer generates it.

   `.env.example` starts with a plain file cache and Redis switched off, so a new machine needs no Redis. Turn Redis
   on later with `CACHE_DRIVER=redis` and `REDIS_ENABLE=true` once it runs.

3. Run the installer.

   ```bash
   php artisan cms:install --admin-email=you@example.com
   ```

   The installer:
   - checks the project folder (`projects/Default`), the writable folders and the database connection, and stops
     with a plain message when one is wrong;
   - asks for the first administrator first: email (unless `--admin-email` is given), username (Enter keeps
     `webmaster`) and a password, typed twice and hidden, at least 12 characters. Leave the password empty to have one
     generated;
   - generates `APP_KEY` into `.env` when it is empty (an existing key is never replaced);
   - creates the tables and loads the modules, permissions and translations (about a minute);
   - creates the first administrator; a generated password is printed once, so save it and change it after signing
     in (a password you typed is never printed). You sign in with the email or the username;
   - links `public/storage`;
   - writes `public/iPanel/config.js` for the admin (see below);
   - prints the hub setup code (see below);
   - on a site installed from the core package, prints the crontab line of the upgrade runner and the user it belongs
     to, so the admin can update the core with one button (see "One-click core updates" below).

   Running it again is safe: it changes nothing that exists and prints no password. If it stops with an error, fix
   the cause and run it again.

   In a script, add `--no-interaction`: nothing is asked, the username is `webmaster` (or `--admin-username=...`) and
   the password is generated and printed once. There is no password option on purpose: a password on the command line
   stays in the shell history.

4. Open the site. Point your web server at `public/`, or for a trial run:

   ```bash
   php artisan serve
   ```

   The public pages of the `Default` project load at once. The admin is at `/iPanel/` on the same address.

## The admin settings file

The admin reads its site settings at run time from `public/iPanel/config.js`. `php artisan cms:admin-config`
generates that file from `.env`: `APP_URL`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `ISPA_ADMIN_AUTH_KEY` and
`PLATFORM_HUB_URL`. `cms:install` runs it for you. Run it again after you change any of those values. Do not edit the
file by hand; it is rewritten each time.

Configure your web server to send `Cache-Control: no-cache` for this one file, so a change shows at once.

nginx:

```nginx
location = /iPanel/config.js {
    add_header Cache-Control "no-cache";
    try_files $uri =404;
}
```

Apache (in the site config, or in `public/.htaccess` with `mod_headers` enabled):

```apache
<Files "config.js">
    Header set Cache-Control "no-cache"
</Files>
```

In the Apache example the rule matches every file named `config.js`; to limit it to the admin, put it in a
`<Directory "/path/to/my-site/public/iPanel">` block.

## Making your own changes

`projects/Default` and the core modules in `cms/` belong to the core and are replaced when the core is updated. Do
not edit them in place.

- To change the site's templates, copy `projects/Default` to `projects/<YourName>` first, then set
  `VITE_TEMPLATE_FOLDER=<YourName>` in `.env` and change your copy.
- Put your own modules in `cms/<YourModule>`.

Changes to the public site's templates or styles need a rebuild with `./ispa build`, which uses Node and pnpm.

## Connecting to the hub

A new site is not connected to the iSPA hub, and it does not need to be: you sign in and use the admin as usual. The
hub adds the module store, grants for premium modules and the iSPA services (translation, text to speech). Without it,
the site keeps everything in the download, and the services that need the hub are skipped.

To connect:

1. Create an account in the iSPA customer portal: open <https://ispa.tech/register>, enter your name and email, open
   the link in the email you receive, then choose a password. This also creates your organization in the portal.
   Already have an account? Sign in at <https://ispa.tech/login>.
2. In the portal open "Websites & domains" ("Website & tên miền") and choose "Connect a website" ("Kết nối website").
   Enter the domain of the site without `www` (for example `example.com`), then choose "Create website codes" ("Tạo
   mã cho website"). Copy the "Secret code" ("Mã bí mật") at once: the portal shows it only once. If you lose it, or
   the site is not connected within 24 hours, create a new code there.
3. Sign in to the admin of your site, open Settings, Modules ("Quản lý module"), tab "Module store" ("Kho module"),
   and choose "Connect to iSPA Hub". The website address is filled in; enter the secret code from the portal and the
   setup code that `cms:install` printed (8 groups of characters, works once, valid for 7 days), then choose
   "Connect".
4. The site calls the hub and the hub calls your domain back to check it. The domain must point to this server and be
   reachable from the internet over HTTPS for this check. Development domains (`localhost`, `*.test`, `*.localhost`,
   private addresses) are not called back and get free modules only. Once the check passes, the portal lists the
   site as connected and the module store opens in the admin.

Lost the code, or it expired? Print a new one on the server:

```bash
php artisan cms:hub-setup-code
```

The site id and secret are stored encrypted in the database, never in `.env`. If you prefer `.env`, set
`CMS_DOMAIN` and `CMS_DOMAIN_SECRET` there; those values win over the stored ones and no setup code is needed.

## What the site sends to iSPA Hub

A connected site sends one signed report a day (`php artisan cms:platform-report`, scheduled), and again after a
module is installed or removed. You choose how much it sends: in the admin open Modules, tab "Shared with iSPA"
("Gửi cho iSPA"). "View the data to be sent" shows the exact JSON of the next report before you save.
`php artisan cms:platform-report --dry-run` prints the same JSON on the server.

| Level | What is sent |
|---|---|
| `off` | Nothing. Module ratings and the hub's install proof need at least `basic`. |
| `basic` | `schema`, `domain`, `app_version`, `release`, and `modules`: for every module its `name`, `enabled`, `version` and `source` (`release` or `runtime`). This is what the hub needs for updates, grants, ratings and support. |
| `full` | Everything in `basic`, plus `revision`, `deployed_at`, `php_version`, `laravel_version`, `activator`, the `revoked` flag of every module, `pending_migrations` (count and up to 50 file names), `health` (database and cache ok/fail, queue connection and failed jobs in the last 24 hours, free disk percent), `backups` (last app backup time and status, server nightly backup date and state), `runtime_collisions` (module names) and `module_integrity` (changed-file count per module, never file names) once an integrity check ran. |

No level ever sends passwords, keys, `.env` values, file paths, content or customer data.

Defaults:
- A site connected from the admin (Modules, "Connect to iSPA Hub") sends `basic` until a Webmaster chooses otherwise.
- A site whose secret is in `.env` (`CMS_DOMAIN_SECRET`, the sites iSPA runs) sends `full`.
- A site that is not connected sends nothing.

Only a Webmaster can change the level, and every change is written to the audit log. A server value
`CMS_PLATFORM_REPORT_LEVEL=off|basic|full` in `.env` wins over the admin choice; the admin then shows the level as set
on the server. The hub keeps reports for at most 90 days, and only iSPA Webmasters can read them (module publishers
cannot).

## One-click core updates

The admin can update the core with one button (Settings > Modules). The web server user does not change the code
itself: it leaves a request, and the upgrade runner, a small job that runs every minute as the user that owns the
code, picks it up. Without the runner the button stays off and `./ispa upgrade` on the command line still works.

Add the runner to the crontab of the code owner (for example the user `deploy`; `crontab -e` as that user):

```cron
* * * * * cd /var/www/example.com && php ispa upgrade --run-requested >/dev/null 2>&1
```

`php artisan cms:install` prints the exact line for your site at the end, and `./ispa upgrade --check` prints it again
with when the runner was last seen. While the runner is missing, the admin card shows the line too, with a ready-made
prompt that the person who manages the server can paste into an AI coding agent to add it. Each minute the runner
only writes a small heartbeat file; Laravel starts only when a request waits.

Or, with systemd, a service and a timer:

```ini
# /etc/systemd/system/ispa-upgrade-runner.service
[Unit]
Description=iSPA CMS upgrade runner

[Service]
Type=oneshot
User=deploy
WorkingDirectory=/var/www/example.com
ExecStart=/usr/bin/php ispa upgrade --run-requested
```

```ini
# /etc/systemd/system/ispa-upgrade-runner.timer
[Unit]
Description=Run the iSPA CMS upgrade runner every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ispa-upgrade-runner.timer
```

`User=` is the user that owns the code, never `root`: the runner refuses to run as root. A site whose code belongs to
root cannot use the button; move the code to a separate account first.

The runner keeps its downloads and file backups in `.ispa-runner/` in the site folder, a folder only the code owner
can open. The site folder itself must not be writable by group or others (for example `chmod go-w` on the site
folder; never on `storage/` or `bootstrap/cache`): otherwise the runner and `./ispa upgrade` refuse to work and the
admin card says why.

The web server user and the code owner both write to `storage/` and `bootstrap/cache`, so give both users access
through ACLs, including default ACLs for new files. A
plain shared group is not enough: the runner creates files with umask 022, and only default ACLs keep them writable
for the web server.

```bash
sudo setfacl -R -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
sudo setfacl -R -d -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
```

On hosting where PHP already runs as the code owner, the site's scheduler acts as the runner and nothing else is
needed.

The admin button stays off for a site deployed as releases behind a `current` link and for a site with its own PHP
dependencies; upgrade those with `./ispa upgrade` on the command line.

## Running without the hub

You can run the site without ever connecting it. The public site, the admin, the content modules and everything that
does not need the hub keep working. Only the module store, premium module grants and the hub-run services (translation,
text to speech) wait for a connection; the Modules page offers "Connect to iSPA Hub" whenever you want them.

## Troubleshooting

- `There is no .env file`: copy `.env.example` to `.env`, set the database and run `cms:install` again.
- `The database is not reachable`: check the `DB_*` values and that the database and user exist.
- `The project folder projects/... does not exist`: `VITE_TEMPLATE_FOLDER` in `.env` names a project that is not in
  this tree; use `Default`.
- A blank page or missing styles on the public site after you changed templates: run `./ispa build`.
- The admin shows old settings after you changed `.env`: run `php artisan cms:admin-config` and reload with the cache cleared.
- Redis errors on a new machine: set `CACHE_DRIVER=file` and `REDIS_ENABLE=false` in `.env`.
