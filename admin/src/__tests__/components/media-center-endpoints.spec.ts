import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import MediaCenter from '../../components/media-center.vue'

const useUploadsMock = vi.fn()
const useUploadFoldersMock = vi.fn()
const getUploadsMock = vi.fn()

vi.mock('@/hooks', () => ({
    useUploads: (...args: unknown[]) => useUploadsMock(...args),
    useUploadFolders: (...args: unknown[]) => useUploadFoldersMock(...args),
}))

vi.mock('@/libs/upload', () => ({
    deleteUpload: vi.fn(),
    getUploads: (...args: unknown[]) => getUploadsMock(...args),
}))

vi.mock('@/config', () => ({
    apiBase: 'https://api.test/api/v2/admin/',
    apiUrl: 'https://api.test/',
    cookie: { get: () => 'token' },
    COOKIE_KEY_TOKEN: 'token',
    defaultFilterParams: { page: 1, pageSize: 20, keywords: '', view_folder: '' },
    domain: 'https://admin.test',
}))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    rule: () => true,
    loadingSpinner: {},
    removeLeftTrailingSlash: (value: string) => value,
}))

const rawRows = [
    {
        id: 1,
        name: '8f2c.pdf',
        name_alias: 'Original name.pdf',
        size: '2 KB',
        url: '/secret/8f2c.pdf',
        type: 'document',
    },
]

const mountCenter = (props: Record<string, unknown> = {}) =>
    mount(MediaCenter, {
        props: { type: 'document', show: true, folder: 'Docs', ...props },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                MediaListFile: {
                    name: 'MediaListFile',
                    props: ['data'],
                    template: '<div class="file-list" />',
                },
                MediaListThumbnail: true,
                'i-icon': true,
                'a-input': true,
                'a-button': { template: '<button type="button"><slot /></button>' },
                'a-tag': true,
                'a-select': true,
                'a-range-picker': { template: '<div class="date-range" />' },
                'a-collapse': { template: '<div class="folders"><slot /></div>' },
                'a-collapse-panel': { template: '<div><slot /></div>' },
                'a-tree': true,
                'a-tabs': { template: '<div><slot /></div>' },
                'a-tab-pane': { template: '<div><slot /></div>' },
                'a-spin': { template: '<div><slot /></div>' },
                'a-pagination': true,
                'a-upload-dragger': {
                    name: 'UploadDragger',
                    props: ['accept', 'action', 'data'],
                    template: '<div class="uploader"><slot /></div>',
                },
                'a-drawer': { template: '<div><slot /><slot name="footer" /></div>' },
                'a-tooltip': { template: '<span><slot /></span>' },
                'a-popconfirm': { template: '<span><slot /></span>' },
            },
        },
    })

describe('media center list and upload endpoints', () => {
    beforeEach(() => {
        useUploadsMock.mockReset()
        useUploadFoldersMock.mockReset()
        getUploadsMock.mockReset()
        useUploadsMock.mockReturnValue({
            data: ref({ data: { data: rawRows, total: 1 } }),
            isLoading: ref(false),
            isFetching: ref(false),
            refetch: vi.fn(),
        })
        useUploadFoldersMock.mockReturnValue({ data: ref({ data: [] }), refetch: vi.fn() })
    })

    it('keeps the general upload routes, folder tools and image types by default', () => {
        const wrapper = mountCenter()

        // No endpoint argument: the hook falls back to `upload`.
        expect(useUploadsMock.mock.calls[0][2]).toBeNull()
        expect(useUploadsMock.mock.calls[0][1]).toEqual({ enabled: true })
        expect(useUploadFoldersMock.mock.calls[0][1]).toEqual({ enabled: true })

        const uploader = wrapper.findComponent({ name: 'UploadDragger' })
        expect(uploader.props('action')).toBe('https://api.test/api/v2/admin/upload/document')
        expect(uploader.props('accept')).toContain('application/msword')
        expect(uploader.props('accept')).toContain('text/csv')
        expect(uploader.props('data')).toEqual({
            target: null,
            targetId: 0,
            category: 'images',
            folder: 'Docs',
        })
        expect(wrapper.text()).toContain('text.upload-file-description')

        // Folder tree, advanced filters and the folder toggle stay available.
        expect(wrapper.find('.folders').exists()).toBe(true)
        expect(wrapper.find('.date-range').exists()).toBe(true)
        expect(wrapper.text()).toContain('web.file.folder')

        // Rows reach the gallery untouched.
        expect(wrapper.findComponent({ name: 'MediaListFile' }).props('data')).toEqual(rawRows)
    })

    it('uses `upload/image` as the default action for an image picker', () => {
        const wrapper = mountCenter({ type: 'image' })

        expect(wrapper.findComponent({ name: 'UploadDragger' }).props('action')).toBe(
            'https://api.test/api/v2/admin/upload/image'
        )
        expect(wrapper.findComponent({ name: 'UploadDragger' }).props('accept')).toContain(
            'image/png'
        )
    })

    it('points the gallery and the upload at module endpoints, PDF only, without folder tools', () => {
        const wrapper = mountCenter({
            listEndpoint: 'digital-library/documents',
            uploadEndpoint: 'digital-library/documents',
            uploadHint: 'Only PDF files can be uploaded.',
        })

        expect(useUploadsMock.mock.calls[0][2]).toBe('digital-library/documents')
        expect(useUploadFoldersMock.mock.calls[0][1]).toEqual({ enabled: false })

        const uploader = wrapper.findComponent({ name: 'UploadDragger' })
        expect(uploader.props('action')).toBe(
            'https://api.test/api/v2/admin/digital-library/documents'
        )
        expect(uploader.props('accept')).toBe('application/pdf, .pdf')
        // The module route derives target, category and folder itself.
        expect(uploader.props('data')).toEqual({})
        expect(wrapper.text()).toContain('Only PDF files can be uploaded.')
        expect(wrapper.text()).not.toContain('text.upload-file-description')

        expect(wrapper.find('.folders').exists()).toBe(false)
        expect(wrapper.find('.date-range').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('web.file.folder')
    })

    it('lists private documents by their original name and never exposes a link', () => {
        const wrapper = mountCenter({ listEndpoint: 'digital-library/documents' })

        const rows = wrapper.findComponent({ name: 'MediaListFile' }).props('data') as Record<
            string,
            unknown
        >[]

        expect(rows).toHaveLength(1)
        expect(rows[0]).toMatchObject({ id: 1, name: 'Original name.pdf', size: '2 KB', url: '' })
    })

    it('accepts a plain array body from a module list endpoint', () => {
        useUploadsMock.mockReturnValue({
            data: ref({ data: rawRows, meta: { total: 7 } }),
            isLoading: ref(false),
            isFetching: ref(false),
            refetch: vi.fn(),
        })

        const wrapper = mountCenter({ listEndpoint: 'digital-library/documents' })

        expect(wrapper.findComponent({ name: 'MediaListFile' }).props('data')).toHaveLength(1)
    })
})
