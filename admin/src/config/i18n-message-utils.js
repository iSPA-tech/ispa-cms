/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Bump on any change to the served admin locale pack that clients must re-pull
// (loadLocaleMessages only refetches when the cached __schema differs). v6 adds
// the Widget Site Layout keys (nav.a.site-layout-*, text.widget.*, and the
// widget_site_layout.* slot/context label group below).
// v9: invalidates clients that cached shards under the old 7-day
// Cache-Control (before the ETag revalidation fix) plus every stale
// localStorage tree missing the widget_site_layout editor/region keys.
// v22 invalidates cached locale trees so Contact mailbox spam controls load
// their newly extracted admin translations instead of missing-key fallbacks.
// v27: pull Education admissions and authorization resource labels.
// v31: refresh the shared form editor's labels, field kinds and preview controls.
// v32: refresh canonical menu editor copy and category expansion labels.
// v40: refresh responsive layout and additional-setting groups.
// v53: pull the shared editor's math-formula labels (text.editor.math.*, text.form.math-block-select).
// v54: pull the AI Runtime settings page labels (text.ai_runtime.*).
// v56: pull the ClassOnline menu keys (nav.a.class-online*) and page labels (text.class-online.*).
// v57: pull a module's menu keys and page labels.
// v58: pull the core admin screen labels that replaced raw text (components, core pages, Education,
// site layout; sidecars translate-admin-ui-literals.php).
// v81: pull the Webmail alias manager and receive/send switch labels (text.webmail.mbx.*).
// v98: pull the Widget site-layout editor labels renamed under widget_site_layout.editor.*.
// v100: pull the UX leftovers (translated root menus, Showroom moderation / Automation runs / pipeline term
// titles, Education and student labels, data-scope hidden-added count).
// v110: pull the drawer child panel labels of a module.
// v115: pull the reward summary and story cost labels of a module.
// v114: pull the sub-menu and schedule conflict resolver labels of a module.
// v116: pull the wording leftovers (text.real_estate.project.*, text.pipeline.data_chart.label, text.order.config.empty, text.showroom-moderation.detail.{not_found,load_failed,back_to_list}).
// v117: pull the wording third pass (text.slug.*, text.inventory.order_config.page_title, text.real_estate.project.{manage_title,name_column}, text.pipeline.template*, reworded text.geoplaces.runs.settings_* and key_source.*).
// v134: pull the uninstall refusal key text.modules.error.table_not_owned (names the tables).
// v129: pull the configuration package apply step, history tab and validation messages (text.config_package.apply_*, history_*, kind_*, validation.*).
// v136: pull the profile tab keys (text.profile.tab_load_failed, text.profile.retry).
// v142: pull the Helpdesk profile keys (text.helpdesk.profile.open_account, requested_tickets_tab).
// v143: pull the record history keys (text.history.*).
// v144: pull the notes and timeline keys (text.notes.*, text.timeline.*_tab).
// v145: pull the Helpdesk mention notification keys (text.helpdesk.notifications.*_mentioned).
// v146: pull the saved views bar keys (text.saved_views.*).
// v153: pull the starter packs page keys (nav.a.starter_packs, text.starter_pack.*).
// v154: pull the starter pack apply keys (text.starter_pack.apply, confirm_*, started*, result_*, error_*, step_*, count_*, task).
// v158: pull the My tasks redesign keys (text.my_tasks.intro, more, tone_*, hint.*).
// v159: pull the My tasks time labels (text.my_tasks.at.*) and agent proposal type names (text.agentic.artifact.*).
// v160: pull the module install codes (text.modules.error.admin_package_missing, theme_coupled, install_command_failed).
// v161: pull the PlatformHub module access and fleet entitlement labels (text.platform.modules.*, text.platform.fleet.*, nav.a.platform-modules).
// v164: pull the uninstall refusal key text.modules.error.has_data (orders or payments remain).
// v171: pull the reinstall keys (backup_reason.earlier_data, db_warning.title_earlier, db_warning.earlier_tables, text.modules.error.table_exists).
// v177: pull the grant plan and transfer keys (text.platform.modules.plan_*, transfer*, updates_*, error.TRIAL_*, error.TRANSFER_*).
// v194: pull the message and name rows that had no text (mgs.module_*, mgs.auth.*, mgs.ok.mfa-*, mgs.failed.*, mgs.error.*, nav.a.* names of the admin screens, text.form_builder.*, text.passkey.*, text.modules.error.assets_publish_failed).
// v195: pull the QuestionBank page labels (text.question-bank.courses.*, bank.*, categories.*, nav.a.question-courses).
// v196: extend-update-window wording on the module access page (text.platform.modules.extend_updates*).
// v197: pull the Game admin rows (text.game.drop_rates.*, text.game.marketplace.*, text.game.common.health.*, empty_table, nav.a.game-operations/content/economy).
// v198: pull the assign-customer wording of the CmsManagement domain and customer pages (text.cmsm.domains.assign_*, taken_*, customers.assign_domain*, msg.customer_assigned, msg.domain_assigned).
export const I18N_MESSAGES_SCHEMA_VERSION = 'canonical-group-v198'

