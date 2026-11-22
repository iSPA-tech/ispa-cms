<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="mx-auto max-w-6xl space-y-4">
        <breadcrumbs
            :items="[
                {
                    name: 'user-manage',
                    title: $t('nav.a.users'),
                    icon: 'account-group',
                },
                {
                    text: $t('nav.a.roles'),
                    icon: 'account-supervisor',
                },
            ]"
        >
            <template #description>
                {{ $t('text.role.manage-desc') }}
            </template>

            <i-button
                v-if="can(anyOf(perm('core', 'role', 'create'), perm('role', 'create')))"
                @click="openModal"
            >
                <template #icon>
                    <i-icon class="size-4" icon="plus-thick" />
                </template>

                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
        </breadcrumbs>

        <div class="i-box">
            <a-table
                :columns="columns"
                :data-source="roleList"
                :loading="isTableLoading"
                :locale="tableLocale"
                :pagination="false"
                :scroll="{ x: '100%' }"
                row-key="id"
                size="small"
            >
                <template #headerCell="{ column }">
                    <template v-if="Array.isArray(column.title)">
                        {{ column.title.map((t) => $t(t)).join(' ') }}
                    </template>
                </template>

                <template #bodyCell="{ column: col, record: row }">
                    <template v-if="col.key === 'name'">
                        <i-badge :type="row.custom?.badge || 'default'">{{ row.name }}</i-badge>
                        <span class="block font-bold pt-3">
                            {{ $t('validation.attributes.level') }}: {{ row.custom?.level ?? '—' }}
                        </span>
                    </template>
                    <template v-else-if="col.key === 'permissions'">
                        <PermissionOverview :permissions="row.permissions" />
                    </template>
                    <template v-else>
                        <span class="flex items-center justify-end gap-1">
                            <template
                                v-if="
                                    can(anyOf(perm('core', 'role', 'edit'), perm('role', 'edit')))
                                "
                            >
                                <AppIconAction
                                    :label="$t('nav.b.update')"
                                    icon="pencil"
                                    variant="blue"
                                    @click="editRole(row.id)"
                                />
                                <AppIconAction
                                    :label="`${$t('nav.b.update')} ${$t('nav.a.permission')}`"
                                    icon="shield-key"
                                    variant="green"
                                    @click="openPermissionEditor(row)"
                                />
                            </template>
                            <AppIconAction
                                v-if="rule('data_scope.manage')"
                                :label="$t('text.data_scope.action')"
                                icon="filter-variant"
                                variant="blue"
                                data-testid="role-data-scope-action"
                                @click="openDataScope(row)"
                            />
                            <template
                                v-if="
                                    can(
                                        anyOf(
                                            perm('core', 'role', 'delete'),
                                            perm('role', 'delete')
                                        )
                                    ) && accountData.level <= (row.custom?.level ?? 0)
                                "
                            >
                                <a-popconfirm
                                    :cancel-text="$t('nav.b.cancel')"
                                    :description="$t('text.user.role_delete_desc', { name: row.name })"
                                    :ok-button-props="{ loading: deletingRoleId === row.id }"
                                    :ok-text="$t('nav.b.delete')"
                                    :title="$t('text.del-confirm')"
                                    @confirm="() => handleDeleteRole(row.id)"
                                >
                                    <AppIconAction
                                        :label="$t('nav.b.delete')"
                                        icon="delete"
                                        variant="red"
                                    />
                                </a-popconfirm>
                            </template>
                        </span>
                    </template>
                </template>
            </a-table>
        </div>

        <!-- Role Metadata Modal (simplified - no permissions) -->
        <a-modal
            :footer="false"
            :open="modalState"
            :title="form.id ? t('text.user.role_update_title') : t('text.user.role_create_title')"
            width="500px"
            @cancel="closeModal"
        >
            <a-spin :spinning="modalBusy">
                <a-form ref="formRef" :model="form" layout="vertical">
                    <a-form-item :label="$t('validation.attributes.name')" name="name">
                        <a-input v-model:value="form.name"></a-input>
                    </a-form-item>
                    <div class="grid grid-cols-2 gap-4">
                        <a-form-item :label="$t('text.text-color')" :name="['custom', 'textColor']">
                            <a-input v-model:value="form.custom.textColor"></a-input>
                        </a-form-item>
                        <a-form-item :label="$t('text.badge-type')" :name="['custom', 'badge']">
                            <a-select
                                v-model:value="form.custom.badge"
                                :options="
                                    [
                                        'default',
                                        'secondary',
                                        'success',
                                        'primary',
                                        'warning',
                                        'info',
                                        'danger',
                                    ].map((item) => {
                                        return { label: item, value: item }
                                    })
                                "
                                :placeholder="$t('text.select')"
                            />
                        </a-form-item>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <a-form-item :label="$t('text.icon-class')" :name="['custom', 'icon']">
                            <a-input v-model:value="form.custom.icon" />
                        </a-form-item>
                        <a-form-item :label="$t('text.admin-level')" :name="['custom', 'level']">
                            <a-input
                                v-model:value="form.custom.level"
                                :max="99"
                                :min="accountData.level"
                                type="number"
                            />
                        </a-form-item>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">
                        {{ t('text.e.admin-level') }}
                    </p>
                    <div class="flex items-center justify-end space-x-3">
                        <a-button @click="closeModal">
                            <i-icon class="size-4" icon="close" />
                            <span>{{ t('nav.b.cancel') }}</span>
                        </a-button>
                        <a-button :loading="btnState" type="primary" @click="submit">
                            <i-icon class="size-4" icon="content-save" />
                            <span>{{ t('nav.b.save') }}</span>
                        </a-button>
                    </div>
                </a-form>
            </a-spin>
        </a-modal>

        <!-- Permission Editor Drawer (lazy-loading) -->
        <PermissionEditor
            v-model:visible="permissionEditorVisible"
            :role-id="permissionEditorRoleId"
            :role-name="permissionEditorRoleName"
            @saved="refetch"
        />

        <!-- Data scope drawer -->
        <RoleDataScopeDrawer
            v-model:visible="dataScopeVisible"
            :role-id="dataScopeRoleId"
            :role-name="dataScopeRoleName"
        />
    </div>
