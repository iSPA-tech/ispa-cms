<template>
    <a-popover
        v-model:open="popoverState"
        placement="bottom"
        :title="t('text.editor.media_image.title')"
        trigger="click"
    >
        <template #content>
            <a-form
                :model="innerValue"
                class="w-[360px] max-w-[80vw]"
                layout="vertical"
                @finish="submitForm"
            >
                <div class="mb-3 rounded-sm border border-dashed border-gray-300 bg-gray-50 p-2">
                    <div v-if="innerValue.src" class="mb-2 overflow-hidden rounded-sm bg-white">
                        <img
                            :alt="innerValue.alt"
                            :src="previewSrc"
                            class="max-h-44 w-full object-contain"
                        />
                    </div>
                    <a-button size="small" type="default" @click="openMediaCenter">
                        <span><i-icon icon="images" /></span>
                        <span>{{ t('text.editor.media_image.select') }}</span>
                    </a-button>
                </div>

                <a-form-item
                    class="mb-2!"
                    :label="t('text.image.alt')"
                    name="alt"
                >
                    <a-input v-model:value="innerValue.alt" />
                </a-form-item>

                <a-form-item
                    class="mb-2!"
                    :label="t('text.editor.media_image.caption')"
                    name="caption"
                >
                    <a-input v-model:value="innerValue.caption" />
                </a-form-item>

                <a-form-item
                    class="mb-2!"
                    :label="t('text.editor.media_image.align')"
                    name="align"
                >
                    <a-radio-group
                        v-model:value="innerValue.align"
                        button-style="solid"
                        size="small"
                    >
                        <a-radio-button
                            v-for="align in mediaImageAlignments"
                            :key="align"
                            :value="align"
                        >
                            {{ t(`text.editor.media_image.align_${align}`) }}
                        </a-radio-button>
                    </a-radio-group>
                </a-form-item>

                <a-form-item
                    class="mb-2!"
                    :label="t('text.editor.media_image.size')"
                    name="size"
                >
                    <a-radio-group
                        v-model:value="innerValue.size"
                        button-style="solid"
                        size="small"
                    >
                        <a-radio-button v-for="size in mediaImageSizes" :key="size" :value="size">
                            {{ t(`text.editor.media_image.size_${size}`) }}
                        </a-radio-button>
                    </a-radio-group>
                </a-form-item>

                <a-form-item
                    class="mb-2!"
                    :label="t('text.editor.media_image.width')"
                    name="width"
                >
                    <a-input
                        v-model:value="innerValue.width"
                        allow-clear
                        placeholder="360px / 45%"
                    />
                </a-form-item>

                <a-form-item class="mb-0!">
                    <div class="flex items-center gap-2">
                        <a-button
                            v-if="isCurrentMediaImage()"
                            danger
                            size="small"
                            type="default"
                            @click="removeCurrent"
                        >
                            {{ t('nav.b.delete') }}
                        </a-button>
                        <a-button class="ml-auto" size="small" @click="popoverState = false">
                            {{ t('nav.b.cancel') }}
                        </a-button>
                        <a-button
                            :disabled="!innerValue.src || mediaSubmitting"
                            :loading="mediaSubmitting"
                            html-type="submit"
                            size="small"
                            type="primary"
                        >
                            {{ t('nav.b.save') }}
                        </a-button>
                    </div>
                </a-form-item>
            </a-form>
        </template>

        <button
            :aria-label="toolbar?.title || t('text.editor.media_image.title')"
            :class="
                classNames(
                    isCurrentMediaImage() ? 'bg-cyan-600 text-white' : '',
                    'hover:bg-gray-200 hover:text-gray-900 text-gray-600 text-center text-base rounded-sm',
                    'w-6 h-6 inline-flex items-center justify-center'
                )
            "
            type="button"
            @click="onOpen"
        >
            <i-icon icon="image" class="size-4" />
        </button>
    </a-popover>

    <modal-media-center
        :id="modalId"
        v-model="selectedMedia"
        v-model:show="mediaShowState"
        :limit="1"
        type="image"
    />
</template>

<script setup>
import { computed, defineComponent, nextTick, ref, watch } from 'vue'
import { apiUrl } from '@/config'
import { classNames } from '@/mixins'
import { useI18n } from 'vue-i18n'
import {
    createMediaImageAttributes,
    mediaImageAlignments,
    mediaImageSizes,
    normalizeMediaImageAttributes,
} from '@/components/tiptap-media-image-extension'
import ModalMediaCenter from '@/components/modal-media-center.vue'

defineComponent({
    name: 'TipTapMediaImage',
})

const { t } = useI18n()

const { editor, toolbar, isActive } = defineProps({
    editor: {
        type: Object,
    },
    toolbar: {
        type: Object,
    },
    isActive: {
        type: Function,
    },
})

const defaultValue = {
    src: '',
    uploadId: '',
    align: 'center',
    size: 'medium',
    width: '',
    alt: '',
    caption: '',
}

const innerValue = ref({ ...defaultValue })
const selectedMedia = ref([])
const popoverState = ref(false)
const mediaSubmitting = ref(false)
const mediaShowState = ref(false)
const reopenPopoverAfterMediaCenter = ref(false)
const modalId = `tiptap-inline-media-image-${Math.random().toString(36).slice(2)}`

const isCurrentMediaImage = () => {
    return Boolean(isActive?.('mediaImage'))
}

const previewSrc = computed(() => {
    const src = innerValue.value.src || ''
    if (!src || /^(https?:)?\/\//i.test(src) || src.startsWith('data:')) {
        return src
    }

    return `${apiUrl.replace(/\/$/, '')}/${src.replace(/^\/+/, '')}`
})

const onOpen = () => {
    if (isCurrentMediaImage()) {
        innerValue.value = normalizeMediaImageAttributes(editor.getAttributes('mediaImage'))
    } else {
        innerValue.value = { ...defaultValue }
    }

    popoverState.value = true
}

const openMediaCenter = async () => {
    reopenPopoverAfterMediaCenter.value = true
    popoverState.value = false
    await nextTick()
    mediaShowState.value = true
}

const removeCurrent = () => {
    editor.chain().focus().deleteSelection().run()
    popoverState.value = false
}

const submitForm = () => {
    if (mediaSubmitting.value) {
        return
    }

    mediaSubmitting.value = true
    const attributes = normalizeMediaImageAttributes(innerValue.value)

    if (!attributes.src) {
        mediaSubmitting.value = false
        return
    }

    if (isCurrentMediaImage()) {
        editor.chain().focus().updateMediaImage(attributes).run()
    } else {
        editor.chain().focus().setMediaImage(attributes).run()
    }

    popoverState.value = false
    mediaSubmitting.value = false
}

watch(
    selectedMedia,
    (value) => {
        const upload = value?.[0]
        if (!upload) {
            return
        }

        innerValue.value = createMediaImageAttributes(upload, innerValue.value)
    },
    {
        deep: true,
    }
)

watch(mediaShowState, async (isOpen) => {
    if (isOpen || !reopenPopoverAfterMediaCenter.value) {
        return
    }

    reopenPopoverAfterMediaCenter.value = false
    await nextTick()
    popoverState.value = true
})
</script>
