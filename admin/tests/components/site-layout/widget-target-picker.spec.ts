/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 27: widget-target-picker.vue -- the generic Target Context
// search/detail combobox used by the Canvas's target-scope override flow.
// Uses api.js's listTargetContexts/searchWidgetTargets/getWidgetTargetDetail
// (Task 25) directly: this is read-only reference data, not part of the
// mutating draft state machine, matching the same "read-only GETs may call
// api.js directly" precedent api.js itself documents. Must never coerce
// target_id (opaque string/UUID end-to-end -- do not reuse the legacy
// integer-coercing select-input-post.vue's `parseInt(e.id)` pattern), never
// branch on target_type, and never render raw hidden metadata
// (route_parameters). A hand-rolled WAI-ARIA combobox (input + listbox) is
// used instead of <a-select> so keyboard selection (ArrowDown/Enter/Escape)
// is directly, reliably testable rather than delegated to a third-party
// component's internals.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const apiMocks = vi.hoisted(() => ({
    listTargetContexts: vi.fn(),
    searchWidgetTargets: vi.fn(),
    getWidgetTargetDetail: vi.fn(),
}))

vi.mock('../../../../cms/Widget/admin/api', () => apiMocks)

import WidgetTargetPicker from '../../../../cms/Widget/admin/components/widget-target-picker.vue'

const contexts = () => [
    { target_type: 'page', label_key: 'nav.a.pages', template_context_key: 'page.static', project_applicable: true },
]

const results = (overrides = {}) => ({
    data: [
        { target_id: 'uuid-a', label: 'About Us', route_name: 'page.show', route_parameters: { secret: 'nope' } },
        { target_id: 'uuid-b', label: 'Contact', route_name: 'page.show', route_parameters: { secret: 'nope' } },
    ],
    meta: { current_page: 1, per_page: 20, total: 2 },
    links: null,
    ...overrides,
})

