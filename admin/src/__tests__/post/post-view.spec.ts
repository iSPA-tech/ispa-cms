import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import PostView from '../../../../cms/Content/admin/pages/view.vue'

const mocks = vi.hoisted(() => ({ usePost: vi.fn(), useEnumerates: vi.fn() }))

vi.mock('@/hooks', () => ({ usePost: mocks.usePost, useEnumerates: mocks.useEnumerates }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { uuid: 'post-uuid' } }) }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string) =>
            ({
                'text.post.full_page_article': 'Chuyên sâu — toàn trang',
                'text.post.content_blocks': 'Số khối nội dung',
                'text.yes': 'Có',
            })[key] || key,
    }),
}))

const mountView = (post: Record<string, unknown>) => {
    mocks.useEnumerates.mockReturnValue({
        data: ref([{ type: 'status', enum_key: 'status_published', name: 'Đã đăng' }]),
    })
    mocks.usePost.mockReturnValue({ data: ref({ data: post }), isLoading: ref(false) })

    return mount(PostView, {
        global: {
            config: { globalProperties: { $t: (key: string) => key } },
            stubs: {
                breadcrumbs: { template: '<nav><slot /></nav>' },
                'router-link': { template: '<a><slot /></a>' },
                'a-tag': { template: '<span><slot /></span>' },
                'a-skeleton': true,
                'a-empty': true,
                'i-icon': true,
                AppStatPill: {
                    props: ['label', 'value'],
                    template: '<span>{{ label }}: {{ value }}</span>',
                },
                SafeHtml: { props: ['html'], template: '<div v-html="html" />' },
            },
        },
    })
}

describe('Post view', () => {
    it('shows the API cover URL, assigned category and technical metadata', async () => {
        const wrapper = mountView({
            id: 1,
            uuid: 'a703ee79-a939-4549-8f1e-584353705312',
            name: 'Xe điện hay xe xăng',
            slug: 'xe-dien-hay-xe-xang',
            status: 'status_published',
            type: 'post',
            image: {
                url: 'https://cdn.acme.test/cover.png',
                cover_lg: 'https://cdn.acme.test/cover-lg.webp',
            },
            category_id: 13,
            categories: [{ id: 13, name: 'Tư vấn từ Chuyên gia' }],
            content: [{ type: 'text' }, { type: 'data_chart' }],
            config: { is_full_page: true },
            convert_content: '<section class="gx-data-chart">Biểu đồ</section>',
        })

        expect(wrapper.find('img[alt="Xe điện hay xe xăng"]').attributes('src')).toBe(
            'https://cdn.acme.test/cover-lg.webp'
        )
        await wrapper.find('img[alt="Xe điện hay xe xăng"]').trigger('error')
        expect(wrapper.find('img[alt="Xe điện hay xe xăng"]').attributes('src')).toBe(
            'https://cdn.acme.test/cover.png'
        )
        expect(wrapper.find('[data-test="post-metadata-category"]').text()).toContain(
            'Tư vấn từ Chuyên gia'
        )
        expect(wrapper.find('[data-test="post-metadata-uuid"]').text()).toContain(
            'a703ee79-a939-4549-8f1e-584353705312'
        )
        expect(wrapper.find('[data-test="post-metadata-content_blocks"]').text()).toContain('2')
        expect(wrapper.find('[data-test="post-metadata-layout"]').text()).toContain('Chuyên sâu')
        expect(wrapper.find('[data-test="post-metadata-layout"]').text()).toContain('Có')
    })

    it('keeps a legacy string cover and empty category safe', () => {
        const wrapper = mountView({
            id: 2,
            uuid: 'post-uuid',
            name: 'Cũ',
            image: '/storage/cover.jpg',
        })

        expect(wrapper.find('img[alt="Cũ"]').attributes('src')).toBe('/storage/cover.jpg')
        expect(wrapper.find('[data-test="post-metadata-category"]').text()).toContain('—')
    })

    it('shows the status label, never the raw status code', () => {
        const known = mountView({ id: 3, uuid: 'post-uuid', name: 'A', status: 'status_published' })
        expect(known.text()).toContain('Đã đăng')
        expect(known.text()).not.toContain('status_')

        const unknown = mountView({ id: 4, uuid: 'post-uuid', name: 'B', status: 'status_mystery' })
        expect(unknown.text()).not.toContain('status_')
    })
})
