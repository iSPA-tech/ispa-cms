<!--
    Inline image picker of the widget live preview (approved mockup): search, upload and a
    thumbnail grid over the same media library list/upload routes the media centre uses.
    Nothing is applied until "Chọn"; "Huỷ" closes without changing the field.
-->
<template>
    <div class="flex flex-col">
        <div class="flex items-center gap-2 pb-3">
            <label class="relative flex min-w-0 flex-1 items-center">
                <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-2.5 size-4" fill="none" stroke="#5b6470" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="6.5" />
                    <path d="M16 16l4 4" />
                </svg>
                <input
                    v-model="keywords"
                    type="search"
                    class="w-full rounded-md border border-[#d0d5db] bg-white pl-8 pr-2.5 text-[#1f2933] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f]"
                    :class="touch ? 'h-11 text-base' : 'h-[34px] text-sm'"
                    :aria-label="t('text.widget.edit_on_preview.image_search')"
                    :placeholder="t('text.widget.edit_on_preview.image_search')"
                    @input="onSearch"
                />
            </label>
            <a-upload
                :accept="accept"
                :action="uploadAction"
                :data="uploadData"
                :headers="requestHeaders"
                :show-upload-list="false"
                name="file"
                with-credentials
                @change="onUpload"
            >
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-[#17a2b8] bg-white px-3 text-[#0b6f7f] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f] disabled:cursor-default disabled:opacity-60"
                    :class="touch ? 'h-11 text-base' : 'h-[34px] text-sm'"
                    :disabled="uploading"
                >
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 16V5M7.5 9.5L12 5l4.5 4.5M5 19h14" />
                    </svg>
                    {{ t('text.widget.edit_on_preview.image_upload') }}
                </button>
            </a-upload>
        </div>
        <div class="max-h-[212px] overflow-y-auto p-0.5" :aria-busy="isFetching">
            <p v-if="!rows.length" class="m-0 py-6 text-center text-sm text-[#5b6470]" role="status">
                {{ isFetching ? `${t('text.refresh')}...` : t('text.widget.edit_on_preview.image_empty') }}
            </p>
            <div v-else class="grid grid-cols-3 gap-2.5 sm:grid-cols-4">
                <button
                    v-for="row in rows"
                    :key="row.id"
                    type="button"
                    class="group flex min-w-0 cursor-pointer flex-col gap-1 border-0 bg-transparent p-0 text-left focus-visible:outline-none"
                    :aria-pressed="isPicked(row)"
                    :aria-label="row.name"
                    @click="toggle(row)"
                >
                    <span
                        class="relative flex h-[76px] items-center justify-center overflow-hidden rounded-md bg-[#e9ecef] group-focus-visible:outline-2 group-focus-visible:outline-offset-2 group-focus-visible:outline-[#0b6f7f]"
                        :class="isPicked(row) ? 'shadow-[0_0_0_2px_#17a2b8]' : 'group-hover:shadow-[0_0_0_1px_#8b929b]'"
                    >
                        <img :src="thumb(row)" alt="" class="size-full object-cover" loading="lazy" @error="(event) => useOriginal(event, row)" />
                        <span v-if="isPicked(row)" class="absolute right-[5px] top-[5px] flex size-[18px] items-center justify-center rounded-full bg-[#0f7f91]">
                            <svg viewBox="0 0 24 24" class="size-3" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12.5l4.5 4.5L19 7.5" />
                            </svg>
                        </span>
                    </span>
                    <span class="truncate text-[11px] leading-4 text-[#4b5563]">{{ row.name }}</span>
                </button>
            </div>
            <button
                v-if="rows.length < total"
                type="button"
                class="mt-2 w-full cursor-pointer rounded-md border border-[#d0d5db] bg-white text-[#0b6f7f] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]"
                :class="touch ? 'h-11 text-base' : 'h-8 text-sm'"
                @click="params.pageSize += PAGE"
            >
                {{ t('text.widget.edit_on_preview.image_more') }}
            </button>
        </div>
        <div class="-mx-4 mt-3 flex flex-wrap items-center gap-2 border-t border-[#e5e7eb] px-4 pt-3.5">
            <div class="min-w-0 flex-1 truncate text-xs text-[#5b6470]" aria-live="polite">{{ pickedLabel }}</div>
            <button
                type="button"
                class="cursor-pointer rounded-md border border-[#d0d5db] bg-white px-3.5 text-[#1f2933] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]"
                :class="touch ? 'h-11 text-base' : 'h-8 text-sm'"
                @click="emit('close')"
            >
                {{ t('nav.b.cancel') }}
            </button>
            <button
                type="button"
                class="cursor-pointer rounded-md border border-[#0f7f91] bg-[#0f7f91] px-[18px] font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]"
                :class="touch ? 'h-11 text-base' : 'h-8 text-sm'"
                data-action="choose"
                @click="choose"
            >
                {{ t('text.widget.edit_on_preview.image_choose') }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { debounce } from 'lodash-es'
import { useI18n } from 'vue-i18n'
import { useUploads } from '@/hooks'
import { apiBase, apiUrl, cookie, COOKIE_KEY_TOKEN, defaultFilterParams, domain } from '@/config'
import { runtimeConfig } from '@/config/runtime'
import { iAlert, removeLeftTrailingSlash } from '@/mixins'

defineOptions({ name: 'WidgetPreviewImagePicker' })

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    limit: { type: Number, default: 1 },
    touch: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'close'])
