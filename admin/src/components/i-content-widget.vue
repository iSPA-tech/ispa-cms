<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form :model="innerObject" layout="vertical">
        {{ innerObject }}
        <div v-show="!isFrameExpand" class="grid grid-cols-3 gap-y-4 md:gap-y-0 md:gap-x-4">
            <div class="col-span-3 md:col-span-1">
                <select-input-widget
                    v-model="innerObject.widget_id"
                    name="widget_id"
                    :label="$t('nav.a.widgets')"
                    @change="getTemplate(innerObject.widget_id, true)"
                />
            </div>
            <div class="col-span-3 md:col-span-1">
                <a-form-item name="template_id" :label="$t('validation.attributes.widget_template')">
                    <a-select
                        v-model:value="innerObject.template_id"
                        :options="
                            (innerWidgetData.template || []).map((item) => ({
                                label: designLabel(item),
                                value: item.id,
                            }))
                        "
                        @change="(value) => resetInputsByTemplate(value, true)"
                    />
                </a-form-item>
            </div>
        </div>
        <div
            v-show="!isFrameExpand"
            class="border border-rose-900/35 rounded-sm px-3 pb-3 pt-4 relative"
        >
            <span class="absolute -top-3 left-4 bg-white px-2 shadow-xs text-rose-600">
                {{ $t('text.widget.inputs') }}
            </span>
            <div
                v-if="innerWidgetData.inputs && innerWidgetData.inputs.length > 0"
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4"
            >
                <div
                    v-for="(item, ind) in innerWidgetData.inputs"
                    :key="ind"
                    class="col-span-1"
                    :class="{
                        'md:col-span-2 lg:col-span-3': ['textarea', 'image', 'upload'].includes(
                            item.type
                        ),
                        'md:col-span-1 lg:col-span-2': !['textarea', 'image', 'upload'].includes(
                            item.type
                        ),
                    }"
                >
                    <template v-if="item.type === 'upload'">
                        <div>
                            <label for="" class="pr-4">{{ `{${item.name.toUpperCase()}}` }}</label>
                            <media-selection
                                v-if="item.value"
                                v-model="item.value"
                                :limit="1"
                                @change="(value) => updateImage(value, item.name)"
                                type="all"
                            />
                        </div>
                    </template>
                    <template v-else-if="item.type === 'image'">
                        <div v-if="item.options.src === 'unknown'">
                            <label for="" class="pr-4">{{ `{${item.name.toUpperCase()}}` }}</label>
                            <media-selection
                                v-if="item.value"
                                v-model="item.value"
                                :limit="1"
                                @change="(value) => updateImage(value, item.name)"
                                type="all"
                            />
                        </div>
                        <a-form-item v-else :name="['inputs', ind, 'value']" no-style size="small">
                            <a-input
                                v-model:value="item.value"
                                :addon-before="item.name"
                                @change="
                                    (value) => updateFieldDebounce(value.target.value, item.name)
                                "
                            />
                        </a-form-item>
                    </template>
                    <template v-else-if="item.type === 'select'">
                        <a-form-item
                            :name="['inputs', ind, 'value']"
                            :label="`{${item.name.toUpperCase()}}`"
                            size="small"
                        >
                            <a-select
                                v-model:value="item.value"
                                :options="item.options || []"
                                size="small"
                                @change="
                                    (value) => updateFieldDebounce(value.target.value, item.name)
                                "
                            />
                        </a-form-item>
                    </template>
                    <template v-else-if="item.type === 'checkbox'">
                        <a-form-item
                            :name="['inputs', ind, 'value']"
                            :label="`{${item.name.toUpperCase()}}`"
                            size="small"
                        >
                            <a-checkbox-group
                                v-model:value="item.value"
                                :options="item.options || []"
                                multiple
                                size="small"
                                @change="
                                    (value) => updateFieldDebounce(value.target.value, item.name)
                                "
                            />
                        </a-form-item>
                    </template>
                    <template v-else-if="item.type === 'radio'">
                        <a-form-item
                            :name="['inputs', ind, 'value']"
                            :label="`{${item.name.toUpperCase()}}`"
                            size="small"
                        >
                            <a-radio-group
                                v-model:value="item.value"
                                :options="item.options || []"
                                size="small"
                                @change="
                                    (value) => updateFieldDebounce(value.target.value, item.name)
                                "
                            />
                        </a-form-item>
                    </template>
                    <template v-else-if="item.type === 'query'">
                        <a-form-item :name="['inputs', ind, 'value']" no-style size="small">
                            <a-input
                                v-model:value="item.value"
                                :addon-before="`{${item.name.toUpperCase()}}`"
                                type="number"
                                @change="
                                    (value) => updateFieldDebounce(value.target.value, item.name)
                                "
                            />
                        </a-form-item>
                    </template>
                    <template v-else-if="item.type === 'textarea'">
                        <i-content-textarea-editor
                            :label="`{${item.name.toUpperCase()}}`"
                            v-model="item.value"
                            @change="(value) => updateFieldDebounce(value, item.name)"
                        />
                    </template>
                    <template v-else>
                        <a-form-item :name="['inputs', ind, 'value']" no-style size="small">
                            <a-input
                                v-model:value="item.value"
                                :addon-before="`{${item.name.toUpperCase()}}`"
                                @change="
                                    (value) => updateFieldDebounce(value.target.value, item.name)
                                "
                            />
                        </a-form-item>
                    </template>
                </div>
            </div>
        </div>
        <div class="relative bg-white p-4 mt-4 border border-cyan-900/35 border-dashed rounded-sm">
            <span class="absolute -top-3 left-4 bg-white px-2 text-cyan-600">
                {{ $t('validation.attributes.widget_template_preview') }}
            </span>
            <span
                class="absolute top-0 right-4 z-10 bg-cyan-600 text-white text-sm rounded-xs px-3 py-1 cursor-pointer"
                size="small"
                @click="isFrameExpand = !isFrameExpand"
            >
                {{ isFrameExpand ? $t('nav.b.update') : $t('nav.b.preview') }}
            </span>
            <a-spin :spinning="innerLoading">
                <div class="bg-gray-100 p-4 rounded shadow-sm border border-gray-300">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-gray-700">
                            {{ t('text.widget.responsive_test_size') }}
                        </span>
                        <a-select v-model:value="frameSize" :options="previewSizeOptions" />
                    </div>

                    <div class="w-full overflow-scroll">
                        <div
                            class="mx-auto bg-white border rounded shadow-md"
                            :style="{ width: frameSize }"
                        >
                            <iframe
                                :srcdoc="innerPreview"
                                class="w-full border h-[500px] shadow-inner rounded-sm"
                            />
                        </div>
                    </div>
                </div>
                <div
                    v-show="!isFrameExpand"
                    class="mt-4 text-xs border-dashed border-t pt-3 rounded-sm relative overflow-hidden"
                    :class="isExpand ? '' : 'max-h-[84px]'"
                >
                    <div>
                        {{ innerPreview }}
                    </div>
                    <span
                        class="absolute bottom-1 left-1/2 -translate-x-1/2 transform z-10 btn-success px-2 py-1 cursor-pointer"
                        size="small"
                        @click="isExpand = !isExpand"
                    >
                        {{ isExpand ? $t('web.a.collapse') : $t('text.product.more') }}
                    </span>
                </div>
            </a-spin>
        </div>
    </a-form>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { getInputByWidget, getTemplateByWidget, getWidgetPreview } from '@/libs'
