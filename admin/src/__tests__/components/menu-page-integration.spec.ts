import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MenuPage from '../../../../cms/Core/admin/pages/setting/menu.vue'
import MenuEditor from '@/components/menu-editor.vue'
import { getMenu, storeMenu, updateMenu, deleteMenuItem } from '@/libs'

const access = vi.hoisted(() => ({ denied: new Set<string>() }))
const refresh = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', () => ({
    rule: (permission: string) => !access.denied.has(permission),
    iAlert: vi.fn(),
}))
vi.mock('vue-i18n', async (importOriginal) => {
    const actual = await importOriginal<any>()
    const { createMenuTestI18n } = await import('./menu-i18n-test-support')
    const instance = createMenuTestI18n(actual.createI18n)
    return { ...actual, useI18n: () => instance }
})
vi.mock('@/runtime', async () => {
    const { ref } = await import('vue')
    return { useAppRuntime: () => ({ languages: ref({ data: [{ id: 1, name: 'Vietnamese' }] }) }) }
})
vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return {
        useMenus: () => ({
            data: ref({ data: [] }),
            isLoading: ref(false),
            isError: ref(false),
            refetch: refresh,
        }),
        useEnumerates: () => ({
            data: ref([{ type: 'status', enum_key: 'status_published', name: 'Published' }]),
        }),
    }
})
vi.mock('@/libs', () => ({
    getMenu: vi.fn(),
    getCategories: vi.fn().mockResolvedValue({ status: 'success', data: [] }),
    storeMenu: vi.fn(),
    updateMenu: vi.fn(),
    deleteMenu: vi.fn(),
    deleteMenuItem: vi.fn(),
    searchFilterPosts: vi.fn().mockResolvedValue([]),
}))

const child = {
    id: 2,
    menu_id: 7,
    name: 'Child',
    title: 'Child title',
    url: 'gallery',
    type: 'gallery',
    target_type: 'App\\Models\\Gallery',
    target_id: 22,
    parent_id: 1,
    priority: 0,
    custom: { action: '_blank' },
    items: [],
}
const root = {
    id: 1,
    menu_id: 7,
    name: 'Root',
    title: 'Separate title',
    url: 'old-slug',
    type: 'category',
    target_type: 'App\\Models\\Category',
    target_id: 10,
    parent_id: null,
    priority: 0,
    custom: {
        prefix: { vi: 'legacy-prefix' },
        project_option: { enabled: true },
        isExpandChild: true,
    },
    items: [child],
}
const record = (items = [root]) => ({
    id: 7,
    name: 'Main menu',
    group_key: 'main_menu',
    language_id: 1,
    status: 'status_published',
    parseItems: structuredClone(items),
})
const mountPage = () =>
    shallowMount(MenuPage, {
        global: {
            mocks: { $t: (key: string) => key },
            stubs: { 'a-spin': { template: '<div><slot /></div>' } },
        },
    })

beforeEach(() => {
    vi.clearAllMocks()
    refresh.mockResolvedValue({})
    access.denied.clear()
    vi.mocked(updateMenu).mockResolvedValue({ status: 'success' })
    vi.mocked(storeMenu).mockResolvedValue({ status: 'success' })
    vi.mocked(deleteMenuItem).mockResolvedValue({ status: 'success' })
})

