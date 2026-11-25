<template>
    <div class="max-w-5xl mx-auto space-y-4" data-testid="email-templates-page">
        <section class="i-box space-y-4">
            <div class="space-y-1">
                <h3 class="m-0 text-sm font-semibold">{{ t('nav.a.email_templates') }}</h3>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.email_template.intro') }}</p>
            </div>

            <!-- What the two mail classes mean, once, above the list -->
            <dl class="m-0 grid grid-cols-1 gap-2 rounded-md bg-surface-sunken px-3 py-3 text-sm md:grid-cols-2" data-testid="et-class-legend">
                <div v-for="mailClass in MAIL_CLASSES" :key="mailClass" class="min-w-0">
                    <dt><a-tag :color="classColor(mailClass)">{{ classLabel(mailClass) }}</a-tag></dt>
                    <dd class="m-0 mt-1 break-words text-xs text-ink-muted">{{ t(`text.email_template.class.${mailClass}_hint`) }}</dd>
                </div>
            </dl>

            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full space-y-1 sm:w-56">
                    <label for="et-filter-group" class="block text-xs font-medium text-ink-muted">{{ t('text.email_template.filter.group') }}</label>
                    <a-select id="et-filter-group" v-model:value="filters.group" class="w-full" :options="groupOptions" data-testid="et-filter-group" @change="applyFilters" />
                </div>
                <div class="w-full space-y-1 sm:w-56">
                    <label for="et-filter-class" class="block text-xs font-medium text-ink-muted">{{ t('text.email_template.filter.class') }}</label>
                    <a-select id="et-filter-class" v-model:value="filters.mailClass" class="w-full" :options="classOptions" data-testid="et-filter-class" @change="applyFilters" />
                </div>
            </div>

            <a-alert v-if="notice" type="success" show-icon closable :message="notice" data-testid="et-notice" @close="notice = ''" />

            <a-skeleton v-if="loading && !rows.length" active :paragraph="{ rows: 3 }" />
            <a-alert v-else-if="loadError" type="error" show-icon :message="t('text.email_template.load_failed')" data-testid="et-load-error">
                <template #action>
                    <i-button size="sm" variant="default" @click="load">{{ t('text.email_template.retry') }}</i-button>
                </template>
            </a-alert>
            <p v-else-if="!rows.length" class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted" data-testid="et-empty">
                {{ filtered ? t('text.email_template.empty_filtered') : t('text.email_template.empty') }}
            </p>
            <div v-else class="space-y-5" :class="loading ? 'opacity-60' : ''" data-testid="et-list">
                <section v-for="section in sections" :key="section.group" class="space-y-2" :data-group="section.group">
                    <h4 class="m-0 flex flex-wrap items-baseline gap-x-2 text-sm font-semibold">
                        {{ groupLabel(section.group) }}
                        <span class="text-xs font-normal text-ink-muted">{{ t('text.email_template.count', { n: section.count }) }}</span>
                    </h4>
                    <div v-for="language in section.languages" :key="language.id ?? 'none'" class="space-y-1">
                        <p class="m-0 text-xs font-medium uppercase tracking-wide text-ink-faint">{{ language.name || t('text.email_template.language_any') }}</p>
                        <ul class="m-0 list-none divide-y divide-border rounded-md border border-border p-0">
                            <li v-for="row in language.rows" :key="row.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-3 py-2" data-testid="et-row">
                                <div class="min-w-0 flex-1 basis-48 space-y-1">
                                    <div class="break-words text-sm font-medium text-ink">{{ row.name }}</div>
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
                                        <a-tooltip :title="t(`text.email_template.class.${classOf(row)}_hint`)">
                                            <a-tag :color="classColor(classOf(row))" class="!mr-0" data-testid="et-row-class">{{ classLabel(classOf(row)) }}</a-tag>
                                        </a-tooltip>
                                        <span class="break-all">{{ t('text.email_template.key') }}: <code class="rounded bg-surface-sunken px-1">{{ row.key_name }}</code></span>
                                    </div>
                                </div>
                                <i-button size="sm" variant="default" :data-testid="`et-open-${row.id}`" @click="open(row)">
                                    {{ canUpdate ? t('text.email_template.edit') : t('text.email_template.view') }}
                                </i-button>
                            </li>
                        </ul>
                    </div>
                </section>
                <div v-if="total > PER_PAGE" class="flex justify-end">
                    <a-pagination size="small" :current="page" :page-size="PER_PAGE" :total="total" :show-size-changer="false" @change="changePage" />
                </div>
            </div>
        </section>

        <a-drawer
            :open="drawer.open"
            :title="canUpdate ? t('text.email_template.edit_title') : t('text.email_template.view_title')"
            width="min(880px, 100vw)"
            destroy-on-close
            @close="close"
        >
            <a-skeleton v-if="drawer.loading" active :paragraph="{ rows: 6 }" />
            <a-alert v-else-if="drawer.loadError" type="error" show-icon :message="t('text.email_template.detail_failed')" data-testid="et-detail-error">
                <template #action>
                    <i-button size="sm" variant="default" @click="open(drawer.row)">{{ t('text.email_template.retry') }}</i-button>
                </template>
            </a-alert>
            <div v-else-if="original" class="space-y-4" data-testid="et-form">
                <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
                    <span>{{ groupLabel(original.group) }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ original.language?.name || t('text.email_template.language_any') }}</span>
                    <span aria-hidden="true">·</span>
                    <span class="break-all">{{ t('text.email_template.key') }}: <code class="rounded bg-surface-sunken px-1">{{ original.key_name }}</code></span>
                </p>
                <a-alert v-if="!canUpdate" type="info" show-icon :message="t('text.email_template.view_only')" />

                <EmailTemplateForm
                    :model-value="form"
                    form-id="core-email-template-form"
                    :subject-label="t('text.email_template.subject')"
                    :key-name-label="t('text.email_template.key')"
                    :blade-layout-label="t('text.email_template.layout.label')"
                    :language-label="t('text.email_template.language')"
                    :variables-label="t('text.email_template.variables')"
                    :add-variable-label="t('text.email_template.variables')"
                    :sample-data-label="t('text.email_template.preview.sample')"
                    :value-label="t('text.email_template.preview.sample')"
                    :label-label="t('text.email_template.variables')"
                    :content-label="t('text.email_template.content')"
                    :preview-label="t('text.email_template.preview.title')"
                    :required-message="(label) => t('text.email_template.required', { field: label })"
                    :show-technical-fields="false"
                    :show-preview="false"
                    :mail-class-label="t('text.email_template.class.label')"
                    :mail-class-options="mailClassChoices"
                    :mail-class-help="classChanged ? t('text.email_template.class.change_warning') : ''"
                    :layout-options="layoutOptions"
                    :variable-list="variableList"
                    :variable-list-hint="t('text.email_template.variables_hint')"
                    :variable-insert-title="t('text.email_template.variable_insert')"
                    @update:model-value="applyForm"
                    @submit="save"
                />
                <p v-if="isWorkflow" class="m-0 text-xs text-ink-muted" data-testid="et-layout-note">{{ t('text.email_template.layout.workflow_note') }}</p>

                <!-- Preview: the unsaved subject and content filled with sample data, rendered by the server like a real send -->
                <section class="space-y-3 rounded-md border border-border p-3" data-testid="et-preview">
                    <div class="space-y-1">
                        <h4 class="m-0 text-sm font-semibold">{{ t('text.email_template.preview.title') }}</h4>
                        <p class="m-0 text-xs text-ink-muted">{{ t('text.email_template.preview.hint') }}</p>
                    </div>
                    <div v-if="Object.keys(samples).length" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div v-for="variable in fillable" :key="variable.key" class="min-w-0 space-y-1">
                            <label :for="`et-sample-${variable.key}`" class="block break-words text-xs font-medium">{{ variable.description || variable.key }}</label>
                            <a-input :id="`et-sample-${variable.key}`" v-model:value="samples[variable.key]" maxlength="500" />
                        </div>
                    </div>
                    <i-button variant="default" :disabled="preview.loading" :loading="preview.loading" data-testid="et-preview-run" @click="runPreview">
                        {{ t('text.email_template.preview.run') }}
                    </i-button>
                    <a-alert v-if="preview.error" type="error" show-icon :message="preview.error" data-testid="et-preview-error" />
                    <div v-else-if="preview.html" class="space-y-2" data-testid="et-preview-result">
                        <p class="m-0 flex flex-wrap gap-x-1 break-words text-sm">
                            <span class="text-ink-muted">{{ t('text.email_template.subject') }}:</span>
                            <span class="min-w-0 font-medium" data-testid="et-preview-subject">{{ preview.subject }}</span>
                        </p>
                        <!-- Server HTML only ever inside a sandboxed frame: no scripts, no forms, no access to this page. -->
                        <iframe
                            sandbox=""
                            :srcdoc="preview.html"
                            :title="t('text.email_template.preview.title')"
                            class="block h-[420px] w-full rounded-md border border-border bg-white"
                            data-testid="et-preview-frame"
                        />
                    </div>
                </section>

                <a-alert v-if="saveError" type="error" show-icon :message="saveError" data-testid="et-save-error" />
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <i-button variant="default" :disabled="saving" @click="close">{{ canUpdate ? t('text.email_template.cancel') : t('text.email_template.close') }}</i-button>
                    <i-button
                        v-if="canUpdate && original"
                        variant="primary"
                        native-type="submit"
                        form="core-email-template-form"
                        :disabled="saving || !dirty"
                        :loading="saving"
                        data-testid="et-save"
                    >
                        {{ t('text.email_template.save') }}
                    </i-button>
                </div>
            </template>
        </a-drawer>
    </div>
