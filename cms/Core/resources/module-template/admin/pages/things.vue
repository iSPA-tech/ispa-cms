<template>
    <div v-if="rule('skeleton.skeleton_thing.viewAny')" class="space-y-4">
        <breadcrumbs :items="[{ icon: 'file-document', title: $t('nav.a.skeleton'), text: $t('nav.a.skeleton-things') }]">
            <i-button v-if="rule('skeleton.skeleton_thing.create')" data-test="create" size="sm" variant="primary" @click="openCreate">
                <template #icon>
                    <i-icon class="size-3.5" icon="plus-thick" />
                </template>
                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
        </breadcrumbs>

        <div class="i-box space-y-3">
            <a-input-search
                v-model:value="search"
                :placeholder="$t('text.skeleton.things.search')"
                allow-clear
                class="max-w-sm"
                data-test="search"
                @search="load(1)"
            />
            <a-table
                :columns="columns"
                :data-source="rows"
                :loading="loading"
                :locale="{ emptyText: $t('text.skeleton.things.empty') }"
                :pagination="pagination"
                :scroll="{ x: 720 }"
                row-key="uuid"
                size="small"
                @change="onTableChange"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'status'">
                        <!-- @mode lite,normal -->
                        <a-tag :color="record.status === 'published' ? 'green' : 'default'">
                            {{ $t(`text.skeleton.things.status_${record.status}`) }}
                        </a-tag>
                        <!-- @endmode -->
                        <!-- @mode full -->
                        <SkeletonThingStatusTag :status="record.status" />
                        <!-- @endmode -->
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <a-space>
                            <a-button v-if="rule('skeleton.skeleton_thing.update')" :data-test="`edit-${record.uuid}`" size="small" @click="openEdit(record)">
                                {{ $t('nav.b.update') }}
                            </a-button>
                            <a-popconfirm
                                v-if="rule('skeleton.skeleton_thing.delete')"
                                :cancel-text="$t('nav.b.cancel')"
                                :ok-text="$t('nav.b.delete')"
                                :title="$t('text.skeleton.things.delete_confirm')"
                                ok-type="danger"
                                @confirm="remove(record)"
                            >
                                <a-button danger size="small">{{ $t('nav.b.delete') }}</a-button>
                            </a-popconfirm>
                        </a-space>
                    </template>
                </template>
            </a-table>
        </div>

        <a-modal
            v-model:open="showForm"
            :footer="null"
            :title="editingUuid ? $t('text.skeleton.things.edit_title') : $t('text.skeleton.things.create_title')"
            destroy-on-close
        >
            <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
                <a-form-item :label="$t('text.skeleton.things.name')" name="name">
                    <a-input v-model:value="form.name" data-test="name" />
                </a-form-item>
                <a-form-item :label="$t('text.skeleton.things.description')" name="description">
                    <a-textarea v-model:value="form.description" :rows="4" />
                </a-form-item>
                <a-form-item name="published">
                    <a-checkbox v-model:checked="form.published">{{ $t('text.skeleton.things.publish_now') }}</a-checkbox>
                </a-form-item>
                <AppModalFooter :dangerSubmit="false" :loading="saving" @cancel="showForm = false" @submit="submit" />
            </a-form>
        </a-modal>
    </div>
    <no-permission-error v-else />
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { iAlert, rule } from '@/mixins'
import AppModalFooter from '@/components/common/AppModalFooter.vue'
// @mode full
import SkeletonThingStatusTag from '../components/SkeletonThingStatusTag.vue'
// @endmode
import { createSkeletonThing, deleteSkeletonThing, getSkeletonThings, updateSkeletonThing } from '../api'

defineOptions({ name: 'SkeletonThings' })

const { t } = useI18n()

// List: search box, server pagination (the API returns { data, total, perPage, currentPage }).
const search = ref('')
const rows = ref([])
const loading = ref(false)
const page = reactive({ current: 1, pageSize: 20, total: 0 })
const pagination = computed(() => ({ ...page, showSizeChanger: true }))

const columns = computed(() => [
    { key: 'name', dataIndex: 'name', title: t('text.skeleton.things.name') },
    { key: 'status', title: t('text.skeleton.things.status'), width: 140 },
    { key: 'updated_at', dataIndex: 'updated_at', title: t('text.skeleton.things.updated_at'), width: 180 },
    { key: 'actions', title: '', width: 170 },
])

const load = async (current = page.current) => {
    loading.value = true
    try {
        const body = await getSkeletonThings({ page: current, pageSize: page.pageSize, search: search.value || undefined })
        const result = body?.data ?? {}
        rows.value = Array.isArray(result) ? result : result.data ?? []
        page.current = result.currentPage ?? current
        page.total = result.total ?? rows.value.length
    } finally {
        loading.value = false
    }
}

const onTableChange = (next) => {
    page.pageSize = next.pageSize
    load(next.current)
}

onMounted(() => load(1))

// Create and edit share one modal form. Only what a person knows is asked; uuid and dates come from the server.
const showForm = ref(false)
const saving = ref(false)
const editingUuid = ref(null)
const formRef = ref(null)
const emptyForm = () => ({ name: '', description: '', published: false })
const form = reactive(emptyForm())
const formRules = computed(() => ({
    name: [{ required: true, whitespace: true, message: t('text.skeleton.things.name_required'), trigger: 'blur' }],
}))

const openCreate = () => {
    Object.assign(form, emptyForm())
    editingUuid.value = null
    showForm.value = true
}

const openEdit = (record) => {
    Object.assign(form, emptyForm(), {
        name: record.name,
        description: record.description || '',
        published: record.status === 'published',
    })
    editingUuid.value = record.uuid
    showForm.value = true
}

const submit = async () => {
    if (saving.value) return
    try {
        await formRef.value?.validate?.()
    } catch {
        return // the form shows the field errors
    }
    saving.value = true
    const payload = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        status: form.published ? 'published' : 'draft',
    }
    try {
        if (editingUuid.value) await updateSkeletonThing(editingUuid.value, payload)
        else await createSkeletonThing(payload)
        iAlert(t('text.skeleton.things.saved'))
        showForm.value = false
        await load(editingUuid.value ? page.current : 1)
    } finally {
        saving.value = false
    }
}

const remove = async (record) => {
    await deleteSkeletonThing(record.uuid)
    iAlert(t('text.skeleton.things.deleted'))
    await load(rows.value.length === 1 && page.current > 1 ? page.current - 1 : page.current)
}
</script>
