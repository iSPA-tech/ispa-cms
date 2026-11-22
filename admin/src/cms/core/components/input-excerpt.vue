<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    modelValue: {
        type: String,
        default: null,
    },
    name: {
        type: [Array, String],
        default: () => 'description',
    },
    maxlength: {
        type: Number,
        default: 500,
    },
})

const emit = defineEmits(['update:modelValue'])

// Local reactive value
const value = ref(props.modelValue ?? '')

// excerptState must be declared BEFORE watchers
const excerptState = ref(!!(props.modelValue && props.modelValue.trim() !== ''))

// Keep local value in sync with parent
watch(
    () => props.modelValue,
    (v) => {
        if (v !== value.value) {
            value.value = v ?? ''
        }
        // When parent updates with a non-empty string → show form
        excerptState.value = !!(v && v.trim() !== '')
    },
    { immediate: true }
)

// Sync local value back to parent
watch(value, (v) => {
    emit('update:modelValue', v)
    // Update excerptState based on current content
    excerptState.value = !!(v && v.trim() !== '')
})

const formItemName = () => (Array.isArray(props.name) ? props.name : [props.name])
</script>

<template>
    <div class="i-box">
        <div class="flex items-center space-x-2">
            <div class="flex-1">
                <h3 class="font-medium text-blk-950">
                    {{ $t('text.form.excerpt_title') }}
                </h3>
            </div>

            <div>
                <a-tooltip v-if="!excerptState" :title="$t('nav.b.add_excerpt')">
                    <span
                        class="rounded-sm bg-white hover:bg-blk-100 transition-all size-6 p-1"
                        role="button"
                        @click="excerptState = !excerptState"
                    >
                        <i-icon
                            class="size-4 text-slate-600 hover:text-slate-800"
                            icon="pencil-outline"
                        />
                    </span>
                </a-tooltip>
            </div>
        </div>
        <p class="text-slate-500 text-sm">
            {{ $t('text.form.excerpt_description') }}
        </p>
        <div v-if="excerptState">
            <a-form-item :name="formItemName()" class="mb-0">
                <a-textarea
                    v-model:value="value"
                    :maxlength="maxlength"
                    :placeholder="$t('validation.attributes.description')"
                    :rows="3"
                    show-count
                />
            </a-form-item>
        </div>
    </div>
</template>

<style scoped></style>
