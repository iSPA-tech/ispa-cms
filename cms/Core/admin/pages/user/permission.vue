<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                name: 'user-manage',
                title: $t('nav.a.users'),
                icon: 'account-group',
            },
            {
                text: $t('nav.a.permission'),
                icon: 'account-multiple-check',
            },
        ]"
    >
        <button
            class="ml-auto text-gray-800 rounded-sm text-xs bg-blk-200/60 hover:bg-blk-300/80 transition-all px-2 py-1.5 inline-flex items-center space-x-1"
            @click="modalState = true"
        >
            <span><i-icon class="size-4" icon="plus-thick" /></span>
            <span>{{ $t('nav.b.create') }}</span>
        </button>
    </breadcrumbs>

    <div class="i-box">
        <a-table
            :columns="columns"
            :data-source="parseData"
            :pagination="false"
            row-key="id"
            size="small"
        >
            <template #headerCell="{ column }">
                <template v-if="Array.isArray(column.title)">
                    {{ column.title.map((t) => $t(t)).join(' ') }}
                </template>
            </template>

            <template #bodyCell="{ column: col, record: row }">
                <template v-if="col.key === 'group'">
                    <span v-if="permissionGroupLabel(row.group) !== row.group" class="block font-medium">{{ permissionGroupLabel(row.group) }}</span>
                    <code class="text-xs text-gray-500">{{ row.group }}</code>
                </template>
                <template v-else-if="col.key === 'permissions'">
                    <i-badge
                        v-for="(rule, ind) in row.rules"
                        :key="ind"
                        :type="badgeColor(rule.name)"
                        class="mr-1 mb-1"
                        dismiss
                        @click="del(rule.id)"
                    >
                        {{ rule.name }}
                    </i-badge>
                    <template v-for="(rule, ind) in row.addedRules">
                        <i-badge
                            v-if="rule.key"
                            :key="ind"
                            :type="badgeColor(rule.name)"
                            class="mr-1 mb-1"
                            dismiss
                            @click="del(rule.id)"
                        >
                            {{ rule.name }}
                        </i-badge>
                    </template>
                </template>
                <template v-else>
                    <div class="flex justify-end space-x-3">
                        <a-tooltip :content="$t('nav.b.edit')" effect="primary" placement="top">
                            <span
                                class="text-blue-500 cursor-pointer"
                                @click="editRights(row.group)"
                            >
                                <i-icon class="size-4" icon="edit" />
                            </span>
                        </a-tooltip>

                        <template v-if="rule('permission.delete')">
                            <a-popconfirm
                                :title="$t('text.del-confirm')"
                                @confirm="delGroup(row.group)"
                            >
                                <span class="cursor-pointer text-rose-500">
                                    <i-icon class="size-4" icon="delete" />
                                </span>
                            </a-popconfirm>
                        </template>
                    </div>
                </template>
            </template>
        </a-table>

        <a-modal :open="modalState" @cancel="modalState = false" :width="800">
            <template #footer>
                <span class="dialog-footer flex flex-nowrap items-center justify-end space-x-3">
                    <a-button @click="modalState = false">
                        <span><i-icon icon="close" class="size-4" /></span>
                        <span>{{ $t('nav.b.cancel') }}</span>
                    </a-button>
                    <a-button
                        key="submit"
                        class="m-0"
                        :loading="btnState"
                        danger
                        type="primary"
                        form="permissions_form"
                        @click="initBeforeSubmit"
                    >
                        <span><i-icon icon="content-save" class="size-4" /></span>
                        <span>{{ $t('nav.b.save') }}</span>
                    </a-button>
                </span>
            </template>

            <a-form
                id="permissions_form"
                ref="formRef"
                :model="form"
                layout="vertical"
                @finish="initBeforeSubmit"
            >
                <a-form-item :label="$t('validation.attributes.group')" name="group" required>
                    <a-input v-model:value="form.group" :placeholder="$t('validation.attributes.group')"></a-input>
                </a-form-item>
                <div class="grid grid-cols-2 gap-3">
                    <a-form-item
                        v-for="(rule, ind) in permissionPresets"
                        :key="ind"
                        :for="`cb_${ind}`"
                        :name="['ruleSelected', ind]"
                    >
                        <a-checkbox
                            :id="`cb_${ind}`"
                            v-model:checked="ruleSelected[ind]"
                            :value="rule.key"
                        >
                            [
                            <span class="font-bold text-gray-900">
                                {{ rule.key }}
                            </span>
                            ] {{ rule.text }}
                        </a-checkbox>
                    </a-form-item>
                </div>
                <div class="block pb-2">{{ $t('validation.attributes.addedRules') }}</div>
                <div v-for="(rule, ind) in form.addedRules" :key="ind">
                    <div class="flex items-start space-x-2">
                        <a-form-item :name="['addedRules', ind, 'key']">
                            <a-input v-model:value="rule.key" :addon-before="$t('validation.attributes.key')" />
                        </a-form-item>
                        <a-form-item :name="['addedRules', ind, 'text']">
                            <a-input v-model:value="rule.text" :addon-before="$t('text.form_builder.type_text')" />
                        </a-form-item>
                        <a-button v-if="ind === 0" class="btn-success" @click="addNewKeys">
                            <i-icon class="size-4" icon="plus-thick" />
                        </a-button>
                        <!--                    <a-button v-else danger @click="addNewKeys">-->
                        <!--                        <i-icon icon="delete" class="size-4" />-->
                        <!--                    </a-button>-->
                    </div>
                </div>
            </a-form>
        </a-modal>
    </div>
