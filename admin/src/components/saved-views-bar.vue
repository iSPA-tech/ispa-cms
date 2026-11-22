<template>
    <section v-if="!hidden" class="space-y-2" data-testid="saved-views-bar">
        <a-alert v-if="loadError" type="error" show-icon :message="t('text.saved_views.load_failed')">
            <template #action>
                <i-button variant="default" size="sm" data-testid="views-retry" @click="load">
                    {{ t('text.profile.retry') }}
                </i-button>
            </template>
        </a-alert>

        <a-skeleton-button v-else-if="loading" active size="small" data-testid="views-loading" />

        <div v-else class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('text.saved_views.saved')">
            <i-button
                :variant="allActive ? 'primary' : 'default'"
                size="sm"
                :aria-pressed="allActive"
                data-testid="view-all"
                @click="applyAll"
            >
                {{ t('text.saved_views.all') }}
            </i-button>
            <i-button
                v-for="view in views"
                :key="view.uuid"
                :variant="view.uuid === activeUuid ? 'primary' : 'default'"
                size="sm"
                :aria-pressed="view.uuid === activeUuid"
                data-testid="view-tab"
                @click="apply(view)"
            >
                {{ view.name }}<span v-if="view.is_shared" class="ml-1 text-xs opacity-75">· {{ t('text.saved_views.shared') }}</span>
            </i-button>

            <a-popover v-model:open="formOpen" trigger="click" placement="bottomLeft">
                <template #content>
                    <div class="w-64 max-w-full space-y-2" data-testid="view-form">
                        <label class="block text-sm text-ink" for="saved-view-name">{{ t('text.saved_views.name') }}</label>
                        <a-input
                            id="saved-view-name"
                            v-model:value="name"
                            data-testid="view-name"
                            :maxlength="MAX_NAME"
                            :disabled="saving"
                            @press-enter="submit"
                        />
                        <p v-if="duplicate" class="m-0 text-xs text-ink-muted" data-testid="view-duplicate">{{ t('text.saved_views.duplicate_name') }}</p>
                        <label v-if="canShare && !renaming" class="flex items-center gap-2 text-sm text-ink">
                            <a-switch v-model:checked="shareNew" size="small" data-testid="view-share-new" :disabled="saving" />
                            {{ t('text.saved_views.share') }}
                        </label>
                        <p v-if="formError" class="m-0 text-sm text-red-700" role="alert" data-testid="view-error">{{ formError }}</p>
                        <div class="flex flex-wrap gap-2">
                            <i-button variant="primary" :loading="saving" :disabled="saving || !nameValid" data-testid="view-submit" @click="submit">
                                {{ t('nav.b.save') }}
                            </i-button>
                            <i-button variant="default" :disabled="saving" data-testid="view-cancel" @click="closeForm">
                                {{ t('nav.b.cancel') }}
                            </i-button>
                        </div>
                    </div>
                </template>
                <i-button variant="default" size="sm" data-testid="view-save" @click="openCreate">
                    {{ t('text.saved_views.save') }}
                </i-button>
            </a-popover>
        </div>

        <div v-if="!loading && !loadError && activeView && (activeView.can_manage || activeView.can_delete)" class="flex flex-wrap items-center gap-2" data-testid="view-actions">
            <i-button v-if="activeView.can_manage && activeView.is_mine" variant="default" size="sm" data-testid="view-rename" @click="openRename(activeView)">
                {{ t('text.saved_views.rename') }}
            </i-button>
            <label v-if="canShare && activeView.can_manage && activeView.is_mine" class="flex items-center gap-2 text-sm text-ink">
                <a-switch
                    :checked="activeView.is_shared"
                    size="small"
                    data-testid="view-share"
                    :disabled="saving"
                    @change="toggleShare(activeView, $event)"
                />
                {{ t('text.saved_views.share') }}
            </label>
            <a-popconfirm v-if="activeView.can_delete" :title="t('text.saved_views.delete_confirm')" @confirm="remove(activeView)">
                <i-button variant="default" size="sm" data-testid="view-delete">{{ t('nav.b.delete') }}</i-button>
            </a-popconfirm>
        </div>
        <p v-if="actionError" class="m-0 text-sm text-red-700" role="alert" data-testid="view-action-error">{{ actionError }}</p>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { message } from 'ant-design-vue'
import { createSavedView, deleteSavedView, getSavedViews, updateSavedView } from '@/libs/saved-views'
import { normaliseViewState, sameState, viewToQuery } from '@/utils/saved-views'

