<template>
    <div class="variable-browser">
        <div class="vb-header">
            <div>
                <p class="vb-title">{{ $t('text.workflow.builder.variables') }}</p>
                <p class="vb-sub">{{ $t('text.workflow.var_browser.hint') }}</p>
            </div>
            <a-button size="small" type="link" @click="$emit('insert', 'root')">{{ $t('text.workflow.var_browser.copy_root') }}</a-button>
        </div>

        <a-collapse v-model:activeKey="activeKeys" :bordered="false">
            <a-collapse-panel key="root" :header="rootHeader">
                <div class="vb-section">
                    <div class="vb-loader" v-if="rootLoading">{{ $t('text.loading') }}</div>
                    <template v-else-if="rootSchema">
                        <p class="vb-label">{{ $t('text.workflow.editor.create_object.fields') }}</p>
                        <ul class="vb-list">
                            <li v-for="field in rootSchema.fields" :key="field.name">
                                <button class="vb-item" type="button" @click="onInsert(`root.${field.name}`)">
                                    <span>{{ field.name }}</span>
                                    <span class="vb-meta">{{ field.type }}</span>
                                </button>
                            </li>
                        </ul>

                        <p class="vb-label" v-if="rootSchema.relations?.length">{{ $t('text.workflow.var_browser.relations') }}</p>
                        <ul class="vb-list" v-if="rootSchema.relations?.length">
                            <li v-for="rel in rootSchema.relations" :key="rel.name">
                                <div class="vb-relation">
                                    <button class="vb-item" type="button" @click="toggleRelation(rel)">
                                        <span>{{ rel.name }}</span>
                                        <span class="vb-meta">
                                            {{ rel.cardinality === 'many' ? '[*]' : '' }} {{ rel.model }}
                                        </span>
                                    </button>
                                    <div v-if="isRelationOpen(rel)" class="vb-relation-fields">
                                        <div class="vb-loader" v-if="relationLoading[rel.name]">{{ $t('text.loading') }}</div>
                                        <template v-else-if="relationSchemas[rel.name]">
                                            <button
                                                v-for="field in relationSchemas[rel.name].fields"
                                                :key="field.name"
                                                class="vb-item"
                                                type="button"
                                                @click="onInsert(`root.${rel.name}.${field.name}`)"
                                            >
                                                <span>{{ field.name }}</span>
                                                <span class="vb-meta">{{ field.type }}</span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </template>
                    <div class="vb-empty" v-else>{{ $t('text.workflow.var_browser.no_model') }}</div>
                </div>
            </a-collapse-panel>

            <a-collapse-panel key="vars" header="vars">
                <div class="vb-section">
                    <div class="flex items-center gap-2">
                        <a-input v-model:value="varKey" size="small" placeholder="variable_name" />
                        <a-button size="small" @click="onInsertVar">{{ $t('text.workflow.var_browser.insert') }}</a-button>
                    </div>
                    <p class="vb-hint">{{ $t('text.workflow.var_browser.vars_hint') }}</p>
                </div>
            </a-collapse-panel>

            <a-collapse-panel key="steps" header="steps">
                <div class="vb-section">
                    <ul class="vb-list">
                        <li v-for="code in stepCodes" :key="code">
                            <div class="vb-step">
                                <span class="font-semibold">{{ code }}</span>
                                <div class="vb-step-actions">
                                    <a-button size="small" type="link" @click="onInsert(`steps.${code}.result`)">
                                        result
                                    </a-button>
                                    <a-button size="small" type="link" @click="onInsert(`steps.${code}.status`)">
                                        status
                                    </a-button>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <div class="vb-hint" v-if="!stepCodes.length">{{ $t('text.workflow.var_browser.no_steps') }}</div>
                </div>
            </a-collapse-panel>

            <a-collapse-panel key="trigger" header="trigger">
                <div class="vb-section">
                    <ul class="vb-list">
                        <li>
                            <button class="vb-item" type="button" @click="onInsert('trigger.event')">
                                <span>event</span>
                            </button>
                        </li>
                        <li>
                            <button class="vb-item" type="button" @click="onInsert('trigger.at')">
                                <span>at</span>
                            </button>
                        </li>
                        <li>
                            <button class="vb-item" type="button" @click="onInsert('trigger.meta')">
                                <span>meta.*</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </a-collapse-panel>
        </a-collapse>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { getModelSchema, getRelationSchema } from '@/libs'

