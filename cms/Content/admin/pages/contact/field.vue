<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->
<template>
    <breadcrumbs :items="[{ name: 'contact-dashboard', title: t('nav.a.contacts'), icon: 'email-outline' }, { text: t('text.form_builder.forms'), icon: 'form-textbox' }]">
        <a-button v-if="rule('field.create')" size="small" @click="openForm"><i-icon icon="plus-thick" class="size-3.5" /> {{ t('nav.b.create') }} {{ t('text.form_builder.forms') }}</a-button>
    </breadcrumbs>

    <div v-if="rule('field.viewAny')" class="i-box">
        <p v-if="listLoading || editLoading" role="status">{{ t(editLoading ? 'text.form_builder.edit_loading' : 'text.form_builder.workspace_list_loading') }}</p>
        <a-alert v-if="editError || listError" type="error" show-icon :message="t(editError ? 'text.form_builder.edit_error' : 'text.form_builder.workspace_list_error')">
            <template #action><a-button size="small" @click="reloadList">{{ t('text.form_builder.retry') }}</a-button></template>
        </a-alert>
        <a-table :columns="columns" :data-source="dataRef || []" :pagination="false" row-key="id" size="small">
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'name'">
                    <button type="button" class="text-left font-medium hover:text-cyan-600" :disabled="!rule('field.update') || editLoading" @click="editForm(record.id)">{{ record.name }}</button>
                </template>
                <template v-else-if="column.key === 'items'">
                    <a-tag v-for="item in record.items" :key="item.id" :color="item.custom?.required ? 'red' : undefined" class="mb-0.5">{{ item.name }}</a-tag>
                    <span v-if="!record.items?.length">—</span>
                </template>
                <template v-else-if="column.key === 'action'">
                    <div class="flex justify-end gap-2">
                        <button v-if="rule('field.update')" type="button" :aria-label="t('nav.b.edit')" :disabled="editLoading" class="text-blue-600" @click="editForm(record.id)"><i-icon icon="pencil" class="size-4" /></button>
                        <a-popconfirm v-if="rule('field.delete')" :title="t('text.del-confirm')" :ok-text="t('nav.b.delete')" :cancel-text="t('nav.b.cancel')" ok-type="danger" @confirm="delForm(record.id)">
                            <button type="button" :aria-label="t('nav.b.delete')" class="text-rose-600"><i-icon icon="delete" class="size-4" /></button>
                        </a-popconfirm>
                    </div>
                </template>
            </template>
        </a-table>
    </div>
    <no-permission-error v-else />

    <a-modal :open="modalState" :footer="null" :title="t(form.id > 0 ? 'text.form_builder.workspace_edit' : 'text.form_builder.workspace_new')" class="modal-fullscreen form-workspace-modal" :mask-closable="false" destroy-on-close @cancel="requestClose">
        <a-form ref="formRef" :model="form" layout="vertical" @finish="submitForm" @finishFailed="formFailed">
            <div class="form-workspace" :data-saving="btnState">
                <header class="fw-header">
                    <div><h2>{{ form.name || t('text.form_builder.workspace_new') }}</h2><p>{{ t('text.form_builder.workspace_help') }}</p></div>
                    <div class="fw-actions">
                        <span role="status" class="fw-dirty" :class="{ changed: dirty }">{{ t(dirty ? 'text.form_builder.workspace_dirty' : 'text.form_builder.workspace_clean') }}</span>
                        <a-button :disabled="btnState" @click="requestClose"><i-icon icon="close" class="size-4" /> {{ t('nav.b.cancel') }}</a-button>
                        <a-button :loading="previewLoading" :disabled="btnState" @click="previewForm"><i-icon icon="eye-outline" class="size-4" /> {{ t('text.form_builder.preview') }}</a-button>
                        <a-button type="primary" html-type="submit" :loading="btnState" :disabled="btnState"><i-icon icon="content-save" class="size-4" /> {{ t('nav.b.save') }}</a-button>
                    </div>
                </header>

                <div class="fw-meta">
                    <a-form-item :label="t('text.form_builder.form_name')" name="name" :rules="[{ required: true, whitespace: true, message: t('text.form_builder.workspace_error_form_name') }]">
                        <a-input v-model:value="form.name" :disabled="btnState" :maxlength="255" :aria-label="t('text.form_builder.form_name')" />
                    </a-form-item>
                    <details v-if="account?.id === 1" class="fw-advanced"><summary>{{ t('text.form_builder.advanced_template') }}</summary>
                        <a-form-item :label="t('text.form_builder.appearance_template')" :name="['custom', 'templateId']"><a-input v-model:value="form.custom.templateId" :disabled="btnState" :maxlength="20" /></a-form-item>
                    </details>
                </div>

                <details class="fw-layout-settings mb-4 rounded border border-slate-200 p-3">
                    <summary class="cursor-pointer font-medium">{{ t('text.form_builder.responsive_form_layout') }}</summary>
                    <form-responsive-layout class="mt-3" :form="form" />
                </details>

                <a-alert v-if="saveError || operationError" type="error" show-icon :message="saveError || operationError" class="mb-3" />
                <section v-if="issues.length" ref="errorSummary" class="fw-errors" role="alert" tabindex="-1" :aria-label="t('text.form_builder.workspace_errors_title')">
                    <strong>{{ t('text.form_builder.workspace_errors_count', { count: issues.length }) }}</strong>
                    <p>{{ t('text.form_builder.workspace_errors_help') }}</p>
                    <ul><li v-for="(issue, index) in issues" :key="issue.key + issue.attribute + index">
                        <button type="button" @click="showIssue(issue)">{{ issue.index + 1 }}. {{ itemTitle(form.items[issue.index], issue.index) }} — {{ issueText(issue) }}</button>
                    </li></ul>
                </section>

                <div class="fw-grid">
                    <aside class="fw-outline" :aria-label="t('text.form_builder.workspace_outline')">
                        <div class="fw-panel-title"><h3>{{ t('text.form_builder.workspace_outline') }}</h3><span>{{ form.items.length }} {{ t('text.form_builder.workspace_items_count') }}</span></div>
                        <a-input-search v-model:value="search" :placeholder="t('text.form_builder.workspace_search')" :aria-label="t('text.form_builder.workspace_search')" allow-clear class="mb-3" />
                        <a-button block :disabled="btnState" @click="addField"><i-icon icon="plus" class="size-4" /> {{ t('text.form_builder.workspace_add') }}</a-button>
                        <p v-if="!form.items.length" class="fw-empty">{{ t('text.form_builder.workspace_empty') }}</p>
                        <p v-else-if="!groups.length" class="fw-empty">{{ t('text.form_builder.workspace_no_matches') }}</p>
                        <div v-for="group in groups" :key="group.key" class="fw-group">
                            <button type="button" class="fw-group-heading" :aria-expanded="!collapsed.includes(group.key) || !!search" @click="toggleGroup(group.key)">
                                <i-icon :icon="collapsed.includes(group.key) && !search ? 'chevron-right' : 'chevron-down'" class="size-4" /> {{ group.label || t('text.form_builder.workspace_ungrouped') }}
                                <small>{{ group.rows.length }}</small>
                            </button>
                            <div v-show="!collapsed.includes(group.key) || search">
                                <template v-for="row in group.rows" :key="row.key">
                                    <div v-if="dragKey && dragKey !== row.key" class="fw-drop" :class="{ over: dropKey === row.key }" @dragover.prevent="dropKey = row.key" @drop.prevent="dropBefore(row.key)">{{ t('text.form_builder.workspace_drop_before') }}</div>
                                    <div class="fw-row" :class="{ selected: selectedKey === row.key }" :draggable="!btnState" @dragstart="startDrag($event, row.key)" @dragend="endDrag">
                                        <i-icon icon="drag-vertical" class="fw-drag size-4" aria-hidden="true" />
                                        <button type="button" :aria-current="selectedKey === row.key ? 'true' : undefined" :disabled="btnState" @click="selectField(row.key)">
                                            <i-icon :icon="itemIcon(row.item.type)" class="size-4 shrink-0" aria-hidden="true" />
                                            <span><strong>{{ itemTitle(row.item, row.index) }} <span v-if="row.item.custom?.required" class="text-red-600">*</span></strong><small>{{ friendlyTypeLabel(row.item.type) }}</small></span>
                                            <span v-if="!row.item.status" class="fw-badge">{{ t('text.form_builder.hide') }}</span>
                                            <span v-if="issueCount(row.key)" class="fw-badge fw-invalid">{{ issueCount(row.key) }} {{ t('text.form_builder.workspace_errors_badge') }}</span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div v-if="dragKey" class="fw-drop" :class="{ over: dropKey === 'end' }" @dragover.prevent="dropKey = 'end'" @drop.prevent="dropBefore(null)">{{ t('text.form_builder.workspace_drop_end') }}</div>
                        <p class="fw-outline-help">{{ t('text.form_builder.workspace_move_help') }}</p>
                    </aside>

                    <section ref="detailsPanel" class="fw-details">
                        <template v-if="previewOpen">
                            <div class="fw-panel-title"><h3>{{ t('text.form_builder.preview') }}</h3><a-button size="small" @click="previewOpen = false">{{ t('text.form_builder.workspace_back_edit') }}</a-button></div>
                            <div class="fw-preview-controls">
                                <a-button :type="previewWidth === '100%' ? 'primary' : 'default'" @click="previewWidth = '100%'">{{ t('text.form_builder.desktop') }}</a-button>
                                <a-button :type="previewWidth === '800px' ? 'primary' : 'default'" @click="previewWidth = '800px'">{{ t('text.form_builder.responsive_tablet') }}</a-button>
                                <a-button :type="previewWidth === '375px' ? 'primary' : 'default'" @click="previewWidth = '375px'">{{ t('text.form_builder.mobile') }}</a-button>
                            </div>
                            <p class="fw-preview-note">{{ t('text.form_builder.workspace_preview_note') }}</p>
                            <p v-if="previewLoading" role="status">{{ t('text.form_builder.preview_loading') }}</p>
                            <div v-else-if="previewError" role="alert"><p>{{ t('text.form_builder.preview_error') }}</p><a-button @click="previewForm">{{ t('text.form_builder.retry') }}</a-button></div>
                            <div v-else-if="previewHtml" class="fw-preview"><iframe :srcdoc="previewHtml" :title="t('text.form_builder.preview')" :style="{ width: previewWidth }" sandbox="allow-scripts" /></div>
                            <p v-else>{{ t('text.form_builder.preview_empty') }}</p>
                        </template>
                        <template v-else-if="selectedItem">
                            <div class="fw-panel-title"><div><small>{{ t('text.form_builder.workspace_selected') }}</small><h3>{{ itemTitle(selectedItem, selectedIndex) }}</h3></div><a-tag>{{ friendlyTypeLabel(selectedItem.type) }}</a-tag></div>
                            <div class="fw-item-actions">
                                <a-button size="small" :disabled="btnState" @click="duplicateSelected"><i-icon icon="content-copy" class="size-4" /> {{ t('nav.b.duplicate') }}</a-button>
                                <a-button size="small" :disabled="btnState || form.items.length < 2" @click="openMove"><i-icon icon="swap-vertical" class="size-4" /> {{ t('text.form_builder.workspace_move') }}</a-button>
                                <a-popconfirm :title="t('text.form_builder.workspace_remove_confirm')" :ok-text="t('nav.b.delete')" :cancel-text="t('nav.b.cancel')" ok-type="danger" @confirm="removeSelected">
                                    <a-button size="small" danger type="text" :disabled="btnState"><i-icon icon="delete-outline" class="size-4" /> {{ t('text.form_builder.workspace_remove') }}</a-button>
                                </a-popconfirm>
                            </div>
                            <fieldset :disabled="btnState">
                                <field-logic :key="selectedItem.field_name" workspace :form="form" :index="selectedIndex" :type="selectedItem.type" :value="selectedItem" name="items" />
                            </fieldset>
                        </template>
                        <div v-else class="fw-empty"><i-icon icon="form-textbox" class="size-8" /><p>{{ t('text.form_builder.workspace_select') }}</p></div>
                    </section>
                </div>
            </div>
        </a-form>
    </a-modal>

    <a-modal :open="moveOpen" :title="t('text.form_builder.workspace_move')" :ok-text="t('text.form_builder.workspace_move')" :cancel-text="t('nav.b.cancel')" @ok="applyMove" @cancel="moveOpen = false">
        <p>{{ itemTitle(selectedItem, selectedIndex) }}</p>
        <a-alert v-if="operationError" type="error" show-icon :message="operationError" class="mb-3" />
        <a-form layout="vertical"><a-form-item :label="t('text.form_builder.workspace_position')" html-for="form-workspace-move-position">
            <a-select id="form-workspace-move-position" v-model:value="moveBeforeKey" :options="moveOptions" :virtual="false" :aria-label="t('text.form_builder.workspace_position')" />
        </a-form-item></a-form>
        <p class="fw-outline-help">{{ t('text.form_builder.workspace_dependency_help') }}</p>
    </a-modal>
    <a-modal :open="discardOpen" :title="t('text.form_builder.workspace_discard_title')" :footer="null" :mask-closable="false" @cancel="answerDiscard(false)">
        <p>{{ t('text.form_builder.workspace_discard_confirm') }}</p>
        <div class="fw-actions justify-end">
            <a-button @click="answerDiscard(false)">{{ t('text.form_builder.workspace_keep_editing') }}</a-button>
            <a-button danger @click="answerDiscard(true)">{{ t('text.form_builder.workspace_discard') }}</a-button>
        </div>
    </a-modal>
