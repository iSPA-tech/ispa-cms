<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <slot v-if="$slots.children" name="children">{{ $slots.children }}</slot>

    <a-modal
        :id="`modal-${id}`"
        v-model:open="isShowState"
        :closable="false"
        :keyboard="false"
        :maskClosable="false"
        class="modal-fullscreen"
        wrap-class-name="is-fullscreen"
        @close="closeModal"
    >
        <template #title>
            <span class="text-rose-500 pr-2">
                <template v-if="type === 'all'">
                    <i-icon class="size-4" icon="multimedia" />
                </template>
                <template v-if="['image', 'avatar'].includes(type)">
                    <i-icon class="size-4" icon="images" />
                </template>
                <template v-if="type === 'video'">
                    <i-icon class="size-4" icon="video" />
                </template>
                <template v-if="type === 'document'">
                    <i-icon class="size-4" icon="file-document" />
                </template>
                <template v-if="type === 'audio'">
                    <i-icon class="size-4" icon="waveform" />
                </template>
                <template v-if="type === 'archive'">
                    <i-icon class="size-4" icon="archive" />
                </template>
            </span>

            <span>
                {{ `${$t('nav.a.media-manage')} (${typeLabel})` }}
            </span>
        </template>
        <media-center
            v-model="innerModelValue"
            :folder="folder"
            :limit="limit"
            :show="show"
            :type="type"
            :category="category"
            :list-endpoint="listEndpoint"
            :upload-endpoint="uploadEndpoint"
            :upload-hint="uploadHint"
        />

        <template #footer>
            <div class="flex flex-nowrap items-center space-x-4">
                <a-button class="ml-auto" @click="closeModal">
                    <span><i-icon icon="close" /></span>
                    <span>{{ $t('nav.b.close') }}</span>
                </a-button>
                <a-button
                    :disabled="innerModelValue.length < 1"
                    danger
                    type="primary"
                    @click="diselect"
                >
                    {{ $t('nav.b.diselect') }}
                </a-button>
                <a-button
                    :disabled="innerModelValue.length < 1"
                    class="btn-info"
                    @click="selectFile"
                >
                    <span><i-icon class="size-3" icon="check-circle" /></span>
                    <span>
                        {{ $t('text.select') }}
                        ({{ innerModelValue.length }}{{ limit > 0 ? '/' + limit : '' }})
                    </span>
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    show: { type: Boolean, default: false },
    limit: { type: Number, default: 0 },
    folder: { type: String, default: null },
    category: { type: String, default: 'images' },
    type: {
        type: String,
        default: 'image',
        validator: (val) =>
            ['image', 'avatar', 'video', 'audio', 'archive', 'document', 'all'].includes(val),
    },
    id: { type: String, default: null },
    // Optional module-owned list/upload routes, forwarded to the media center (defaults keep the general store).
    listEndpoint: { type: String, default: null },
    uploadEndpoint: { type: String, default: null },
    uploadHint: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'update:show'])

const { t } = useI18n()
// Upper-cased, translated media type shown in the dialog title.
const typeLabel = computed(() => t(`text.media.type_${props.type}`).toUpperCase())
defineComponent({
    name: 'ModalMediaCenter',
})

const innerModelValue = ref(props.modelValue)
const isShowState = ref(props.show || false)

const selectFile = () => {
    updateModelValue()
    //  Reset model selected data
    innerModelValue.value = []
    //  Prevent modal close
    closeModal()
}
//  Close modal
const closeModal = () => {
    isShowState.value = false

    updateShowValue()
}
const diselect = () => {
    innerModelValue.value = []
    updateModelValue()
}
//  Update model value
const updateModelValue = () => {
    emit('update:modelValue', innerModelValue.value)
}

const updateShowValue = () => {
    emit('update:show', isShowState.value)
}

//  Watch model value to update inner model value
watch(
    () => props.modelValue,
    (newValue) => {
        innerModelValue.value = newValue
    }
)
//  Watch show status
watch(
    () => props.show,
    (newValue) => {
        if (newValue !== isShowState.value) {
            isShowState.value = newValue
        }
    }
)
</script>
