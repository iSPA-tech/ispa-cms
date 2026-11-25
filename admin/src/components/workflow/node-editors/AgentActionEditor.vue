<template>
    <div class="agent-action-editor space-y-4">
        <div class="rounded border border-violet-200 bg-violet-50 p-3 text-xs text-violet-900">
            {{ t('text.workflow.editor.agent_action.intro') }}
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.profile_slug') }}</p>
                <a-input
                    v-model:value="config.agentProfileSlug"
                    placeholder="content-editor"
                    :disabled="readOnly"
                    @change="emitConfig"
                />
                <p class="mt-1 mb-0 text-xs text-gray-500">
                    {{ t('text.workflow.editor.agent_action.profile_slug_hint', { examples: 'content-editor, ops-maintainer' }) }}
                </p>
            </div>

            <div>
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.agentic.tool_name') }}</p>
                <a-input
                    v-model:value="config.toolName"
                    placeholder="content.propose_post_seo_refresh"
                    :disabled="readOnly"
                    @change="emitConfig"
                />
                <p class="mt-1 mb-0 text-xs text-gray-500">
                    {{ t('text.workflow.editor.agent_action.tool_name_hint') }}
                </p>
            </div>

            <div>
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.agentic.module_scope') }}</p>
                <a-input
                    v-model:value="config.moduleScope"
                    placeholder="content"
                    :disabled="readOnly"
                    @change="emitConfig"
                />
                <p class="mt-1 mb-0 text-xs text-gray-500">
                    {{ t('text.workflow.editor.agent_action.module_scope_hint') }}
                </p>
            </div>

            <div>
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.store_result_as') }}</p>
                <a-input
                    v-model:value="config.storeAs"
                    placeholder="vars.agent_result"
                    :disabled="readOnly"
                    @change="emitConfig"
                    :status="storeAsInvalid ? 'error' : ''"
                />
                <p class="mt-1 mb-0 text-xs" :class="storeAsInvalid ? 'text-red-600' : 'text-gray-500'">
                    {{ storeAsInvalid ? t('text.workflow.editor.agent_action.store_result_invalid') : t('text.workflow.editor.agent_action.store_result_hint') }}
                </p>
            </div>
        </div>

        <div>
            <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.goal') }}</p>
            <a-textarea
                v-model:value="config.goal"
                :auto-size="{ minRows: 2, maxRows: 4 }"
                :placeholder="t('text.workflow.editor.agent_action.goal_placeholder')"
                :disabled="readOnly"
                @change="emitConfig"
            />
            <p class="mt-1 mb-0 text-xs text-gray-500">
                {{ t('text.workflow.editor.agent_action.goal_hint') }}
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between">
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.tool_input') }}</p>
                <a-button size="small" @click="applyExampleInput" :disabled="readOnly">
                    {{ t('text.workflow.editor.agent_action.insert_example') }}
                </a-button>
            </div>
            <a-textarea
                v-model:value="toolInputDraft"
                :auto-size="{ minRows: 6, maxRows: 12 }"
                :disabled="readOnly"
                :status="toolInputError ? 'error' : ''"
                @change="syncToolInput"
                placeholder="{\n  &quot;post_id&quot;: &quot;{{ vars.post_id }}&quot;,\n  &quot;language_id&quot;: &quot;{{ vars.language_id }}&quot;\n}"
            />
            <p v-if="toolInputError" class="mt-1 mb-0 text-xs text-red-600">
                {{ toolInputError }}
            </p>
            <p v-else class="mt-1 mb-0 text-xs text-gray-500">
                {{ t('text.workflow.editor.agent_action.tool_input_hint') }}
            </p>
        </div>

        <!-- Rewrite on request: the reviewer may send the AI's proposal back, up to a number of rounds -->
        <div class="space-y-3" data-testid="agent-action-rewrite">
            <div>
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <a-switch
                        :checked="config.allow_revision !== false"
                        :disabled="readOnly"
                        size="small"
                        data-testid="agent-action-allow-revision"
                        @update:checked="setAllowRevision"
                    />
                    <span class="break-words">{{ t('text.workflow.field.allow_revision') }}</span>
                </label>
                <p class="mt-1 mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.field.allow_revision_hint') }}</p>
            </div>
            <div v-if="config.allow_revision !== false">
                <p class="mb-1 break-words text-sm font-semibold text-gray-700">{{ t('text.workflow.field.max_rounds') }}</p>
                <a-input-number
                    class="w-full md:w-40"
                    :value="config.max_rounds ?? null"
                    :min="1"
                    :max="5"
                    :precision="0"
                    :placeholder="'3'"
                    :disabled="readOnly"
                    :aria-label="t('text.workflow.field.max_rounds')"
                    data-testid="agent-action-max-rounds"
                    @update:value="setMaxRounds"
                />
                <p class="mt-1 mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.editor.agent_action.max_rounds_hint') }}</p>
            </div>
        </div>

        <!-- Who reviews the AI's proposal, and how fast: applied to every round of the proposal -->
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2" data-testid="agent-action-review">
            <div class="min-w-0">
                <p class="mb-1 break-words text-sm font-semibold text-gray-700">{{ t('text.workflow.field.assignees') }}</p>
                <ReviewerPicker :model-value="config.assignees" :read-only="readOnly" :label="t('text.workflow.field.assignees')" @update:model-value="setAssignees" />
                <p class="mt-1 mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.field.assignees_hint') }}</p>
            </div>
            <div class="min-w-0">
                <p class="mb-1 break-words text-sm font-semibold text-gray-700">{{ t('text.workflow.field.sla') }}</p>
                <SlaDurationInput :model-value="config.sla" :read-only="readOnly" :label="t('text.workflow.field.sla')" @update:model-value="setSla" />
                <p class="mt-1 mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.field.sla_hint') }}</p>
            </div>
        </div>

        <a-collapse size="small" ghost>
            <a-collapse-panel key="advanced" :header="t('text.workflow.editor.agent_action.advanced')">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.environment') }}</p>
                        <a-input
                            v-model:value="config.environment"
                            placeholder="local"
                            :disabled="readOnly"
                            @change="emitConfig"
                        />
                    </div>
                    <div>
                        <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.policy_scope') }}</p>
                        <a-input
                            v-model:value="config.policyScope"
                            placeholder="default"
                            :disabled="readOnly"
                            @change="emitConfig"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.agent_action.tool_class') }}</p>
                        <a-input
                            v-model:value="config.toolClass"
                            placeholder="Cms\\Content\\Services\\Agentic\\PostProposalToolService"
                            :disabled="readOnly"
                            @change="emitConfig"
                        />
                        <p class="mt-1 mb-0 text-xs text-gray-500">
                            {{ t('text.workflow.editor.agent_action.tool_class_hint') }}
                        </p>
                    </div>
                </div>
            </a-collapse-panel>
        </a-collapse>
    </div>
