<template>
    <a-modal
        :open="open"
        :title="t('nav.b.preview')"
        :footer="null"
        :width="1180"
        destroy-on-close
        @cancel="closePreview"
    >
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
            <div
                class="flex flex-col gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="m-0 text-sm font-semibold text-slate-800">
                        {{ t('text.form.unsaved_changes') }}
                    </p>
                    <p class="m-0 mt-1 text-xs text-slate-500">
                        {{ t('nav.b.preview') }} · {{ activeViewport.label }}
                    </p>
                </div>

                <div
                    class="inline-flex w-fit rounded-md border border-slate-200 bg-slate-50 p-1"
                    role="group"
                    :aria-label="t('text.widget.responsive_test_size')"
                >
                    <button
                        v-for="option in viewportOptions"
                        :key="option.key"
                        type="button"
                        class="min-h-9 rounded px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-600"
                        :class="
                            viewport === option.key
                                ? 'bg-slate-900 text-white shadow-sm'
                                : 'text-slate-600 hover:bg-white hover:text-slate-900'
                        "
                        :aria-label="option.label"
                        :aria-pressed="viewport === option.key"
                        :data-test="`post-preview-viewport-${option.key}`"
                        @click="viewport = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div class="max-h-[72vh] overflow-auto p-3 sm:p-6">
                <div
                    class="mx-auto overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl transition-[width] duration-200"
                    :style="previewFrameStyle"
                    data-test="post-preview-frame"
                >
                    <article class="mx-auto max-w-3xl px-5 py-8 sm:px-8 sm:py-10">
                        <header class="border-b border-slate-200 pb-6">
                            <div class="mb-4 flex flex-wrap items-center gap-2">
                                <a-tag v-if="post.type" class="m-0 uppercase">
                                    {{ post.type }}
                                </a-tag>
                                <a-tag v-if="post.status" class="m-0 uppercase">
                                    {{ post.status }}
                                </a-tag>
                            </div>

                            <h1
                                class="m-0 text-3xl font-bold leading-tight tracking-tight text-slate-950"
                            >
                                {{ post.name || t('nav.a.posts') }}
                            </h1>
                            <p
                                v-if="post.description"
                                class="m-0 mt-4 text-base leading-7 text-slate-600"
                            >
                                {{ post.description }}
                            </p>

                            <img
                                v-if="coverImage"
                                :src="coverImage"
                                :alt="post.name || t('nav.a.posts')"
                                class="mt-6 max-h-[440px] w-full rounded-lg object-cover"
                                data-test="post-preview-cover"
                            />
                        </header>

                        <div class="mt-7 space-y-7">
                            <template
                                v-for="(block, index) in previewBlocks"
                                :key="blockKey(block, index)"
                            >
                                <SafeHtml
                                    v-if="['text', 'glossary'].includes(block.type)"
                                    :html="block.value"
                                    class="prose prose-slate max-w-none leading-7"
                                    data-test="post-preview-text"
                                />

                                <figure
                                    v-else-if="block.type === 'image' && blockImages(block).length"
                                    class="m-0 space-y-3"
                                    data-test="post-preview-images"
                                >
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <img
                                            v-for="(image, imageIndex) in blockImages(block)"
                                            :key="`${image}-${imageIndex}`"
                                            :src="image"
                                            :alt="
                                                block.options?.alt || post.name || t('nav.a.posts')
                                            "
                                            class="max-h-[520px] w-full rounded-lg object-cover"
                                        />
                                    </div>
                                    <figcaption
                                        v-if="block.options?.caption"
                                        class="text-center text-sm italic text-slate-500"
                                    >
                                        {{ block.options.caption }}
                                    </figcaption>
                                </figure>

                                <div
                                    v-else-if="block.type === 'video' && safeHttpUrl(block.value)"
                                    class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950"
                                    data-test="post-preview-video"
                                >
                                    <iframe
                                        v-if="youtubeEmbedUrl(block.value)"
                                        :src="youtubeEmbedUrl(block.value)"
                                        :title="t('validation.attributes.video_url')"
                                        class="aspect-video w-full border-0"
                                        allow="encrypted-media; picture-in-picture"
                                        allowfullscreen
                                        sandbox="allow-scripts allow-same-origin allow-presentation"
                                        referrerpolicy="strict-origin-when-cross-origin"
                                    />
                                    <a
                                        v-else-if="safeHttpUrl(block.value)"
                                        :href="safeHttpUrl(block.value)"
                                        class="block px-4 py-6 text-center text-sm text-white underline"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {{ t('validation.attributes.video_url') }}
                                    </a>
                                </div>

                                <div
                                    v-else-if="block.type === 'widget'"
                                    class="min-h-32 overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                                    data-test="post-preview-widget"
                                >
                                    <div
                                        v-if="widgetState(block, index).status === 'loading'"
                                        class="flex min-h-32 items-center justify-center"
                                        aria-live="polite"
                                    >
                                        <a-spin />
                                    </div>
                                    <iframe
                                        v-else-if="widgetState(block, index).status === 'success'"
                                        :srcdoc="widgetState(block, index).html"
                                        :title="t('validation.attributes.widget_template_preview')"
                                        class="h-80 w-full border-0 bg-white"
                                        sandbox="allow-scripts"
                                        referrerpolicy="no-referrer"
                                    />
                                    <div
                                        v-else
                                        class="flex min-h-32 flex-col items-center justify-center gap-2 px-4 py-6 text-center text-sm text-slate-600"
                                        role="status"
                                    >
                                        <span>
                                            {{
                                                widgetState(block, index).error || t('nav.b.retry')
                                            }}
                                        </span>
                                        <a-button
                                            size="small"
                                            @click="loadWidgetPreview(block, index)"
                                        >
                                            {{ t('nav.b.retry') }}
                                        </a-button>
                                    </div>
                                </div>

                                <component
                                    v-else-if="contentBlockPreviewFor(block.type)"
                                    :is="contentBlockPreviewFor(block.type)"
                                    :model-value="block"
                                />

                                <div
                                    v-else-if="block.type"
                                    class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500"
                                    data-test="post-preview-unsupported-block"
                                >
                                    {{ block.options?.title || t('validation.attributes.content') }}
                                </div>
                            </template>

                            <a-empty
                                v-if="previewBlocks.length === 0"
                                :description="t('text.post.no_content')"
                            />
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </a-modal>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SafeHtml from '@/components/common/SafeHtml.vue'
import { getWidgetPreview } from '@/libs'
import { adminContentBlockExtensions } from '@/config/admin-modules'
import { useActiveModules } from '@/hooks/useActiveModules'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    post: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['update:open'])
const { t } = useI18n()
const viewport = ref('desktop')
const widgetStates = reactive({})
const widgetControllers = new Map()
const { isModuleExtensionAllowed } = useActiveModules()
const contentBlockPreviewExtensions = computed(() =>
    adminContentBlockExtensions.filter(
        (extension) => extension.previewComponent && isModuleExtensionAllowed(extension.module)
    )
)
const contentBlockPreviewFor = (type) =>
    contentBlockPreviewExtensions.value.find((extension) => extension.type === type)?.previewComponent

