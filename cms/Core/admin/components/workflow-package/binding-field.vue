<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-2 rounded-lg border border-slate-200 p-3" :data-testid="`binding-${row.name}`" :data-state="row.state">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p :id="labelId" class="m-0 break-words text-sm font-semibold">{{ t(`text.workflow.package.binding.${row.kind}`) }}</p>
                <p class="m-0 break-words text-xs text-ink-muted">
                    {{ t('text.workflow.package.binding.used_by', { steps: row.steps.join(', ') }) }}
                </p>
            </div>
            <a-tag class="m-0" :color="row.state === 'ok' ? 'green' : row.state === 'invalid' ? 'red' : 'orange'" data-testid="binding-state">
                {{ t(`text.workflow.package.binding.state_${row.state}`) }}
            </a-tag>
        </div>

        <!-- Credential: only credentials of a type the step accepts. -->
        <template v-if="row.kind === 'credential'">
            <p class="m-0 break-words text-xs text-ink-muted">{{ t('text.workflow.package.binding.credential_hint', { name: row.name }) }}</p>
            <a-select
                v-if="credentialChoices.length"
                class="w-full"
                :value="row.value || undefined"
                :options="credentialChoices"
                :loading="lists.credentials.loading"
                :disabled="disabled"
                :placeholder="t('text.workflow.package.binding.choose')"
                :aria-labelledby="labelId"
                show-search
                option-filter-prop="label"
                data-testid="binding-select"
                @update:value="(value) => emit('update:value', value || '')"
            />
            <a-spin v-else-if="lists.credentials.loading" size="small" />
            <div v-else class="space-y-1" data-testid="binding-credential-none">
                <p v-if="row.state === 'ok'" class="m-0 break-words text-sm" role="status" data-testid="binding-credential-same-name">
                    {{ t('text.workflow.package.binding.credential_same_name', { name: row.name }) }}
                </p>
                <p v-else class="m-0 break-words text-sm text-amber-700" role="status">
                    {{
                        lists.credentials.failed
                            ? t('text.workflow.package.binding.credential_list_failed')
                            : t('text.workflow.package.binding.credential_none')
                    }}
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <router-link
                        v-if="canManageCredentials"
                        :to="{ name: 'workflow-credentials' }"
                        target="_blank"
                        class="text-sm"
                        data-testid="binding-credential-link"
                    >
                        {{ t('text.workflow.package.binding.credential_add') }}
                    </router-link>
                    <a-button size="small" :disabled="disabled" data-testid="binding-reload" @click="emit('reload', 'credentials')">
                        {{ t('text.workflow.package.binding.reload') }}
                    </a-button>
                </div>
            </div>
        </template>

        <!-- Agent profile or another workflow of this site, picked by name. -->
        <template v-else-if="row.kind === 'agent_profile' || row.kind === 'workflow'">
            <a-select
                v-if="namedChoices.length || namedList.loading"
                class="w-full"
                :value="row.value || undefined"
                :options="namedChoices"
                :loading="namedList.loading"
                :disabled="disabled"
                :placeholder="t('text.workflow.package.binding.choose')"
                :aria-labelledby="labelId"
                show-search
                option-filter-prop="label"
                data-testid="binding-select"
                @update:value="(value) => emit('update:value', value || '')"
            />
            <div v-else class="flex flex-wrap items-center gap-3" data-testid="binding-list-none">
                <p class="m-0 break-words text-sm text-amber-700" role="status">
                    {{ namedList.failed ? t('text.workflow.package.binding.list_failed') : t(`text.workflow.package.binding.${row.kind}_none`) }}
                </p>
                <a-button size="small" :disabled="disabled" data-testid="binding-reload" @click="emit('reload', listKey)">
                    {{ t('text.workflow.package.binding.reload') }}
                </a-button>
            </div>
        </template>

        <!-- A person, picked by name. -->
        <reviewer-picker
            v-else-if="row.kind === 'user'"
            :model-value="row.value ? [row.value] : []"
            :max="1"
            :read-only="disabled"
            :label="t('text.workflow.package.binding.user')"
            @update:model-value="(value) => emit('update:value', value[0] || '')"
        />

        <!-- A fixed record of the source site: no list can exist for it, so its code is pasted. -->
        <template v-else>
            <p class="m-0 break-words text-xs text-ink-muted">{{ t('text.workflow.package.binding.record_hint') }}</p>
            <a-input
                :value="row.value"
                :disabled="disabled"
                :placeholder="t('text.workflow.package.binding.record_placeholder')"
                :aria-labelledby="labelId"
                :status="row.state === 'invalid' ? 'error' : undefined"
                allow-clear
                data-testid="binding-record"
                @update:value="(value) => emit('update:value', String(value || '').trim())"
            />
            <p v-if="row.state === 'invalid'" class="m-0 break-words text-xs text-red-600" role="alert">
                {{ t('text.workflow.package.binding.record_invalid') }}
            </p>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ReviewerPicker from '@/components/workflow/inputs/ReviewerPicker.vue'
import { credentialOptions } from '../../workflow-package-report'

/**
 * One thing a workflow package needs from this site (a credential, an agent profile, another workflow, a person or
 * a fixed record), chosen by name. The operator never types an identifier, except the code of a fixed record.
 */
defineOptions({ name: 'WorkflowPackageBindingField' })

const props = defineProps({
    /** Row of bindingRows(): { name, kind, credentialType, steps, value, state }. */
    row: { type: Object, required: true },
    /** { credentials, profiles, workflows }, each { items, loading, failed }. */
    lists: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    canManageCredentials: { type: Boolean, default: false },
})
const emit = defineEmits(['update:value', 'reload'])

const { t, te } = useI18n()

const labelId = computed(() => `wf-binding-${props.row.name}`)

const typeLabel = (type) => (te(`text.workflow_credential.type_${type}`) ? t(`text.workflow_credential.type_${type}`) : type)

const credentialChoices = computed(() => credentialOptions(props.lists.credentials.items, props.row.credentialType, typeLabel))

const listKey = computed(() => (props.row.kind === 'agent_profile' ? 'profiles' : 'workflows'))
const namedList = computed(() => props.lists[listKey.value])
const namedChoices = computed(() => namedList.value.items.map((item) => ({ value: item.slug, label: item.name })))
</script>