import { designLabel } from '@/libs/widget-names'
import { getInputVariableByTemplate, iAlert } from '@/mixins'
import { debounce, isEmpty } from 'lodash-es'
import IContentTextAreaEditor from '@/components/i-content-textarea-editor.vue'
import { useI18n } from 'vue-i18n'

const innerLoading = ref(false)
const frameSize = ref('100%') // Default to full width
const isInternalChange = ref(false)
const { t } = useI18n()

defineComponent({
    name: 'IContentWidget',
    components: {
        'i-content-textarea-editor': IContentTextAreaEditor,
    },
})

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => {
            return { widget_id: null, template_id: null, inputs: [] }
        },
    },
    index: {
        type: Number,
        default: 0,
    },
})
const emits = defineEmits(['update:modelValue', 'change'])

const innerObject = ref({ widget_id: null, template_id: null, inputs: [] })

const innerWidgetData = ref({
    template: [],
    inputs: [],
})
const innerPreview = ref('')
const isExpand = ref(false)
const isFrameExpand = ref(true)
const previewSizeOptions = computed(() => [
    { label: t('text.widget.preview_size.small_mobile'), value: '375px' },
    { label: t('text.widget.preview_size.mobile'), value: '768px' },
    { label: t('text.widget.preview_size.tablet_desktop'), value: '1024px' },
    { label: t('text.widget.preview_size.full_width'), value: '100%' },
    { label: t('text.widget.preview_size.wide_screen'), value: '1440px' },
    { label: t('text.widget.preview_size.wider_screen'), value: '1560px' },
])