/**
 * Bar above an admin list: tabs for the user's saved views (and shared ones), "Save current filters", rename, delete and
 * a share switch. Applying a view writes its state into the route query with `router.replace` (the page restarts on
 * page 1), so the list needs no code beyond reading its own query. A view is active when the current normalised state
 * equals the view's state. Views load once per mount (at most 40 rows); the bar hides itself when the API does not
 * accept the list key (404). The pending request is aborted on unmount.
 */
const props = defineProps({
    listKey: { type: String, required: true },
    schema: { type: Object, required: true },
    state: { type: Object, default: () => ({}) },
    canShare: { type: Boolean, default: false },
})

const MAX_NAME = 80
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const views = ref([])
const loading = ref(true)
const loadError = ref(false)
const hidden = ref(false)
const formOpen = ref(false)
const renaming = ref(null)
const name = ref('')
const shareNew = ref(false)
const saving = ref(false)
const formError = ref('')
const actionError = ref('')
let controller = null

const cleanName = computed(() => (name.value || '').trim())
const nameValid = computed(() => cleanName.value.length >= 1 && cleanName.value.length <= MAX_NAME)
const duplicate = computed(() =>
    views.value.some((v) => v.is_mine && v.uuid !== renaming.value && v.name.trim().toLowerCase() === cleanName.value.toLowerCase())
)
const current = computed(() => normaliseViewState(props.state))
const allActive = computed(() => Object.keys(current.value).length === 0)
const activeView = computed(() => views.value.find((v) => sameState(v.state, current.value)) || null)
const activeUuid = computed(() => activeView.value?.uuid || null)

const statusOf = (e) => e?.response?.status

const load = async () => {
    if (controller) controller.abort()
    const mine = new AbortController()
    controller = mine
    loading.value = true
    loadError.value = false
    try {
        const rows = await getSavedViews(props.listKey, { signal: mine.signal })
        if (mine.signal.aborted) return
        views.value = rows
    } catch (e) {
        if (mine.signal.aborted) return
        if (statusOf(e) === 404) hidden.value = true
        else loadError.value = true
    } finally {
        if (controller === mine) {
            loading.value = false
            controller = null
        }
    }
}

const keptQuery = () => {
    const kept = {}
    for (const [key, value] of Object.entries(route.query || {})) {
        if (!(key in props.schema)) kept[key] = value
    }

    return kept
}

const apply = (view) => router.replace({ query: { ...keptQuery(), ...viewToQuery(view, props.schema) } })
const applyAll = () => router.replace({ query: keptQuery() })

const closeForm = () => {
    formOpen.value = false
    renaming.value = null
    formError.value = ''
}
const openCreate = () => {
    renaming.value = null
    name.value = ''
    shareNew.value = false
    formError.value = ''
}
const openRename = (view) => {
    renaming.value = view.uuid
    name.value = view.name
    formError.value = ''
    formOpen.value = true
}

const failureText = (e) => (statusOf(e) === 422 ? t('text.saved_views.limit_reached') : t('text.saved_views.save_failed'))

const submit = async () => {
    if (saving.value || !nameValid.value) return
    saving.value = true
    formError.value = ''
    try {
        if (renaming.value) {
            const item = await updateSavedView(renaming.value, { name: cleanName.value })
            views.value = views.value.map((v) => (v.uuid === renaming.value ? { ...v, ...item } : v))
        } else {
            const item = await createSavedView(props.listKey, {
                name: cleanName.value,
                state: current.value,
                is_shared: props.canShare && shareNew.value,
            })
            views.value = [...views.value.filter((v) => !v.is_mine), ...views.value.filter((v) => v.is_mine), item].sort(
                (a, b) => Number(b.is_mine) - Number(a.is_mine)
            )
        }
        message.success(t('text.saved_views.saved'))
        closeForm()
    } catch (e) {
        formError.value = failureText(e)
    } finally {
        saving.value = false
    }
}

const toggleShare = async (view, value) => {
    if (saving.value) return
    saving.value = true
    actionError.value = ''
    try {
        const item = await updateSavedView(view.uuid, { is_shared: !!value })
        views.value = views.value.map((v) => (v.uuid === view.uuid ? { ...v, ...item } : v))
    } catch (e) {
        actionError.value = failureText(e)
    } finally {
        saving.value = false
    }
}

const remove = async (view) => {
    actionError.value = ''
    try {
        await deleteSavedView(view.uuid)
        views.value = views.value.filter((v) => v.uuid !== view.uuid)
    } catch (e) {
        actionError.value = t('text.saved_views.save_failed')
    }
}

watch(() => props.listKey, load, { immediate: true })

onBeforeUnmount(() => {
    if (controller) controller.abort()
})
</script>
