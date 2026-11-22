<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="min-h-[calc(100vh_-_200px)]">
        <div class="flex flex-col gap-3">
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto]">
                <a-input
                    v-model:value="filterString"
                    :placeholder="$t('text.f.search')"
                    allow-clear
                    class="w-full"
                    type="search"
                    @change="setParamsKeywordsDebounced"
                    @pressEnter="getUploadList"
                />

                <a-button class="justify-self-start" @click="getUploadList">
                    {{ $t('nav.b.filter') }}
                </a-button>
                <a-button v-if="!privateStore" class="justify-self-start" @click="toggleAdvancedPanel">
                    <span class="inline-flex items-center gap-2">
                        <i-icon icon="folder-open" class="size-4" />
                        {{ $t('web.file.folder') }}
                    </span>
                </a-button>
            </div>

            <div v-if="!privateStore" class="flex flex-wrap items-center gap-2">
                <a-button
                    :type="!currentFolderPath ? 'primary' : 'default'"
                    size="small"
                    @click="clearCurrentFolder"
                >
                    {{ $t('text.all-files') }}
                </a-button>

                <a-tag
                    v-if="currentFolderLabel"
                    class="ml-1 max-w-56 truncate"
                    color="blue"
                    closable
                    @close="clearCurrentFolder"
                    :title="currentFolderPath"
                >
                    {{ $t('web.file.folder') }}: {{ currentFolderPath }}
                </a-tag>
            </div>

            <div
                v-if="!privateStore"
                class="grid gap-2 md:grid-cols-2 xl:grid-cols-[180px_180px_220px_minmax(180px,1fr)_auto]"
            >
                <a-select
                    v-model:value="filterParams.owner"
                    :allow-clear="true"
                    :options="ownerFilterOptions"
                    :placeholder="$t('text.uploaded_by')"
                    @change="applyAdvancedFilters"
                />
                <a-select
                    v-model:value="filterParams.usage"
                    :allow-clear="true"
                    :options="usageFilterOptions"
                    :placeholder="$t('text.linked-target')"
                    @change="applyAdvancedFilters"
                />
                <a-range-picker
                    v-model:value="createdDateRange"
                    value-format="YYYY-MM-DD"
                    @change="handleCreatedDateRangeChange"
                />
                <a-input
                    v-model:value="filterParams.target_folder"
                    :placeholder="$t('text.target_folder')"
                    allow-clear
                    @change="applyAdvancedFiltersDebounced"
                    @pressEnter="applyAdvancedFilters"
                />
                <a-button @click="clearAdvancedFilters">
                    {{ $t('nav.b.clear') }}
                </a-button>
            </div>

            <a-collapse
                v-if="!privateStore"
                v-model:activeKey="advancedPanelKeys"
                class="!leading-none media-center-folders"
            >
                <a-collapse-panel key="folder-tree" :header="$t('web.file.folder')">
                    <div class="max-h-52 overflow-y-auto rounded-md border border-gray-200 bg-white p-3">
                        <a-tree
                            v-if="folderList?.data?.length > 0"
                            :tree-data="folderList?.data || []"
                            default-expand-all
                            show-icon
                            show-line
                            @select="handleSelectFolder"
                        />
                        <button
                            v-else
                            class="relative block h-24 w-full rounded-lg border-2 border-dashed border-gray-300 text-center hover:border-gray-400 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            type="button"
                            @click="currentTab = 'upload'"
                        >
                            <i-icon icon="folder-multiple" class="size-6" />

                            <span class="mt-2 block text-sm font-semibold text-gray-900">
                                {{ $t('web.document.empty-message') }}
                            </span>
                        </button>
                    </div>
                </a-collapse-panel>
            </a-collapse>
        </div>

        <div ref="currentBox" class="flex-1 min-h-0">
            <div class="pt-3">
                <a-tabs v-model:activeKey="currentTab" class="media-center-tabs">
                    <a-tab-pane key="gallery" :tab="$t('nav.a.media-manage')">
                        <a-spin :loadingSpinner="loadingSpinner" :spinning="isLoading || isFetching">
                            <template v-if="galleryTotal > 0">
                                <div
                                    ref="galleryFrame"
                                    :style="{ minHeight: `${galleryMinHeight}px` }"
                                    class="media-center-gallery flex min-h-[320px] flex-col pb-4"
                                >
                                    <MediaListThumbnail
                                        v-if="['image', 'avatar'].includes(type)"
                                        v-model="selectedIds"
                                        :data="galleryRows"
                                        :isSelectDisabled="isSelectDisabled"
                                        @select="handleSelect"
                                        @view-detail="viewDetail"
                                    />
                                    <MediaListFile
                                        v-else
                                        v-model="selectedIds"
                                        :data="galleryRows"
                                        :isSelectDisabled="isSelectDisabled"
                                        @select="handleSelect"
                                        @view-detail="viewDetail"
                                    />

                                    <a-pagination
                                        ref="paginationRef"
                                        v-model:current="filterParams.page"
                                        v-model:page-size="filterParams.pageSize"
                                        :page-size-options="getPageSizeOptions()"
                                        :show-size-changer="true"
                                        :total="galleryTotal"
                                        class="custom-paginator mt-auto pt-4 text-right"
                                        responsive
                                        @change="mathTotalPageSize"
                                    />
                                </div>
                            </template>
                            <button
                                v-else
                                class="relative block w-full rounded-lg border-2 border-dashed border-gray-300 p-12 text-center hover:border-gray-400 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                type="button"
                                @click="currentTab = 'upload'"
                            >
                                <i-icon icon="folder-multiple" class="size-6" />

                                <span class="mt-2 block text-sm font-semibold text-gray-900">
                                    {{ $t('text.folder.no-file') }}
                                </span>
                            </button>
                        </a-spin>
                    </a-tab-pane>

                    <a-tab-pane v-if="type !== 'all'" key="upload" :tab="$t('nav.b.upload')">
                        <div>
                            <a-upload-dragger
                                v-model:file-list="fileList"
                                :accept="uploadAccept"
                                :action="uploadAction"
                                :data="uploadData"
                                :headers="requestHeaders"
                                :list-type="['image', 'avatar'].includes(type) ? 'picture' : 'text'"
                                class="group"
                                multiple
                                name="file"
                                with-credentials
                                @change="uploadOnChange"
                            >
                                <p
                                    class="ant-upload-drag-icon text-3xl text-gray-500 transition-all group-hover:text-gray-600"
                                >
                                    <i-icon class="size-4" icon="cloud-upload" />
                                </p>
                                <p class="ant-upload-text text-gray-700!">
                                    {{ $t('text.select') }}
                                </p>
                                <p class="ant-upload-hint text-center inline-flex text-gray-500!">
                                    <span v-if="['image', 'avatar'].includes(type)" class="max-w-lg">
                                        {{ $t('text.upload-image-description') }}
                                    </span>
                                    <span v-else-if="uploadHint" class="max-w-lg">{{ uploadHint }}</span>
                                    <span v-else class="max-w-lg">
                                        {{
                                            $t('text.upload-file-description', {
                                                s: '100Mb',
                                            })
                                        }}
                                    </span>
                                </p>
                            </a-upload-dragger>
                        </div>
                    </a-tab-pane>
                </a-tabs>
            </div>
        </div>

        <a-drawer
            v-model:open="viewDetailState"
            :title="$t('text.acc.meta')"
            class="custom-class"
            placement="right"
        >
            <template v-if="currentFile.id">
                <div class="pb-4">
                    <img
                        v-if="['image', 'avatar'].includes(currentFile.type)"
                        :alt="currentFile.name"
                        :src="currentFile.url"
                        class="w-full"
                    />
                    <audio
                        v-else-if="type === 'audio'"
                        :src="`${apiUrl}${removeLeftTrailingSlash(currentFile.url)}`"
                        class="w-full"
                        controls
                    ></audio>
                </div>
            </template>
            <div class="flex items-center space-x-4">
                <template v-if="!privateStore">
                    <a-tooltip :title="$t('text.linked-target')">
                        <p class="text-cyan-500">
                            <span><i-icon icon="link-box" class="size-4" /></span>
                            {{ currentFile.pivots_count || 0 }}
                        </p>
                    </a-tooltip>
                    <p class="text-green-500">
                        <span><i-icon class="size-4" icon="eye" /></span>
                        {{ currentFile.custom?.view || 0 }}
                    </p>
                    <p class="text-orange-500">
                        <span><i-icon icon="cloud-download" class="size-4" /></span>
                        {{ currentFile.custom?.downloaded || 0 }}
                    </p>
                </template>
                <p class="text-slate-500">
                    <span><i-icon icon="archive" class="size-4" /></span>
                    {{ currentFile.size || 0 }}
                </p>
            </div>
            <div>
                <h2 class="text-sm font-medium text-gray-500 m-0">
                    {{ $t('text.file-name') }}
                </h2>
                <div class="mb-3 mt-1 line-clamp-2">
                    {{ currentFile.name || 'N/a' }}
                </div>
            </div>
            <div v-if="['image', 'avatar'].includes(currentFile.type) && currentFile.dimension">
                <h2 class="text-sm font-medium text-gray-500 m-0">
                    {{ $t('text.file-size') }}
                </h2>
                <div class="mb-3 mt-1 line-clamp-2">
                    {{ currentFile.dimension }}
                </div>
            </div>
            <div>
                <h2 class="text-sm font-medium text-gray-500 m-0">
                    <span v-if="currentFile?.uploader" class="font-bold text-indigo-500 pr-1">{{
                        currentFile.uploader.name
                    }}</span>
                    {{ $t('text.uploaded_at') }}
                </h2>
                <div class="mb-3 mt-1 line-clamp-2">
                    {{ currentFile.created_at || 'N/a' }}
                </div>
            </div>
            <div v-if="currentFile.url">
                <h2 class="text-sm font-medium text-gray-500 m-0">
                    {{ $t('text.menu-url') }}
                </h2>
                <div class="mb-3 mt-1 line-clamp-2">
                    <a
                        :href="getFileUrl(currentFile)"
                        :title="currentFile.name"
                        class="line-clamp-2 text-green-800 hover:text-green-500"
                        target="_blank"
                    >
                        {{ currentFile.url || 'N/a' }}
                    </a>
                </div>
            </div>

            <template #footer>
                <a-popconfirm
                    v-if="!privateStore && (currentFile?.pivots_count || 0) < 1 && rule('upload.delete')"
                    :cancel-text="$t('nav.b.cancel')"
                    :ok-text="$t('nav.b.delete')"
                    :title="$t('text.del-confirm')"
                    ok-type="danger"
                    @confirm="() => deleteFile()"
                >
                    <a-button danger type="primary">
                        <span class="pr-2">
                            <i-icon class="size-4" icon="delete" />
                        </span>
                        <span>
                            {{ $t('nav.b.delete') }}
                        </span>
                    </a-button>
                </a-popconfirm>
            </template>
        </a-drawer>
    </div>
