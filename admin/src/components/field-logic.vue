<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div :class="workspace ? 'mb-0' : 'max-w-lg transition transform duration-200 mb-1'">
        <div
            v-if="!workspace"
            data-field-summary
            class="flex items-center p-2 border leading-6 text-sm bg-white cursor-pointer"
            @click="value.isShow = !value.isShow"
        >
            <span>
                <svg
                    class="h-4 w-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                    />
                </svg>
            </span>
            <span class="pl-2 text-ellipsis max-w-full"
                >{{ value.name || $t('text.form_builder.untitled_field') }}
                <i v-if="value.custom.required" class="text-red-500 px-1">*</i>
                <i v-if="value.type" class="text-gray-400">({{ friendlyTypeLabel(value.type) }})</i>
            </span>
            <span
                v-if="!value.isShow"
                class="cursor-pointer text-green-500 hover:text-green-700 flex-1 flex items-center space-x-1"
                role="button"
            >
                <span class="flex-1"></span>
                <span>{{ $t('nav.b.edit') }}</span>
                <span>
                    <svg
                        class="h-4 w-4"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            clip-rule="evenodd"
                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                            fill-rule="evenodd"
                        />
                    </svg>
                </span>
            </span>
        </div>
        <transition name="accordion">
            <div
                v-if="workspace || value.isShow"
                :class="workspace ? 'p-3 bg-white items-form' : 'px-4 pt-2 border-b border-dashed bg-gray-100 items-form'"
            >
                <div :class="workspace ? 'flex flex-col' : ''">
                    <component
                        :is="workspace ? 'details' : 'div'"
                        :data-field-section="workspace ? 'content' : null"
                        :open="workspace || null"
                        :class="workspace ? 'order-1 pb-2' : null"
                    >
                        <summary v-if="workspace" class="cursor-pointer font-medium pb-2">
                            {{ $t('text.form_builder.workspace_content') }}
                        </summary>
                    <div :class="workspace ? 'fw-settings-grid' : null">
                    <a-form-item :label="$t('text.form_builder.item_kind')" :name="[name, index, 'type']">
                        <a-select
                            v-model:value="form[name][index].type"
                            :placeholder="$t('text.select')"
                            :virtual="false"
                            class="w-full"
                        >
                            <a-select-opt-group
                                v-for="group in typeOption"
                                :key="group.text"
                                :label="friendlyGroupLabel(group)"
                            >
                                <a-select-option
                                    v-for="item in group.options"
                                    :key="item.value"
                                    :label="friendlyTypeLabel(item.value)"
                                    :value="item.value"
                                >{{ friendlyTypeLabel(item.value) }}</a-select-option>
                            </a-select-opt-group>
                        </a-select>
                    </a-form-item>

                    <a-form-item
                        :label="$t('text.form_builder.item_name')"
                        :name="[name, index, 'name']"
                    >
                        <a-input
                            v-model:value="form[name][index].name"
                            :placeholder="$t('text.form_builder.item_name')"
                        />
                    </a-form-item>

                    <a-form-item
                        :label="$t('text.form_builder.placeholder')"
                        :name="[name, index, 'custom', 'placeholder']"
                    >
                        <a-input v-model:value="form[name][index].custom.placeholder" />
                    </a-form-item>

                    <a-form-item
                        :label="$t('text.form_builder.guidance')"
                        :name="[name, index, 'description']"
                        :class="workspace ? 'fw-field-span-full' : null"
                    >
                        <a-textarea
                            v-model:value="form[name][index].description"
                            :rows="3"
                        />
                    </a-form-item>
                    </div>
                    </component>

                    <component
                        v-if="['checkbox', 'select', 'radio'].includes(form[name][index].type)"
                        :is="workspace ? 'details' : 'div'"
                        :data-field-section="workspace ? 'choices' : null"
                        :class="workspace ? 'order-2 border-t py-2' : null"
                    >
                        <summary v-if="workspace" class="cursor-pointer font-medium">
                            {{ $t('text.form_builder.workspace_choices') }}
                        </summary>
                        <div :class="workspace ? 'fw-settings-grid' : null">
                        <a-form-item
                            v-if="form[name][index].type === 'radio'"
                            :label="$t('text.form_builder.choice_layout')"
                            :name="[name, index, 'custom', 'choiceLayout']"
                        >
                            <a-select v-model:value="form[name][index].custom.choiceLayout">
                                <a-select-option value="auto">{{ $t('text.form_builder.layout_auto') }}</a-select-option>
                                <a-select-option value="inline">{{ $t('text.form_builder.layout_inline') }}</a-select-option>
                                <a-select-option value="stacked">{{ $t('text.form_builder.layout_stacked') }}</a-select-option>
                            </a-select>
                        </a-form-item>
                        <a-form-item
                            v-if="form[name][index].type === 'select'"
                            :label="$t('text.multiple')"
                            :name="[name, index, 'custom', 'multiple']"
                        >
                            <a-switch
                                v-model:checked="form[name][index].custom.multiple"
                                :checked-children="$t('text.form_builder.yes')"
                                :un-checked-children="$t('text.form_builder.no')"
                                size="small"
                            />
                        </a-form-item>
                        <div data-field-setting="custom.optionsSource">
                            <form-option-source
                                :item="form[name][index]"
                                :earlier-fields="conditionalFields"
                                :sources="optionSources"
                                :sources-loading="optionSourcesLoading"
                                :sources-error="optionSourcesError"
                                @retry-sources="reloadOptionSources"
                            />
                        </div>
                        <div v-if="!form[name][index].custom.optionsSource" class="fw-field-span-full">
                            <span class="font-medium">
                                {{ $t('text.options') }}
                            </span>
                            <div class="flex flex-col w-full">
                                <div class="flex">
                                    <div class="flex-1"></div>
                                    <a-button class="btn-success" size="small" @click="addOption">
                                        {{ $t('nav.b.add') }}
                                        {{ $t('text.option') }}
                                    </a-button>
                                </div>
                                <div
                                    v-for="(e, ind) in form[name][index].options"
                                    v-if="form[name][index].options.length > 0"
                                    :key="ind"
                                    class="flex items-center space-x-1 pt-1 options-list"
                                >
                                    <a-form-item
                                        :name="[name, index, 'options', ind, 'label']"
                                        no-style
                                    >
                                        <a-input
                                            v-model:value="e.label"
                                            :placeholder="$t('text.form_builder.option_label')"
                                            class="m-0"
                                            size="small"
                                        >
                                            <template #addonBefore>
                                                {{ $t('text.form_builder.option_label') }}
                                            </template>
                                        </a-input>
                                    </a-form-item>

                                    <a-button
                                        :disabled="form[name][index].options.length < 3"
                                        danger
                                        size="small"
                                        type="primary"
                                        @click="removeOption(ind)"
                                    >
                                        <i-icon class="size-4" icon="delete" />
                                    </a-button>
                                </div>
                            </div>
                        </div>
                        </div>
                    </component>

                    <component
                        :is="workspace ? 'details' : 'div'"
                        :data-field-section="workspace ? 'visibility' : null"
                        :class="workspace ? 'order-3 border-t py-2' : null"
                    >
                        <summary v-if="workspace" class="cursor-pointer font-medium">
                            {{ $t('text.form_builder.workspace_visibility') }}
                        </summary>
                    <div :class="workspace ? 'fw-settings-grid' : null">
                    <div class="grid gap-x-3 grid-cols-2 pick-color">
                        <a-form-item
                            :label="$t('text.form_builder.required')"
                            :name="[name, index, 'custom', 'required']"
                        >
                            <a-switch
                                v-model:checked="form[name][index].custom.required"
                                :checked-children="$t('text.form_builder.yes')"
                                :un-checked-children="$t('text.form_builder.no')"
                                size="small"
                            />
                        </a-form-item>
                        <a-form-item :label="$t('text.form_builder.visible')" :name="[name, index, 'status']">
                            <a-switch
                                v-model:checked="form[name][index].status"
                                :checked-children="$t('text.form_builder.show')"
                                :un-checked-children="$t('text.form_builder.hide')"
                                size="small"
                            />
                        </a-form-item>
                    </div>

                    <form-responsive-layout
                        v-if="workspace"
                        class="fw-field-span-full rounded border border-gray-200 p-3"
                        :form="form"
                        :item="form[name][index]"
                    />

                    <a-form-item :label="$t('text.form_builder.start_section')">
                        <a-switch :checked="sectionEnabled" :aria-label="$t('text.form_builder.start_section')" @change="setSectionEnabled" />
                    </a-form-item>
                    <a-form-item
                        v-if="sectionEnabled"
                        :label="$t('text.form_builder.section_name')"
                        :name="[name, index, 'custom', 'section']"
                    >
                        <a-input v-model:value="form[name][index].custom.section" />
                    </a-form-item>

                    <div class="grid gap-x-3 grid-cols-2 pick-color">
                        <a-form-item
                            :label="$t('text.form_builder.visible_when')"
                            :name="[name, index, 'custom', 'visibleWhen', 'field']"
                        >
                            <a-select
                                v-model:value="form[name][index].custom.visibleWhen.field"
                                allow-clear
                                :placeholder="$t('text.form_builder.condition_item')"
                                @change="clearVisibleWhenValue"
                            >
                                <a-select-option
                                    v-for="field in conditionalFields"
                                    :key="field.field_name"
                                    :value="field.field_name"
                                >
                                    {{ field.name || $t('text.form_builder.untitled_field') }}
                                </a-select-option>
                            </a-select>
                        </a-form-item>
                        <a-form-item
                            :label="$t('text.form_builder.condition_answer')"
                            :name="[name, index, 'custom', 'visibleWhen', 'value']"
                        >
                            <a-select
                                v-model:value="form[name][index].custom.visibleWhen.value"
                                :disabled="!form[name][index].custom.visibleWhen.field"
                                allow-clear
                                :placeholder="$t('text.form_builder.choose_answer')"
                            >
                                <a-select-option
                                    v-for="option in conditionalOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label || $t('text.form_builder.untitled_option') }}
                                </a-select-option>
                            </a-select>
                        </a-form-item>
                    </div>
                    </div>
                    </component>

                    <component
                        v-if="
                            ['file', 'archived', 'document', 'image'].includes(
                                form[name][index].type
                            )
                        "
                        :is="workspace ? 'details' : 'div'"
                        :data-field-section="workspace ? 'upload' : null"
                        :class="workspace ? 'order-4 border-t py-2' : null"
                    >
                        <summary v-if="workspace" class="cursor-pointer font-medium">
                            {{ $t('text.form_builder.workspace_upload') }}
                        </summary>
                        <div :class="workspace ? 'fw-settings-grid' : null">
                        <a-form-item
                            v-if="form[name][index].type === 'image'"
                            :label="$t('text.ui.field-logic.aspect_ratio')"
                            :name="[name, index, 'custom', 'aspectRatio']"
                        >
                            <a-input v-model:value="form[name][index].custom.aspectRatio" placeholder="3/4" />
                        </a-form-item>
                        <a-form-item
                            :label="$t('text.form_builder.file_types')"
                            :name="[name, index, 'custom', 'allowFileType']"
                        >
                            <a-select
                                :value="selectedFileTypes"
                                mode="multiple"
                                :placeholder="$t('text.form_builder.file_types')"
                                @change="setFileTypes"
                            >
                                <a-select-option value="pdf">PDF</a-select-option>
                                <a-select-option value="jpg">JPG</a-select-option>
                                <a-select-option value="jpeg">JPEG</a-select-option>
                                <a-select-option value="png">PNG</a-select-option>
                                <a-select-option v-for="extension in otherFileTypes" :key="extension" :value="extension">
                                    {{ extension.toUpperCase() }}
                                </a-select-option>
                            </a-select>
                        </a-form-item>
                        <a-form-item
                            :label="$t('text.form_builder.max_file_size')"
                            :name="[name, index, 'custom', 'maxFileSizeMb']"
                        >
                            <a-input-number
                                v-model:value="form[name][index].custom.maxFileSizeMb"
                                :min="1"
                                :max="10"
                                class="w-full"
                            />
                        </a-form-item>
                        </div>
                    </component>
                    <component
                        :is="workspace ? 'details' : 'div'"
                        :data-field-section="workspace ? 'advanced' : null"
                        :class="workspace ? 'order-5 border-t py-2' : null"
                    >
                        <summary v-if="workspace" class="cursor-pointer font-medium">
                            {{ $t('text.form_builder.workspace_advanced') }}
                        </summary>
                    <div :class="workspace ? 'fw-additional-groups' : null">
                        <component :is="workspace ? 'section' : 'details'" :class="workspace ? 'fw-additional-card' : 'mb-4'" :data-additional-group="workspace ? 'language' : null">
                            <h4 v-if="workspace"><i-icon icon="translate" class="size-4" /> {{ $t('text.form_builder.additional_language') }}</h4>
                            <summary v-else class="cursor-pointer">{{ $t('text.form_builder.advanced_translations') }}</summary>
                            <p v-if="workspace" class="fw-additional-help">{{ $t('text.form_builder.additional_language_help') }}</p>
                            <div :class="workspace ? 'fw-settings-grid' : null">
                                <select-input-translate v-model="form[name][index].name_trans_key"
                                    :label="$t('text.form_builder.translated_label')"
                                    :name="workspace ? [name, index, 'name_trans_key'] : 'name_trans_key'" />
                                <select-input-translate v-model="form[name][index].phd_trans_key"
                                    :label="$t('text.form_builder.translated_placeholder')"
                                    :name="workspace ? [name, index, 'phd_trans_key'] : 'phd_trans_key'" />
                            </div>
                        </component>

                        <component v-if="['text', 'number', 'email', 'password'].includes(form[name][index].type)"
                            :is="workspace ? 'section' : 'details'" :class="workspace ? 'fw-additional-card' : 'mb-4'" :data-additional-group="workspace ? 'appearance' : null">
                            <h4 v-if="workspace"><i-icon icon="palette-outline" class="size-4" /> {{ $t('text.form_builder.additional_appearance') }}</h4>
                            <summary v-else class="cursor-pointer">{{ $t('text.form_builder.advanced_template') }}</summary>
                            <p v-if="workspace" class="fw-additional-help">{{ $t('text.form_builder.additional_appearance_help') }}</p>
                            <div :class="workspace ? 'fw-settings-grid' : 'grid gap-x-3 grid-cols-2 pick-color'">
                                <a-form-item :label="$t('text.menu-icon')" :name="[name, index, 'custom', 'icon']">
                                    <a-input v-model:value="form[name][index].custom.icon" :placeholder="$t('text.menu-icon')" />
                                </a-form-item>
                                <a-form-item :label="$t('text.menu-color')" :name="[name, index, 'custom', 'color']" class="color-picker">
                                    <a-input v-model:value="form[name][index].custom.color" :placeholder="$t('text.menu-color')" />
                                </a-form-item>
                                <a-form-item :label="workspace ? $t('text.form_builder.additional_icon_position') : undefined"
                                    :name="[name, index, 'custom', 'iconAppend']" :no-style="!workspace" class="fw-field-span-full">
                                    <a-radio-group v-model:value="form[name][index].custom.iconAppend">
                                        <a-radio :value="false">{{ $t('text.appendFalse') }}</a-radio>
                                        <a-radio :value="true">{{ $t('text.appendTrue') }}</a-radio>
                                    </a-radio-group>
                                </a-form-item>
                            </div>
                        </component>

                        <section v-if="form[name][index].type === 'number'" :class="workspace ? 'fw-additional-card' : null" :data-additional-group="workspace ? 'number' : null">
                            <h4 v-if="workspace"><i-icon icon="numeric" class="size-4" /> {{ $t('text.form_builder.additional_number') }}</h4>
                            <div :class="workspace ? 'fw-settings-grid' : null">
                                <a-form-item :label="$t('text.defaultValue')" :name="[name, index, 'custom', 'defaultValue']">
                                    <a-input v-model:value="form[name][index].custom.defaultValue" :placeholder="$t('text.defaultValue')" />
                                </a-form-item>
                                <a-form-item :label="$t('text.minValue')" :name="[name, index, 'custom', 'minValue']">
                                    <a-input v-model:value="form[name][index].custom.minValue" :placeholder="$t('text.minValue')" type="number" />
                                </a-form-item>
                                <a-form-item :label="$t('text.maxValue')" :name="[name, index, 'custom', 'maxValue']">
                                    <a-input v-model:value="form[name][index].custom.maxValue" :placeholder="$t('text.maxValue')" type="number" />
                                </a-form-item>
                                <a-form-item :label="$t('text.stepValue')" :name="[name, index, 'custom', 'stepValue']">
                                    <a-input v-model:value="form[name][index].custom.stepValue" :placeholder="$t('text.stepValue')" type="number">
                                        <template #suffix><a-tooltip :title="$t('text.e.stepValue')"><i-icon class="size-4" icon="help-circle-outline" /></a-tooltip></template>
                                    </a-input>
                                </a-form-item>
                            </div>
                        </section>

                        <section v-if="['textarea', 'editor'].includes(form[name][index].type)" :class="workspace ? 'fw-additional-card' : null" :data-additional-group="workspace ? 'input' : null">
                            <h4 v-if="workspace"><i-icon icon="text-box-outline" class="size-4" /> {{ $t('text.form_builder.additional_input') }}</h4>
                            <div :class="workspace ? 'fw-settings-grid' : null">
                                <a-form-item v-if="form[name][index].type === 'textarea'" :label="$t('text.textareaRows')" :name="[name, index, 'custom', 'rows']">
                                    <a-input v-model:value="form[name][index].custom.rows" :max="30" :min="2" :placeholder="$t('text.textareaRows')" type="number" />
                                </a-form-item>
                                <a-form-item v-else :label="$t('text.toolbar')" :name="[name, index, 'custom', 'toolbar']">
                                    <a-select v-model:value="form[name][index].custom.toolbar" :placeholder="$t('text.toolbar')" class="w-full">
                                        <a-select-option :label="$t('text.toolbarFalse')" :value="false" />
                                        <a-select-option :label="$t('text.toolbarTrue')" :value="true" />
                                    </a-select>
                                </a-form-item>
                            </div>
                        </section>
                    </div>
                    </component>

                </div>

                <div v-if="!workspace" data-field-footer class="flex text-sm space-x-3 pb-1.5 pt-2">
                    <!--					<el-popconfirm :title="$t('text.del-confirm')" @confirm="deleteItem(index)">-->
                    <!--						<template #reference>-->
                    <!--							<span class="cursor-pointer text-rose-500">-->
                    <!--								<i-icon class="size-4" icon="delete" /> {{ $t('nav.b.delete') }}-->
                    <!--							</span>-->
                    <!--						</template>-->
                    <!--					</el-popconfirm>-->

                    <span
                        class="text-gray-500 hover:text-gray-800 cursor-pointer flex items-center flex-1"
                        role="button"
                        @click="value.isShow = !value.isShow"
                    >
                        <span class="flex-1"></span>
                        <span class="mr-1">{{ $t('nav.b.hide') }}</span>
                        <svg
                            class="h-4 w-4"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                clip-rule="evenodd"
                                d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z"
                                fill-rule="evenodd"
                            />
                        </svg>
                    </span>
                </div>
            </div>
        </transition>
    </div>