</template>
<script setup>
import { computed, defineComponent, nextTick, ref } from 'vue'
import { anyOf, can, iAlert, perm, rule } from '@/mixins'
import ajax from '@/ajax'
import { useRoles } from '@/hooks'
import { getRole } from '@/libs'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { useI18n } from 'vue-i18n'
import PermissionEditor from '@/components/permission-editor/PermissionEditor.vue'
import PermissionOverview from '@/components/permission-editor/PermissionOverview.vue'
import RoleDataScopeDrawer from '@/components/data-scope/role-data-scope-drawer.vue'

defineComponent({
    name: 'UserRoles',
})

const { t } = useI18n()

const { data, isFetching, isLoading, refetch } = useRoles({})
const columns = [
    { key: 'name', title: ['validation.attributes.name'] },
    { key: 'permissions', title: ['nav.a.permission'] },
    { key: 'action' },
]

const modalState = ref(false)
const modalBusy = ref(false)
const btnState = ref(false)
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})

// Permission Editor state
const permissionEditorVisible = ref(false)
const permissionEditorRoleId = ref(null)
const permissionEditorRoleName = ref('')
// Data scope drawer state
const dataScopeVisible = ref(false)
const dataScopeRoleId = ref(null)
const dataScopeRoleName = ref('')
const form = ref({
    id: 0,
    name: null,
    user_id: 0,
    custom: {
        textColor: null,
        badge: null,
        level: 99,
        icon: null,
        image: null,
    },
})
const formRef = ref(null)
const deletingRoleId = ref(null)
const roleList = computed(() => {
    const payload = data.value

    if (!payload) {
        return []
    }

    if (Array.isArray(payload)) {
        return payload
    }

    if (Array.isArray(payload?.data)) {
        return payload.data
    }

    if (Array.isArray(payload?.data?.data)) {
        return payload.data.data
    }

    return []
})
const isTableLoading = computed(() => isFetching.value || isLoading.value)
const tableLocale = computed(() => ({
    emptyText: isTableLoading.value
        ? t('text.loading')
        : t('text.no-data'),
}))

/** Open Modal */
const openModal = ({ keepBusy = false } = {}) => {
    modalBusy.value = true
    modalState.value = true

    nextTick(() => {
        if (!keepBusy) {
            modalBusy.value = false
        }
    })
}
/** Close modal */
const closeModal = () => {
    formRef.value?.resetFields()
    nextTick(() => {
        formRef.value?.clearValidate()
    })
    form.value.id = 0
    btnState.value = false
    modalBusy.value = false
    modalState.value = false
}
/** Open Permission Editor drawer */
const openPermissionEditor = (role) => {
    permissionEditorRoleId.value = role.id
    permissionEditorRoleName.value = role.name
    permissionEditorVisible.value = true
}
/** Open the data scope drawer for a role */
const openDataScope = (role) => {
    dataScopeRoleId.value = role.id
    dataScopeRoleName.value = role.name
    dataScopeVisible.value = true
}
/** Format group of role & permission */
const parseGroup = (item) => {
    if (!Array.isArray(item) || item.length < 1) {
        return '<span class="text-xs text-slate-400">—</span>'
    }

    const group = item.reduce((acc, entry) => {
        if (!acc[entry.group]) {
            acc[entry.group] = []
        }

        acc[entry.group].push(entry.name)
        return acc
    }, {})

    return Object.entries(group)
        .map(([key, value]) => {
            const permissions = value
                .map((name) => `<span class="inline-flex items-center">${name}</span>`)
                .join(' ')
            return `<span class="mr-1 mb-1 inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-1 text-xs text-slate-700"><b>${key}:</b> ${permissions}</span>`
        })
        .join(' ')
}
/** Process to edit */
const editRole = async (id) => {
    openModal({ keepBusy: true })

    await getRole(id, true)
        .then((res) => {
            form.value = {
                id: res.id,
                name: res.name,
                custom: res.custom || {},
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            modalBusy.value = false
        })
}

/** Submit form  */
const submit = () => {
    let call =
        form.value.id > 0
            ? ajax.put(`role/${form.value.id}`, form.value)
            : ajax.post('role', form.value)
    if (btnState.value) {
        return
    }
    btnState.value = true
    call.then((res) => {
        const payload = res?.data || {}
        if (payload.status === 'success') {
            refetch()
            closeModal()
        }
        if (payload.message) {
            iAlert(payload)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

const handleDeleteRole = async (id) => {
    if (!id || deletingRoleId.value) {
        return
    }

    deletingRoleId.value = id

    try {
        const response = await ajax.delete(`role/${id}`)
        const payload = response?.data || {}

        if (payload.status === 'success') {
            await refetch()
        }

        if (payload.message) {
            iAlert(payload)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        deletingRoleId.value = null
    }
}
</script>