</template>

<script setup>
import { computed, defineComponent, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useUploadFolders, useUploads } from '@/hooks'
import { apiBase, apiUrl, cookie, COOKIE_KEY_TOKEN, defaultFilterParams, domain } from '@/config'
import { runtimeConfig } from '@/config/runtime'
import MediaListThumbnail from '@/components/media-list-thumbnail.vue'
import MediaListFile from '@/components/media-list-file.vue'
import { iAlert, rule, loadingSpinner, removeLeftTrailingSlash } from '@/mixins'
import { debounce } from 'lodash-es'
import { useI18n } from 'vue-i18n'
import { deleteUpload } from '@/libs/upload'

const acceptFileTypeList = {
    image: 'image/gif, image/vnd.microsoft.icon, image/jpeg, image/png, image/svg+xml, image/webm, image/webp, image/x-icon',
    audio: 'audio/aac, audio/mpeg, application/ogg, audio/wav, audio/webm, audio/x-flac, audio/flac, video/x-ms-wma, audio/*',
    video: 'video/x-msvideo, video/mpeg, video/webm, video/mp2t, video/mp4, video/x-ms-wmv',
    document:
        'text/csv, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document,' +
        ' application/pdf, application/vnd.ms-powerpoint, application/vnd.openxmlformats-officedocument.presentationml.presentation,' +
        ' text/plain, application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    archive: 'application/gzip, application/vnd.rar, application/zip, application/x-7z-compressed',
}

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    limit: { type: Number, default: 0 },
    target: { type: String, default: null },
    targetId: { type: Number, default: 0 },
    category: { type: String, default: 'images' },
    show: { type: Boolean, default: false },
    folder: { type: String, default: null },
    type: {
        type: String,
        default: 'image',
        validator: (val) =>
            ['image', 'avatar', 'video', 'audio', 'archive', 'document', 'all'].includes(val),
    },
    // Optional module-owned routes (relative to the admin API base). Without them the center lists `upload` and
    // uploads to `upload/{type}`; with them it works on a private document store: no folders, filters, public URLs,
    // deletes, and PDF-only uploads.
    listEndpoint: { type: String, default: null },
    uploadEndpoint: { type: String, default: null },
    uploadHint: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'MediaCenter',
})

