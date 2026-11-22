import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import Breadcrumbs from '../../components/breadcrumbs.vue'

const RouterLinkStub = defineComponent({
    name: 'RouterLink',
    props: ['to'],
    setup(props, { slots }) {
        return () =>
            h(
                'a',
                { class: 'router-link-stub', 'data-to': JSON.stringify(props.to) },
                slots.default?.()
            )
    },
})

const mountCrumbs = (items: Record<string, unknown>[], slots = {}) =>
    mount(Breadcrumbs, {
        props: { items },
        slots,
        global: {
            mocks: { $t: (key: string) => key, $route: { name: 'test-route' } },
            stubs: { RouterLink: RouterLinkStub, 'router-link': RouterLinkStub, 'i-icon': true },
        },
    })

describe('breadcrumbs', () => {
    afterEach(() => vi.restoreAllMocks())

    it('renders a root item as plain text without a link', () => {
        const wrapper = mountCrumbs([{ icon: 'text-box', title: 'Posts', text: 'Posts' }])
        // Only the home link exists.
        expect(wrapper.findAll('a.router-link-stub')).toHaveLength(1)
        expect(wrapper.text()).toContain('Posts')
    })

    it('renders sub items as link then text-lg leaf', () => {
        const wrapper = mountCrumbs([
            { name: 'post-dashboard', icon: 'text-box', title: 'Posts' },
            { text: 'Create', title: 'Create' },
        ])
        const links = wrapper.findAll('a.router-link-stub')
        expect(links).toHaveLength(2)
        expect(links[1].attributes('data-to')).toContain('post-dashboard')
        const leaf = wrapper.find('span.text-lg')
        expect(leaf.text()).toBe('Create')
        expect(wrapper.text()).toContain('Create')
    })

    it('renders default slot actions', () => {
        const wrapper = mountCrumbs([{ text: 'A', title: 'A' }], {
            default: '<button class="action">Go</button>',
        })
        expect(wrapper.find('button.action').exists()).toBe(true)
    })

    it('lets the leaf title wrap between words without a percentage max-width', () => {
        const wrapper = mountCrumbs([{ text: 'A very long page title that must wrap by word', title: 'A' }])
        const classes = wrapper.find('span.text-lg').classes()
        expect(classes).toEqual(expect.arrayContaining(['min-w-0', 'break-words', 'sm:line-clamp-1']))
        expect(classes.some((name) => name.includes('max-w'))).toBe(false)
        expect(classes).not.toContain('pr-2')
        expect(wrapper.find('span.text-lg').element.parentElement?.classList.contains('grow')).toBe(true)
    })

    it('keeps the padding before the badge slot and does not grow the leaf', () => {
        const wrapper = mountCrumbs([{ text: 'Title', title: 'T' }], { badge: '<span class="chip">New</span>' })
        const title = wrapper.find('span.text-lg')
        expect(title.classes()).toContain('pr-2')
        expect(title.element.parentElement?.classList.contains('grow')).toBe(false)
        expect(wrapper.find('span.chip').exists()).toBe(true)
    })

    it('warns on an item with neither text nor icon', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        mountCrumbs([{ name: 'x', title: 'Blank' }])
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('neither "text" nor "icon"'))
    })
})