</template>

<script>
import { computed, defineComponent, nextTick, onBeforeUnmount, onMounted, provide, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'
import { useFields, useAccount } from '@/hooks'
import { iAlert, rule } from '@/mixins'
import { deleteField, getField, storeField, updateField } from '@/libs'
import ajax from '@/ajax'
import { getOptionSources } from '@/libs/form-option-sources'
import FormResponsiveLayout from '@/components/form-responsive-layout.vue'
import { draftFingerprint, outlineGroups, moveFormField, duplicateFormField, removeFormField, formItemIssues, serializeFormDraft } from './form-workspace-state'
import './form-workspace.css'

export default defineComponent({
    name: 'FieldManage',
    components: { FormResponsiveLayout },
    setup() {
        const { t } = useI18n()
        const modalState = ref(false), editLoading = ref(false), editError = ref(false), listError = ref(false), btnState = ref(false)
        const previewOpen = ref(false), previewLoading = ref(false), previewError = ref(false), previewHtml = ref(''), previewWidth = ref('100%')
        const selectedKey = ref(null), search = ref(''), collapsed = ref([]), baseline = ref(''), attempted = ref(false)
        const saveError = ref(''), operationError = ref(''), serverIssues = ref([]), serverFingerprint = ref('')
        const dragKey = ref(null), dropKey = ref(null), moveOpen = ref(false), moveBeforeKey = ref('')
        const discardOpen = ref(false)
        const optionSources = ref([]), optionSourcesLoading = ref(false), optionSourcesError = ref(false)
        const formRef = ref(), detailsPanel = ref(), errorSummary = ref()
        const { data: account } = useAccount()
        const fieldsQuery = useFields()
        const dataRef = fieldsQuery.data, listLoading = fieldsQuery.isLoading || ref(false)
        let editSequence = 0, previewSequence = 0
        let discardPromise, discardResolver

        const newKey = (prefix) => {
            const bytes = crypto.getRandomValues(new Uint8Array(16))
            bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80
            return prefix + '_' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
        }
        const defaultFieldItem = () => ({
            id: 0, name: null, field_id: 0, field_name: null, type: null, description: null, options: [], items: [],
            custom: { stepValue: null, allowFileType: null, placeholder: null, maxFileSizeMb: 10, choiceLayout: 'auto', aspectRatio: null,
                icon: null, color: null, iconAppend: false, defaultValue: null, minValue: null, maxValue: null, multiple: false, rows: 3,
                toolbar: false, required: false, section: null, visibleWhen: { field: null, value: null }, optionsSource: null },
            priority: 0, name_trans_key: null, phd_trans_key: null, status: true, isShow: true,
        })
        const defaultForm = () => ({ id: 0, name: '', form_id: null, custom: { templateId: 0, layout: { columns: { mobile: 1, tablet: 2, desktop: 2 } } }, items: [], status: 'status_published' })
        const form = ref(defaultForm())
        provide('newFormKey', newKey); provide('defaultFieldItem', defaultFieldItem)
        const loadOptionSources = async () => {
            optionSourcesLoading.value = true; optionSourcesError.value = false
            try { optionSources.value = await getOptionSources() }
            catch { optionSourcesError.value = true }
            finally { optionSourcesLoading.value = false }
        }
        provide('optionSources', optionSources); provide('optionSourcesLoading', optionSourcesLoading)
        provide('optionSourcesError', optionSourcesError); provide('reloadOptionSources', loadOptionSources)
        onMounted(loadOptionSources)
        const columns = computed(() => [{ key: 'name', title: t('text.form_builder.forms') }, { key: 'items', title: t('text.form_builder.items') }, { key: 'action', align: 'right', width: 80 }])
        const selectedIndex = computed(() => form.value.items.findIndex(item => item.field_name === selectedKey.value))
        const selectedItem = computed(() => form.value.items[selectedIndex.value] || null)
        const dirty = computed(() => !!baseline.value && draftFingerprint(form.value) !== baseline.value)
        const groups = computed(() => outlineGroups(form.value.items, search.value))
        const issues = computed(() => !attempted.value ? [] : [
            ...formItemIssues(form.value.items),
            ...(serverFingerprint.value === draftFingerprint(form.value) ? serverIssues.value : []),
        ])
        const itemTitle = (item, index) => item?.name || t('text.form_builder.workspace_unnamed', { position: index + 1 })
        const knownTypes = ['text', 'email', 'tel', 'date', 'select', 'radio', 'checkbox', 'textarea', 'file', 'document', 'image', 'number', 'password', 'datetime', 'editor', 'button', 'reset', 'submit', 'color', 'search', 'range', 'time', 'month', 'week', 'url']
        const friendlyTypeLabel = (type) => t('text.form_builder.type_' + (knownTypes.includes(type) ? type : 'other'))
        const itemIcon = (type) => ({ image: 'image-outline', file: 'file-outline', document: 'file-document-outline', select: 'form-dropdown', radio: 'radiobox-marked', checkbox: 'checkbox-marked-outline', email: 'email-outline', tel: 'phone-outline', date: 'calendar-outline', textarea: 'text-box-outline' }[type] || 'form-textbox')
        const issueCount = (key) => issues.value.filter(issue => issue.key === key).length
        const issueText = (issue) => t('text.form_builder.' + issue.reason)
        const moveOptions = computed(() => [
            ...form.value.items.filter(item => item.field_name !== selectedKey.value).map(item => ({ value: item.field_name, label: t('text.form_builder.workspace_before', { name: itemTitle(item, form.value.items.indexOf(item)) }) })),
            { value: '', label: t('text.form_builder.workspace_drop_end') },
        ])
        const toggleGroup = (key) => { collapsed.value = collapsed.value.includes(key) ? collapsed.value.filter(value => value !== key) : [...collapsed.value, key] }
        const selectField = (key) => {
            selectedKey.value = key; previewOpen.value = false; operationError.value = ''
            const group = outlineGroups(form.value.items).find(group => group.rows.some(row => row.key === key))
            if (group) collapsed.value = collapsed.value.filter(value => value !== group.key)
        }
        const resetForm = () => {
            editSequence++; previewSequence++
            form.value = defaultForm(); selectedKey.value = null; search.value = ''; collapsed.value = []
            baseline.value = ''; attempted.value = false; saveError.value = ''; operationError.value = ''; serverIssues.value = []
            modalState.value = false; previewOpen.value = false; moveOpen.value = false; editLoading.value = false; previewLoading.value = false
        }
        const canDiscard = () => {
            if (!dirty.value) return Promise.resolve(true)
            if (discardPromise) return discardPromise
            discardOpen.value = true
            discardPromise = new Promise(resolve => { discardResolver = resolve })
            return discardPromise
        }
        const answerDiscard = (accepted) => {
            discardOpen.value = false
            const resolve = discardResolver
            discardPromise = undefined; discardResolver = undefined
            resolve?.(accepted)
        }
        const requestClose = async () => { if (!btnState.value && await canDiscard()) resetForm() }
        const openForm = async () => {
            if (modalState.value && !await canDiscard()) return
            resetForm(); form.value = defaultForm(); baseline.value = draftFingerprint(form.value); modalState.value = true
        }
        const addField = () => {
            const item = { ...defaultFieldItem(), field_name: newKey('field') }
            form.value.items.push(item); search.value = ''; selectField(item.field_name)
        }
        const duplicateSelected = () => {
            if (!selectedItem.value || btnState.value) return
            const key = newKey('field')
            form.value.items = duplicateFormField(form.value.items, selectedKey.value, key)
            search.value = ''; selectField(key)
        }
        const dependencyNames = (rows) => rows.map(item => itemTitle(item, form.value.items.indexOf(item))).join(', ')
        const removeSelected = () => {
            if (!selectedItem.value || btnState.value) return
            const index = selectedIndex.value
            const result = removeFormField(form.value.items, selectedKey.value)
            if (result.blockedBy.length) { operationError.value = t('text.form_builder.workspace_delete_dependency', { names: dependencyNames(result.blockedBy) }); return }
            form.value.items = result.items
            selectField(result.items[Math.min(index, result.items.length - 1)]?.field_name || null)
        }
        const commitMove = (key, before) => {
            const result = moveFormField(form.value.items, key, before)
            if (result.blockedBy.length) { operationError.value = t('text.form_builder.workspace_move_dependency', { names: dependencyNames(result.blockedBy) }); return false }
            form.value.items = result.items; operationError.value = ''; return true
        }
        const openMove = () => { operationError.value = ''; moveBeforeKey.value = form.value.items[selectedIndex.value + 1]?.field_name || ''; moveOpen.value = true }
        const applyMove = () => { if (commitMove(selectedKey.value, moveBeforeKey.value || null)) moveOpen.value = false }
        const startDrag = (event, key) => { dragKey.value = key; event.dataTransfer?.setData('text/plain', key); if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move' }
        const endDrag = () => { dragKey.value = null; dropKey.value = null }
        const dropBefore = (before) => { if (!btnState.value && dragKey.value) commitMove(dragKey.value, before); endDrag() }
        const showIssue = async (issue) => {
            search.value = ''; selectField(issue.key)
            await nextTick()
            const panel = detailsPanel.value
            if (!panel) return
            const id = 'form_item_items_' + issue.index + '_' + issue.attribute.replaceAll('.', '_')
            const sectionName = issue.attribute.startsWith('options') || issue.attribute.includes('optionsSource') ? 'choices' : issue.attribute.includes('visibleWhen') || issue.attribute.includes('section') || issue.attribute.includes('layout') ? 'visibility' : issue.attribute.includes('File') || issue.attribute.includes('aspectRatio') ? 'upload' : 'content'
            let control = panel.querySelector('[id="' + id + '"]')
            if (!control && issue.attribute.startsWith('custom.optionsSource')) {
                control = panel.querySelector('[data-field-setting="custom.optionsSource"] [role="combobox"]')
            }
            const section = control?.closest('details') || panel.querySelector('[data-field-section="' + sectionName + '"]')
            if (section?.tagName === 'DETAILS') section.open = true
            await nextTick()
            control ||= section?.querySelector('input:not([type=hidden]),textarea,select,button')
            control?.focus(); control?.scrollIntoView?.({ block: 'nearest' })
        }
        const focusSummary = async () => { await nextTick(); errorSummary.value?.focus(); errorSummary.value?.scrollIntoView?.({ block: 'nearest' }) }
        const formFailed = () => { saveError.value = t('text.form_builder.workspace_error_form_name') }
        const mapServerIssues = (errors = {}) => Object.keys(errors).flatMap(path => {
            const match = path.match(/^items\.(\d+)(?:\.(.+))?$/)
            if (!match) return []
            const index = Number(match[1]), item = form.value.items[index]
            if (!item) return []
            const attribute = match[2] || 'name'
            const reason = attribute.startsWith('custom.optionsSource') ? 'workspace_error_source' : attribute === 'type' ? 'workspace_error_type' : attribute === 'name' ? 'workspace_error_name' : 'workspace_error_setting'
            return [{ index, key: item.field_name, attribute, reason }]
        })
        const previewForm = async () => {
            attempted.value = true
            if (formItemIssues(form.value.items).length) { await focusSummary(); return }
            previewOpen.value = true; previewLoading.value = true; previewError.value = false; previewHtml.value = ''
            const sequence = ++previewSequence
            try {
                const response = await ajax.post('/field/preview', serializeFormDraft(form.value), { responseType: 'text' })
                if (sequence === previewSequence) previewHtml.value = response.data
            } catch { if (sequence === previewSequence) previewError.value = true }
            finally { if (sequence === previewSequence) previewLoading.value = false }
        }
        const reloadList = async () => {
            listError.value = false
            try { const result = await fieldsQuery.refetch(); if (result?.isError) listError.value = true }
            catch { listError.value = true }
        }
        const submitForm = async () => {
            if (btnState.value) return
            attempted.value = true; saveError.value = ''; operationError.value = ''; serverIssues.value = []
            if (!form.value.name.trim()) { formFailed(); return }
            if (formItemIssues(form.value.items).length) { await focusSummary(); return }
            btnState.value = true
            const payload = serializeFormDraft(form.value)
            try {
                const response = await (payload.id > 0 ? updateField(payload.id, payload) : storeField(payload))
                if (response.status === 'success') {
                    resetForm()
                    await reloadList()
                } else saveError.value = t('text.form_builder.workspace_save_error')
                if (response.status === 'success') iAlert([t('text.form_builder.workspace_saved'), t('text.form_builder.workspace_saved_title')])
            } catch (error) {
                saveError.value = t('text.form_builder.workspace_save_error')
                serverIssues.value = mapServerIssues(error.response?.data?.errors)
                serverFingerprint.value = draftFingerprint(form.value)
                if (serverIssues.value.length) await focusSummary()
            } finally { btnState.value = false }
        }
        const editForm = async (id) => {
            if (modalState.value && !await canDiscard()) return
            const sequence = ++editSequence
            editLoading.value = true; editError.value = false
            try {
                const data = await getField(id, true)
                if (sequence !== editSequence) return
                const loaded = { ...defaultForm(), ...data }
                loaded.custom = data.custom || { templateId: 0 }
                loaded.items = (loaded.items || []).map(item => {
                    const defaults = defaultFieldItem()
                    return { ...defaults, ...item, custom: { ...defaults.custom, ...(item.custom || {}),
                        visibleWhen: { ...defaults.custom.visibleWhen, ...(item.custom?.visibleWhen || {}) } }, isShow: false }
                })
                form.value = loaded; search.value = ''; collapsed.value = []; attempted.value = false
                saveError.value = ''; operationError.value = ''; serverIssues.value = []
                selectedKey.value = loaded.items[0]?.field_name || null
                previewOpen.value = false; baseline.value = draftFingerprint(loaded); modalState.value = true
            } catch { if (sequence === editSequence) editError.value = true }
            finally { if (sequence === editSequence) editLoading.value = false }
        }
        const delForm = async (id) => { try { await deleteField(id); await reloadList() } catch (error) { iAlert(error, true) } }
        const beforeUnload = (event) => { if (modalState.value && dirty.value) { event.preventDefault(); event.returnValue = '' } }
        window.addEventListener('beforeunload', beforeUnload)
        onBeforeRouteLeave(async () => !modalState.value || (!btnState.value && await canDiscard()))
        onBeforeUnmount(() => { window.removeEventListener('beforeunload', beforeUnload); answerDiscard(false); editSequence++; previewSequence++ })
        return {
            t, rule, account, form, formRef, dataRef, listLoading, columns, modalState, editLoading, editError, listError, btnState,
            selectedKey, selectedIndex, selectedItem, dirty, search, collapsed, groups, issues, saveError, operationError, detailsPanel, errorSummary,
            previewOpen, previewLoading, previewError, previewHtml, previewWidth, optionSources, optionSourcesLoading, optionSourcesError,
            dragKey, dropKey, moveOpen, moveBeforeKey, moveOptions, itemTitle, itemIcon, issueCount, issueText, friendlyTypeLabel,
            openForm, requestClose, resetForm, addField, selectField, duplicateSelected, removeSelected, openMove, applyMove,
            toggleGroup, startDrag, endDrag, dropBefore, showIssue, previewForm, submitForm, editForm, delForm, reloadList, formFailed, loadOptionSources,
            discardOpen, answerDiscard,
        }
    },
})
</script>
