// Shared stubs of the security token specs.
export const stubs = {
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}<slot name="action" /></div>' },
    'a-skeleton': true,
    'a-tag': { template: '<span><slot /></span>' },
    'a-modal': { props: ['open'], template: '<div v-if="open"><slot /></div>' },
    'i-button': {
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        template: '<button :type="nativeType || \'button\'" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
    },
}
export const i18nMock = {
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}|${Object.values(params).join(',')}` : key),
    }),
}