</template>

<script setup>
import { computed, defineComponent, onActivated, onMounted, ref, watch } from 'vue'
import { badgeColor, iAlert, rule } from '@/mixins'
import ajax from '@/ajax'
import { usePermissions } from '@/hooks'
import { useI18n } from 'vue-i18n'
import { permissionResourceLabel } from '@/components/permission-editor/resource-label'

defineComponent({
    name: 'Permission',
})

const { t, te } = useI18n()
const permissionGroupLabel = (group) => permissionResourceLabel(group, t, te)
const { data: dataPermissions, refetch } = usePermissions()
const columns = [
    { key: 'group', title: ['validation.attributes.group'] },
    { key: 'permissions', title: ['text.permission'] },
    { key: 'action', align: 'right' },
]

const parseData = ref([])
const form = ref({
    group: null,
    title: null,
    addedRules: [{ key: null, text: null }],
    rules: [],
})
const formRef = ref(null)

const modalState = ref(false)
const btnState = ref(false)
const ruleSelected = ref([])
const permissionPresets = computed(() => [
    { key: 'viewAny', text: t('text.permission.preset.viewAny') },
    { key: 'create', text: t('text.permission.preset.create') },
    { key: 'view', text: t('text.permission.preset.view') },
    { key: 'edit', text: t('text.permission.preset.edit') },
    { key: 'update', text: t('text.permission.preset.update') },
    { key: 'delete', text: t('text.permission.preset.delete') },
    { key: 'restore', text: t('text.permission.preset.restore') },
    { key: 'forceDelete', text: t('text.permission.preset.forceDelete') },
])

const initBeforeSubmit = () => {
    const ruleParse = []
    ruleSelected.value.forEach((e) => {
        if (!e) {
            return
        }

        const existing = form.value.rules.find((f) => f.key === e)
        if (existing) {
            ruleParse.push(existing)
            return
        }

        const preset = permissionPresets.value.find((f) => f.key === e)
        if (preset) {
            ruleParse.push({
                id: preset.id ?? null,
                key: preset.key,
                name: preset.key,
                title: preset.text,
                text: preset.text,
            })
        }
    })

    form.value.rules = ruleParse

    //  Call submit
    submitForm()
}

const normalizePermissionRecords = (input) => {
    if (Array.isArray(input)) {
        return input
    }

    if (input && Array.isArray(input.data)) {
        return input.data;
    }

    if (Array.isArray(input?.value)) {
        return input.value
    }

    if (input?.data && Array.isArray(input.data.data)) {
        return input.data.data
    }

    return [];
}