const { t } = useI18n()

const PAGE = 8
// Same store, folder and category as media-selection's defaults, so both show one library.
const FOLDER = 'User'
const accept = 'image/gif, image/vnd.microsoft.icon, image/jpeg, image/png, image/svg+xml, image/webm, image/webp, image/x-icon'
const uploadAction = `${apiBase}upload/image`
const uploadData = { target: null, targetId: 0, category: 'images', folder: FOLDER }
const requestHeaders = {
    Authorization: 'Bearer ' + cookie.get(COOKIE_KEY_TOKEN),
    'Access-Control-Allow-Origin': domain,
    'ISPA-ADMIN-AUTH-KEY': runtimeConfig().adminAuthKey,
}

const keywords = ref('')
const uploading = ref(false)
const picked = ref((props.modelValue || []).filter((file) => file && typeof file === 'object'))
const params = reactive({ ...defaultFilterParams, pageSize: PAGE, folder: FOLDER, type: 'image' })
const { data, isFetching, refetch } = useUploads(params)

const body = computed(() => data.value?.data)
const rows = computed(() => (Array.isArray(body.value) ? body.value : body.value?.data || []))
const total = computed(() =>
    Array.isArray(body.value) ? (data.value?.meta?.total ?? body.value.length) : (body.value?.total ?? rows.value.length)
)
const pickedLabel = computed(() =>
    picked.value.length
        ? t('text.widget.edit_on_preview.image_selected', { name: picked.value.map((file) => file.name).join(', ') })
        : t('text.widget.edit_on_preview.image_none')
)

const applySearch = debounce(() => {
    params.keywords = keywords.value
    params.pageSize = PAGE
}, 400)
const onSearch = () => applySearch()

const isPicked = (row) => picked.value.some((file) => file.id === row.id)
function toggle(row) {
    if (isPicked(row)) picked.value = picked.value.filter((file) => file.id !== row.id)
    else if (props.limit === 1) picked.value = [row]
    else if (props.limit < 1 || picked.value.length < props.limit) picked.value = [...picked.value, row]
}
const fileUrl = (path = '') => (!path || path.includes('http') ? path : `${apiUrl}${removeLeftTrailingSlash(path)}`)
const thumb = (row) => fileUrl(row.thumbnail_sm || row.url || '')
// A thumbnail that is not generated yet falls back to the original file, once.
function useOriginal(event, row) {
    const original = fileUrl(row.url)
    if (original && event.target.src !== original) event.target.src = original
}
async function onUpload({ file }) {
    const { status = 'uploading', response = {} } = file
    uploading.value = status === 'uploading'
    if (status === 'error') return iAlert(response?.message || t('text.media.upload_failed'), true)
    if (status !== 'done' || response?.status !== 'success') return
    const result = await refetch()
    const uploaded = Array.isArray(response.data) ? response.data[0] : response.data
    const fresh = result?.data?.data
    const list = Array.isArray(fresh) ? fresh : fresh?.data || []
    // Select the new file from the refreshed list (it carries the thumbnail fields).
    const row = list.find((item) => item.id === uploaded?.id) || (uploaded?.id ? uploaded : null)
    if (row && !isPicked(row)) toggle(row)
}
function choose() {
    emit('update:modelValue', picked.value)
    emit('close')
}

onBeforeUnmount(() => applySearch.cancel())
</script>