describe('menu page integration using the existing API contract', () => {
    it('closes a successfully created draft even when refreshing the list fails', async () => {
        const page = mountPage()
        ;(page.vm as any).create()
        await flushPromises()
        page.findComponent(MenuEditor).props('form').name = 'Created menu'
        refresh.mockRejectedValueOnce(new Error('offline'))
        await (page.vm as any).save()
        expect(storeMenu).toHaveBeenCalledOnce()
        expect(page.findComponent(MenuEditor).exists()).toBe(false)
        page.unmount()
    })
    it('loads and saves existing items without persisting UI keys or changing legacy custom/target/type', async () => {
        vi.mocked(getMenu).mockResolvedValue(record())
        const page = mountPage()
        await (page.vm as any).edit(7)
        await flushPromises()
        expect(updateMenu).not.toHaveBeenCalled()
        await (page.vm as any).save()
        const [id, payload] = vi.mocked(updateMenu).mock.calls[0]
        expect(id).toBe(7)
        expect(payload).toEqual({
            id: 7,
            name: 'Main menu',
            group_key: 'main_menu',
            language_id: 1,
            status: 'status_published',
            items: [root],
        })
        expect(deleteMenuItem).not.toHaveBeenCalled()
        page.unmount()
    })

    it('creates a menu through POST only after Save and retains the old payload shape', async () => {
        const page = mountPage()
        ;(page.vm as any).create()
        await flushPromises()
        const form = page.findComponent(MenuEditor).props('form')
        Object.assign(form, {
            name: 'New menu',
            items: [
                {
                    ...structuredClone(child),
                    id: 0,
                    menu_id: 0,
                    parent_id: 0,
                    type: 'url',
                    url: '/new',
                    target_type: '',
                    target_id: 0,
                    custom: {},
                },
            ],
        })
        expect(storeMenu).not.toHaveBeenCalled()
        await (page.vm as any).save()
        expect(storeMenu).toHaveBeenCalledOnce()
        expect(vi.mocked(storeMenu).mock.calls[0][0]).toMatchObject({
            id: 0,
            name: 'New menu',
            group_key: 'main_menu',
            language_id: 1,
            items: [{ id: 0, url: '/new' }],
        })
        expect(getMenu).not.toHaveBeenCalled()
        page.unmount()
    })

    it('queues removals until Save, uses the existing DELETE endpoint, and safely retries a partial delete', async () => {
        const inserted = {
            ...structuredClone(child),
            id: 30,
            name: 'New item',
            type: 'url',
            url: '/new',
            target_type: '',
            target_id: 0,
            parent_id: null,
            items: [],
        }
        vi.mocked(getMenu).mockResolvedValue(
            record([{ ...structuredClone(root), items: [child] }, inserted])
        )
        const page = mountPage()
        await (page.vm as any).edit(7)
        await flushPromises()
        const form = page.findComponent(MenuEditor).props('form')
        form.items[0].items = []
        form.items[1].id = 0
        ;(page.vm as any).queueDeletes([2])
        expect(deleteMenuItem).not.toHaveBeenCalled()
        vi.mocked(deleteMenuItem)
            .mockResolvedValueOnce({ status: 'error' })
            .mockResolvedValueOnce({ status: 'success' })
        await (page.vm as any).save()
        await flushPromises()
        expect(vi.mocked(updateMenu).mock.calls[0][1].items[1].id).toBe(0)
        expect(page.findComponent(MenuEditor).props('saveError')).toContain('chưa xóa xong')
        expect(page.findComponent(MenuEditor).props('deletedIds')).toEqual([2])
        vi.mocked(getMenu).mockResolvedValue(
            record([{ ...structuredClone(root), items: [] }, inserted])
        )
        await (page.vm as any).save()
        expect(deleteMenuItem).toHaveBeenNthCalledWith(1, 7, 2)
        expect(deleteMenuItem).toHaveBeenNthCalledWith(2, 7, 2)
        expect(vi.mocked(updateMenu).mock.calls[1][1].items[1].id).toBe(30)
        expect(page.findComponent(MenuEditor).props('deletedIds')).toEqual([])
        page.unmount()
    })

    it('preserves the edited draft on write failure and respects menu permissions', async () => {
        vi.mocked(getMenu).mockResolvedValue(record())
        const page = mountPage()
        await (page.vm as any).edit(7)
        await flushPromises()
        page.findComponent(MenuEditor).props('form').name = 'Unsaved name'
        vi.mocked(updateMenu).mockRejectedValueOnce(new Error('offline'))
        await (page.vm as any).save()
        expect(page.findComponent(MenuEditor).props('form').name).toBe('Unsaved name')
        expect(page.findComponent(MenuEditor).props('saveError')).toContain('Không lưu được')
        access.denied.add('menu.update')
        await (page.vm as any).save()
        expect(updateMenu).toHaveBeenCalledTimes(1)
        page.unmount()
    })
})
