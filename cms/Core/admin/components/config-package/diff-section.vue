<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section class="i-box space-y-3" :data-testid="`diff-section-${section}`">
        <h4 class="m-0 break-words text-sm font-semibold">{{ sectionLabel }}</h4>

        <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-testid="diff-counts">
            <li v-for="action in ACTIONS" :key="action">
                <a-tag class="m-0" :data-testid="`diff-count-${action}`">
                    {{ t(`text.config_package.action_${action}`) }}: {{ counts[action] }}
                </a-tag>
            </li>
        </ul>

        <ul v-if="warnings.length" class="m-0 space-y-1 p-0 text-sm text-ink-muted" data-testid="diff-warnings">
            <li v-for="(warning, index) in warnings" :key="index" class="list-none break-words">{{ warning }}</li>
        </ul>

        <a-empty v-if="!rows.length" :description="t('text.config_package.import_no_items')" data-testid="diff-empty" />
        <div v-else class="max-w-full overflow-x-auto" data-testid="diff-table-wrap">
            <table class="w-full border-collapse text-left text-sm" data-testid="diff-table">
                <thead>
                    <tr>
                        <th class="p-2 font-semibold">{{ t('text.config_package.import_col_item') }}</th>
                        <th class="p-2 font-semibold">{{ t('text.config_package.import_col_action') }}</th>
                        <th class="p-2 font-semibold">{{ t('text.config_package.import_col_before') }}</th>
                        <th class="p-2 font-semibold">{{ t('text.config_package.import_col_after') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" :data-testid="`diff-row-${row.action}`" class="align-top">
                        <td class="max-w-[16rem] break-words p-2">{{ row.label || row.key }}</td>
                        <td class="p-2">
                            <a-tag class="m-0">{{ t(`text.config_package.action_${row.action}`) }}</a-tag>
                            <p v-if="row.reason" class="m-0 mt-1 break-words text-ink-muted" data-testid="diff-reason">{{ row.reason }}</p>
                            <p v-for="(warning, index) in row.warnings" :key="index" class="m-0 mt-1 break-words text-ink-muted" data-testid="diff-item-warning">{{ warning }}</p>
                            <a-checkbox
                                v-if="row.needsChoice"
                                class="mt-1"
                                :checked="row.choice"
                                data-testid="diff-choice"
                                @update:checked="(on) => setChoice(row.key, on)"
                            >
                                {{ t('text.config_package.import_choice_label') }}
                            </a-checkbox>
                        </td>
                        <td v-if="row.action === 'unchanged'" colspan="2" class="p-2 text-ink-muted" data-testid="diff-same">
                            {{ t('text.config_package.import_same_as_now') }}
                        </td>
                        <template v-else>
                            <td class="max-w-[16rem] break-words p-2 text-ink-muted">{{ row.pair.before }}</td>
                            <td class="max-w-[16rem] break-words p-2" :class="{ 'font-medium': row.pair.changed }">{{ row.pair.after }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="more > 0" class="m-0 text-sm text-ink-muted" data-testid="diff-more">
            {{ t('text.config_package.import_more', { count: more }) }}
        </p>
    </section>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ACTIONS, formatPair, matchMessage } from '../../config-package-diff.js'

defineComponent({ name: 'ConfigPackageDiffSection' })

// One section of an import plan: counts per action, the items of the current page and the operator's per-item
// choices. Values are shown through formatPair, which masks secret-like keys. The component only emits choices.
const props = defineProps({
    /** Section name (key). */
    section: { type: String, required: true },
    /** Section overview from the plan: { status, counts, total, more, warnings }. */
    overview: { type: Object, default: () => ({}) },
    /** Items of the current page. */
    items: { type: Array, default: () => [] },
    /** Number of items beyond the server cap that are not listed. */
    more: { type: Number, default: 0 },
    /** Plan choices: [{ section, key, default_choice, reason }]. */
    choices: { type: Array, default: () => [] },
    /** Current operator choices keyed by item key (true = apply). */
    selected: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:choices'])

const { t, te } = useI18n()

/** Local copy of the operator's choices. */
const local = ref({ ...props.selected })
watch(
    () => props.selected,
    (value) => {
        local.value = { ...value }
    },
)

/**
 * Translated label of a section key; an untranslated key falls back to a humanised name.
 *
 * @param {string} key Section key.
 * @returns {string}
 */
function labelOf(key) {
    const path = `text.config_package.section_${key}`
    if (te(path)) return t(path)
    const words = String(key).replace(/[_-]+/g, ' ').trim()
    return words.charAt(0).toUpperCase() + words.slice(1)
}

const sectionLabel = computed(() => labelOf(props.section))

/**
 * Translate a known server message (reason or warning); an unknown one is shown as sent.
 *
 * @param {string} text Server message.
 * @returns {string}
 */
function describe(text) {
    const match = matchMessage(text)
    if (!match) return text
    const params = { ...match.params }
    if (params.section) params.section = labelOf(params.section)
    const path = `text.config_package.${match.key}`
    return match.plural ? t(path, params, params.count) : t(path, params)
}

const counts = computed(() => Object.fromEntries(ACTIONS.map((action) => [action, Number(props.overview?.counts?.[action]) || 0])))
const warnings = computed(() => (Array.isArray(props.overview?.warnings) ? props.overview.warnings.map(describe) : []))

/**
 * Default choice of an item: the item's own flag, else the plan choice entry.
 *
 * @param {object} item Plan item.
 * @returns {boolean}
 */
function defaultChoice(item) {
    if (typeof item.default_choice === 'boolean') return item.default_choice
    const entry = props.choices.find((choice) => choice.key === item.key && (!choice.section || choice.section === props.section))
    return !!entry?.default_choice
}

const rows = computed(() =>
    props.items.map((item) => ({
        key: String(item.key ?? ''),
        // Human name of the item when the server sent one; the key is only the fallback.
        label: item.label ? String(item.label) : '',
        action: ACTIONS.includes(item.action) ? item.action : 'unchanged',
        reason: item.reason ? describe(String(item.reason)) : '',
        warnings: (Array.isArray(item.warnings) ? item.warnings : []).map((warning) => describe(String(warning))),
        needsChoice: item.needs_choice === true,
        choice: item.key in local.value ? !!local.value[item.key] : defaultChoice(item),
        pair: formatPair(item.before, item.after, String(item.key ?? '')),
    })),
)

/**
 * Record a choice and tell the parent the full map of choices of this section.
 *
 * @param {string} key Item key.
 * @param {boolean} on Whether the item is applied.
 */
function setChoice(key, on) {
    local.value = { ...local.value, [key]: !!on }
    emit('update:choices', { ...local.value })
}
</script>
