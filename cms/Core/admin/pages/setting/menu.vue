<template>
    <div v-if="!editorOpen" class="menu-management">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ $t('nav.a.menus') }}</h2>
            <a-button v-if="rule('menu.create')" type="primary" @click="create"
                ><PlusOutlined /> {{ $t('nav.b.create') }}</a-button
            >
        </div>
        <a-alert
            v-if="listError"
            type="error"
            :message="t('text.menu_editor.list-error')"
            show-icon
            class="my-4"
        />
        <a-table
            v-if="rule('menu.viewAny')"
            :loading="listLoading"
            :columns="columns"
            :data-source="menuRows"
            row-key="id"
            size="small"
            class="mt-4"
        >
            <template #bodyCell="{ column, record }"
                ><template v-if="column.key === 'name'"
                    ><strong>{{ record.name }}</strong>
                    <div class="text-xs text-slate-500">
                        {{ record.items?.length || 0 }}
                        {{ t('text.menu_editor.top-level-items') }}
                    </div></template
                ><template v-else-if="column.key === 'language'">{{
                    record.language?.name || '—'
                }}</template
                ><template v-else-if="column.key === 'group_key'">{{
                    placementLabel(record.group_key)
                }}</template
                ><template v-else-if="column.key === 'action'"
                    ><a-button
                        v-if="rule('menu.update')"
                        type="text"
                        @click="edit(record.id)"
                        :aria-label="$t('nav.b.edit') + ' ' + record.name"
                        ><EditOutlined /> {{ $t('nav.b.edit') }}</a-button
                    ><a-popconfirm
                        v-if="rule('menu.delete')"
                        :title="$t('text.del-confirm')"
                        :ok-text="$t('nav.b.delete')"
                        :cancel-text="$t('nav.b.cancel')"
                        @confirm="destroy(record.id)"
                        ><a-button
                            danger
                            type="text"
                            :aria-label="$t('nav.b.delete') + ' ' + record.name"
                            ><DeleteOutlined /></a-button></a-popconfirm></template
            ></template>
        </a-table>
    </div>
    <a-spin v-else :spinning="loading">
        <a-alert v-if="loadError" type="error" :message="loadError" show-icon
            ><template #action
                ><a-button size="small" :disabled="saving" @click="reload">{{
                    t('text.menu_editor.reload')
                }}</a-button
                ><a-button size="small" :disabled="saving" @click="close">{{
                    t('text.menu_editor.back-to-list')
                }}</a-button></template
            ></a-alert
        >
        <menu-editor
            v-if="!loading && !loadError"
            :key="editorVersion"
            :form="form"
            :languages="languages?.data || []"
            :statuses="
                statuses.filter(
                    (status) => status.type === 'status' && status.enum_key !== 'pending'
                )
            "
            :categories="categoryRows"
            :categories-loading="categoryLoading"
            :categories-error="categoryError"
            :saving="saving"
            :save-error="saveError"
            :deleted-ids="deletedIds"
            @save="save"
            @cancel="close"
            @remove="queueDeletes"
        />
    </a-spin>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { useAppRuntime } from '@/runtime'
import { useEnumerates, useMenus } from '@/hooks'
import { deleteMenu, deleteMenuItem, getCategories, getMenu, storeMenu, updateMenu } from '@/libs'
import { iAlert, rule } from '@/mixins'
import MenuEditor from '@/components/menu-editor.vue'
import { flattenMenu, locateMenu } from '@/components/menu-tree-state'
import { prepareMenuItems, serializeMenuItems } from '@/pages/setting/menu-editor-state'

const { t, locale } = useI18n()
const { languages } = useAppRuntime()
const { data: statusData } = useEnumerates()
const statuses = computed(() => statusData.value || [])
const { data, isLoading: listLoading, isError: listError, refetch } = useMenus({}, { staleTime: 0 })
const menuRows = computed(() => (Array.isArray(data.value) ? data.value : data.value?.data || []))
const columns = computed(() => [
    { key: 'name', title: t('nav.a.menus') },
    { key: 'language', title: t('nav.a.languages') },
    { key: 'group_key', title: t('text.menu_editor.display-location') },
    { key: 'action', title: '', align: 'right' },
])
function placementLabel(group) {
    const key = 'text.menu.' + (group === 'secondary_footer' ? 'secondary_footer_menu' : group)
    const label = t(key)
    return label === key ? group : label
}
const defaults = () => ({
    id: 0,
    name: '',
    group_key: 'main_menu',
    language_id: languages.value?.data?.[0]?.id || null,
    status: 'status_published',
    items: [],
})
const form = ref(defaults())
const editorOpen = ref(false)
const editorVersion = ref(0)
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const deletedIds = ref([])
const categoryRows = ref([])
const categoryLoading = ref(false)
const categoryError = ref(false)
let categorySequence = 0
let menuSequence = 0

