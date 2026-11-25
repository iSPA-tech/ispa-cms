# Changelog

## 2026.11.08 - 2026-11-25
- Installing or upgrading a module no longer stops with "the queue gives up too early" on sites whose queue hands a job to another worker sooner than a long install takes (the usual Redis and database settings). Such sites now run installs through the every-minute schedule, as sites without a queue worker already do; the install only needs that schedule to be running.
- Clearer messages in the install dialog when the schedule is not running, when the site is not ready to install, and when data updates are still waiting.

## 2026.11.07 - 2026-11-25
- Stored email templates are filled by placeholder only and never run as code; a template can only use a mail layout from the allowed list.
- Managed email templates and the Email templates screen in Settings; workflows can send a managed template.
- A version number from before CalVer (a "month" above 12, such as 2026.16.2) now counts as older than every CalVer release: such a site is told to deploy or upgrade Core first instead of installing a module that needs a newer Core, and it is offered the CalVer Core upgrade.
- One invalid workflow node no longer drops the other nodes of its module.
