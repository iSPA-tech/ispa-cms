# Changelog

## 2026.11.11 - 2026-11-26
- A Core update started from the admin no longer rolls back with a failed health check on servers where the web server and the code owner are different users: the health check compiles its views in a private temporary folder.
- The Core update card shows its state at a glance: update available, in progress with a progress bar, done, failed, refused or up to date.
- The switch that allows module installs reads as its own setting.

## 2026.11.10 - 2026-11-26
- Sites deployed by iSPA (release folders behind `current`) can update Core from the admin once their deploy writes a core manifest. The update changes only files of the public core package that this site's deploy also took from the core build; it never touches client modules, projects, installed modules, site translations, `vendor/` or the site's built assets, and removes a file only when the deploy's manifest lists it. `VERSION`, the composer files and `runtime_modules.json` stay the deploy's (the card shows the core version from `core-manifest.json`), so the admin keeps rebuilding. It runs inside the live release folder.
- Such an update stops before any change when the package's Core, Content or Widget is older than the site's, or when it needs PHP packages the site does not have (deploy instead).
- The upgrade runner line shown on the Core card goes through the `current` link on release-folder sites.

## 2026.11.09 - 2026-11-25
- Upgrading several modules one after another no longer stops at the third: starting an install, uploading a package and uninstalling a module each allow 10 requests in 10 minutes instead of 3.
- When that limit is reached, the dialog says so in plain words and tells how many minutes to wait, instead of "The install could not be started".

## 2026.11.08 - 2026-11-25
- Installing or upgrading a module no longer stops with "the queue gives up too early" on sites whose queue hands a job to another worker sooner than a long install takes (the usual Redis and database settings). Such sites now run installs through the every-minute schedule, as sites without a queue worker already do; the install only needs that schedule to be running.
- Clearer messages in the install dialog when the schedule is not running, when the site is not ready to install, and when data updates are still waiting.

## 2026.11.07 - 2026-11-25
- Stored email templates are filled by placeholder only and never run as code; a template can only use a mail layout from the allowed list.
- Managed email templates and the Email templates screen in Settings; workflows can send a managed template.
- A version number from before CalVer (a "month" above 12, such as 2026.16.2) now counts as older than every CalVer release: such a site is told to deploy or upgrade Core first instead of installing a module that needs a newer Core, and it is offered the CalVer Core upgrade.
- One invalid workflow node no longer drops the other nodes of its module.
