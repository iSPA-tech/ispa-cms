<template>
    <!-- "Cần xử lý": the first thing on a dashboard. Hidden when nothing needs the operator. -->
    <section
        v-if="visibleItems.length"
        class="overflow-hidden rounded-xl border border-border bg-surface"
        data-testid="attention-panel"
    >
        <header class="flex items-center gap-2 border-b border-border px-4 py-2.5 text-sm font-semibold text-ink">
            <span>{{ $t('text.dashboard.attention.title') }}</span>
            <span class="rounded-full bg-warning-soft px-2 text-xs font-medium tabular-nums text-warning-ink">
                {{ visibleItems.length }}
            </span>
        </header>
        <div role="list" class="divide-y divide-border">
            <div
                v-for="item in visibleItems"
                :key="item.key"
                role="listitem"
                class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3"
                :data-tone="toneOf(item)"
            >
                <span
                    class="grid h-7 min-w-7 place-items-center rounded-lg px-1.5 text-sm font-semibold tabular-nums"
                    :class="toneOf(item) === 'danger' ? 'bg-danger-soft text-danger-ink' : 'bg-warning-soft text-warning-ink'"
                    data-testid="attention-count"
                >
                    {{ isFlag(item) ? '!' : item.count }}
                </span>
                <div class="min-w-0">
                    <div class="text-sm font-medium text-ink">
                        {{ item.label || $t('text.dashboard.attention.' + item.key) }}
                    </div>
                    <div v-if="item.description" class="mt-0.5 text-xs leading-5 text-ink-muted">{{ item.description }}</div>
                </div>
                <router-link
                    :to="linkFor(item)"
                    class="inline-flex h-7 items-center whitespace-nowrap rounded-md px-2.5 text-xs font-semibold no-underline"
                    :class="item.primary ? 'bg-primary text-on-primary hover:bg-primary-hover' : 'border border-border-strong bg-surface text-ink hover:bg-surface-hover'"
                    data-testid="attention-action"
                >
                    {{ item.action || $t('text.dashboard.attention.open') }}
                </router-link>
            </div>
        </div>
    </section>
</template>

<script setup>
/**
 * IAttentionStrip - the "Cần xử lý" panel: one row per thing that needs the operator, with what it is, an optional
 * one-line consequence, and a link to where it is handled. Rows with a count of zero are not shown; the panel hides
 * when nothing is left. Danger rows come first, then by count (stable), capped at six.
 *
 * Item: `{ key, route, query?, count, label?, description?, action?, tone?: 'warning'|'danger', primary?: boolean }`.
 * `count: true` marks a yes/no condition (shown as "!").
 */
import { computed, defineComponent } from 'vue'

defineComponent({ name: 'IAttentionStrip' })

const props = defineProps({
    /** Rows that may need attention. */
    items: { type: Array, default: () => [] },
})

/**
 * Router target for a row: `route` is a route name; a route object is also accepted (its query merges with `query`).
 * @param {{ route: string|object, query?: object }} item
 */
const linkFor = (item) =>
    typeof item.route === 'object' && item.route !== null
        ? { ...item.route, query: { ...(item.route.query || {}), ...(item.query || {}) } }
        : { name: item.route, query: item.query || {} }

/** True for a yes/no condition rather than a count. */
const isFlag = (item) => item.count === true

/** Numeric weight of a row (flags count as one). */
const weight = (item) => (isFlag(item) ? 1 : Number(item.count) || 0)

/** Row tone: explicit `tone`, otherwise warning. */
const toneOf = (item) => (item.tone === 'danger' ? 'danger' : 'warning')

/** Maximum number of rows shown. */
const MAX_ROWS = 6

/** Rows that need an operator: danger first, then by count (desc, stable), capped; zero counts are not shown. */
const visibleItems = computed(() =>
    (props.items || [])
        .filter((item) => weight(item) > 0)
        .map((item, index) => ({ item, index }))
        .sort(
            (a, b) =>
                (toneOf(b.item) === 'danger') - (toneOf(a.item) === 'danger') ||
                weight(b.item) - weight(a.item) ||
                a.index - b.index
        )
        .slice(0, MAX_ROWS)
        .map(({ item }) => item)
)
</script>
