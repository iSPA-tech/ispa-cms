import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PostEditorialReviewWarning from '../../../../cms/Content/admin/pages/post-editorial-review-warning.vue'

const mountPanel = (reviewPipeline: Record<string, any> | null) =>
    mount(PostEditorialReviewWarning, {
        props: {
            postUuid: '1cd5b00f-523f-4921-aa59-358c63e07ed8',
            reviewPipeline,
        },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                RouterLink: {
                    props: ['to'],
                    template: '<a :data-route-name="to.name" :data-route-uuid="to.params.uuid"><slot /></a>',
                },
            },
        },
    })

describe('post editorial review warnings', () => {
    it('shows exact blocking and advisory warnings beside a draft editor', () => {
        const wrapper = mountPanel({
            editorial_review: {
                version: 1,
                status: 'blocked',
                score: 100,
                reviewed_state_sha256: 'a'.repeat(64),
                reviewed_at: '2026-09-07T09:00:00+07:00',
                blocking_warnings: [
                    'Source driving claim cites the wrong evidence block.',
                ],
                advisory_warnings: ['Kiểm tra lại cách trình bày giá tham khảo.'],
            },
        })

        expect(wrapper.get('[data-editorial-review-warning]').text())
            .toContain('text.post.review.title')
            .toContain('Source driving claim cites the wrong evidence block.')
            .toContain('Kiểm tra lại cách trình bày giá tham khảo.')
            .toContain('text.post.review.disclaimer')
        expect(wrapper.get('a').attributes('data-route-name')).toBe('pipeline-detail')
        expect(wrapper.get('a').attributes('data-route-uuid')).toBe(
            '1cd5b00f-523f-4921-aa59-358c63e07ed8'
        )
    })

    it('stays absent when the post has no editorial review', () => {
        expect(mountPanel(null).find('[data-editorial-review-warning]').exists()).toBe(false)
    })

    it('ignores unrelated project payloads', () => {
        expect(mountPanel({
            editorial_review: {
                status: 'blocked',
                blocking_warnings: ['Unrelated review contract.'],
            },
        }).find('[data-editorial-review-warning]').exists()).toBe(false)
    })

    it('shows stale state instead of green when the editor changes reviewed prose', () => {
        const wrapper = mount(PostEditorialReviewWarning, {
            props: {
                reviewPipeline: {
                    editorial_review: {
                        version: 1,
                        status: 'publish_ready',
                        score: 95,
                        reviewed_state_sha256: 'a'.repeat(64),
                        blocking_warnings: [],
                    },
                },
                postName: 'Tiêu đề vừa sửa',
                postDescription: 'Mô tả',
                postContent: [{ type: 'text', value: '<p>Nội dung</p>' }],
                stale: true,
            },
            global: { mocks: { $t: (key: string) => key } },
        })

        expect(wrapper.get('[data-editorial-review-warning]').text())
            .toContain('text.post.review.state_stale')
            .not.toContain('Đủ điều kiện chờ duyệt')
    })

    it('shows a neutral stored result when the editor fields remain unchanged', () => {
        const wrapper = mountPanel({
            editorial_review: {
                version: 1,
                status: 'publish_ready',
                score: 95,
                reviewed_state_sha256: 'b'.repeat(64),
                blocking_warnings: [],
            },
        })

        expect(wrapper.get('[data-editorial-review-warning]').text())
            .toContain('text.post.review.state_saved')
            .not.toContain('Đủ điều kiện chờ duyệt')
    })
})
