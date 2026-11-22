<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-drawer
        :open="open"
        width="min(560px, 100vw)"
        :title="titleText"
        data-testid="store-drawer"
        @close="emit('close')"
    >
        <div v-if="loading" aria-busy="true" data-testid="store-loading">
            <a-skeleton active :paragraph="{ rows: 6 }" />
        </div>
        <div v-else-if="error" role="alert" class="space-y-2" data-testid="store-error">
            <p class="m-0 text-sm text-danger">{{ error }}</p>
            <i-button size="sm" data-testid="store-retry" @click="load">
                {{ $t('text.modules.install.retry') }}
            </i-button>
        </div>
        <div v-else-if="data" class="space-y-5">
            <header class="flex items-start gap-3">
                <store-image
                    :hash="listing?.icon_hash || null"
                    class="size-14 shrink-0 rounded-lg bg-surface-sunken"
                >
                    <i-icon icon="puzzle-outline" class="size-7 text-ink-muted" />
                </store-image>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <publisher-badge :publisher="listing?.publisher || null" />
                        <a-tag v-if="entry?.latest">{{ $t('text.modules.version') }} {{ entry.latest.version }}</a-tag>
                        <a-tag v-if="entry && entry.installed !== 'none'" color="success">
                            {{ $t('text.modules.install.catalog.installed', { version: entry.installed_version || '-' }) }}
                        </a-tag>
                    </div>
                    <p v-if="summary" class="m-0 text-sm text-ink-muted">{{ summary }}</p>
                    <p v-if="data.installs !== null && data.installs !== undefined" class="m-0 text-xs text-ink-muted" data-testid="store-installs">
                        {{ $t('text.modules.store.installs', { count: data.installs }, data.installs) }}
                    </p>
                    <p v-if="text('price_note')" class="m-0 text-xs font-medium" data-testid="store-price-note">
                        {{ text('price_note') }}
                    </p>
                </div>
            </header>

            <p v-if="!listing" class="m-0 text-sm text-ink-muted" data-testid="store-no-listing">
                {{ $t('text.modules.store.no_listing') }}
            </p>

            <section v-if="screenshots.length" class="space-y-2" data-testid="store-screenshots">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.screenshots') }}</h4>
                <div class="flex gap-3 overflow-x-auto pb-2">
                    <!-- Fixed height: a tall phone image must not stretch the row; phone shots get a narrow frame. -->
                    <figure
                        v-for="(shot, index) in screenshots"
                        :key="shot.hash"
                        :class="['m-0 shrink-0 space-y-1', shot.kind === 'phone' ? 'w-20' : 'w-64']"
                    >
                        <store-image
                            :hash="shot.hash"
                            :alt="shot.caption || $t('text.modules.store.screenshot_alt', { number: index + 1 })"
                            class="h-40 w-full rounded-lg bg-surface-sunken"
                            data-testid="store-screenshot"
                        >
                            <i-icon icon="image" class="size-6 text-ink-muted" />
                        </store-image>
                        <figcaption v-if="shot.caption" class="text-xs text-ink-muted">{{ shot.caption }}</figcaption>
                    </figure>
                </div>
            </section>

            <section v-if="paragraphs.length" class="space-y-2" data-testid="store-description">
                <p v-for="(text, index) in paragraphs" :key="index" class="m-0 whitespace-pre-line text-sm">{{ text }}</p>
            </section>

            <section v-if="features.length" class="space-y-2" data-testid="store-features">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.features') }}</h4>
                <ul class="m-0 list-disc space-y-1 pl-5 text-sm">
                    <li v-for="feature in features" :key="feature" class="break-words">{{ feature }}</li>
                </ul>
            </section>

            <section v-if="faq.length" class="space-y-2" data-testid="store-faq">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.faq') }}</h4>
                <details v-for="item in faq" :key="item.q" class="rounded-lg border border-border px-3 py-2 text-sm">
                    <summary class="cursor-pointer font-medium">{{ item.q }}</summary>
                    <p class="m-0 mt-2 whitespace-pre-line text-ink-muted">{{ item.a }}</p>
                </details>
            </section>

            <section v-if="sheet" class="space-y-2" data-testid="store-sheet">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.sheet') }}</h4>
                <dl class="m-0 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <template v-for="item in sheetRows" :key="item.key">
                        <dt class="text-ink-muted">{{ $t('text.modules.store.sheet.' + item.key) }}</dt>
                        <dd class="m-0 break-words">{{ item.value }}</dd>
                    </template>
                </dl>
            </section>

            <section class="space-y-2" data-testid="store-database">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.database') }}</h4>
                <template v-if="database.changes">
                    <a-alert type="warning" show-icon :message="$t('text.modules.install.drawer.db_warning.title')" />
                    <ul class="m-0 list-disc space-y-1 pl-5 text-xs">
                        <li v-for="fact in database.facts" :key="fact" class="break-words">{{ fact }}</li>
                    </ul>
                </template>
                <p v-else class="m-0 text-sm text-ink-muted">
                    {{ $t(sheet ? 'text.modules.store.database.none' : 'text.modules.store.database.unknown') }}
                </p>
            </section>

            <section v-if="data.release_notes?.length" class="space-y-2" data-testid="store-release-notes">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.notes') }}</h4>
                <article v-for="note in data.release_notes" :key="note.version" class="space-y-1" data-testid="store-release-note">
                    <h5 class="m-0 text-xs font-medium text-ink-muted">
                        {{ $t('text.modules.version') }} {{ note.version }}
                    </h5>
                    <pre class="m-0 whitespace-pre-wrap break-words font-sans text-sm">{{ note.notes }}</pre>
                </article>
            </section>

            <store-community v-if="entry" :entry="entry" :locale="locale" @rated="emit('rated', $event)" />

            <section v-if="hasLinks" class="space-y-1 text-sm" data-testid="store-links">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.section.links') }}</h4>
                <p v-if="listing.demo_url" class="m-0">
                    <a :href="listing.demo_url" target="_blank" rel="noopener noreferrer" data-testid="store-demo-link">
                        {{ $t('text.modules.store.link.demo') }}
                    </a>
                </p>
                <p v-if="listing.video_url" class="m-0">
                    <a :href="listing.video_url" target="_blank" rel="noopener noreferrer" data-testid="store-video-link">
                        {{ $t('text.modules.store.link.video') }}
                    </a>
                </p>
                <p v-if="listing.support_contact" class="m-0 break-words" data-testid="store-support">
                    {{ $t('text.modules.store.link.support') }}: {{ listing.support_contact }}
                </p>
            </section>
        </div>

        <template #footer>
            <div v-if="entry" class="flex flex-wrap justify-end gap-2">
                <i-button v-if="entry.installed !== 'none'" data-testid="store-report" @click="emit('support', entry)">
                    {{ $t('text.modules.support.open') }}
                </i-button>
                <i-button v-if="entry.canUpgrade" variant="primary" :disabled="installBlocked" data-testid="store-upgrade" @click="emit('upgrade', entry)">
                    {{ $t('text.modules.install.action.upgrade') }}
                </i-button>
                <i-button
                    v-else-if="entry.installed === 'none'"
                    variant="primary"
                    data-testid="store-install"
                    :disabled="!entry.canInstall || installBlocked"
                    @click="emit('install', entry)"
                >
                    {{ $t('text.modules.install.action.install') }}
                </i-button>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getModuleListing, moduleErrorCodeOf, moduleErrorKey } from '@/libs/module'
