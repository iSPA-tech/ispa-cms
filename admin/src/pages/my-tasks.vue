<template>
    <breadcrumbs :items="[{ icon: 'clipboard-list-outline', title: $t('text.my_tasks.title'), text: $t('text.my_tasks.title') }]">
        <i-button variant="default" size="sm" data-testid="my-tasks-refresh" :loading="loading" @click="load">
            <template #icon>
                <i-icon icon="reload" class="size-3.5" />
            </template>
            <span>{{ $t('nav.b.reload') }}</span>
        </i-button>
    </breadcrumbs>

    <div class="w-full pb-6">
        <div class="space-y-4">
            <p class="m-0 max-w-3xl text-sm leading-6 text-ink-muted" data-testid="my-tasks-intro">
                {{ $t('text.my_tasks.intro') }}
            </p>

            <a-alert v-if="error" type="error" show-icon :message="$t('text.my_tasks.load_failed')">
                <template #action>
                    <i-button variant="default" size="sm" @click="load">
                        {{ $t('nav.b.reload') }}
                    </i-button>
                </template>
            </a-alert>

            <a-skeleton v-if="loading && !groups.length" active :paragraph="{ rows: 4 }" />

            <p
                v-else-if="!error && !groups.length"
                class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted"
                data-testid="my-tasks-empty"
            >
                {{ $t('text.my_tasks.empty') }}
            </p>

            <section
                v-for="group in sortedGroups"
                :key="group.key"
                class="overflow-hidden rounded-xl border border-border bg-surface"
                data-testid="my-tasks-group"
                :data-key="group.key"
                :data-tone="toneOf(group)"
            >
                <header class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                    <span
                        class="grid min-h-8 min-w-8 place-items-center rounded-lg px-2 text-sm font-semibold tabular-nums"
                        :class="TONE_CLASS[toneOf(group)]"
                        data-testid="my-tasks-count"
                    >
                        {{ group.count }}
                        <span class="sr-only">{{ $t('text.my_tasks.tone_' + toneOf(group)) }}</span>
                    </span>
                    <div class="min-w-0 flex-1 basis-48">
                        <h2 class="m-0 text-sm font-semibold text-ink">{{ $t('text.dashboard.attention.' + group.key) }}</h2>
                        <p v-if="hintOf(group)" class="m-0 mt-0.5 text-xs leading-5 text-ink-muted" data-testid="my-tasks-hint">
                            {{ hintOf(group) }}
                        </p>
                    </div>
                    <router-link
                        v-if="group.route"
                        :to="{ name: group.route, query: group.query }"
                        class="inline-flex min-h-8 items-center whitespace-nowrap rounded-md bg-primary px-3 py-1 text-xs font-semibold text-on-primary no-underline hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2"
                        data-testid="my-tasks-action"
                    >
                        {{ $t('text.dashboard.attention.open') }}
                    </router-link>
                </header>
                <ul v-if="group.items?.length" class="m-0 list-none divide-y divide-border border-t border-border p-0">
                    <li v-for="(item, index) in group.items" :key="index">
                        <router-link
                            v-if="item.route"
                            :to="{ name: item.route, params: item.params, query: item.query }"
                            :class="ROW_CLASS + ' hover:bg-surface-hover focus-visible:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2'"
                            data-testid="my-tasks-item"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block break-words text-sm font-medium text-ink">{{ titleOf(item) }}</span>
                                <span v-if="item.subtitle" class="block break-words text-xs text-ink-muted">{{ item.subtitle }}</span>
                            </span>
                            <time v-if="item.at" :datetime="item.at" class="max-w-[45%] text-right text-xs tabular-nums text-ink-muted">
                                {{ atText(item) }}
                            </time>
                            <i-icon icon="chevron-right" class="size-4 shrink-0 text-ink-muted" />
                        </router-link>
                        <div v-else :class="ROW_CLASS" data-testid="my-tasks-item">
                            <span class="min-w-0 flex-1">
                                <span class="block break-words text-sm text-ink">{{ titleOf(item) }}</span>
                                <span v-if="item.subtitle" class="block break-words text-xs text-ink-muted">{{ item.subtitle }}</span>
                            </span>
                            <time v-if="item.at" :datetime="item.at" class="max-w-[45%] text-right text-xs tabular-nums text-ink-muted">
                                {{ atText(item) }}
                            </time>
                        </div>
                    </li>
                </ul>
                <component
                    :is="group.route ? 'router-link' : 'p'"
                    v-if="group.items?.length && group.count > group.items.length"
                    :to="group.route ? { name: group.route, query: group.query } : undefined"
                    class="m-0 block border-t border-border px-4 py-2.5 text-xs font-medium text-ink-muted"
                    :class="group.route ? 'no-underline hover:bg-surface-hover hover:text-ink' : ''"
                    data-testid="my-tasks-more"
                >
                    {{ $t('text.my_tasks.more', { n: group.count - group.items.length }) }}
                </component>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, getCurrentInstance, onMounted, ref } from 'vue'
import { getMyTasks } from '@/libs/my-tasks'

// The inbox behind the header badge: every group of work waiting for the signed-in person, up to 10 rows each.
const groups = ref([])
const loading = ref(false)
const error = ref(false)

const instance = getCurrentInstance()

/** Count badge colours per tone (tokens only). */
const TONE_CLASS = {
    danger: 'bg-danger-soft text-danger-ink',
    warning: 'bg-warning-soft text-warning-ink',
    info: 'bg-info-soft text-info-ink',
}

/** Shared by linked and plain item rows. */
const ROW_CLASS = 'flex min-h-11 items-center gap-3 px-4 py-2.5 text-ink no-underline'

const TONE_RANK = { danger: 0, warning: 1, info: 2 }

/** Group tone; a missing or unknown tone counts as warning. */
const toneOf = (group) => (group.tone in TONE_RANK ? group.tone : 'warning')

/** Most urgent first; the order the API gave is kept inside a tone. */
const sortedGroups = computed(() =>
    groups.value
        .map((group, index) => ({ group, index }))
        .sort((a, b) => TONE_RANK[toneOf(a.group)] - TONE_RANK[toneOf(b.group)] || a.index - b.index)
        .map(({ group }) => group)
)

/** One-line explanation of what to do, or nothing when the key has no translation. */
const hintOf = (group) => {
    const key = 'text.my_tasks.hint.' + group.key
    const proxy = instance?.proxy
    return proxy?.$te?.(key) ? proxy.$t(key) : ''
}

/** Translation key lookup that never shows a raw key: the translated text or ''. */
const translated = (key) => {
    const proxy = instance?.proxy
    return key && proxy?.$te?.(key) ? proxy.$t(key) : ''
}

/** Item title: the provider's own text, else the translated type, else the generic fallback; never a raw code. */
const titleOf = (item) => item.title || translated(item.title_key) || translated(item.title_fallback_key) || ''

/** The time with what it means ("Created at ...") when the provider names it. */
const atText = (item) => {
    const label = translated(item.at_label)
    const when = formatAt(item.at)
    return label && when ? `${label}: ${when}` : when
}

/** Short local date and time in the admin language. */
const formatAt = (value) => {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    return date.toLocaleString(instance?.proxy?.$i18n?.locale, { dateStyle: 'short', timeStyle: 'short' })
}

async function load() {
    if (loading.value) return
    loading.value = true
    error.value = false
    try {
        const result = await getMyTasks()
        groups.value = result.items
    } catch (e) {
        error.value = true
    } finally {
        loading.value = false
    }
}

onMounted(load)
defineExpose({ load })
</script>