const privateStore = computed(() => Boolean(props.listEndpoint || props.uploadEndpoint))
const uploadAction = computed(() => `${apiBase}${props.uploadEndpoint || `upload/${props.type}`}`)
const uploadAccept = computed(() => {
    if (props.uploadEndpoint) {
        return 'application/pdf, .pdf'
    }

    return props.type !== 'all' ? acceptFileTypeList[props.type] : '*'
})

const getFileUrl = (file) => {
    if (file.url.includes('http')) {
        return file.url
    }

    return `${apiUrl}${removeLeftTrailingSlash(file.url)}`
}

const token = cookie.get(COOKIE_KEY_TOKEN)
const totalItemsPerPage = ref(10)
const innerModelValue = ref(props.modelValue || [])
const advancedPanelKeys = ref([])
const currentBox = ref(null)
const galleryFrame = ref(null)
const paginationRef = ref(null)
const galleryMinHeight = ref(320)
let galleryResizeObserver = null
const viewDetailState = ref(false)
const filterString = ref(null)
const createdDateRange = ref([])
const currentFile = ref({})
const { t } = useI18n()
const ownerFilterOptions = computed(() => [
    { label: t('text.media.filter.mine'), value: 'mine' },
])
const usageFilterOptions = computed(() => [
    { label: t('text.media.filter.used'), value: 'used' },
    { label: t('text.media.filter.unused'), value: 'unused' },
])
const filterParams = reactive({
    ...defaultFilterParams,
    pageSize: totalItemsPerPage.value,
    folder: props.folder,
    type: props.type,
})
const folderParams = reactive({
    type: props.type,
})
const currentTab = ref('gallery')
const fileList = ref([])
const selectedIds = ref([])
const requestHeaders = {
    Authorization: 'Bearer ' + token,
    'Access-Control-Allow-Origin': domain,
    'ISPA-ADMIN-AUTH-KEY': runtimeConfig().adminAuthKey,
}
// A module upload route derives target, category and folder itself.
const uploadData = props.uploadEndpoint
    ? {}
    : {
          target: props.target,
          targetId: props.targetId,
          category: props.category,
          folder: props.folder,
      }
