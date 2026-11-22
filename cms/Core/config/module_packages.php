<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// The iSPA hub countersign key(s): key id (16 hex) => base64 Ed25519 public key, added in a reviewed commit
// once the owner has generated it with cms:module-package-keygen. The hub signs approved partner packages with it;
// until it is listed, sites refuse those packages and the hub refuses to approve them. Its secret half is set only on
// the hub, in .env PLATFORM_HUB_COUNTERSIGN_SECRET (read through the config platform-hub.countersign_secret, so it
// works with config:cache), never in the repository. Revoke it through revoked_key_ids below.
// Listed here, not in trusted_keys directly: a key in this list is trusted AND marks every package it signs as a
// partner (non-iSPA) package, so its first install on a site needs the explicit confirmation.
$countersignKeys = [
    // Hub countersign key generated 2026-11-16 on the owner's machine (secret in ~/.ispa-release-keys/hub-countersign and
    // only in the cms.ispa.io .env PLATFORM_HUB_COUNTERSIGN_SECRET, never in git).
    '94c2f5485ad0a5cb' => 'd1Sexag9wjU2lm8aorFzif/WgMyZO/YMa3NkcjXGIv4=',
];

return [
    // Trusted release-signing public keys: key id (16 hex, see Cms\Core\Services\Modules\Packages\KeyId) => base64
    // Ed25519 public key. The owner adds the real key in a reviewed commit. Trust lives in code, never in .env.
    // The hub countersign keys above are added to this list. Partner publisher keys are never listed here.
    'trusted_keys' => [
        // Release key generated 2026-11-12 on the owner's release machine (secret in ~/.ispa-release-keys, never in git).
        '1dc45f3eec76dd72' => 'ei2TCJ+2iJ4i4MjrKkvHc/0W0UpuUHpU63zSj0hbMeA=',
    ] + $countersignKeys,

    // Key ids of the hub countersign keys: packages signed with them are partner packages on every path
    // (hub, offline upload, starter pack), whatever the hub listing says.
    'countersign_key_ids' => array_map('strval', array_keys($countersignKeys)),

    // Key ids allowed to sign widget bundles: the iSPA owner release keys only. A widget bundle carries Blade that is
    // compiled to PHP on every site, so it is verified against these ids (each must also be in trusted_keys and not
    // revoked) and never against a hub countersign key, even if one is listed here: a leaked hub secret must not be
    // able to push Blade to sites. Add a new release key id here in the same reviewed commit as its trusted_keys entry.
    'widget_bundle_key_ids' => ['1dc45f3eec76dd72'],

    // Key ids allowed to sign core packages: the iSPA release keys only, never a partner or hub countersign key. A
    // core package replaces every core file and vendor/, so `ispa upgrade` refuses any other trusted key (fails closed).
    'core_package_key_ids' => ['1dc45f3eec76dd72'],

    // Key ids that must never verify again, even if still listed in trusted_keys.
    'revoked_key_ids' => [],

    // Package limits enforced by the installer / safe extractor.
    'max_zip_bytes' => 50 * 1024 * 1024,
    'max_unpacked_bytes' => 200 * 1024 * 1024,
    'max_files' => 5000,
    'max_file_bytes' => 20 * 1024 * 1024,
    'max_ratio' => 100,

    // Publish `irreversible` in a signed release manifest as the list of migration names instead of a boolean.
    // Flip this only after every site that installs from the hub runs a build with the list reader (check the
    // build ids in the fleet platform reports): an older reader rejects a signed manifest that carries a list.
    // Off = the manifest and the hub catalog keep the boolean `irreversible`.
    'publish_irreversible_list' => (bool) env('MODULE_PACKAGES_PUBLISH_IRREVERSIBLE_LIST', false),

    // The admin shell's shared package list (base-admin src/config/shared-packages.json) the package builder checks
    // module admin imports against. Empty = the workspace checkout's base-admin, else the release layout's admin/.
    'shared_admin_packages_file' => env('MODULE_PACKAGES_SHARED_ADMIN_PACKAGES_FILE') ?: null,

    // Refuse a package build when the module has no CHANGELOG.md section `## <version>` for the version being built
    //. Off = the build only warns; the hub sheet then has no release notes for that version.
    'require_changelog' => (bool) env('MODULE_PACKAGES_REQUIRE_CHANGELOG', false),

    // Server say on package installation (spec 2026-11-22), tri-state: `false` forbids (the admin switch is locked),
    // `true` keeps the old meaning (on without the admin switch), unset (null) lets the Webmaster's "allow module
    // install" switch decide (off until switched on). Read through ModuleInstallPolicy, never directly.
    'install_enabled' => env('MODULE_INSTALL_ENABLED'),
    'queue' => env('MODULE_INSTALL_QUEUE', 'default'),
    'job_timeout' => 2700,
];