/**
 * Human-readable fallback for a missing translation key.
 * Takes the last dot-segment and turns separators / camelCase into
 * Title Case words: 'text.rp.ctype-vehiclemodel' -> 'Ctype Vehiclemodel',
 * 'VehicleModel' -> 'Vehicle Model', 'manual_adjust' -> 'Manual Adjust'.
 * Dynamic-key call sites should pass the raw (original-case) value for the
 * best result; the global i18n missing handler uses this for everything else.
 */
export const humanizeI18nKey = (key) => {
    const tail = String(key || '').split('.').pop() || ''
    const words = tail
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .split(/[-_\s]+/)
        .filter(Boolean)
    if (!words.length) {
        return String(key || '')
    }

    return words
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ')
}

/**
 * Canonical admin locale groups that the admin dashboard actually uses.
 * Only these files are loaded from the manifest — extra groups
 * (legacy single-letter shorthands, module-specific files) are ignored.
 */
export const ADMIN_LOCALE_GROUPS = [
    'action',
    'alert',
    'assessment',
    'cart',
    'education',
    'enum',
    'mgs',
    'nav',
    'pagination',
    'setting',
    'status',
    'text',
    'validation',
    'web',
    // Widget Site Layout domain labels (slot + template-context label_key
    // values returned by GET /slots and /target-contexts resolve here).
    'widget_site_layout',
]


const CANONICAL_GROUPS_KEY = '__groups'

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)

const removeCanonicalGroupBucket = (value) => {
    if (!isPlainObject(value)) {
        return value
    }

    const { [CANONICAL_GROUPS_KEY]: _ignored, ...rest } = value
    return rest
}

export const mergeLocaleMessageTree = (target, source) => {
    Object.entries(source || {}).forEach(([key, value]) => {
        if (isPlainObject(value) && isPlainObject(target[key])) {
            mergeLocaleMessageTree(target[key], value)
            return
        }

        target[key] = value
    })

    return target
}

/**
 * Put the loaded pack on top of the bundled fallback messages: the pack wins for every key it has, the fallback only
 * fills the keys it lacks (for example when the pack request failed). Neither argument is mutated.
 *
 * @param {object|undefined} fallbackTree Bundled messages of one locale (virtual:admin-i18n-fallback).
 * @param {object} packTree Messages loaded from the admin pack (possibly empty).
 * @returns {object} A new merged tree.
 */
export const withFallbackMessages = (fallbackTree, packTree) =>
    mergeLocaleMessageTree(JSON.parse(JSON.stringify(fallbackTree || {})), packTree)

export const normalizeLocaleManifest = (
    manifestPayload,
    fallback = ADMIN_LOCALE_GROUPS
) => {
    const candidateList = Array.isArray(manifestPayload)
        ? manifestPayload
        : manifestPayload?.files || manifestPayload?.groups || []

    const normalized = candidateList.filter(
        (value, index) =>
            typeof value === 'string' &&
            value.trim() !== '' &&
            candidateList.indexOf(value) === index
    )

    if (normalized.length >= 1) {
        return normalized
    }

    return [...fallback]
}

/**
 * Content stamp of an admin pack manifest: current servers send `{ files, stamp }`, older ones a plain shard list
 * (no stamp, so the cached pack is kept as before).
 * @returns {string}
 */
export const localeManifestStamp = (manifestPayload) =>
    manifestPayload && !Array.isArray(manifestPayload) && typeof manifestPayload.stamp === 'string'
        ? manifestPayload.stamp
        : ''

export const normalizeLocaleShardPayload = (shardKey, shardPayload) => {
    if (!isPlainObject(shardPayload)) {
        return {}
    }

    if (Object.prototype.hasOwnProperty.call(shardPayload, shardKey)) {
        return removeCanonicalGroupBucket(shardPayload)
    }

    const normalized = {
        [shardKey]: removeCanonicalGroupBucket(shardPayload),
    }

    if (!isPlainObject(shardPayload) || !isPlainObject(shardPayload[CANONICAL_GROUPS_KEY])) {
        return normalized
    }

    Object.entries(shardPayload[CANONICAL_GROUPS_KEY]).forEach(([groupName, groupValue]) => {
        normalized[groupName] = groupValue
    })

    return normalized
}
