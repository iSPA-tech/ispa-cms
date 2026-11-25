<template>
    <div v-if="errorRows.length || warningRows.length" class="space-y-2" data-testid="graph-warnings">
        <a-alert v-if="errorRows.length" type="error" show-icon data-testid="graph-save-errors">
            <template #message>
                <span class="break-words text-sm font-semibold">{{ t('text.workflow.issue.title') }}</span>
            </template>
            <template #description>
                <ul class="m-0 list-none space-y-2 p-0">
                    <li v-for="row in errorRows" :key="`${row.step_code}:${row.code}:${row.message}`" class="break-words text-xs">
                        <span class="font-semibold">{{ row.name }}</span>
                        — {{ t(`text.workflow.issue.${row.code}`) }}
                        <span v-if="row.message" class="block text-gray-500">{{ row.message }}</span>
                    </li>
                </ul>
            </template>
        </a-alert>

        <a-alert v-if="warningRows.length" type="warning" show-icon data-testid="graph-taint-warnings">
            <template #message>
                <span class="break-words text-sm font-semibold">{{ t('text.workflow.warning.title') }}</span>
            </template>
            <template #description>
                <ul class="m-0 list-none space-y-2 p-0">
                    <li v-for="row in warningRows" :key="row.step_code" class="break-words text-xs">
                        {{ t('text.workflow.warning.taint', { step: row.name, source: row.originName }) }}
                    </li>
                </ul>
            </template>
        </a-alert>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { clearSaveErrors, lintTaint, setTaintWarnings, useNodeCatalog, useNodeIssues } from '@/components/workflows/nodeCatalog'

const props = defineProps({
    /** Canvas (VueFlow) nodes and edges. */
    nodes: {
        type: Array,
        default: () => [],
    },
    edges: {
        type: Array,
        default: () => [],
    },
})

const { t } = useI18n()
const { catalog } = useNodeCatalog()
const { saveErrors } = useNodeIssues()

const nameByCode = computed(() => {
    const names = {}
    props.nodes.forEach((node) => {
        const code = String(node?.data?.step_code || node?.id || '')
        if (code) names[code] = node?.data?.name || code
    })
    return names
})

const problems = computed(() => (catalog.loaded ? lintTaint(props.nodes, props.edges, catalog) : []))

// The node cards read the same result to mark themselves.
watch(problems, (list) => setTaintWarnings(list), { immediate: true })

const warningRows = computed(() =>
    problems.value.map((problem) => ({
        ...problem,
        name: nameByCode.value[problem.step_code] || problem.step_code,
        originName: nameByCode.value[problem.origin] || problem.origin,
    }))
)

const errorRows = computed(() =>
    Object.entries(saveErrors.value).flatMap(([stepCode, rows]) =>
        rows.map((row) => ({ ...row, step_code: stepCode, name: nameByCode.value[stepCode] || stepCode }))
    )
)

onBeforeUnmount(() => {
    setTaintWarnings([])
    clearSaveErrors()
})
</script>