import StoreImage from './store-image.vue'
import PublisherBadge from './publisher-badge.vue'
import StoreCommunity from './store-community.vue'
import { listingText } from './store-text'

/**
 * The full store page of one module in a drawer: screenshots, description, features, FAQ, technical sheet, what it
 * does to the data, release notes above the installed version, ratings and Q&A with private feedback,
 * demo/support links and the Install or Upgrade action, plus "Báo lỗi / Cần hỗ trợ" for an installed module (, an event: the page opens the support dialog). The action is an event: the page closes this drawer and opens its own install drawer, so the preflight and
 * install flow stays in one place.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The catalog row of the module (label, latest, installed, canInstall, canUpgrade, ...). */
    entry: { type: Object, default: null },
    locale: { type: String, default: 'vi' },
    /** Installs are blocked for the whole website (the page says why): Install and Upgrade are off. */
    installBlocked: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'install', 'upgrade', 'support', 'rated'])

const { t } = useI18n()
const loading = ref(false)
const error = ref('')
const data = ref(null)
let token = 0

const listing = computed(() => data.value?.listing || null)
const text = (field) => listingText(listing.value?.content, props.locale, field)
const summary = computed(() => text('summary'))
const titleText = computed(() => text('display_name') || props.entry?.label || '')

/** Description split on blank lines; rendered as plain text, never as markup. */
const paragraphs = computed(() =>
    text('description')
        .split(/\n{2,}/)
        .map((part) => part.trim())
        .filter(Boolean),
)

