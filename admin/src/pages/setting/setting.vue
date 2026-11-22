<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="xl:flex-1 w-full">
        <breadcrumbs :items="breadcrumbItems">
            <button
                v-if="showCreateButton"
                class="ml-auto text-ink rounded-sm text-xs bg-surface-sunken hover:bg-surface-hover transition-all px-2 py-1.5 inline-flex items-center space-x-1"
                @click="openCreateModal"
            >
                <i-icon class="size-4" icon="plus-thick" />
                {{ $t('nav.b.create') }} {{ $t('nav.a.settings') }}
            </button>
        </breadcrumbs>

        <div class="py-4">
            <router-view />
        </div>
    </div>

    <a-modal
        v-model:open="modalState"
        :title="t(form.id > 0 ? 'setting.form.update_title' : 'setting.form.create_title')"
        wrapClassName="setting-modal"
        @afterClose="resetForm"
        @cancel="closeModal"
    >
        <div class="max-h-full">
            <a-form
                id="settingForm"
                ref="formRef"
                :model="form"
                layout="vertical"
                @finish="submitForm"
                @finish-failed="finishFailed"
            >
                <div class="grid grid-cols-1 md:grid-cols-2 gap-y-3 gap-x-0 md:gap-x-3 md:gap-y-0">
                    <a-form-item :label="$t('validation.attributes.type')" name="type">
                        <a-select v-model:value="form.type" :placeholder="$t('text.select')">
                            <a-select-opt-group
                                v-for="group in typeOption"
                                :key="group.text"
                                :label="$t(group.text)"
                            >
                                <a-select-option
                                    v-for="item in group.options"
                                    :key="item.value"
                                    :label="item.label"
                                    :value="item.value"
                                />
                            </a-select-opt-group>
                        </a-select>
                    </a-form-item>
                    <a-form-item :label="$t('validation.attributes.group_name')" name="group_name">
                        <a-select v-model:value="form.group_name" :placeholder="$t('text.select')">
                            <a-select-option
                                v-for="type in [
                                    'ads',
                                    'advanced',
                                    'api',
                                    'basic',
                                    'cache',
                                    'chat',
                                    'contact',
                                    'email',
                                    'html',
                                    'image',
                                    'notify',
                                    'seo',
                                    'social',
                                    'tracking',
                                    'upload',
                                ]"
                                :key="type"
                                :label="type"
                                :value="type"
                            />
                        </a-select>
                    </a-form-item>
                    <a-form-item
                        :label="$t('text.setting_name')"
                        :rules="[
                            {
                                required: true,
                                trigger: 'blur',
                                message: $t('validation.required', { a: $t('text.setting_name') }),
                            },
                        ]"
                        name="name"
                    >
                        <a-input v-model:value="form.name" :placeholder="$t('text.setting_name')" />
                    </a-form-item>
                    <a-form-item :label="$t('validation.attributes.value')" name="value">
                        <a-input
                            v-model:value="form.value"
                            :disabled="form.id > 0"
                            :placeholder="$t('validation.attributes.value')"
                        />
                    </a-form-item>

                    <select-input-translate
                        v-model="form.input_setting.title"
                        :label="$t('validation.attributes.title')"
                        :name="['input_setting', 'title']"
                        is-admin
                        required
                    />

                    <select-input-translate
                        v-model="form.input_setting.description"
                        :label="$t('validation.attributes.description')"
                        :name="['input_setting', 'description']"
                        is-admin
                        required
                    />

                    <a-form-item :label="$t('setting.form.is_env_update')">
                        <a-switch
                            v-model:checked="form.input_setting.isEnv"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('setting.form.visibility')">
                        <a-switch
                            v-model:checked="form.input_setting.isPublic"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                        />
                        {{
                            form.input_setting.isPublic
                                ? $t('setting.form.public_status')
                                : $t('setting.form.private_status')
                        }}
                    </a-form-item>

                    <a-form-item :label="$t('setting.form.required_field')">
                        <a-switch v-model:checked="form.input_setting.required" />
                    </a-form-item>

                    <a-form-item :label="$t('setting.form.multi_language')">
                        <a-switch
                            v-model:checked="form.input_setting.language"
                            :disabled="form.id > 0"
                        />
                        <p class="text-ink-faint text-xs block pt-1">
                            {{ $t('setting.form.multi_language_help') }}
                        </p>
                    </a-form-item>

                    <a-form-item
                        v-if="form.type === 'textarea'"
                        :label="$t('text.textareaRows')"
                        :name="['input_setting', 'rows']"
                    >
                        <a-input
                            v-model:value="form.input_setting.rows"
                            :max="10"
                            :min="1"
                            type="number"
                        />
                    </a-form-item>

                    <a-form-item
                        v-if="form.type === 'editor'"
                        :label="$t('setting.form.editor_type')"
                    >
                        <a-select
                            v-model:value="form.input_setting.editor"
                            :placeholder="$t('text.select')"
                            default-first-option
                        >
                            <a-select-option
                                :label="$t('setting.form.editor.markdown')"
                                value="markdown"
                            ></a-select-option>
                            <a-select-option
                                :label="$t('setting.form.editor.ckeditor')"
                                value="ckeditor"
                            ></a-select-option>
                        </a-select>
                    </a-form-item>

                    <a-form-item
                        :label="$t('text.parent_group')"
                        :name="['input_setting', 'parent_group']"
                    >
                        <a-input
                            v-model:value="form.input_setting.parent_group"
                            :placeholder="$t('setting.form.parent_group_placeholder')"
                        />
                    </a-form-item>
                </div>

                <template v-if="['checkbox', 'select', 'radio'].includes(form.type)">
                    <a-form-item v-if="form.type === 'select'" :label="$t('text.multiple')">
                        <a-switch
                            v-model:checked="form.input_setting.multiple"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                        />
                    </a-form-item>
                    <a-form-item :label="$t('text.options')">
                        <div class="flex flex-col w-full">
                            <div class="flex">
                                <div class="flex-1"></div>
                                <a-button
                                    class="btn-succes"
                                    html-type="button"
                                    size="small"
                                    @click="addOption"
                                >
                                    {{ $t('nav.b.add') }} {{ $t('text.option') }}
                                </a-button>
                            </div>
                            <div
                                v-for="(e, ind) in form.input_setting.options"
                                :key="e[OPTION_ROW_KEY] || ind"
                                class="flex items-center space-x-1 pt-1 options-list"
                            >
                                <a-input
                                    v-model:value="e.value"
                                    :placeholder="$t('validation.attributes.value')"
                                    size="small"
                                >
                                    <template #prepend>
                                        {{ $t('validation.attributes.value') }}
                                    </template>
                                </a-input>
                                <a-input
                                    v-model:value="e.label"
                                    :placeholder="$t('text.e.label')"
                                    class="m-0"
                                    size="small"
                                >
                                    <template #prepend>
                                        {{ $t('text.label') }}
                                    </template>
                                </a-input>
                                <a-button
                                    :disabled="form.input_setting.options.length < 3"
                                    danger
                                    html-type="button"
                                    size="small"
                                    type="primary"
                                    @click="removeOption(ind)"
                                >
                                    <i-icon class="size-4" icon="delete" />
                                </a-button>
                            </div>
                            <p
                                class="text-xs text-ink-faint break-normal mt-1"
                                v-html="$t('text.e.options')"
                            ></p>
                        </div>
                    </a-form-item>
                </template>
            </a-form>
        </div>
        <template #footer>
            <div class="flex flex-nowrap items-center justify-end gap-2">
                <a-button class="inline-flex space-x-1" @click="closeModal">
                    <i-icon class="size-4" icon="close" />
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    :loading="btnState"
                    :disabled="btnState"
                    class="inline-flex space-x-1"
                    danger
                    form="settingForm"
                    html-type="submit"
                    type="primary"
                >
                    <i-icon class="size-4" icon="content-save" />
                    {{ $t(form.id > 0 ? 'nav.b.save' : 'nav.b.create') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { computed, defineComponent, inject, nextTick, onMounted, provide, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useQueryClient } from '@tanstack/vue-query'
import { deepCloneObj, finishFailed, iAlert, objectAssignOnlyByKeyHasValue, rule, strReplace, } from '@/mixins'
import { useI18n } from 'vue-i18n'
import { useSettings } from '@/hooks'
import { storeSetting, updateSetting } from '@/libs'
import ajax from '@/ajax'

defineComponent({
    name: 'Setting',
})

const { data: setting, refetch } = useSettings({})
const queryClient = useQueryClient()
const btnState = ref(false)

const { t } = useI18n()

const route = useRoute()

/**
 * The "create setting" button adds a raw settings row, so it only belongs on the pages that list settings rows
 * (they render setting-items). Everywhere else (hub, tokens, backups, modules...) it would be meaningless.
 */
const createRoutes = [
    'setting-basic',
    'setting-contact',
    'setting-social',
    'setting-email',
    'setting-ads',
    'setting-upload',
    'setting-advanced',
    'setting-cache',
]

const showCreateButton = computed(() => {
    return rule('setting.create') && createRoutes.includes(route.name)
})

const breadcrumbItems = computed(() => {
    return [
        {
            text: t('nav.a.settings'),
            icon: 'cog-sync',
            name: 'setting-hub',
        },
    ]
})

/** Form setting */
const formDefault = () => {
    return {
        id: 0,
        setting_key: null,
        type: 'text',
        group_name: 'basic',
        name: null,
        value: null,
        enumerate_id: 0,
        language_id: 0,
        input_setting: {
            required: false,
            multiple: false,
            min: -1,
            max: -1,
            language: false,
            title: null,
            description: null,
            rows: 3,
            editor: null,
            options: [
                //  {value: xxx, label: xxx}
            ],
            parent_group: null /** This parent group belong to email driver mailer */,
            isEnv: false /** This value is enable to update ENV file */,
            isPublic: true /** This value is public setting or private setting */,
        },
    }
}
const modalState = ref(false)
const form = ref(formDefault())
const formRef = ref(null)

const typeOption = inject('typeOption')
const OPTION_ROW_KEY = '__optionRowKey'
const OPTION_TYPES = ['checkbox', 'select', 'radio']
const createOptionRow = (item = {}) => {
    return {
        value: item?.value ?? null,
        label: item?.label ?? null,
        [OPTION_ROW_KEY]:
            item?.[OPTION_ROW_KEY] || `opt_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
    }
}
const normalizeOptions = (options = []) => {
    const usedValues = new Set()

    return (options || []).reduce((result, option) => {
        const value = String(option?.value ?? '').trim()
        const label = String(option?.label ?? '').trim()

        if (!value || !label) {
            return result
        }

        const normalizedValue = value.toLowerCase()
        if (usedValues.has(normalizedValue)) {
            return result
        }

        usedValues.add(normalizedValue)
        result.push({
            value,
            label,
        })

        return result
    }, [])
}
const validateOptionsStrict = (options = []) => {
    const invalidIndex = (options || []).findIndex((option) => {
        const value = String(option?.value ?? '').trim()
        const label = String(option?.label ?? '').trim()

        return !value || !label
    })

    if (invalidIndex < 0) {
        return true
    }

    iAlert(
        {
            type: 'error',
            message: t('setting.form.option_required', { index: invalidIndex + 1 }),
        },
        true
    )
    return false
}
const resetForm = () => {
    form.value = formDefault()

    nextTick(() => {
        formRef.value?.clearValidate?.()
    })
}
const openCreateModal = () => {
    resetForm()
    modalState.value = true
}
/** Add new option */
const addOption = () => {
    form.value.input_setting.options.push(createOptionRow())
}
/** Delete an option */
const removeOption = (index) => {
    form.value.input_setting.options.splice(index, 1)
}
/** Submit to create/update field */
const submitForm = async (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(deepCloneObj(form.value), data)

    if (btnState.value) {
        return
    }
    btnState.value = true

    value.input_setting.title = strReplace(value.input_setting.title)

    if (OPTION_TYPES.includes(value.type)) {
        if (!validateOptionsStrict(value.input_setting.options)) {
            btnState.value = false
            return
        }
    }

    value.input_setting.options = normalizeOptions(value.input_setting.options)

    try {
        const res = await (value.id > 0 ? updateSetting(value.id, value) : storeSetting(value))

        if (res?.status === 'success') {
            iAlert(res)
            await queryClient.invalidateQueries({ queryKey: ['settings'] })
            await refetch()
            closeModal()
            return
        }

        if (res?.message) {
            iAlert(res)
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        btnState.value = false
    }
}
/** Edit setting */
const editSetting = (id = 0) => {
    const list = (setting.value?.data || []).filter((e) => e.setting_key === id)

    if (list && list.length > 0) {
        form.value = {
            id: list[0].id,
            type: list[0].type,
            group_name: list[0].group_name,
            name: list[0].name,
            input_setting: objectAssignOnlyByKeyHasValue(
                formDefault().input_setting,
                list[0].input_setting
            ),
        }
        form.value.input_setting.options = (form.value.input_setting.options || []).map((option) =>
            createOptionRow(option)
        )
        //  Get the id list
        form.value.list = list.map((e) => {
            return { id: e.id, language_id: e.language_id }
        })

        modalState.value = true
    }
}
provide('editSetting', editSetting)
/** Parse data */
const showList = ref({})
provide('showList', showList)
const filterList = ref([])
provide('filterList', filterList)
const initState = ref(false)
provide('initState', initState)
const updateInitState = (state = false) => {
    initState.value = state
}
provide('updateInitState', updateInitState)
const initForm = (settingList = {}) => {
    const form = {}
    Object.keys(settingList).forEach((groupName) => {
        const group = settingList[groupName]
        Object.keys(group).forEach((parentGroup) => {
            const parent = group[parentGroup]
            Object.keys(parent.languages).forEach((title) => {
                if (!form[title]) {
                    form[title] = {}
                }
                parent.languages[title].items.forEach((item) => {
                    if (!form[title][item.setting_key]) {
                        let itemValue = item.value || null
                        if (
                            item.type === 'image' &&
                            typeof item.value === 'object' &&
                            item.value.id
                        ) {
                            itemValue = [item.value]
                        }
                        form[title][item.setting_key] = itemValue
                    }
                })
            })
            parent.list.forEach((item) => {
                const title = item.input_setting.title.replaceAll('.', '-')
                if (!form[title]) {
                    form[title] = {}
                }
                if (!form[title][item.setting_key]) {
                    let itemValue = item.value || null
                    if (item.type === 'image' && typeof item.value === 'object' && item.value.id) {
                        itemValue = [item.value]
                    }
                    form[title][item.setting_key] = itemValue
                }
            })
            nextTick(() => {
                initState.value = true
            })
        })
    })

    return form
}
provide('initForm', initForm)
const parseData = (form, rule) => {
    showList.value = {}
    filterList.value.forEach((e) => {
        const key = e.input_setting.title.replaceAll('.', '-')
        if (!showList.value[e.input_setting.title]) {
            showList.value[e.input_setting.title] = {
                group: e.group_name,
                data: [e],
            }
            rule[key] = {
                type: 'object',
                fields: {},
            }
        } else {
            showList.value[e.input_setting.title].data.push(e)
        }
        if (e.input_setting.required) {
            rule[key].fields[e.setting_key] = {
                required: true,
                trigger: 'blur',
                message: t('validation.required', {
                    a: t(e.input_setting.title),
                }),
            }
        }
        if (!form[key]) {
            form[key] = { type: e.type }
        }
        form[key][e.setting_key] = e.value
    })
}
provide('parseData', parseData)
const filterShowList = (list, group) => {
    const asArray = Object.entries(list)
    const filtered = asArray.filter(([, value]) => value.group === group)

    return Object.fromEntries(filtered)
}
provide('filterShowList', filterShowList)
/** Reload the list by type */
const reLoad = async (form, rule, type = ['basic']) => {
    parseData(form, rule)
}
provide('reLoad', reLoad)

const closeModal = () => {
    modalState.value = false
}

onMounted(() => {
    ajax.get('dashboard/server')
})
</script>
