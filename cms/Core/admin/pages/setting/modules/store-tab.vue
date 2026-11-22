<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-3" data-testid="store-cards">
        <article
            v-for="entry in rows"
            :key="entry.name"
            class="i-box space-y-2"
            data-testid="catalog-row"
        >
            <div class="flex flex-col gap-3 md:flex-row md:items-start">
                <store-image
                    :hash="entry.listing?.icon_hash || null"
                    class="size-12 shrink-0 rounded-lg bg-surface-sunken"
                    data-testid="store-card-icon"
                >
                    <i-icon icon="puzzle-outline" class="size-6 text-ink-muted" />
                </store-image>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="m-0 text-lg font-semibold">{{ titleOf(entry) }}</h3>
                        <publisher-badge :publisher="entry.listing?.publisher || entry.publisher || null" />
                        <a-tag v-if="entry.latest">
                            {{ $t('text.modules.version') }} {{ entry.latest.version }}
                        </a-tag>
                        <a-tag v-if="entry.installed !== 'none'" color="success" data-testid="catalog-installed">
                            {{ $t('text.modules.install.catalog.installed', { version: entry.installed_version || '-' }) }}
                        </a-tag>
                        <a-tag v-if="entry.canUpgrade" color="processing" data-testid="catalog-upgrade-available">
                            {{ $t('text.modules.install.upgrade.available', { version: entry.upgrade_version }) }}
                        </a-tag>
                        <a-tag v-if="entry.irreversible" color="warning" data-testid="catalog-irreversible">
                            {{ $t('text.modules.install.catalog.irreversible') }}
                        </a-tag>
                        <a-tag v-if="entry.updates_unavailable && entry.installed !== 'none' && !entry.renewal" data-testid="catalog-updates-unavailable">
                            {{ $t('text.modules.install.updates_unavailable') }}
                        </a-tag>
                        <a-tag v-if="entry.revoked" color="error" data-testid="catalog-revoked">
                            {{ $t('text.modules.install.catalog.revoked') }}
                        </a-tag>
                        <a-tag v-if="entry.renewal" color="warning" data-testid="catalog-renew">
                            {{ $t('text.modules.store.renew.tag') }}
                        </a-tag>
                    </div>
                    <p v-if="entry.renewal" class="m-0 text-sm" data-testid="catalog-renew-note">
                        {{ renewalNote(entry) }}
                    </p>
                    <p v-if="summaryOf(entry)" class="m-0 text-sm text-ink-muted" data-testid="store-card-summary">
                        {{ summaryOf(entry) }}
                    </p>
                    <div
                        v-if="hasRating(entry) || hasInstalls(entry) || priceNoteOf(entry)"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                    >
                        <span v-if="hasRating(entry)" class="inline-flex items-center gap-1 text-ink-muted" data-testid="store-card-rating">
                            <i-icon icon="star" class="size-3.5 text-warning" aria-hidden="true" />{{ $t('text.modules.store.community.summary', { average: formatAverage(entry.listing.rating.average), count: entry.listing.rating.count }, entry.listing.rating.count) }}
                        </span>
                        <span v-if="hasInstalls(entry)" class="text-ink-muted" data-testid="store-card-installs">
                            {{ $t('text.modules.store.installs', { count: entry.listing.installs }, entry.listing.installs) }}
                        </span>
                        <span v-if="priceNoteOf(entry)" class="font-medium" data-testid="store-card-price-note">
                            {{ priceNoteOf(entry) }}
                        </span>
                    </div>
                    <p
                        v-if="entry.latest && entry.latest.custom_code"
                        class="m-0 text-xs text-ink-muted"
                        data-testid="catalog-custom-code"
                    >
                        {{ $t('text.modules.install.catalog.custom_code') }}
                    </p>
                    <div v-if="entry.requirements.length" class="flex flex-wrap items-center gap-1 text-xs">
                        <span class="text-ink-muted">{{ $t('text.modules.install.catalog.requires') }}:</span>
                        <template v-for="req in entry.requirements" :key="`${entry.name}-${req.name}`">
                            <span
                                v-if="req.met"
                                class="inline-flex items-center gap-1 mr-2 text-ink-muted"
                                :title="$t('text.modules.install.catalog.requires_met')"
                                data-testid="catalog-requirement"
                                data-met="true"
                            >
                                <i-icon icon="check-circle" class="size-3 text-success" />
                                {{ labelFor(req.name) }}
                            </span>
                            <a-tag v-else color="error" data-testid="catalog-requirement" data-met="false">
                                {{ labelFor(req.name) }} ({{ $t('text.modules.install.catalog.requires_unmet') }})
                            </a-tag>
                        </template>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 md:flex-col md:items-stretch">
                    <i-button
                        v-if="entry.canUpgrade"
                        variant="primary"
                        size="sm"
                        data-testid="catalog-upgrade"
                        :disabled="installBlocked"
                        @click="emit('upgrade', entry)"
                    >
                        {{ $t('text.modules.install.action.upgrade') }}
                    </i-button>
                    <i-button
                        v-else-if="entry.installed === 'none' && entry.renewal?.reason !== 'expired'"
                        variant="primary"
                        size="sm"
                        data-testid="catalog-install"
                        :disabled="!entry.canInstall || installBlocked"
                        @click="emit('install', entry)"
                    >
                        {{ $t('text.modules.install.action.install') }}
                    </i-button>
                    <i-button size="sm" data-testid="catalog-details" @click="emit('details', entry)">
                        {{ $t('text.modules.store.details') }}
                    </i-button>
                </div>
            </div>
        </article>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import StoreImage from './store-image.vue'