//  Upload hooks
const {
    data: mediaFiles,
    isLoading,
    isFetching,
    refetch: retchUpload,
} = useUploads(
    filterParams,
    {
        enabled: props.show,
    },
    props.listEndpoint
)
//  Upload folder hooks
const { data: folderList, refetch: retchFolderTree } = useUploadFolders(folderParams, {
    enabled: props.show && !privateStore.value,
})
// The folder tree belongs to the general media store; a private store never loads it.
const retchFolder = () => {
    if (!privateStore.value) {
        retchFolderTree()
    }
}
// Rows of the current gallery page. A private store lists documents by their original file name, never by link.
const galleryBody = computed(() => mediaFiles.value?.data)
const galleryRows = computed(() => {
    const body = galleryBody.value
    const rows = Array.isArray(body) ? body : body?.data || []

    if (!privateStore.value) {
        return rows
    }

    return rows.map((row) => ({ ...row, name: row.name_alias || row.name, url: '' }))
})
const galleryTotal = computed(() => {
    const body = galleryBody.value

    return Array.isArray(body) ? (mediaFiles.value?.meta?.total ?? body.length) : body?.total
})
const currentFolderPath = computed(() => {
    const folderPath = filterParams.view_folder || ''
    if (!folderPath) {
        return ''
    }
    return folderPath
})
const currentFolderLabel = computed(() => {
    const folderPath = currentFolderPath.value
    const segments = folderPath.split('/').filter(Boolean)

    return segments.length ? segments.at(-1) : folderPath
})
//  Get upload list
const getUploadList = async () => {
    Object.assign(filterParams, {
        folder: props.folder,
        type: props.type,
    })
}

const clearCurrentFolder = () => {
    if (filterParams.view_folder) {
        delete filterParams.view_folder
        filterParams.page = 1
        getUploadList()
    }
}