/** A list field of the admin language, else the other language. */
const listOf = (field) => {
    const own = listing.value?.content?.[props.locale]?.[field]
    if (Array.isArray(own) && own.length) return own
    const other = listing.value?.content?.[props.locale === 'vi' ? 'en' : 'vi']?.[field]
    return Array.isArray(other) ? other : []
}
const features = computed(() => listOf('features'))
const faq = computed(() => listOf('faq'))

const screenshots = computed(() =>
    (listing.value?.screenshots || []).map((shot) => ({
        hash: shot.hash,
        kind: shot.kind,
        caption: (props.locale === 'vi' ? shot.caption_vi || shot.caption_en : shot.caption_en || shot.caption_vi) || '',
    })),
)

const hasLinks = computed(() => Boolean(listing.value && (listing.value.demo_url || listing.value.video_url || listing.value.support_contact)))

/** Technical sheet of the version the Install button would install (the latest). */
const sheet = computed(() => {
    const version = props.entry?.latest?.version || props.entry?.latest_version
    return (data.value?.versions || []).find((v) => v.version === version)?.sheet || null
})

const formatBytes = (bytes) => {
    if (!Number.isFinite(bytes)) return '-'
    if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB'
    return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}

/** Rows of the technical sheet, without internal paths or codes. */
const sheetRows = computed(() => {
    const s = sheet.value
    if (!s) return []
    const rows = []
    if (props.entry?.latest?.version) rows.push({ key: 'version', value: props.entry.latest.version })
    if (s.core_range) rows.push({ key: 'core_range', value: s.core_range })
    const requires = Object.keys(s.requires_modules || {})
    if (requires.length) rows.push({ key: 'requires', value: requires.join(', ') })
    if (s.php_min) rows.push({ key: 'php_min', value: s.php_min })
    rows.push({ key: 'permissions', value: String(s.admin?.permissions?.length || 0) })
    rows.push({ key: 'menus', value: String(s.admin?.menus || 0) })
    if (s.size?.zip_bytes) rows.push({ key: 'size', value: formatBytes(s.size.zip_bytes) })
    return rows
})

/** What the latest version does to the data, in the same words as the install drawer. */
const database = computed(() => {
    const db = sheet.value?.database
    if (!db || !(db.migrations > 0)) return { changes: false, facts: [] }
    const key = 'text.modules.install.drawer.db_warning.'
    const facts = [t(key + 'count', { count: db.migrations })]
    if (db.tables_altered?.length) facts.push(t(key + 'tables', { tables: db.tables_altered.join(', ') }))
    else facts.push(t(key + 'own_tables'))
    const irreversible = db.irreversible === true || (Array.isArray(db.irreversible) && db.irreversible.length > 0)
    if (irreversible) facts.push(t(key + 'irreversible'))
    return { changes: true, facts }
})

/** Load the listing of the open module; a stale answer is dropped. */
const load = async () => {
    if (!props.open || !props.entry?.name) return
    const mine = ++token
    loading.value = true
    error.value = ''
    data.value = null
    try {
        const answer = await getModuleListing(props.entry.name)
        if (mine === token) data.value = answer
    } catch (e) {
        if (mine === token) {
            const key = moduleErrorKey(moduleErrorCodeOf(e))
            error.value = t(key || 'text.modules.store.load_failed')
        }
    } finally {
        if (mine === token) loading.value = false
    }
}

watch(() => [props.open, props.entry?.name], load, { immediate: true })
</script>
