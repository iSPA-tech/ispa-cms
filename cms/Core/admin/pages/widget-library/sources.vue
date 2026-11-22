<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Design editor of the widget library ("Sửa thiết kế"): the markup, styles, script and input fields of each widget.
    Technical screen for whoever builds widgets. Opened from a library card with `?edit=<widget id>`, which opens that
    widget's form directly. Saving a hub widget outside the authoring environment creates this site's own copy; the
    form says so before the save and a toast confirms it after.
-->
<template>
    <template v-if="rule('widget.viewAny')">
        <div class="mx-auto max-w-7xl space-y-4">
            <breadcrumbs
                :items="[
                    {
                        icon: 'puzzle-outline',
                        text: $t('nav.a.widget-library'),
                        title: $t('nav.a.widget-library'),
                        name: 'widget-library',
                    },
                    {
                        text: $t('text.widget_library.sources_title'),
                        title: $t('text.widget_library.sources_title'),
                    },
                ]"
            >
                <i-button as="router-link" :to="{ name: 'widget-library' }" data-test="back-to-library">
                    <template #icon><i-icon class="size-3.5" icon="arrow-left" /></template>
                    <span>{{ $t('text.widget_library.back_to_library') }}</span>
                </i-button>
                <i-button
                    v-if="rule('widget.create')"
                    variant="primary"
                    native-type="button"
                    data-test="widget-create"
                    @click="modalState = true"
                >
                    <template #icon><i-icon class="size-3.5" icon="plus-thick" /></template>
                    <span>{{ $t('nav.b.create') }} {{ $t('nav.a.widgets') }}</span>
                </i-button>
                <template #description>{{ $t('text.widget_library.sources_intro') }}</template>
            </breadcrumbs>
        </div>

        <div class="mx-auto max-w-7xl px-2 md:px-1 py-4 mb-12">
            <a-table
                :columns="columns"
                :data-source="widgets?.data || []"
                row-key="id"
                size="small"
            >
                <template #headerCell="{ column }">
                    <template v-if="Array.isArray(column.title)">
                        {{ column.title.map((t) => $t(t)).join(' ') }}
                    </template>
                </template>

                <template #bodyCell="{ column: col, record: row, index }">
                    <template v-if="col.key === 'name'">
                        <span class="text-gray-700 font-medium">{{ widgetLabel(row) }}</span>
                        <span v-if="row.has_js" class="text-white bg-green-700 px-1 ml-2">Js</span>
                    </template>
                    <template v-else-if="col.key === 'template'">
                        <span v-for="(item, ind) in row.template" :key="ind">
                            <a-tag>
                                <span>{{ designLabel(item) }}</span>
                                <span
                                    v-if="item.has_css"
                                    class="text-white bg-pink-700 text-xs pr-1 ml-2 rounded-sm"
                                >
                                    Css
                                </span>
                            </a-tag>
                        </span>
                    </template>
                    <template v-else-if="col.key === 'inputs'">
                        <span v-for="(item, ind) in row.inputs" :key="ind">
                            <a-tag>{{ item.name }}</a-tag>
                        </span>
                    </template>
                    <template v-else-if="col.key === 'action'">
                        <div class="flex items-center justify-end gap-x-2">
                            <template v-if="rule('widget.update')">
                                <a-button
                                    :icon="h(EditOutlined)"
                                    class="btn-success plain text-xs"
                                    size="small"
                                    :data-test="`widget-edit-${row.id}`"
                                    @click="editWidget(row.id)"
                                >
                                    {{ $t('nav.b.edit') }}
                                </a-button>
                            </template>
                            <template v-if="rule('widget.delete')">
                                <a-popconfirm
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.delete')"
                                    :title="$t('text.del-confirm')"
                                    ok-type="danger"
                                    @confirm="() => removeWidget(row.id)"
                                >
                                    <a-button
                                        :icon="h(DeleteOutlined)"
                                        class="btn-danger plain text-xs"
                                        size="small"
                                        :data-test="`widget-delete-${row.id}`"
                                    >
                                        {{ $t('nav.b.delete') }}
                                    </a-button>
                                </a-popconfirm>
                            </template>
                        </div>
                    </template>
                </template>
            </a-table>
        </div>
        <a-modal
            v-model:open="modalState"
            :keyboard="false"
            :maskClosable="false"
            :title="`${form.id === 0 ? $t('nav.b.create') : $t('nav.b.edit')} ${$t('nav.a.widgets')}`"
            class="modal-fullscreen"
            width="100%"
            @cancel="resetForm"
        >
            <a-form
                :model="form"
                layout="vertical"
                @finish="submitForm"
                @finish-failed="finishFailed"
                @change="updateTemplate"
                id="widgetForm"
            >
                <div class="px-2 md:px-1 py-4 mb-12 min-h-full">
                    <!-- A hub widget cannot change on this site: saving makes this site's own copy. Said before the save. -->
                    <a-alert
                        v-if="savesAsCopy"
                        class="mb-4"
                        data-test="hub-edit-notice"
                        :description="
                            $t('text.widget_library.hub_impact', {
                                pages: form.usage?.pages ?? 0,
                                areas: form.usage?.areas ?? 0,
                            })
                        "
                        :message="$t('text.widget_library.hub_notice')"
                        show-icon
                        type="info"
                    />
                    <!-- The original name and description, then the same in each admin language (empty = original). -->
                    <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
                        <a-form-item
                            :label="$t('text.widget_library.name_original')"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: $t('text.widget_library.name_original'),
                                    }),
                                },
                            ]"
                            name="name"
                        >
                            <a-input v-model:value="form.name" :placeholder="$t('text.widget_library.name_original')" />
                        </a-form-item>
                        <widget-name-translations v-model="form.name_translations" class="md:col-span-2" />
                    </div>
                    <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
                        <a-form-item
                            :label="$t('text.widget_library.description_original')"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: $t('text.widget_library.description_original'),
                                    }),
                                },
                            ]"
                            name="description"
                        >
                            <a-textarea
                                v-model:value="form.description"
                                :placeholder="$t('text.widget_library.description_original')"
                                :rows="5"
                            />
                        </a-form-item>
                        <widget-name-translations
                            v-model="form.description_translations"
                            class="md:col-span-2"
                            kind="description"
                        />
                    </div>
                    <p class="mb-4 mt-0 break-words text-xs text-slate-600" data-test="names-hint">
                        {{ $t('text.widget_library.names_hint') }}
                    </p>
                    <a-form-item name="js" :label="$t('text.widget.javascript')">
                        <a-textarea
                            v-model:value="form.js"
                            :placeholder="$t('text.widget.javascript')"
                            :rows="5"
                        />
                    </a-form-item>
                    <div class="border bg-gray-50 p-4 rounded-sm space-y-4 relative">
                        <span class="absolute z-10 -top-3 left-4 bg-white px-2 shadow-xs">
                            {{ $t('text.widget.template_count', { count: form.template.length }) }}
                        </span>
                        <widget-template v-model="form.template" @change="updateTemplate" />
                    </div>
                    <div class="border-t mt-6 pt-4 relative">
                        <span class="absolute z-10 -top-3 left-4 bg-white px-2">{{ $t('text.widget.input') }}</span>
                        <widget-input v-model="form.inputs" @change="updateInputs" />
                    </div>
                </div>
            </a-form>
            <template #footer>
                <div class="flex flex-nowrap items-center space-x-2">
                    <div class="flex-1"></div>
                    <a-button :icon="h(CloseCircleOutlined)" data-test="widget-cancel" @click="closeModal">
                        {{ $t('nav.b.cancel') }}
                    </a-button>
                    <a-button
                        :icon="h(SaveOutlined)"
                        form="widgetForm"
                        danger
                        html-type="submit"
                        type="primary"
                        data-test="widget-save"
                        :disabled="disabledButton() || btnState"
                        :loading="btnState"
                    >
                        {{ $t(form.id > 0 ? 'nav.b.save' : 'nav.b.create') }} {{ $t('nav.a.widgets') }}
                    </a-button>
                </div>
            </template>
        </a-modal>
    </template>
    <template v-else>
        <no-permission-error />
    </template>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { useWidgets, widgetInputDefault } from '@/hooks'