const toggleAdvancedPanel = () => {
    advancedPanelKeys.value = advancedPanelKeys.value.length ? [] : ['folder-tree']
}

const applyAdvancedFilters = () => {
    filterParams.page = 1
    getUploadList()
}

const applyAdvancedFiltersDebounced = debounce(applyAdvancedFilters, 500)

const handleCreatedDateRangeChange = (dates = []) => {
    const [createdFrom = '', createdTo = ''] = dates || []
    if (createdFrom) {
        filterParams.created_from = createdFrom
    } else {
        delete filterParams.created_from
    }
    if (createdTo) {
        filterParams.created_to = createdTo
    } else {
        delete filterParams.created_to
    }
    applyAdvancedFilters()
}

const clearAdvancedFilters = () => {
    delete filterParams.owner
    delete filterParams.usage
    delete filterParams.created_from
    delete filterParams.created_to
    delete filterParams.target_folder
    createdDateRange.value = []
    applyAdvancedFilters()
}

const measureGalleryHeight = () => {
    const galleryElement = galleryFrame.value
    if (!galleryElement || currentTab.value !== 'gallery') {
        return
    }

    const modalBody = galleryElement.closest('.ant-modal-body')
    const bodyRect = modalBody?.getBoundingClientRect()
    const galleryRect = galleryElement.getBoundingClientRect()

    if (!bodyRect || galleryRect.top <= 0) {
        return
    }

    const bottomGap = 16
    const availableHeight = Math.floor(bodyRect.bottom - galleryRect.top - bottomGap)
    galleryMinHeight.value = Math.max(320, availableHeight)
}

const measureGalleryHeightNextTick = () => {
    nextTick(() => {
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(measureGalleryHeight)
            return
        }
        measureGalleryHeight()
    })
}
//  Action when user select a folder
const handleSelectFolder = (folder = []) => {
    //  Only get first folder
    const selectedFolder = folder[0] || ''
    if (selectedFolder) {
        filterParams.view_folder = selectedFolder
    } else {
        delete filterParams.view_folder
    }
    //  Reset page
    filterParams.page = 1

    getUploadList()
}
const updateModelValue = () => {
    emit('update:modelValue', innerModelValue.value)
}
//  Is the image disabled to select?
const isSelectDisabled = (imageId = 0) => {
    return imageId > 0
        ? !selectedIds.value.includes(imageId) &&
              props.limit > 0 &&
              selectedIds.value.length >= props.limit
        : false
}
//  Handle select image
const handleSelect = ($event, indexInObject, imageId) => {
    const isChecked = $event.target.checked || false
    const currentSelectedIds = [...selectedIds.value]
    const currentSelectedObject = [...innerModelValue.value]
    if (isChecked) {
        //  Get the image object from the uploadsList
        const selectedImageObject = galleryRows.value.find(
            (image = {}) => image.id === imageId
        )
        selectedIds.value = [...currentSelectedIds, imageId]
        // push selected file to the files array
        innerModelValue.value = [...currentSelectedObject, selectedImageObject]
    } else {
        //  Remove the selected file from the files array
        selectedIds.value = currentSelectedIds.filter((i) => i !== imageId)
        //  Remove the selected file from the files array
        innerModelValue.value = currentSelectedObject.filter((image = {}) => image.id !== imageId)
    }
}
//  Process to upload file
const uploadOnChange = ({ file }) => {
    //  Check the response from server and update the state vs props
    const { status = 'uploading', response = {} } = file
    if (status === 'done') {
        const { data, status: serverStatus } = response
        // A module upload route answers 2xx on success; its body is not the general upload envelope.
        if (props.uploadEndpoint || (data && serverStatus === 'success')) {
            mathTotalPageSize()
            //  Re-fetch data & folder
            retchUpload()
            retchFolder()
            //  Change current tab
            currentTab.value = 'gallery'
        }
    } else if (status === 'error') {
        iAlert(response?.message || t('text.media.upload_failed'), true)
    }
}
//  Mapping selected ids
const mappingData = () => {
    selectedIds.value = innerModelValue.value.map((image) => {
        return image.id
    })
}
//  Math total pageSize
const mathTotalPageSize = () => {
    //  Set new pageSize only for image type
    if (['image', 'avatar'].includes(props.type)) {
        const { offsetWidth = 0, offsetHeight = 0 } = currentBox.value

        if (props.show && (offsetWidth === 0 || offsetHeight < 200)) {
            return setTimeout(() => mathTotalPageSize(), 1000)
        }
        const ratio = offsetWidth / offsetHeight

        const itemsPerRow = (width) => {
            if (width > 1024) {
                return 6
            } else if (width > 768) {
                return 4
            } else if (width > 640) {
                return 3
            } else {
                return 2
            }
        }

        const totalItems = itemsPerRow(offsetWidth) * (ratio > 1.75 ? 2 : 3)

        if (totalItems !== totalItemsPerPage.value) {
            Object.assign(filterParams, {
                pageSize: totalItems,
            })
        }
        //  Re update totalItemsPerPage
        totalItemsPerPage.value = totalItems
    }

    nextTick(mappingData)
}