const getTemplate = async (id = 0, reload = false) => {
    if (id < 1) {
        return
    }

    // If changed widget, we need to get the template list from api
    if (innerWidgetData.value.template.length < 1 || reload) {
        await getTemplateByWidget(id)
            .then((res) => {
                if (res.status === 'success') {
                    innerWidgetData.value.template = res.data
                    //  if reload (changed widget), we need to set the template_id to the first template id
                    if (reload) {
                        innerObject.value.template_id = res.data[0].id || null
                    }
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
    }
    if (innerWidgetData.value.inputs.length < 1 || reload) {
        await getInputByWidget(id)
            .then((res) => {
                if (res.status === 'success') {
                    const responseData = res.data
                    responseData.map((item) => {
                        if (['image', 'checkbox'].includes(item.type)) {
                            item.value = []
                        } else {
                            item.value = item.default_value || ''
                        }
                    })
                    //  Assign responseData to innerWidgetData.inputs
                    innerWidgetData.value.inputs = [...responseData]
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
    }

    updateModelField(id, 'widget_id')

    resetInputsByTemplate(reload)
}

const resetInputsByTemplate = (template_id, reload = false) => {
    const templateInputs = getInputVariableByTemplate(
        innerWidgetData.value.inputs,
        innerWidgetData.value.template.filter((item) => item.id === template_id)
    )

    if (reload) {
        replaceInputs(innerObject.value, templateInputs)
        updateModelField(template_id, 'template_id')
    } else {
        mergeInputs(innerObject.value, templateInputs) // if needed for cases without widget reload
    }
}

const replaceInputs = (modelValue = {}, templateInputs = []) => {
    const currentInputsMap = new Map((modelValue.inputs || []).map((i) => [i.name, i]))

    const newInputs = templateInputs.map((tplInput) => {
        const existing = currentInputsMap.get(tplInput.name)

        return {
            ...tplInput,
            value:
                existing?.value ??
                (['image', 'upload', 'checkbox', 'radio', 'select'].includes(tplInput.type)
                    ? []
                    : ''),
        }
    })

    // Optional: Sort alphabetically, push images and uploads to the end
    newInputs.sort((a, b) => {
        const isAHeavy = ['image', 'upload', 'textarea'].includes(a.type)
        const isBHeavy = ['image', 'upload', 'textarea'].includes(b.type)
        if (isAHeavy && !isBHeavy) return 1
        if (!isAHeavy && isBHeavy) return -1
        return a.name.localeCompare(b.name)
    })

    modelValue.inputs = newInputs
    innerObject.value = { ...modelValue }
    innerWidgetData.value.inputs = newInputs

    fetchWidgetDebounce()
}

const getPreview = () => {
    innerLoading.value = true
    //  Call ajax to get preview by widget id and template id, with user input, preview will return html rendered, we will show it in preview
    const params = {
        template_id: innerObject.value.template_id,
    }

    innerWidgetData.value.inputs.map((item) => {
        if (item.type === 'view') {
            params[item.name] = {
                ...item.component,
            }
        } else if (item.type === 'upload') {
            if (!item.value || !item.value.length) {
                return
            }
            params[item.name] = {
                url: item.value[0].url || null,
                is_background: item.options[0] && item.options[0].is_background,
                id: item.value[0].id || null,
                name: item.value[0].name || null,
                path: `/storage/${item.value[0].path}/${item.value[0].name}`,
            }
        } else {
            params[item.name] = item.value
        }
    })

    getWidgetPreview(innerObject.value.widget_id, params)
        .then((res) => {
            innerPreview.value = res
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            innerLoading.value = false
        })
}

const fetchWidgetDebounce = debounce(getPreview, 500)

const syncModel = () => {
    isInternalChange.value = true

    emits('update:modelValue', { ...innerObject.value })
    emits('change', { ...innerObject.value })
}

const updateImage = (val = {}, name = '') => {
    let index = innerWidgetData.value.inputs.findIndex((item) => item.name === name)

    if (index >= 0) {
        // Update the input value
        innerWidgetData.value.inputs[index] = {
            ...innerWidgetData.value.inputs[index],
            value: val,
        }

        // Sync back to modelValue to ensure reactivity
        innerObject.value.inputs = [...innerWidgetData.value.inputs]
        innerObject.value = { ...innerObject.value } // Trigger reactivity
    } else {
        const item = innerWidgetData.value.inputs.find((item) => item.name === name)

        // Add new input if not found
        innerWidgetData.value.inputs.push({
            ...item,
            value: val,
        })

        // Sync back to modelValue to ensure reactivity
        innerObject.value.inputs = [...innerWidgetData.value.inputs]
        innerObject.value = { ...innerObject.value } // Trigger reactivity
    }

    fetchWidgetDebounce()
}

// const mergeInputs = (modelValue = {}, inputsList = []) => {
//     const currentInputs = modelValue?.inputs || []
//
//     // Step 1: Map existing content inputs for fast lookup
//     const inputMap = new Map(currentInputs.map((inp) => [inp.name, inp]))
//
//     // Step 2: Create the updated input list based on widget inputs
//     const updatedInputs = inputsList.map((input) => {
//         const matchedInput = inputMap.get(input.name)
//         if (matchedInput) {
//             // If input exists, retain its value
//             return { ...input, value: matchedInput.value }
//         } else {
//             // New input from widget, use default or empty value
//             return { ...input, value: input.value || '' }
//         }
//     })
//
//     // Step 3: Add any remaining content inputs not present in the widget inputs
//     currentInputs.forEach((contentInput) => {
//         if (!updatedInputs.find((inp) => inp.name === contentInput.name)) {
//             updatedInputs.push(contentInput)
//         }
//     })
//
//     // Step 4: Update the content inputs while maintaining the correct order
//     innerWidgetData.value.inputs = updatedInputs
//
//     // Step 5: Sync back to modelValue to ensure reactivity
//     modelValue.inputs = updatedInputs
//     innerObject.value = modelValue
//
//     fetchWidgetDebounce()
// }
const mergeInputs = (modelValue = {}, filteredInputs = []) => {
    const filteredInputsName = new Map(filteredInputs.map((item) => [item.name, item]))
    const currentInputs = modelValue?.inputs || []

    const updatedInputs = currentInputs.filter((item) => filteredInputsName.has(item.name))

    updatedInputs.map((item) => {
        const templateInput = filteredInputs.find((i) => i.name === item.name)

        if (templateInput) {
            item.type = templateInput.type
        }
        //  Check if item type === image and empty value, make value empty array
        if (isEmpty(item?.value)) {
            if (['image', 'upload', 'checkbox', 'radio', 'select'].includes(item.type)) {
                item.value = []
            } else {
                item.value = ''
            }
        }

        return item
    })

    // Replace current modelValue.inputs with only the matched ones
    modelValue.inputs = updatedInputs
    //  Sort inputs by name from a to z
    modelValue.inputs.sort((a, b) => a.name.localeCompare(b.name))

    //  Sort input by name, let the image, upload and textarea show last
    modelValue.inputs.sort((a, b) => {
        if (['image', 'upload', 'textarea'].includes(a.type)) {
            return 1
        }
        if (['image', 'upload', 'textarea'].includes(b.type)) {
            return -1
        }
        return a.name.localeCompare(b.name)
    })

    innerObject.value = { ...modelValue }

    // Optionally update innerWidgetData.inputs for view layer
    innerWidgetData.value.inputs = updatedInputs

    fetchWidgetDebounce()
}

const updateModelField = (val = '', name = '') => {
    //  Check if innerObject has name, update it with new value
    if (innerObject.value[name] !== undefined) {
        innerObject.value[name] = val
    }
}

const updateField = (val = '', name = '') => {
    let index = innerWidgetData.value.inputs.findIndex((item) => item.name === name)

    if (index >= 0) {
        // Update the input value
        innerWidgetData.value.inputs[index] = {
            ...innerWidgetData.value.inputs[index],
            value: val,
        }

        // Sync back to modelValue to ensure reactivity
        innerObject.value.inputs = [...innerWidgetData.value.inputs]
        innerObject.value = { ...innerObject.value } // Trigger reactivity
    }

    fetchWidgetDebounce()
}

const updateFieldComponent = (val = '', name = '', component = {}) => {
    let index = innerWidgetData.value.inputs.findIndex((item) => item.name === name)

    if (index >= 0) {
        // Update the input value
        innerWidgetData.value.inputs[index] = {
            ...innerWidgetData.value.inputs[index],
            value: val,
            component: component,
        }

        // Sync back to modelValue to ensure reactivity
        innerObject.value.inputs = [...innerWidgetData.value.inputs]
        innerObject.value = { ...innerObject.value } // Trigger reactivity
    }

    fetchWidgetDebounce()
}

const updateFieldDebounce = debounce(updateField, 500)

watch(
    () => props.modelValue,
    (val, old) => {
        if (isInternalChange.value) {
            isInternalChange.value = false
            return // ⛔ Prevent circular update from triggering logic
        }

        if (val.widget_id === null || val?.widget_id !== old?.widget_id) {
            //  Reset the innerObject to the new index value
            innerObject.value = val
            //  Reset preview and loading state
            innerPreview.value = ''
            innerLoading.value = false
            isExpand.value = false
            isFrameExpand.value = val?.widget_id > 0
            innerWidgetData.value.inputs = []
            innerWidgetData.value.template = []
        } else {
            innerObject.value = val
        }
        //  then load the template and inputs list from api
        if (val.widget_id > 0) {
            getTemplate(val.widget_id)
        }
    },
    {
        immediate: true,
    }
)

// watch(
//     () => innerObject.value,
//     (val) => {
//         emits('update:modelValue', val)
//         emits('change', val)
//     },
//     {
//         immediate: true,
//     }
// )
</script>