const props = defineProps({
    rootModel: {
        type: String,
        default: null,
    },
    steps: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['insert'])

const activeKeys = ref(['root', 'vars'])
const rootSchema = ref(null)
const rootLoading = ref(false)
const relationLoading = reactive({})
const relationSchemas = reactive({})
const openRelations = ref(new Set())
const varKey = ref('')

const schemaCache = new Map()

const rootHeader = computed(() => (props.rootModel ? `root (${props.rootModel})` : 'root'))

const isActive = (key) =>
    Array.isArray(activeKeys.value) ? activeKeys.value.includes(key) : activeKeys.value === key

async function loadRootSchema(model) {
    if (!model) return
    if (schemaCache.has(model)) {
        rootSchema.value = schemaCache.get(model)
        return
    }
    rootLoading.value = true
    try {
        const schema = await getModelSchema(model)
        schemaCache.set(model, schema)
        rootSchema.value = schema
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error('Failed to load model schema', error)
    } finally {
        rootLoading.value = false
    }
}

watch(
    () => props.rootModel,
    async (model) => {
        rootSchema.value = null
        openRelations.value = new Set()
        Object.keys(relationSchemas).forEach((key) => delete relationSchemas[key])
        if (model && isActive('root')) {
            await loadRootSchema(model)
        }
    },
    { immediate: true }
)

watch(
    () => activeKeys.value,
    () => {
        if (isActive('root') && props.rootModel) {
            loadRootSchema(props.rootModel)
        }
    }
)

const stepCodes = computed(() =>
    Array.from(
        new Set(
            (props.steps || [])
                .map((n) => n.data?.step_code || n.id || n.name)
                .filter((v) => !!v)
        )
    )
)

const toggleRelation = async (rel) => {
    const isOpen = openRelations.value.has(rel.name)
    if (isOpen) {
        openRelations.value.delete(rel.name)
        return
    }
    openRelations.value.add(rel.name)
    if (!relationSchemas[rel.name]) {
        relationLoading[rel.name] = true
        try {
            const cacheKey = `${rel.model}`
            if (schemaCache.has(cacheKey)) {
                relationSchemas[rel.name] = schemaCache.get(cacheKey)
            } else {
                const schema = await getRelationSchema(props.rootModel, rel.name)
                schemaCache.set(cacheKey, schema)
                relationSchemas[rel.name] = schema
            }
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error('Failed to load relation schema', error)
        } finally {
            relationLoading[rel.name] = false
        }
    }
}

const isRelationOpen = (rel) => openRelations.value.has(rel.name)

const onInsert = (expr) => emit('insert', expr)

const onInsertVar = () => {
    const key = (varKey.value || '').trim()
    if (!key) return
    onInsert(`vars.${key}`)
}
</script>

<style scoped>
.variable-browser {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    box-shadow: 0 8px 24px -12px rgba(15, 23, 42, 0.18);
}

.vb-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.vb-title {
    font-weight: 600;
    margin: 0;
}

.vb-sub {
    margin: 0;
    color: #64748b;
    font-size: 12px;
}

.vb-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.vb-label {
    font-size: 12px;
    color: #475569;
    margin: 0;
}

.vb-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.vb-item {
    width: 100%;
    text-align: left;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}

.vb-item:hover {
    background: #e5f0ff;
}

.vb-meta {
    color: #94a3b8;
    font-size: 12px;
}

.vb-relation-fields {
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.vb-step {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px dashed #e2e8f0;
    border-radius: 6px;
    padding: 6px 8px;
    background: #f8fafc;
}

.vb-step-actions {
    display: flex;
    gap: 4px;
}

.vb-hint {
    font-size: 12px;
    color: #94a3b8;
    margin: 0;
}

.vb-empty {
    font-size: 12px;
    color: #94a3b8;
}

.vb-loader {
    font-size: 12px;
    color: #475569;
}
</style>