const getPageSizeOptions = () => {
    const limit = totalItemsPerPage.value

    return [limit, limit * 2, limit * 3, limit * 5]
}
//  View detail a file
const viewDetail = (file) => {
    viewDetailState.value = true
    currentFile.value = file
}
//  Set params keywords by filterString
const setParamsKeywords = () => {
    Object.assign(filterParams, {
        keywords: filterString.value,
    })
}
const setParamsKeywordsDebounced = debounce(setParamsKeywords, 500)
//  Call to delete current file
const deleteFile = async () => {
    //  Call to delete file
    await deleteUpload(currentFile.value.id).then(() => {
        //  Re-fetch data & folder
        retchUpload()
        retchFolder()
        //  Change current tab
        currentTab.value = 'gallery'
        //  Close drawer
        viewDetailState.value = false
    })
}

onMounted(() => {
    innerModelValue.value = props.modelValue || []

    nextTick(() => {
        mappingData()
        mathTotalPageSize()
        measureGalleryHeight()
    })

    window.addEventListener('resize', measureGalleryHeightNextTick)

    if (typeof ResizeObserver === 'function') {
        galleryResizeObserver = new ResizeObserver(measureGalleryHeightNextTick)
        nextTick(() => {
            const modalBody = currentBox.value?.closest('.ant-modal-body')
            if (modalBody) {
                galleryResizeObserver.observe(modalBody)
            }
            if (currentBox.value) {
                galleryResizeObserver.observe(currentBox.value)
            }
        })
    }
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', measureGalleryHeightNextTick)
    galleryResizeObserver?.disconnect()
})

watch(
    () => props.type,
    (val) => {
        if (!val) {
            return
        }
        filterParams.type = val
        filterParams.page = 1
        delete filterParams.view_folder
        folderParams.type = val
        currentTab.value = 'gallery'
        if (props.show) {
            retchUpload()
            retchFolder()
        }
        measureGalleryHeightNextTick()
    }
)

watch(
    () => props.folder,
    (val) => {
        filterParams.folder = val || null
        uploadData.folder = val || null
        if (filterParams.view_folder) {
            delete filterParams.view_folder
        }
        if (props.show) {
            retchUpload()
        }
        measureGalleryHeightNextTick()
    }
)

//  Watch show prop to get current width & height of box => math ratio & amount files to showing in the list
watch([() => props.show, () => props.modelValue], (val) => {
    const [show, modelValue] = val
    if (show) {
        nextTick(() => {
            mathTotalPageSize()
            measureGalleryHeight()
        })
    }
    if (modelValue !== innerModelValue.value) {
        innerModelValue.value = modelValue

        mappingData()
    }
})

watch(
    () => innerModelValue.value,
    (val) => {
        updateModelValue(val)
    }
)

watch(
    () => [advancedPanelKeys.value, currentTab.value, galleryTotal.value],
    () => {
        measureGalleryHeightNextTick()
    },
    {
        deep: true,
    }
)
</script>

<style scoped>
:deep(.media-center-tabs > .ant-tabs-content-holder),
:deep(.media-center-tabs > .ant-tabs-content-holder > .ant-tabs-content),
:deep(.media-center-tabs > .ant-tabs-content-holder > .ant-tabs-content > .ant-tabs-tabpane),
:deep(.media-center-tabs .ant-spin-nested-loading),
:deep(.media-center-tabs .ant-spin-container) {
    height: 100%;
    min-height: 0;
}
</style>
