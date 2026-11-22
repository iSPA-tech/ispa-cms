// Shared mocks and stubs of the i-contents specs.

export const defaultMocks = {
    $t: (key: string) => key,
}

export const defaultStubs = {
    'a-button': {
        template: '<button type="button"><slot /></button>',
    },
    'a-form-item': {
        template: '<div><slot /><slot name="label" /></div>',
    },
    'a-input': {
        props: ['value'],
        emits: ['update:value', 'input'],
        template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value); $emit(\'input\', $event)" />',
    },
    'a-switch': {
        template: '<div />',
    },
    draggable: {
        props: ['modelValue'],
        template: `<div><slot v-for="(element, index) in modelValue" name="item" :element="element" :index="index" :isDragging="false" /></div>`,
    },
    'tip-tap-editor': {
        props: ['modelValue'],
        template: '<div class="tiptap-stub" />',
    },
    'i-content-video': {
        template: '<div class="video-stub" />',
    },
    'i-content-widget-v2': {
        template: '<div class="widget-stub" />',
    },
    'i-icon': {
        template: '<i />',
    },
    ModalMediaCenter: {
        props: ['modelValue', 'show'],
        emits: ['update:modelValue', 'update:show'],
        template: `
            <div class="modal-media-stub">
                <slot name="children" />
                <button class="select-img-btn" @click="$emit('update:modelValue', [{ id: 99, thumbnail_sm: 'img99.jpg' }])">Select Image</button>
            </div>
        `,
    },
}
