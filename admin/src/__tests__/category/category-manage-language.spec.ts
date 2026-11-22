import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import CategoryManage from '@/pages/category/manage.vue'

vi.mock('vue-router', () => ({
    useRoute: () => ({
        name: 'category-product',
    }),
}))

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({
        languages: ref({
            data: [{ id: 1, name: 'Vietnamese' }],
        }),
    }),
}))

vi.mock('@/hooks', () => ({
    useCategories: () => ({
        isLoading: ref(false),
        data: ref({ data: [] }),
        refetch: vi.fn(),
    }),
    useEnumerates: () => ({
        data: ref([]),
    }),
}))

vi.mock('@/libs', () => ({
    deleteCategory: vi.fn(),
    getCategory: vi.fn(),
    storeCategory: vi.fn(),
    updateCategory: vi.fn(),
}))

vi.mock('@/config', () => ({
    CATEGORY_TYPE: {
        event: 1,
        gallery: 2,
        place: 3,
        product: 4,
        upload: 5,
    },
    CATEGORY_TYPE_STR_EVENT: 'event',
    CATEGORY_TYPE_STR_GALLERY: 'gallery',
    CATEGORY_TYPE_STR_PLACE: 'place',
    CATEGORY_TYPE_STR_PRODUCT: 'product',
    CATEGORY_TYPE_STR_UPLOAD: 'upload',
}))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    objectAssignOnlyByKeyHasValue: (_form: unknown, data: unknown) => data,
    rule: (key: string) => key === 'category.seo',
}))

const passthrough = {
    template: '<div><slot /></div>',
}

describe('CategoryManage language field', () => {
    it('renders the language selector in the drawer when category SEO is enabled', () => {
        const wrapper = mount(CategoryManage, {
            global: {
                mocks: {
                    $t: (key: string) => key,
                    $isMobile: () => false,
                },
                stubs: {
                    breadcrumbs: passthrough,
                    'a-button': passthrough,
                    'a-cascader': true,
                    'a-checkbox': passthrough,
                    'a-checkbox-group': passthrough,
                    'a-drawer': passthrough,
                    'a-form': { template: '<form><slot /></form>' },
                    'a-form-item': passthrough,
                    'a-input': true,
                    'a-popconfirm': passthrough,
                    'a-select': true,
                    'a-skeleton': true,
                    'a-switch': true,
                    'a-table': passthrough,
                    'a-tooltip': passthrough,
                    'i-icon': true,
                    'i-seo': true,
                    'image-v3': true,
                    'media-selection': true,
                    'select-input-language': {
                        props: ['modelValue', 'label', 'name', 'required', 'disabled'],
                        template:
                            '<div data-testid="category-language" :data-label="label" :data-name="name" :data-required="required" :data-disabled="disabled" />',
                    },
                    'slug-v3': true,
                },
            },
        })

        const language = wrapper.get('[data-testid="category-language"]')

        expect(language.attributes('data-label')).toBe('validation.attributes.language_id')
        expect(language.attributes('data-name')).toBe('language_id')
        expect(language.attributes()).toHaveProperty('data-required')
    })
})
