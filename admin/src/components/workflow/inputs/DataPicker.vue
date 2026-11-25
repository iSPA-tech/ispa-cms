<template>
    <div
        role="dialog"
        :aria-label="t('text.workflow.data.insert')"
        class="flex flex-col gap-1 rounded-lg border border-solid border-gray-300 bg-white p-2 shadow-lg"
        data-testid="data-picker"
        @keydown.esc.stop.prevent="emit('close')"
    >
        <div class="flex items-center gap-1">
            <a-input
                ref="searchRef"
                v-model:value="query"
                size="small"
                allow-clear
                class="min-w-0 flex-1"
                :placeholder="t('text.workflow.data.search')"
                :aria-label="t('text.workflow.data.search')"
                data-testid="data-picker-search"
            >
                <template #prefix>
                    <i-icon icon="magnify" class="h-4 w-4 text-gray-400" />
                </template>
            </a-input>
            <a-button size="small" type="text" :aria-label="t('text.workflow.data.close')" :title="t('text.workflow.data.close')" data-testid="data-picker-close" @click="emit('close')">
                <i-icon icon="close" class="h-4 w-4" />
            </a-button>
        </div>

        <div v-if="loading" class="flex items-center gap-2 px-1 py-3 text-xs text-gray-600" data-testid="data-picker-loading">
            <a-spin size="small" />
            <span>{{ t('text.workflow.data.loading') }}</span>
        </div>

        <div class="max-h-72 overflow-y-auto">
            <section v-for="group in available" :key="group.key" :data-group="group.key">
                <p class="mb-0 break-words px-1 pb-0.5 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                    {{ group.title }}
                </p>
                <button
                    v-for="item in group.items"
                    :key="item.path"
                    type="button"
                    class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-md border-0 bg-transparent px-2 py-2 text-left hover:bg-cyan-50 focus-visible:bg-cyan-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-600"
                    :data-path="item.path"
                    data-testid="data-picker-item"
                    @click="emit('pick', item.path)"
                >
                    <span class="min-w-0 break-words text-sm text-gray-900">{{ item.label }}</span>
                    <span class="max-w-[55%] shrink-0 truncate text-xs text-gray-600" :title="item.sample || item.typeLabel">
                        {{ item.sample || item.typeLabel }}
                    </span>
                </button>
            </section>

            <section v-if="later.length" data-group="later" data-testid="data-picker-later">
                <p class="mb-0 break-words px-1 pb-0.5 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                    {{ t('text.workflow.data.later_title') }}
                </p>
                <div
                    v-for="item in later"
                    :key="item.path"
                    class="mb-0.5 flex items-center justify-between gap-3 rounded-md bg-gray-50 px-2 py-2"
                    aria-disabled="true"
                    :data-path="item.path"
                    data-testid="data-picker-later-item"
                >
                    <span class="min-w-0 break-words text-sm text-gray-500">{{ item.label }}</span>
                    <span class="shrink-0 text-xs text-gray-500">{{ t('text.workflow.data.later_note') }}</span>
                </div>
            </section>

            <p v-if="!loading && !available.length && !later.length" class="mb-0 px-1 py-3 text-xs text-gray-600" data-testid="data-picker-empty">
                {{ query.trim() ? t('text.workflow.data.none_found') : t('text.workflow.data.none') }}
            </p>
        </div>

        <p v-if="unplaced" class="mb-0 px-1 pt-1 text-xs text-amber-700" data-testid="data-picker-unplaced">
            {{ t('text.workflow.data.unplaced_note') }}
        </p>
        <p class="mb-0 px-1 pt-1 text-xs text-gray-600" data-testid="data-picker-note">
            {{ hasSample ? t('text.workflow.data.sample_note') : t('text.workflow.data.no_run_note') }}
        </p>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { filterGroups } from './dataTokens.js'

/**
 * "Insert data" panel of a step setting: the data earlier steps produced, grouped by the step it comes from, with a
 * sample value from the last run (or the kind of data when the workflow never ran). Data a later step produces is
 * listed greyed and cannot be picked. Shown in the form itself, under its field: a popover would trap the focus.
 */
defineOptions({ name: 'DataPicker' })

const props = defineProps({
    groups: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    hasSample: { type: Boolean, default: false },
    /** The step is not linked into the diagram yet, so which steps run before it is unknown. */
    unplaced: { type: Boolean, default: false },
})
const emit = defineEmits(['pick', 'close'])

const { t } = useI18n()
const query = ref('')
const searchRef = ref(null)

const shown = computed(() => filterGroups(props.groups, query.value))
const available = computed(() => shown.value.filter((group) => !group.later))
/** One flat list: the step is named on each row, since these rows are only there to explain what is missing. */
const later = computed(() =>
    shown.value
        .filter((group) => group.later)
        .flatMap((group) =>
            group.items.map((item) => ({ path: item.path, label: item.role === 'part' ? `${group.title} › ${item.label}` : group.title }))
        )
        .filter((item, index, list) => list.findIndex((other) => other.label === item.label) === index)
)

onMounted(() => nextTick(() => searchRef.value?.focus?.()))
</script>