import {
    CloseCircleOutlined,
    DeleteOutlined,
    EditOutlined,
    SaveOutlined,
} from '@ant-design/icons-vue'
import { finishFailed, getInputVariableByTemplate, iAlert, rule } from '@/mixins'
import WidgetTemplate from '../../components/widget-source/widget-template.vue'
import WidgetInput from '../../components/widget-source/widget-input.vue'
import WidgetNameTranslations from '../../components/widget-source/widget-name-translations.vue'
import { designLabel, widgetLabel } from '@/libs/widget-names'
import { forgetPlaceableDesigns, forgetWidgetThumbnails, getCatalogStatus } from '../../widget-library-api'
import { capturePreviewsAfterSave } from '../../widget-preview-capture'
import { widgetTemplateDefault } from '@/hooks'
import { buildWidgetSavePayload, deleteWidget, getWidget, storeWidget, updateWidget } from '@/libs'

const { t } = useI18n()

const columns = [
    { key: 'name', title: ['nav.a.widgets'] },
    { key: 'template', title: ['validation.attributes.widget_template'] },
    { key: 'inputs', title: ['text.widget.inputs'] },
    { key: 'action', align: 'right' },
]

defineComponent({
    name: 'WidgetLibrarySources',
})

const { data: widgets, refetch: reload } = useWidgets({})
/** Form */
const modalState = ref(false)
const btnState = ref(false)
//  Snapshot of persisted child ids taken when an edit session loads. Used by
//  buildWidgetSavePayload to report persisted rows the input rebuild dropped,
//  so they cannot become invisible DB orphans while the sweep is disabled.
const persistedChildIdsDefault = () => ({ templateIds: [], inputIds: [] })
const persistedChildIds = ref(persistedChildIdsDefault())
const snapshotPersistedChildIds = (data = {}) => {
    persistedChildIds.value = {
        templateIds: (data.template || []).filter((item) => item?.id > 0).map((item) => item.id),
        inputIds: (data.inputs || []).filter((item) => item?.id > 0).map((item) => item.id),
    }
}
const formDefault = () => {
    return {
        id: 0,
        name: null,
        description: null,
        // `{vi?, en?}` each: the name and description per admin language; null = the original text everywhere.
        name_translations: null,
        description_translations: null,
        js: '',
        inputs: [widgetInputDefault()],
        template: [widgetTemplateDefault()],
    }
}
const form = ref(formDefault())