async function loadCategories() {
    if (!rule('category.viewAny')) return
    const sequence = ++categorySequence
    categoryLoading.value = true
    categoryError.value = false
    try {
        const result = await getCategories({})
        if (result.status && result.status !== 'success') throw new Error('Category read failed')
        if (sequence === categorySequence) categoryRows.value = result.data || []
    } catch {
        if (sequence === categorySequence) categoryError.value = true
    } finally {
        if (sequence === categorySequence) categoryLoading.value = false
    }
}
function create() {
    if (!rule('menu.create')) return
    menuSequence++
    form.value = defaults()
    deletedIds.value = []
    saveError.value = ''
    loadError.value = ''
    loading.value = false
    editorVersion.value++
    editorOpen.value = true
    loadCategories()
}
function close() {
    if (saving.value) return
    menuSequence++
    categorySequence++
    editorOpen.value = false
    form.value = defaults()
    deletedIds.value = []
    loadError.value = ''
    saveError.value = ''
}
function prunePending(items) {
    for (const { node } of [...flattenMenu(items)].reverse()) {
        if (deletedIds.value.includes(Number(node.id))) {
            const found = locateMenu(items, node._key)
            if (found) found.siblings.splice(found.index, 1)
        }
    }
}
async function load(id) {
    const result = await getMenu(id, true)
    if (!result?.id || Number(result.id) !== Number(id)) throw new Error('Menu read failed')
    const next = {
        ...defaults(),
        ...Object.fromEntries(
            ['id', 'name', 'group_key', 'language_id', 'status']
                .filter((key) => key in result)
                .map((key) => [key, result[key]])
        ),
        items: prepareMenuItems(result.parseItems || []),
    }
    prunePending(next.items)
    return next
}
async function edit(id) {
    if (!rule('menu.update')) return
    const sequence = ++menuSequence
    editorOpen.value = true
    loading.value = true
    loadError.value = ''
    saveError.value = ''
    deletedIds.value = []
    form.value = { ...defaults(), id }
    loadCategories()
    try {
        const result = await load(id)
        if (sequence === menuSequence) {
            form.value = result
            editorVersion.value++
        }
    } catch {
        if (sequence === menuSequence) loadError.value = t('text.menu_editor.load-error')
    } finally {
        if (sequence === menuSequence) loading.value = false
    }
}
async function reload() {
    const id = form.value.id
    if (id) {
        loading.value = true
        try {
            form.value = await load(id)
            loadError.value = ''
            editorVersion.value++
        } catch {
            loadError.value = t('text.menu_editor.reload-error')
        } finally {
            loading.value = false
        }
    }
}
function queueDeletes(ids) {
    deletedIds.value = [...new Set([...deletedIds.value, ...ids])]
}

async function save() {
    if (saving.value) return
    const id = Number(form.value.id)
    if (!rule(id ? 'menu.update' : 'menu.create')) return
    saving.value = true
    saveError.value = ''
    let written = false
    try {
        // Keep the existing request contract; UI keys and target metadata never enter the payload.
        const payload = {
            id: form.value.id,
            name: form.value.name,
            group_key: form.value.group_key,
            language_id: form.value.language_id,
            status: form.value.status,
            items: serializeMenuItems(form.value.items),
        }
        const result = await (id ? updateMenu(id, payload) : storeMenu(payload))
        if (result?.status !== 'success') {
            saveError.value = result?.message || t('text.menu_editor.not-saved')
            return
        }
        written = true
        if (id) {
            // Read assigned IDs before deletes, so a retry never duplicates newly inserted items.
            try {
                form.value = await load(id)
                editorVersion.value++
            } catch {
                loadError.value = t('text.menu_editor.saved-reload-error')
                return
            }
            for (const itemId of [...deletedIds.value]) {
                const deleted = await deleteMenuItem(id, itemId)
                if (deleted?.status !== 'success') throw new Error('Menu item delete failed')
                deletedIds.value = deletedIds.value.filter((value) => value !== itemId)
            }
            try {
                form.value = await load(id)
                editorVersion.value++
            } catch {
                loadError.value = t('text.menu_editor.saved-reload-error')
                return
            }
        }
        if (!id) {
            saving.value = false
            close()
        }
        if (result.message) iAlert(result.message)
        try {
            await refetch()
        } catch {
            iAlert(t('text.menu_editor.list-refresh-error'), true)
        }
    } catch {
        saveError.value = !written
            ? t('text.menu_editor.save-error')
            : deletedIds.value.length
              ? t('text.menu_editor.remove-error')
              : t('text.menu_editor.list-refresh-error')
    } finally {
        saving.value = false
    }
}
async function destroy(id) {
    if (!rule('menu.delete')) return
    try {
        const result = await deleteMenu(id)
        if (result?.status === 'success') await refetch()
        if (result?.message) iAlert(result.message)
    } catch {
        iAlert(t('text.menu_editor.delete-error'), true)
    }
}
</script>