const viewportOptions = computed(() => [
    {
        key: 'mobile',
        label: t('text.widget.preview_size.small_mobile'),
        width: '390px',
    },
    {
        key: 'tablet',
        label: t('text.widget.preview_size.tablet_desktop'),
        width: '768px',
    },
    {
        key: 'desktop',
        label: t('text.widget.preview_size.full_width'),
        width: '100%',
    },
])

const activeViewport = computed(
    () =>
        viewportOptions.value.find((option) => option.key === viewport.value) ||
        viewportOptions.value[2]
)
const previewFrameStyle = computed(() => ({
    width: activeViewport.value.width,
    maxWidth: '100%',
}))
const rawBlocks = computed(() =>
    Array.isArray(props.post?.content)
        ? props.post.content.filter((block) => block && typeof block === 'object')
        : []
)

const isSafeImageDataUrl = (value) =>
    /^data:image\/(?:gif|jpeg|jpg|png|webp);base64,[a-z0-9+/]+=*$/i.test(value)

const safeImageUrl = (value) => {
    const source = String(value || '').trim()
    if (!source) return ''

    return /^(https?:\/\/|\/(?!\/)|blob:)/i.test(source) || isSafeImageDataUrl(source) ? source : ''
}

const mediaUrl = (media) => {
    if (typeof media === 'string') return safeImageUrl(media)
    if (!media || typeof media !== 'object') return ''

    return safeImageUrl(
        media.original_url ||
            media.url ||
            media.large ||
            media.medium ||
            media.thumbnail ||
            media.thumbnail_sm
    )
}

