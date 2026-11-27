<!--
  Who reviews workflow changes on this site (the account that set the site up need not approve other
  people's changes nor hear about them). Empty: everyone who may publish and every Webmaster. Webmaster only: the server
  answers it to Webmasters, other accounts see nothing.
-->
<template>
    <div
        v-if="state"
        class="space-y-2 rounded-lg border border-solid border-slate-200 bg-white px-3 py-2"
        data-testid="change-reviewers"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0 text-sm">
                <span class="font-medium">{{ t('text.workflow.guard.reviewers.title') }}:</span>
                <span class="ml-1 break-words text-gray-600" data-testid="change-reviewers-summary">{{ summary }}</span>
            </div>
            <a-button v-if="!editing" size="small" data-testid="change-reviewers-edit" @click="startEdit">
                {{ t('text.workflow.guard.reviewers.change') }}
            </a-button>
        </div>
        <template v-if="editing">
            <ReviewerPicker
                :model-value="draft"
                audience="notice"
                :max="20"
                :label="t('text.workflow.guard.reviewers.title')"
                @update:model-value="(value) => (draft = value)"
            />
            <p class="m-0 text-xs text-gray-500">{{ t('text.workflow.guard.reviewers.hint') }}</p>
            <p v-if="errorKey" class="m-0 text-xs text-red-600" role="alert">{{ t(errorKey) }}</p>
            <div class="flex flex-wrap gap-2">
                <a-button type="primary" size="small" :loading="busy" data-testid="change-reviewers-save" @click="save">
                    {{ t('text.workflow.guard.reviewers.save') }}
                </a-button>
                <a-button size="small" :disabled="busy" @click="editing = false">{{ $t('nav.b.cancel') }}</a-button>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isWebmaster } from '@/hooks/useCan'
import ReviewerPicker from '@/components/workflow/inputs/ReviewerPicker.vue'
import { fetchChangeReviewers, setChangeReviewers } from '../../../../workflow-guard-api'

defineOptions({ name: 'WorkflowChangeReviewers' })

const { t } = useI18n()
const webmaster = computed(() => isWebmaster())
const state = ref(null)
const editing = ref(false)
const draft = ref([])
const busy = ref(false)
const errorKey = ref('')

/** "Name - username": the username (or the email of an account without one) tells two people with one name apart. */
const personName = (person) => (person?.detail && person.detail !== person?.name ? `${person.name} - ${person.detail}` : person?.name || '')
const summary = computed(() =>
    state.value?.named?.length ? state.value.named.map(personName).join(', ') : t('text.workflow.guard.reviewers.default')
)

async function load() {
    // Answered to Webmasters only; other accounts are not asked (it would be a refused request).
    if (!webmaster.value) {
        state.value = null
        return
    }
    try {
        state.value = await fetchChangeReviewers()
    } catch (error) {
        state.value = null
    }
}

function startEdit() {
    draft.value = (state.value?.named || []).map((person) => person.uuid)
    errorKey.value = ''
    editing.value = true
}

async function save() {
    if (busy.value) return
    busy.value = true
    errorKey.value = ''
    try {
        state.value = await setChangeReviewers(draft.value)
        editing.value = false
    } catch (error) {
        errorKey.value = 'text.workflow.guard.reviewers.failed'
    } finally {
        busy.value = false
    }
}

watch(webmaster, load, { immediate: true })
defineExpose({ reload: load })
</script>