</template>

<script setup>
/**
 * "Email templates" (Settings > General). The managed e-mail templates of the site, listed by group and language: the
 * subject, the template's key (for reference only), its language and its mail class. The class decides how a workflow
 * may send the template: a `system` mail (received, welcome, reminder) goes out with no extra
 * switch; a `business` mail goes out only from a step whose switch is on. Editing changes the subject, the content,
 * the class and, outside workflow mails, the frame (picked from the allowed frames); the preview is rendered by the
 * server with sample data and shown in a sandboxed frame. Needs `email_template.viewAny` (route rule); saving needs
 * `email_template.update`.
 */
import { computed, defineComponent, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { rule } from '@/mixins'
import { isWebmaster } from '@/hooks/useCan'
import EmailTemplateForm from '@/components/email-template/EmailTemplateForm.vue'
import {
    LAYOUTS,
    MAIL_CLASSES,
    PER_PAGE,
    WORKFLOW_GROUP,
    changedFields,
    fetchEmailTemplate,
    fetchEmailTemplates,
    groupTemplates,
    humanizeGroup,
    previewEmailTemplate,
    refusal,
    sampleValues,
    saveEmailTemplate,
} from '../../email-template-api'

defineComponent({ name: 'SettingEmailTemplates' })

const { t, te } = useI18n()
const canUpdate = computed(() => rule('email_template.update'))

// ---------------------------------------------------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------------------------------------------------

const rows = ref([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const loadError = ref(false)
const notice = ref('')
const filters = reactive({ group: '', mailClass: '' })
const knownGroups = ref([])

const filtered = computed(() => filters.group !== '' || filters.mailClass !== '')
const sections = computed(() => groupTemplates(rows.value))

const classOf = (row) => (row?.mail_class === 'system' ? 'system' : 'business')
const classLabel = (mailClass) => t(`text.email_template.class.${mailClass}`)
const classColor = (mailClass) => (mailClass === 'system' ? 'blue' : 'gold')
const groupLabel = (group) => (te(`text.email_template.group.${group}`) ? t(`text.email_template.group.${group}`) : humanizeGroup(group) || t('text.email_template.group_other'))

const groupOptions = computed(() => [{ value: '', label: t('text.email_template.filter.group_all') }, ...knownGroups.value.map((group) => ({ value: group, label: groupLabel(group) }))])
const classOptions = computed(() => [{ value: '', label: t('text.email_template.filter.class_all') }, ...MAIL_CLASSES.map((mailClass) => ({ value: mailClass, label: classLabel(mailClass) }))])

async function load() {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        const result = await fetchEmailTemplates({ group: filters.group, mailClass: filters.mailClass, page: page.value })
        rows.value = result.rows
        total.value = result.total
        // The group filter keeps every group seen so far, so a filtered list can be widened again.
        knownGroups.value = [...new Set([...knownGroups.value, ...result.rows.map((row) => String(row.group || '')).filter(Boolean)])].sort()
    } catch (e) {
        loadError.value = true
    } finally {
        loading.value = false
    }
}

const applyFilters = () => {
    page.value = 1
    load()
}
const changePage = (next) => {
    page.value = next
    load()
}

// ---------------------------------------------------------------------------------------------------------------------
// Edit
// ---------------------------------------------------------------------------------------------------------------------

const drawer = reactive({ open: false, loading: false, loadError: false, row: null })
const original = ref(null)
const form = ref({})
const variableList = ref([])
const samples = reactive({})
const saving = ref(false)
const saveError = ref('')
const preview = reactive({ loading: false, error: '', html: '', subject: '' })

const isWorkflow = computed(() => original.value?.group === WORKFLOW_GROUP)
const fillable = computed(() => variableList.value.filter((row) => !row.builtin))
/** Only a Webmaster may make a template system mail; a template that already is system keeps its class for others. */
const canSetSystem = computed(() => isWebmaster() || classOf(original.value) === 'system')
const mailClassChoices = computed(() =>
    MAIL_CLASSES.map((mailClass) => {
        const locked = mailClass === 'system' && !canSetSystem.value
        return {
            value: mailClass,
            label: classLabel(mailClass),
            hint: locked ? t('text.email_template.class.system_webmaster_only') : t(`text.email_template.class.${mailClass}_hint`),
            disabled: locked,
        }
    }),
)
/** Workflow mails always go out in the site's own frame, so the frame is only offered for the other groups. */
const layoutOptions = computed(() => {
    if (!original.value || isWorkflow.value) return null
    const options = [{ value: '', label: t('text.email_template.layout.none') }, ...LAYOUTS.map((layout) => ({ value: layout.value, label: t(`text.email_template.layout.${layout.key}`) }))]
    const current = original.value.blade_layout || ''
    // A frame saved before the allowlist existed stays visible; the server refuses to save it again.
    if (current && !options.some((option) => option.value === current)) options.push({ value: current, label: t('text.email_template.layout.other') })
    return options
})
const changes = computed(() => (original.value ? changedFields(original.value, form.value, { withLayout: !isWorkflow.value }) : {}))
const dirty = computed(() => Object.keys(changes.value).length > 0)
const classChanged = computed(() => !!original.value && 'mail_class' in changes.value)

const resetPreview = () => Object.assign(preview, { loading: false, error: '', html: '', subject: '' })

async function open(row) {
    if (!row || drawer.loading) return
    Object.assign(drawer, { open: true, loading: true, loadError: false, row })
    original.value = null
    saveError.value = ''
    resetPreview()
    try {
        const data = await fetchEmailTemplate(row.id)
        if (!data) throw new Error('empty')
        const clean = { ...data, mail_class: classOf(data), blade_layout: data.blade_layout || '' }
        original.value = clean
        // The shared form edits a copy; its own variable editor is off, so the stored variables are never rewritten.
        form.value = { id: clean.id, name: clean.name || '', content: clean.content || '', mail_class: clean.mail_class, blade_layout: clean.blade_layout, language_id: clean.language_id, key_name: clean.key_name, group: clean.group }
        variableList.value = Array.isArray(data.variable_list) ? data.variable_list : []
        Object.keys(samples).forEach((key) => delete samples[key])
        Object.assign(samples, sampleValues(variableList.value))
    } catch (e) {
        drawer.loadError = true
    } finally {
        drawer.loading = false
    }
}

const applyForm = (values = {}) => {
    form.value = { ...form.value, name: values.name ?? '', content: values.content ?? '', mail_class: values.mail_class, blade_layout: values.blade_layout ?? '' }
}

function close() {
    if (saving.value) return
    drawer.open = false
}

/** A refusal in the screen's own words; the server's sentence only when the screen has none for it. */
const refusalText = (error, fallback) => {
    const { code, fields, message } = refusal(error)
    if (code === 'system_webmaster_only') return t('text.email_template.refused.system_webmaster_only')
    if (code === 'forbidden') return t('text.email_template.refused.forbidden')
    if (code === 'mail_class') return t('text.email_template.refused.mail_class')
    if (code === 'blade_layout') return t('text.email_template.refused.layout')
    if (code === 'invalid') return Object.values(fields)[0]
    return message || t(fallback)
}

async function save() {
    if (saving.value || !original.value || !canUpdate.value) return
    if (!dirty.value) return
    saving.value = true
    saveError.value = ''
    try {
        await saveEmailTemplate(original.value.id, changes.value)
        notice.value = t('text.email_template.saved', { name: form.value.name })
        drawer.open = false
        saving.value = false
        await load()
    } catch (e) {
        // The button comes back only on a failure, so a slow save cannot be sent twice.
        saveError.value = refusalText(e, 'text.email_template.save_failed')
        saving.value = false
    }
}

async function runPreview() {
    if (preview.loading || !original.value) return
    preview.loading = true
    preview.error = ''
    try {
        const payload = { name: form.value.name, content: form.value.content, sample_data: { ...samples } }
        if (!isWorkflow.value) payload.blade_layout = form.value.blade_layout || ''
        const data = await previewEmailTemplate(original.value.id, payload)
        preview.html = String(data.html || data.body || '')
        preview.subject = String(data.subject || '')
        if (!preview.html) preview.error = t('text.email_template.preview.empty')
    } catch (e) {
        preview.html = ''
        preview.error = refusalText(e, 'text.email_template.preview.failed')
    } finally {
        preview.loading = false
    }
}

onMounted(load)
</script>