const coverImage = computed(() => {
    const images = Array.isArray(props.post?.images) ? props.post.images : []
    return mediaUrl(images[0])
})

const blockImages = (block) => {
    const images = Array.isArray(block?.value) ? block.value : [block?.value]
    return images.map(mediaUrl).filter(Boolean)
}

const safeHttpUrl = (value) => {
    const source = String(value || '').trim()
    return /^https?:\/\//i.test(source) ? source : ''
}

const youtubeEmbedUrl = (value) => {
    const source = safeHttpUrl(value)
    if (!source) return ''

    try {
        const url = new URL(source)
        const host = url.hostname.toLowerCase().replace(/^www\./, '')
        let videoId = ''

        if (host === 'youtu.be') {
            videoId = url.pathname.split('/').filter(Boolean)[0] || ''
        } else if (host === 'youtube.com' || host === 'm.youtube.com') {
            videoId = url.pathname.startsWith('/embed/')
                ? url.pathname.split('/').filter(Boolean)[1] || ''
                : url.searchParams.get('v') || ''
        }

        return /^[a-z0-9_-]{6,}$/i.test(videoId)
            ? `https://www.youtube-nocookie.com/embed/${videoId}`
            : ''
    } catch {
        return ''
    }
}

const hasTextPreview = (value) =>
    String(value || '')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/gi, '')
        .trim().length > 0

const previewBlocks = computed(() =>
    rawBlocks.value.filter((block) => {
        if (['text', 'glossary'].includes(block.type)) return hasTextPreview(block.value)
        if (block.type === 'image') return blockImages(block).length > 0
        if (block.type === 'video') return Boolean(safeHttpUrl(block.value))
        return Boolean(block.type)
    })
)

const blockKey = (block, index) => block.id || `${block.type || 'content'}-${index}`
const widgetKey = (block, index) => `widget-${blockKey(block, index)}`
const widgetState = (block, index) =>
    widgetStates[widgetKey(block, index)] || { status: 'idle', html: '', error: '' }

const widgetPreviewParams = (block) => {
    const params = { template_id: block.template_id }
    const values = block.value && typeof block.value === 'object' ? block.value : {}
    const inputNames = new Set(
        Array.isArray(block.inputs) ? block.inputs.map((input) => input?.name).filter(Boolean) : []
    )

    Object.entries(values).forEach(([key, value]) => {
        if (inputNames.has(key)) params[key] = value
    })

    return params
}

const loadWidgetPreview = async (block, index) => {
    const key = widgetKey(block, index)
    widgetControllers.get(key)?.abort()

    if (!(Number(block.widget_id) > 0) || !(Number(block.template_id) > 0)) {
        widgetStates[key] = { status: 'error', html: '', error: t('nav.b.retry') }
        return
    }

    const controller = new AbortController()
    widgetControllers.set(key, controller)
    widgetStates[key] = { status: 'loading', html: '', error: '' }

    try {
        const html = await getWidgetPreview(block.widget_id, widgetPreviewParams(block), {
            signal: controller.signal,
        })

        if (widgetControllers.get(key) === controller) {
            widgetStates[key] = { status: 'success', html: html || '', error: '' }
        }
    } catch (error) {
        const isCanceled = error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED'
        if (!isCanceled && widgetControllers.get(key) === controller) {
            widgetStates[key] = {
                status: 'error',
                html: '',
                error: error?.response?.data?.message || error?.message || t('nav.b.retry'),
            }
        }
    } finally {
        if (widgetControllers.get(key) === controller) widgetControllers.delete(key)
    }
}

const stopWidgetPreviews = () => {
    widgetControllers.forEach((controller) => controller.abort())
    widgetControllers.clear()
}

const loadWidgetPreviews = () => {
    stopWidgetPreviews()
    if (!props.open) return

    previewBlocks.value.forEach((block, index) => {
        if (block.type === 'widget') loadWidgetPreview(block, index)
    })
}

const closePreview = () => {
    stopWidgetPreviews()
    emit('update:open', false)
}

watch([() => props.open, () => props.post?.content], loadWidgetPreviews, {
    deep: true,
    immediate: true,
})

onBeforeUnmount(stopWidgetPreviews)
</script>
