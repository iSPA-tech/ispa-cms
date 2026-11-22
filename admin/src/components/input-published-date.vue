<template>
    <a-tooltip v-if="!isEdit" :title="$t('nav.b.set_published_date')">
        <span
            @click="openModal"
            role="button"
            class="rounded-sm bg-white hover:bg-blk-100 transition-all size-6 p-1"
        >
            <i-icon
                icon="calendar-clock-outline"
                class="size-4 text-slate-600 hover:text-slate-800"
            />
        </span>
    </a-tooltip>
    <a-tooltip v-else :title="`${$t('nav.b.update')} ${$t('validation.attributes.published_at')}`">
        <span
            @click="openModal"
            role="button"
            class="rounded-sm bg-white hover:bg-blk-100 transition-all size-6 p-1"
        >
            <i-icon icon="pencil-outline" class="size-4 text-slate-600 hover:text-slate-800" />
        </span>
    </a-tooltip>

    <a-modal
        v-model:open="modalState"
        :title="$t('validation.attributes.published_at')"
        @ok="updateValue"
        @cancel="closeModal"
        class="cms-modal"
    >
        <div class="p-3 space-y-3">
            <div class="text-blk-500">
                {{ $t('text.form.published_at').replace(':name', type) }}
            </div>

            <a-date-picker
                class="w-full"
                v-model:value="innerValue"
                :show-time="{ defaultValue: dayjs().hour(0).minute(0) }"
                format="YYYY-MM-DD HH:mm:ss"
                :placeholder="$t('text.form.select_published_date')"
                :allow-clear="false"
            />
        </div>
        <template #footer>
            <div class="flex flex-nowrap justify-end gap-x-2 px-3 py-2">
                <a-button @click="closeModal" type="default" class="rounded-lg">
                    <i-icon icon="close" class="size-4" />
                    <span class="pl-1.5">{{ $t('nav.b.cancel') }}</span>
                </a-button>
                <a-button
                    @click="updateValue"
                    :disabled="!innerValue"
                    type="primary"
                    class="rounded-lg bg-blk-600 hover:bg-blk-700 disabled:bg-blk-100 disabled:text-blk-500"
                >
                    <i-icon icon="content-save" class="size-4" />
                    <span class="pl-1.5">{{ $t('nav.b.set_published_date') }}</span>
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import dayjs from 'dayjs'

defineComponent({
    name: 'InputPublishedDate',
})

const props = defineProps({
    modelValue: { type: [String, Object], default: null },
    type: { type: String, default: 'post' },
    isEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const modalState = ref(false)
const toPickerValue = (value) => {
    if (!value) {
        return dayjs()
    }

    if (dayjs.isDayjs(value)) {
        return value
    }

    const parsed = dayjs(value)

    return parsed.isValid() ? parsed : dayjs()
}
const innerValue = ref(toPickerValue(props.modelValue))

watch(
    () => props.modelValue,
    (value) => {
        if (!modalState.value) {
            innerValue.value = toPickerValue(value)
        }
    }
)

const updateValue = () => {
    emit(
        'update:modelValue',
        innerValue.value ? dayjs(innerValue.value).format('YYYY-MM-DD HH:mm:ss') : null
    )
    modalState.value = false
}
const openModal = () => {
    innerValue.value = toPickerValue(props.modelValue)
    modalState.value = true
}

const closeModal = () => {
    modalState.value = false
    innerValue.value = toPickerValue(props.modelValue)
}
</script>