import PublisherBadge from './publisher-badge.vue'
import { listingText } from './store-text'

/**
 * The "Module store" cards: icon, name, publisher, summary, average stars, install count and price note
 * (, from the catalog summary) and the existing status tags. A module without a store
 * listing shows its name and the module's own description. Install, Upgrade and Details are events; the page owns
 * the preflight and install flow.
 *
 * when the hub sends a renewal note (`entry.renewal`: the grant ended, the update window of a purchase
 * ended, or the grant is in grace) the card shows a "Gia hạn" tag (in place of "no updates") and one plain sentence
 * with the date. An ended grant has nothing to install, so its card offers only Details; an installed module keeps
 * running either way.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    locale: { type: String, default: 'vi' },
    /** Module name -> its own description (used when there is no listing). */
    describe: { type: Function, default: () => '' },
    /** Module name -> readable label (used for the required-module rows). */
    labelFor: { type: Function, default: (name) => name },
    /** Installs are blocked for the whole website (the page shows why above the cards): Install and Upgrade are off. */
    installBlocked: { type: Boolean, default: false },
})
const emit = defineEmits(['details', 'install', 'upgrade'])
const { t } = useI18n()

/** Listing name in the admin language, else the other language, else the module label. */
const titleOf = (entry) => listingText(entry.listing?.content, props.locale, 'display_name') || entry.label

/** Listing summary in the admin language, else the other language, else the module's own description. */
const summaryOf = (entry) => listingText(entry.listing?.content, props.locale, 'summary') || props.describe(entry.name) || ''

/**
 * The renewal sentence for the note's reason, with its date in the admin language and the version it concerns. A
 * module that is not installed gets the `_new` wording (nothing on the site to keep running).
 */
const renewalNote = (entry) => {
    const renewal = entry.renewal
    const date = new Date(renewal.date)
    const day = Number.isNaN(date.getTime())
        ? '-'
        : date.toLocaleDateString(props.locale === 'vi' ? 'vi-VN' : 'en-GB', { dateStyle: 'short' })
    const fresh = entry.installed === 'none' && renewal.reason !== 'grace' ? '_new' : ''
    return t('text.modules.store.renew.' + renewal.reason + fresh, { date: day, version: renewal.version || '' })
}

/** Whether the hub sent an install count (public modules only; null for private and paid ones and from older hubs). */
/** The price note in the admin language (falls back to the other language when empty). */
const priceNoteOf = (entry) => listingText(entry.listing?.content, props.locale, 'price_note')

const hasInstalls = (entry) => Number.isInteger(entry.listing?.installs)

/** Whether the hub sent an average from at least one visible rating (; absent from older hubs). */
const hasRating = (entry) => Number.isFinite(entry.listing?.rating?.average) && entry.listing.rating.count > 0

/** Average stars with one decimal in the admin language ("4,5" in Vietnamese). */
const formatAverage = (value) =>
    new Intl.NumberFormat(props.locale === 'vi' ? 'vi-VN' : 'en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)
</script>
