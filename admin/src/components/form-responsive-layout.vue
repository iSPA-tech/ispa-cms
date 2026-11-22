<template>
    <div class="form-responsive-layout grid gap-x-3 lg:grid-cols-2">
        <div class="col-span-full mb-3">
            <div class="font-medium">
                {{ $t(item ? 'text.form_builder.responsive_item_span' : 'text.form_builder.responsive_columns') }}
            </div>
            <p class="mb-0 mt-1 text-xs text-gray-500">
                {{ $t(item ? 'text.form_builder.responsive_item_span_help' : 'text.form_builder.responsive_columns_help') }}
            </p>
        </div>

        <a-form-item
            v-for="device in devices"
            :key="device.key"
            :data-responsive-setting="`${settingPrefix}.${device.key}`"
            :html-for="inputId(device.key)"
            :label="$t(device.label)"
        >
            <a-select
                :id="inputId(device.key)"
                :value="settingValue(device.key)"
                class="w-full"
                @change="setSetting(device.key, $event)"
            >
                <template v-if="item">
                    <a-select-option value="auto">
                        {{ $t('text.form_builder.responsive_auto') }}
                    </a-select-option>
                    <a-select-option value="full">
                        {{ $t('text.form_builder.responsive_full') }}
                    </a-select-option>
                </template>
                <a-select-option v-for="count in 3" :key="count" :value="count">
                    {{ $t('text.form_builder.responsive_count', { count }) }}
                </a-select-option>
            </a-select>
        </a-form-item>
    </div>
</template>

<script>
import { computed, defineComponent } from 'vue'

const devices = [
    { key: 'mobile', label: 'text.form_builder.responsive_mobile' },
    { key: 'tablet', label: 'text.form_builder.responsive_tablet' },
    { key: 'desktop', label: 'text.form_builder.responsive_desktop' },
]

const validColumnCount = (value) => Number.isInteger(value) && value >= 1 && value <= 3
const validSpan = (value) => ['auto', 'full'].includes(value) || validColumnCount(value)

export default defineComponent({
    name: 'FormResponsiveLayout',
    props: {
        form: { type: Object, required: true },
        item: { type: Object, default: null },
    },
    setup(props) {
        const settingPrefix = computed(() => props.item ? 'span' : 'columns')
        const inputId = (device) => `responsive-layout-${props.item ? 'item' : 'form'}-${device}`
        const settingValue = (device) => {
            const value = props.item
                ? props.item.custom?.layout?.span?.[device]
                : props.form.custom?.layout?.columns?.[device]
            return props.item
                ? (validSpan(value) ? value : 'auto')
                : (validColumnCount(value) ? value : 1)
        }
        const setSetting = (device, value) => {
            const target = props.item || props.form
            const layoutKey = props.item ? 'span' : 'columns'
            const normalized = props.item
                ? (validSpan(value) ? value : 'auto')
                : (validColumnCount(Number(value)) ? Number(value) : 1)

            target.custom = {
                ...(target.custom || {}),
                layout: {
                    ...(target.custom?.layout || {}),
                    [layoutKey]: {
                        ...(target.custom?.layout?.[layoutKey] || {}),
                        [device]: normalized,
                    },
                },
            }
        }

        return { devices, settingPrefix, inputId, settingValue, setSetting }
    },
})
</script>
