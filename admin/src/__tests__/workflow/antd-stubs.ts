import { defineComponent, h } from 'vue'

// Small stand-ins for the globally registered Ant Design components the workflow designer uses.
const passthrough = (name: string, tag = 'div') =>
    defineComponent({
        name,
        inheritAttrs: false,
        setup(_, { slots, attrs }) {
            return () => h(tag, attrs, [slots.header?.(), slots.message?.(), slots.description?.(), slots.prefix?.(), slots.default?.()])
        },
    })

const textInput = (name: string, tag: 'input' | 'textarea') =>
    defineComponent({
        name,
        props: { value: { type: null, default: '' }, disabled: Boolean },
        emits: ['update:value', 'change', 'blur'],
        setup(props, { emit }) {
            return () =>
                h(tag, {
                    value: props.value ?? '',
                    disabled: props.disabled,
                    onInput: (event: Event) => {
                        emit('update:value', (event.target as HTMLInputElement).value)
                        emit('change', event)
                    },
                    onBlur: (event: Event) => emit('blur', event),
                })
        },
    })

export const antdStubs = {
    'a-tooltip': passthrough('ATooltipStub', 'span'),
    'a-tag': passthrough('ATagStub', 'span'),
    'a-spin': passthrough('ASpinStub', 'span'),
    'a-collapse': passthrough('ACollapseStub'),
    'a-collapse-panel': passthrough('ACollapsePanelStub', 'section'),
    'a-alert': passthrough('AAlertStub'),
    'a-button': passthrough('AButtonStub', 'button'),
    'a-empty': defineComponent({
        name: 'AEmptyStub',
        props: { description: { type: String, default: '' } },
        setup: (props) => () => h('div', { class: 'empty-stub' }, props.description),
    }),
    'a-input': textInput('AInputStub', 'input'),
    'a-textarea': textInput('ATextareaStub', 'textarea'),
    'a-input-number': defineComponent({
        name: 'AInputNumberStub',
        props: { value: { type: null, default: null }, disabled: Boolean },
        emits: ['update:value'],
        setup(props, { emit }) {
            return () =>
                h('input', {
                    type: 'number',
                    value: props.value ?? '',
                    disabled: props.disabled,
                    onInput: (event: Event) => {
                        const raw = (event.target as HTMLInputElement).value
                        emit('update:value', raw === '' ? null : Number(raw))
                    },
                })
        },
    }),
    'a-switch': defineComponent({
        name: 'ASwitchStub',
        props: { checked: Boolean, disabled: Boolean },
        emits: ['update:checked'],
        setup(props, { emit }) {
            return () =>
                h('input', {
                    type: 'checkbox',
                    checked: props.checked,
                    disabled: props.disabled,
                    onChange: (event: Event) => emit('update:checked', (event.target as HTMLInputElement).checked),
                })
        },
    }),
    'a-select': defineComponent({
        name: 'ASelectStub',
        props: { value: { type: null, default: undefined }, options: { type: Array, default: () => [] }, disabled: Boolean },
        emits: ['update:value'],
        setup(props, { emit }) {
            return () =>
                h(
                    'select',
                    {
                        value: props.value,
                        disabled: props.disabled,
                        onChange: (event: Event) => emit('update:value', (event.target as HTMLSelectElement).value),
                    },
                    (props.options as Array<{ value: string; label: string }>).map((option) =>
                        h('option', { value: option.value }, option.label)
                    )
                )
        },
    }),
    'i-icon': defineComponent({
        name: 'IIconStub',
        props: { icon: { type: String, default: '' } },
        setup: (props) => () => h('i', { 'data-icon': props.icon }),
    }),
}