</template>

<script setup>
import { reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ReviewerPicker from '@/components/workflow/inputs/ReviewerPicker.vue'
import SlaDurationInput from '@/components/workflow/inputs/SlaDurationInput.vue'
import { formatSla, parseSla } from './schemaForm.js'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const defaultConfig = () => ({
    agentProfileSlug: '',
    toolName: '',
    moduleScope: '',
    goal: '',
    storeAs: 'vars.agent_result',
    toolInput: {},
    environment: '',
    policyScope: '',
    toolClass: '',
    // Rewrite on request: on unless switched off; an empty round cap means the default (3).
    allow_revision: true,
    max_rounds: null,
    // Reviewers (user uuids) and review deadline (`30m` / `4h` / `2d`); empty = whoever may approve, not measured.
    assignees: [],
    sla: null,
})

const config = reactive(defaultConfig())
const toolInputDraft = ref('{}')
const toolInputError = ref('')

const normalizeConfig = (value = {}) => {
    const normalized = {
        ...defaultConfig(),
        ...(value || {}),
    }

    if (!normalized.toolInput || typeof normalized.toolInput !== 'object' || Array.isArray(normalized.toolInput)) {
        normalized.toolInput = {}
    }

    return normalized
}

const applyConfig = (value = {}) => {
    const normalized = normalizeConfig(value)
    Object.assign(config, normalized)
    toolInputDraft.value = JSON.stringify(normalized.toolInput || {}, null, 2)
    toolInputError.value = ''
}

watch(
    () => props.modelValue,
    (value) => {
        applyConfig(value)
    },
    { immediate: true, deep: true }
)

const storeAsInvalid = computed(() => {
    if (!config.storeAs) return false
    return typeof config.storeAs !== 'string' || !config.storeAs.startsWith('vars.')
})

const buildPayload = () => {
    const payload = {}

    ;[
        'agentProfileSlug',
        'toolName',
        'moduleScope',
        'goal',
        'storeAs',
        'environment',
        'policyScope',
        'toolClass',
    ].forEach((key) => {
        const value = config[key]
        if (typeof value === 'string' && value.trim() !== '') {
            payload[key] = value.trim()
        }
    })

    // Only what differs from the default is stored, so untouched steps keep their saved config.
    if (config.allow_revision === false) payload.allow_revision = false
    const rounds = Number(config.max_rounds)
    if (config.allow_revision !== false && Number.isInteger(rounds) && rounds >= 1 && rounds <= 5) payload.max_rounds = rounds

    const assignees = Array.isArray(config.assignees) ? config.assignees.filter((uuid) => typeof uuid === 'string' && uuid !== '') : []
    if (assignees.length) payload.assignees = assignees
    const sla = parseSla(config.sla)
    if (sla.amount !== null) payload.sla = formatSla(sla.amount, sla.unit)

    payload.toolInput = parseToolInput() || {}
    return payload
}

const emitConfig = () => {
    emit('update:modelValue', buildPayload())
}

const setAllowRevision = (checked) => {
    config.allow_revision = checked !== false
    emitConfig()
}

const setMaxRounds = (value) => {
    config.max_rounds = value === null || value === undefined || value === '' ? null : Math.min(5, Math.max(1, Math.round(Number(value)) || 1))
    emitConfig()
}

const setAssignees = (uuids) => {
    config.assignees = Array.isArray(uuids) ? uuids : []
    emitConfig()
}

const setSla = (value) => {
    config.sla = value || null
    emitConfig()
}

const parseToolInput = () => {
    try {
        const parsed = JSON.parse(toolInputDraft.value || '{}')
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            toolInputError.value = t('text.workflow.editor.agent_action.tool_input_must_be_object')
            return null
        }
        toolInputError.value = ''
        return parsed
    } catch (error) {
        toolInputError.value = error?.message || t('text.workflow.editor.agent_action.invalid_json')
        return null
    }
}

const syncToolInput = () => {
    const parsed = parseToolInput()
    if (!parsed) return
    config.toolInput = parsed
    emitConfig()
}

const applyExampleInput = () => {
    toolInputDraft.value = JSON.stringify(
        {
            post_id: '{{ vars.post_id }}',
            language_id: '{{ vars.language_id }}',
            instruction: '{{ vars.operator_note }}',
        },
        null,
        2
    )
    syncToolInput()
}
</script>

<style scoped>
.agent-action-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