</template>

<script>
import { computed, defineComponent, inject, ref, watch } from 'vue'
import { useTranslates } from '@/hooks'
import FormOptionSource from '@/components/form-option-source.vue'
import FormResponsiveLayout from '@/components/form-responsive-layout.vue'

export default defineComponent({
    name: 'FieldLogic',
    components: { FormOptionSource, FormResponsiveLayout },
    methods: {
        friendlyTypeLabel(type) {
            const known = ['text', 'email', 'tel', 'date', 'select', 'radio', 'checkbox', 'textarea', 'file', 'document', 'image', 'number', 'password', 'datetime', 'editor', 'button', 'reset', 'submit', 'color', 'search', 'range', 'time', 'month', 'week', 'url']
            return this.$t(`text.form_builder.type_${known.includes(type) ? type : 'other'}`)
        },
        friendlyGroupLabel(group) {
            const types = group.options.map((option) => option.value)
            const groupKey = types.includes('file') ? 'group_files' : types.includes('button') ? 'type_other' : 'group_information'
            return this.$t(`text.form_builder.${groupKey}`)
        },
    },
    props: {
        workspace: { type: Boolean, default: false },
        name: { type: String, default: null },
        index: { type: [String, Number], default: 0 },
        type: { type: String, default: null },
        value: {
            type: Object,
            default: () => {
                return { options: [] }
            },
        },
        form: {
            type: Object,
            default: () => {},
        },
    },
    emits: ['update:value', 'change'],
    setup(props) {
        const { data: translate } = useTranslates()
        const fieldState = ref(false)

        const form = ref(props.form)
        const typeOption = inject('typeOption')
        const nameHandle = inject('nameHandle')
        const newFormKey = inject('newFormKey')
        const optionSources = inject('optionSources', ref([]))
        const optionSourcesLoading = inject('optionSourcesLoading', ref(false))
        const optionSourcesError = inject('optionSourcesError', ref(false))
        const reloadOptionSources = inject('reloadOptionSources', () => {})

        const conditionalFields = computed(() =>
            (form.value[props.name] || []).filter(
                (item, itemIndex) =>
                    itemIndex < props.index &&
                    ['select', 'radio'].includes(item.type) &&
                    item.field_name
            )
        )

        const conditionalOptions = computed(() => {
            const visibleWhen = form.value[props.name][props.index].custom.visibleWhen
            return (
                conditionalFields.value.find((item) => item.field_name === visibleWhen.field)
                    ?.options || []
            )
        })

        const clearVisibleWhenValue = () => {
            form.value[props.name][props.index].custom.visibleWhen.value = null
        }

        const custom = computed(() => form.value[props.name][props.index].custom)
        const sectionEnabled = ref(Boolean(custom.value.section))
        watch(() => props.value, (item) => {
            sectionEnabled.value = Boolean(item.custom?.section)
        })
        const setSectionEnabled = (enabled) => {
            sectionEnabled.value = enabled
            if (!enabled) custom.value.section = null
        }
        const knownFileTypes = ['pdf', 'jpg', 'jpeg', 'png']
        const fileTypes = computed(() => (custom.value.allowFileType || '').split(',').map((type) => type.trim()).filter(Boolean))
        const selectedFileTypes = computed(() => fileTypes.value.map((type) => type.toLowerCase()))
        const otherFileTypes = computed(() => selectedFileTypes.value.filter((type) => !knownFileTypes.includes(type)))
        const setFileTypes = (types) => {
            custom.value.allowFileType = types.join(',') || null
        }

        /** Add new option */
        const addOption = () => {
            form.value[props.name][props.index].options.push({
                label: null,
                value: newFormKey('option'),
            })
        }

        /** Delete an option */
        const removeOption = (index) => {
            form.value[props.name][props.index].options.splice(index, 1)
        }

        watch(
            () => form.value[props.name][props.index].options,
            (val) => {
                if (
                    ['select', 'radio', 'checkbox'].includes(
                        form.value[props.name][props.index].type
                    )
                ) {
                    fieldState.value = val.length < 2
                }
            }
        )

        return {
            form,
            typeOption,
            addOption,
            removeOption,
            translate,
            nameHandle,
            fieldState,
            conditionalFields,
            conditionalOptions,
            clearVisibleWhenValue,
            sectionEnabled,
            setSectionEnabled,
            selectedFileTypes,
            otherFileTypes,
            setFileTypes,
            optionSources,
            optionSourcesLoading,
            optionSourcesError,
            reloadOptionSources,
        }
    },
})
</script>
