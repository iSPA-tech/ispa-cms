# Security policy

## Supported versions

Security fixes are made in the latest release. Upgrade to it before reporting, and upgrade when a fix is released
(`./ispa upgrade`, or a new `composer create-project` / release zip).

## Reporting a vulnerability

Please do not open a public issue, pull request or discussion for a vulnerability.

Send an email to **hello@ispa.tech** with "Security" in the subject, or use "Report a vulnerability" in the Security
tab of the GitHub repository when it is shown. Include:

- the version of iSPA CMS (the `VERSION` file) and the modules involved;
- what an attacker can do, and what they need first (an account, a role, a setting);
- the steps or a request that reproduce it;
- any logs or screenshots, with passwords, tokens and personal data removed.

We confirm that we received the report, keep you informed while we work on a fix, and credit you in the release notes
unless you prefer not to be named. Please give us the time to release a fix before you publish anything.

## Scope

This repository: the core, the Core, Content and Widget modules, the admin and the Default theme. Modules installed from
iSPA Hub can be reported to the same address. Sites that other people run with iSPA CMS are operated by them; report
issues of those sites to their owners.