// Hub widgets are edited in place only where they are authored. Everywhere else a save creates this site's own copy
// and moves every use to it, so the form says so before the operator saves. `null` = not answered yet: the notice
// waits for the answer, so it never flashes where hub widgets are edited in place. A refused read answers "not authoring".
const authoring = ref(null)
const savesAsCopy = computed(() => form.value.id > 0 && form.value.origin === 'hub' && authoring.value === false)
//  Close modal
const closeModal = () => {
    modalState.value = false
    resetForm()
}

const setForm = (val) => {
    resetForm()
    form.value = {
        ...formDefault(),
        ...val,
        template: val.template || [widgetTemplateDefault()],
        inputs: val.inputs || [widgetInputDefault()],
    }

    form.value.inputs = getInputVariableByTemplate(form.value.inputs, form.value.template)
}

const editWidget = async (id) => {
    await getWidget(id, true)
        .then((res) => {
            if (res.status === 'success') {
                //  Snapshot server-truth child ids BEFORE the input rebuild can
                //  drop persisted rows from the working arrays.
                snapshotPersistedChildIds(res.data)
                setForm(res.data)
                form.value.inputs = getInputVariableByTemplate(
                    form.value.inputs,
                    form.value.template
                ) // 🔥 Add this line here
                openModal()
            } else {
                iAlert(res)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

const openModal = () => {
    modalState.value = true
}

const updateInputs = (value = []) => {
    form.value.inputs = value
}

//  Delete a widget. Widgets have no uuid column, so numeric id is the
//  correct identifier per the UUID-first rule's own carve-out.
const removeWidget = (id = 0) => {
    deleteWidget(id)
        .then((res) => {
            if (res.status === 'success') {
                reload()
            } else {
                iAlert(res)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

const submitForm = (value = {}) => {
    if (btnState.value) {
        return
    }

    btnState.value = true
    //  Rows staged for deletion (or dropped by the input rebuild) are excluded
    //  from the payload arrays and reported via deleted_template_ids /
    //  deleted_input_ids, which are omitted entirely when nothing was deleted
    //  (see buildWidgetSavePayload).
    const payload = buildWidgetSavePayload(form.value, persistedChildIds.value)
    const call = form.value.id > 0 ? updateWidget(form.value.id, payload) : storeWidget(payload)
    call.then((res) => {
        if (res.status === 'success') {
            //  A pending sync is still a committed, successful save - surface a
            //  non-blocking notice instead of treating it as a failure.
            if (res.data?.sync_status === 'pending') {
                const incidentId = res.data?.sync_incident_id
                message.warning(
                    t('text.widget.sync_pending') +
                        (incidentId ? ` (#${incidentId})` : '')
                )
            }
            // The server saved a site copy of a hub widget: pages already use it. Shared areas wait for "Xuất bản",
            // which is said only when the widget was in a shared area (the usage read with the form; it is 0 while
            // the Widget module is off).
            if (res.data?.cloned) {
                message.success(
                    t(
                        Number(form.value.usage?.areas ?? 0) > 0
                            ? 'text.widget_library.cloned_toast'
                            : 'text.widget_library.cloned_toast_pages'
                    )
                )
            }
            forgetWidgetThumbnails(form.value.id)
            forgetPlaceableDesigns()
            const picturesFor = siteWidgetSaved(res.data)
            closeModal()
            reload()
            // New pictures of the saved designs, after the save and without waiting: a failed capture never undoes
            // or blocks the save, the card then keeps "Chưa có ảnh mới".
            if (picturesFor > 0) {
                capturePreviewsAfterSave(picturesFor).then(() => forgetWidgetThumbnails(picturesFor))
            }
        } else {
            //  Save is the single commit point for staged deletions - a silent
            //  failure would hide uncommitted destructive intent. Keep the
            //  modal open and surface the backend message.
            iAlert(res)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

// Widget whose designs were just saved on this site and get a new picture: the site copy a save made, a site widget,
// a new widget, or a hub widget where hub widgets are edited in place. 0 = none (a hub widget the save did not copy).
const siteWidgetSaved = (data = {}) => {
    if (data?.cloned?.widget_id > 0) return Number(data.cloned.widget_id)
    const id = form.value.id > 0 ? form.value.id : Number(data?.id ?? 0)
    if (!(id > 0)) return 0
    return form.value.origin === 'hub' && authoring.value !== true ? 0 : id
}

const updateTemplate = () => {
    form.value.inputs = getInputVariableByTemplate(form.value.inputs, form.value.template)
}

const resetForm = () => {
    form.value = formDefault()
    persistedChildIds.value = persistedChildIdsDefault()
}

const disabledButton = () => {
    const validTemplate = form.value.template.every((item) => item.name)
    const validInput = form.value.inputs.every((item) => item.name)

    return !validTemplate || !validInput || !form.value.name || !form.value.description
}

// Gallery deep link (?edit=<widget_id>): open THAT widget's edit modal
// directly instead of landing on the table and hunting for the row.
const route = useRoute()

onMounted(() => {
    getCatalogStatus().then((status) => {
        authoring.value = status.authoring === true
    })
    const editId = Number.parseInt(String(route?.query?.edit ?? ''), 10)
    if (Number.isFinite(editId) && editId > 0 && rule('widget.update')) {
        editWidget(editId)
    }
})
</script>
