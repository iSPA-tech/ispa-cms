/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Page editor side of "Thêm vào… → Trang" (widget library): `?add_widget=<id>&theme=<id>` is read once on mount, taken
// out of the address with `router.replace` (no history entry) and handed to the block list after the post is loaded.
// A reload, where the address no longer carries the two values, hands over nothing.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'

const routerState = vi.hoisted(() => ({
    route: { name: 'post-update', params: { uuid: 'page-uuid' }, query: {} as Record<string, unknown> },
    replace: vi.fn(),
    push: vi.fn(),
}))
const libsState = vi.hoisted(() => ({ getPost: vi.fn() }))

vi.mock('vue-router', () => ({
    useRoute: () => routerState.route,
    useRouter: () => ({ replace: routerState.replace, push: routerState.push, back: vi.fn() }),
}))
vi.mock('@/config', () => ({
    CACHE_KEY_ACCOUNT_DATA: 'CACHE_KEY_ACCOUNT_DATA',
    CACHE_KEY_MESSAGES_LOCALE: 'CACHE_KEY_MESSAGES_LOCALE',
    CONTENT_STATUSES: {},
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    defaultLocale: 'en',
    basePath: '/iPanel/',
    apiUrl: 'https://example.com/',
    url: 'https://example.com/',
    cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
    CATEGORY_TYPE_STR_POST: 'article',
    CATEGORY_TYPE: { article: 0 },
}))
vi.mock('@/mixins', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    can: () => true,
    rule: () => true,
    iAlert: vi.fn(),
    finishFailed: vi.fn(),
}))
vi.mock('@/hooks', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    useEnumerates: () => ({ data: ref([]) }),
}))
vi.mock('@/libs', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    getPost: libsState.getPost,
}))

import PostUpdate from '../../../../cms/Content/admin/pages/update.vue'

const IContents = defineComponent({
    name: 'IContents',
    props: { initialWidget: { type: Object, default: null } },
    emits: ['initial-widget-handled'],
    setup: () => () => h('div', { class: 'contents-stub' }),
})

const post = () => ({
    id: 5,
    type: 'page',
    name: 'Home',
    content: [{ id: 'content_5_0', type: 'text', value: '<p>Hello</p>' }],
    images: [],
    attachments: [],
    config: {},
    status: 'status_published',
})
const mountUpdate = () => {
    const form = ref<Record<string, unknown>>({ ...post(), id: 0 })
    return mount(PostUpdate, {
        shallow: true,
        global: {
            components: { IContents },
            stubs: { IContents: false },
            provide: {
                form,
                formRef: ref(null),
                setForm: (value: Record<string, unknown>) => (form.value = value),
                submitForm: vi.fn(),
                btnState: ref(false),
                getLimitImageByPostType: () => 1,
                checkFormChanged: vi.fn(),
                postMedia: () => [],
            },
        },
    })
}
const handedOver = (wrapper: ReturnType<typeof mountUpdate>) =>
    wrapper.findComponent(IContents).props('initialWidget')

describe('page editor: widget handed over by the widget library', () => {
    beforeEach(() => {
        routerState.replace.mockReset()
        libsState.getPost.mockReset().mockResolvedValue({ status: 'success', data: post() })
    })

    it('reads the widget once, clears the address without a history entry and hands it to the block list', async () => {
        routerState.route.query = { add_widget: '12', theme: '34', lang: 'vi' }
        const wrapper = mountUpdate()
        await flushPromises()

        expect(routerState.replace).toHaveBeenCalledTimes(1)
        expect(routerState.replace).toHaveBeenCalledWith({ query: { lang: 'vi' } })
        expect(routerState.push).not.toHaveBeenCalled()
        expect(handedOver(wrapper)).toEqual({ widgetId: 12, templateId: 34 })

        // Once the block list has dealt with it, nothing is left to hand over again (e.g. after the post reloads).
        wrapper.findComponent(IContents).vm.$emit('initial-widget-handled', { status: 'added' })
        await flushPromises()
        expect(handedOver(wrapper)).toBeNull()
    })

    it('a reload with the query already cleared hands over nothing', async () => {
        routerState.route.query = { lang: 'vi' }
        const wrapper = mountUpdate()
        await flushPromises()

        expect(routerState.replace).not.toHaveBeenCalled()
        expect(handedOver(wrapper)).toBeNull()
    })
})
