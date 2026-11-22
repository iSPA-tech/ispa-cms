<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section class="space-y-2" data-testid="third-party-confirm">
        <a-alert type="warning" show-icon :message="$t('text.modules.install.third_party.title')" :description="intro" data-testid="third-party-intro" />
        <publisher-badge v-if="showBadge && details?.publisher" :publisher="details.publisher" />
        <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.install.third_party.impact_title') }}</h4>
        <ul v-if="facts.length" class="m-0 list-disc space-y-1 pl-5 text-xs" data-testid="third-party-impact">
            <li v-for="fact in facts" :key="fact" class="break-words">{{ fact }}</li>
        </ul>
        <p v-else class="m-0 text-xs text-ink-muted" data-testid="third-party-impact-unknown">
            {{ $t('text.modules.install.third_party.impact_unknown') }}
        </p>
        <label class="flex cursor-pointer items-start gap-2">
            <input
                type="checkbox"
                class="mt-1"
                :checked="modelValue"
                data-testid="third-party-confirm-check"
                @change="emit('update:modelValue', $event.target.checked)"
            />
            <span class="text-sm font-medium">{{ $t('text.modules.install.third_party.confirm_label') }}</span>
        </label>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PublisherBadge from './publisher-badge.vue'

/**
 * Confirmation step before the first install of a module that iSPA does not publish.
 *
 * `details` is the `details` of the preflight warning MODULE_WARN_THIRD_PARTY_FIRST_INSTALL, or the `data` of the
 * MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED answer of an upload: `{ publisher: {key, type, name}|null, impact }`, where
 * `impact` (from the hub technical sheet, or null when unknown) holds permission and menu counts, route counts and the
 * database tables it creates or changes. Only counts and table names are shown, never permission codes or paths.
 */
const props = defineProps({
    details: { type: Object, default: null },
    modelValue: { type: Boolean, default: false },
    // Off where the drawer already shows the publisher badge (install preview).
    showBadge: { type: Boolean, default: true },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const key = 'text.modules.install.third_party.'

const intro = computed(() =>
    props.details?.publisher?.name ? t(key + 'intro', { name: props.details.publisher.name }) : t(key + 'intro_unknown'),
)

/** What the module adds to the site, in plain words; empty when the hub has no technical sheet for it. */
const facts = computed(() => {
    const impact = props.details?.impact
    if (!impact) return []
    const list = []
    const permissions = Array.isArray(impact.permissions) ? impact.permissions.length : 0
    if (permissions > 0) list.push(t(key + 'impact.permissions', { count: permissions }, permissions))
    if (impact.menus > 0) list.push(t(key + 'impact.menus', { count: impact.menus }, impact.menus))
    const routes = impact.routes || {}
    const pages = (routes.public || 0) + (routes.admin || 0) + (routes.api || 0)
    if (pages > 0) list.push(t(key + 'impact.routes', { public: routes.public || 0, admin: routes.admin || 0, api: routes.api || 0 }))
    if (impact.tables_created?.length) list.push(t(key + 'impact.tables_created', { tables: impact.tables_created.join(', ') }))
    if (impact.tables_altered?.length) list.push(t(key + 'impact.tables_altered', { tables: impact.tables_altered.join(', ') }))
    if (impact.irreversible) list.push(t(key + 'impact.irreversible'))
    if (!(impact.migrations > 0)) list.push(t(key + 'impact.no_database'))
    return list
})
</script>