describe('widget-target-picker.vue', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        vi.useFakeTimers()
        apiMocks.listTargetContexts.mockResolvedValue(contexts())
        apiMocks.searchWidgetTargets.mockResolvedValue(results())
        apiMocks.getWidgetTargetDetail.mockResolvedValue({
            target_id: 'uuid-a',
            target_type: 'page',
            label: 'About Us',
            route_name: 'page.show',
            route_parameters: {},
        })
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    const mountPicker = (props = {}) =>
        mount(WidgetTargetPicker, {
            props: { modelValue: null, ...props },
        })

    it('fetches the registered Target Context types on mount and auto-selects a single type', async () => {
        const wrapper = mountPicker()
        await flushPromises()

        expect(apiMocks.listTargetContexts).toHaveBeenCalledTimes(1)
        // With exactly one registered type, the first search fires without the
        // user having to choose a type first.
        vi.advanceTimersByTime(600)
        await flushPromises()
        expect(apiMocks.searchWidgetTargets).toHaveBeenCalledWith('page', expect.any(Object), expect.any(Object))
    })

    it('debounces search input instead of firing a request per keystroke', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        apiMocks.searchWidgetTargets.mockClear()

        const input = wrapper.get('[data-test="target-picker-input"]')
        await input.setValue('a')
        await input.setValue('ab')
        await input.setValue('abo')

        // No debounce window elapsed yet -- nothing should have fired.
        expect(apiMocks.searchWidgetTargets).not.toHaveBeenCalled()

        vi.advanceTimersByTime(600)
        await flushPromises()

        expect(apiMocks.searchWidgetTargets).toHaveBeenCalledTimes(1)
        expect(apiMocks.searchWidgetTargets).toHaveBeenCalledWith(
            'page',
            expect.objectContaining({ search: 'abo' }),
            expect.any(Object)
        )
    })

    it('lists the first pages without sending an empty search (the server refuses one), and sends what is typed', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        const [, first] = apiMocks.searchWidgetTargets.mock.calls[0]
        expect(first).not.toHaveProperty('search')

        await wrapper.get('[data-test="target-picker-input"]').setValue('Giới')
        vi.advanceTimersByTime(600)
        await flushPromises()
        const [, typed] = apiMocks.searchWidgetTargets.mock.calls.at(-1)!
        expect(typed.search).toBe('Giới')
    })

    it('caps pagination via a bounded per_page parameter', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        const [, query] = apiMocks.searchWidgetTargets.mock.calls[0]
        expect(query.per_page).toBeLessThanOrEqual(20)
    })

    it('cancels the previous in-flight search when a new one supersedes it', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        apiMocks.searchWidgetTargets.mockClear()

        const input = wrapper.get('[data-test="target-picker-input"]')
        await input.setValue('first')
        vi.advanceTimersByTime(600)
        await flushPromises()

        await input.setValue('second')
        vi.advanceTimersByTime(600)
        await flushPromises()

        expect(apiMocks.searchWidgetTargets).toHaveBeenCalledTimes(2)
        const [, , firstOpts] = apiMocks.searchWidgetTargets.mock.calls[0]
        expect(firstOpts.signal).toBeInstanceOf(AbortSignal)
        // The first call's own signal must have been aborted by the second
        // (superseding) search, not left dangling.
        expect(firstOpts.signal.aborted).toBe(true)
    })

    it('discards a stale response that resolves after a newer search has already superseded it', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises() // mount-triggered search resolves with the default fixture; irrelevant here

        let resolveFirst!: (v: unknown) => void
        apiMocks.searchWidgetTargets.mockClear()
        apiMocks.searchWidgetTargets.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve
                })
        )

        const input = wrapper.get('[data-test="target-picker-input"]')
        await input.setValue('first')
        vi.advanceTimersByTime(600)
        await flushPromises() // "first" search now in flight, deliberately left unresolved

        apiMocks.searchWidgetTargets.mockResolvedValueOnce(
            results({ data: [{ target_id: 'uuid-b', label: 'Contact' }] })
        )
        await input.setValue('second')
        vi.advanceTimersByTime(600)
        await flushPromises() // "second" search fires and resolves, superseding "first"

        expect(wrapper.text()).toContain('Contact')

        // The stale "first" request finally resolves AFTER "second" already won.
        resolveFirst(results({ data: [{ target_id: 'uuid-a', label: 'About Us (stale)' }] }))
        await flushPromises()

        expect(wrapper.text()).not.toContain('About Us (stale)')
        expect(wrapper.text()).toContain('Contact')
    })

    it('never coerces target_id -- an opaque numeric-looking string id is preserved exactly, never parsed to a Number', async () => {
        apiMocks.searchWidgetTargets.mockResolvedValue(
            results({ data: [{ target_id: '007', label: 'Agent Page' }] })
        )
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        await wrapper.get('[data-test="target-option-0"]').trigger('click')

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeTruthy()
        const payload = emitted![0][0] as { target_id: unknown }
        expect(payload.target_id).toBe('007')
        expect(typeof payload.target_id).toBe('string')
    })

    it('never renders raw hidden metadata (route_parameters) -- only the safe label is shown', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        expect(wrapper.text()).toContain('About Us')
        expect(wrapper.html()).not.toContain('secret')
        expect(wrapper.html()).not.toContain('nope')
    })

    it('applies the identical generic code path regardless of target_type (never branches on Page/Post)', async () => {
        apiMocks.listTargetContexts.mockResolvedValue([
            { target_type: 'post', label_key: 'nav.a.posts', template_context_key: 'article.detail', project_applicable: true },
        ])
        apiMocks.searchWidgetTargets.mockResolvedValue(
            results({ data: [{ target_id: 'post-uuid', label: 'Blog Post' }] })
        )

        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        expect(apiMocks.searchWidgetTargets).toHaveBeenCalledWith('post', expect.any(Object), expect.any(Object))
        expect(wrapper.text()).toContain('Blog Post')
    })

    it('keyboard selection: ArrowDown highlights the first result and Enter selects it', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        const input = wrapper.get('[data-test="target-picker-input"]')
        await input.trigger('keydown', { key: 'ArrowDown' })
        expect(wrapper.get('[data-test="target-option-0"]').attributes('aria-selected')).toBe('true')

        await input.trigger('keydown', { key: 'Enter' })

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeTruthy()
        expect((emitted![0][0] as { target_id: string }).target_id).toBe('uuid-a')
    })

    it('Escape closes the listbox without selecting anything', async () => {
        const wrapper = mountPicker()
        await flushPromises()
        vi.advanceTimersByTime(600)
        await flushPromises()

        const input = wrapper.get('[data-test="target-picker-input"]')
        await input.trigger('keydown', { key: 'ArrowDown' })
        await input.trigger('keydown', { key: 'Escape' })

        expect(input.attributes('aria-expanded')).toBe('false')
        expect(wrapper.emitted('update:modelValue')).toBeFalsy()
    })

    it('the search input stays keyboard-reachable (a real, non-disabled, tabbable element)', async () => {
        const wrapper = mountPicker()
        await flushPromises()

        const input = wrapper.get('[data-test="target-picker-input"]')
        expect(input.element.tagName).toBe('INPUT')
        expect(input.attributes('disabled')).toBeUndefined()
        expect(input.attributes('tabindex')).not.toBe('-1')
    })

    it('hydrates an initial label via getWidgetTargetDetail when modelValue has an id but no cached label', async () => {
        const wrapper = mountPicker({ modelValue: { target_type: 'page', target_id: 'uuid-a', label: null } })
        await flushPromises()

        expect(apiMocks.getWidgetTargetDetail).toHaveBeenCalledWith('page', 'uuid-a', expect.any(Object))
        expect(wrapper.get('[data-test="target-picker-input"]').element.value).toBe('About Us')
    })
})