const parseRulesByGroup = (list = []) => {
    const records = normalizePermissionRecords(list);
    if (!Array.isArray(records) || !records.length) {
        parseData.value = [];
        return;
    }

    let data = [];

    records.forEach((e) => {
        //  Check if group already in data[]
        let index = data.findIndex((f) => f.group === e.group)
        if (index < 0) {
            //  if not => push this e to first object in data[]
            data.push({ group: e.group, rules: [], addedRules: [] })
            //  Reset index
            index = data.findIndex((f) => f.group === e.group)
        }

        //  Get this value
        const val = checkInRules(e, data[index])
        //  Reset and remove all empty value
        const temAddedRule = []
        val.addedRules.forEach((e) => {
            if (e.key !== null) {
                temAddedRule.push(e)
            }
        })
        temAddedRule.push({ key: null, text: null })
        val.addedRules = temAddedRule
        data.splice(index, 1, val)
    })

    parseData.value = data
}

const checkInRules = (e, val) => {
    const item = permissionPresets.value.find((f) => f.key === e.name)
    if (item && item.key !== null) {
        //  Push this (e) into value[rules]
        val.rules.push({
            id: e.id,
            name: e.name,
            key: e.name,
            title: e.title,
            text: e.title,
        })
    } else {
        //  Push this (e) into value[addedRules]
        val.addedRules.push({
            id: e.id,
            name: e.name,
            key: e.name,
            title: e.title,
            text: e.title,
        })
    }
    return val
}

/** Add new keys and rules */
const addNewKeys = () => {
    form.value.addedRules.push({ key: null, text: null })
}

/** Submit form */
const submitForm = () => {
    formRef.value
        .validateFields()
        .then((values) => {
            if (btnState.value) {
                return
            }
            btnState.value = true
            ajax.post('/permission', form.value)
                .then((res) => {
                    if (res.data.status === 'success') {
                        refetch()
                    }
                    if (res.data.message) {
                        iAlert(res.data)
                    }
                })
                .catch((err) => {
                    iAlert(err, true)
                })
                .finally(() => {
                    modalState.value = false
                    btnState.value = false
                })
        })
        .catch((info) => {
            console.warn('Validate Failed:', info)
        })
}

/** Process to edit rights by group */
const editRights = (val) => {
    const item = parseData.value.find((e) => e.group === val)
    if (item) {
        permissionPresets.value.forEach((e, ind) => {
            const isChecked = item.rules.findIndex((f) => f.name === e.key)
            ruleSelected.value[ind] = isChecked >= 0 ? e.key : null
        })

        //  Set form value
        form.value = JSON.parse(JSON.stringify(item))

        form.value.id = val

        //  Open modal
        modalState.value = true
    } else {
        console.warn('Development Error: cannot find group from list.')
    }
}

const delGroup = (group) => {
    ajax.delete(`permission/delete/${group}`)
        .then((res) => {
            if (res.data.status === 'success') {
                refetch()
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}
const del = (id) => {
    if (id > 0) {
        ajax.delete(`permission/${id}`)
            .then((res) => {
                if (res.data.status === 'success') {
                    refetch()
                }
                if (res.data.message) iAlert(res.data.message)
            })
            .catch((err) => {
                iAlert(err, true)
            })
    }
}

const loadPermissionData = async () => {
    const cached = normalizePermissionRecords(dataPermissions.value)

    if (cached.length > 0) {
        parseRulesByGroup(cached)
        return
    }

    try {
        const response = await refetch()
        parseRulesByGroup(normalizePermissionRecords(response?.data))
    } catch {
        parseData.value = []
    }
}

watch(
    () => dataPermissions.value,
    (records) => {
        parseRulesByGroup(normalizePermissionRecords(records))
    }
)

onMounted(() => {
    loadPermissionData()
})

onActivated(() => {
    loadPermissionData()
})
</script>
