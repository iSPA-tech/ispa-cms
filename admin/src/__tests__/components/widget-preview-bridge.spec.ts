import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BRIDGE_SOURCE, buildEditableSrcdoc, isBridgeMessage } from '@/components/widget-preview-bridge'
import { FRAME_SCRIPT } from '@/components/widget-preview-frame-script'

const manifest = '<script type="application/json" id="ispa-edit-fields">{"on_preview":["title"],"off_preview":["cta"]}</script>'

describe('buildEditableSrcdoc', () => {
    it('extracts manifest and injects script', () => {
        const { srcdoc, onPreview, offPreview } = buildEditableSrcdoc(
            `<html><body><p>x</p>${manifest}</body></html>`,
            { title: 'Title' }
        )
        expect(onPreview).toEqual(['title'])
        expect(offPreview).toEqual(['cta'])
        expect(srcdoc).not.toContain('id="ispa-edit-fields"')
        expect(srcdoc).toContain('__ISPA_LABELS__')
        expect(srcdoc.indexOf('__ISPA_LABELS__')).toBeLessThan(srcdoc.indexOf('</body>'))
    })

    it('neutralises the site viewport min-height so the frame cannot grow on every size report', () => {
        // Site app.css ships html,body{min-height:100vh}; with a frame sized to its scrollHeight that
        // feeds back (+leading margin per round) until the 6000px cap.
        const { srcdoc } = buildEditableSrcdoc(
            `<style>html,body{min-height:100vh}</style><section><div class="mt-8">x</div></section>${manifest}`,
            {}
        )
        // The injected override comes after the site rule and is !important, so it wins the cascade.
        expect(srcdoc.indexOf('min-height:0!important')).toBeGreaterThan(srcdoc.indexOf('min-height:100vh'))
        expect(srcdoc).toMatch(/html,body\{min-height:0!important\}/)
    })

    it('appends the injection when there is no body tag', () => {
        const { srcdoc } = buildEditableSrcdoc(`<p>x</p>${manifest}`, {})
        expect(srcdoc.startsWith('<p>x</p>')).toBe(true)
        expect(srcdoc).toContain('__ISPA_LABELS__')
    })

    it('uses the last manifest when widget content carries a lookalike', () => {
        const fake = '<script type="application/json" id="ispa-edit-fields">{"on_preview":["evil"],"off_preview":[]}</script>'
        const { srcdoc, onPreview } = buildEditableSrcdoc(`<body>${fake}<p>x</p>${manifest}</body>`, {})
        expect(onPreview).toEqual(['title'])
        expect(srcdoc).toContain('"evil"')
        expect(srcdoc).not.toContain('"cta"')
    })

    it('injects the frame strings next to the labels, escaped', () => {
        const html = '<body><script type="application/json" id="ispa-edit-fields">{"on_preview":[],"off_preview":[]}</script></body>'
        const { srcdoc } = buildEditableSrcdoc(html, {}, { typing: '</script>gõ' })
        expect(srcdoc).toContain('__ISPA_STRINGS__={"typing":"\\u003c/script>gõ"}')
    })

    it('html without manifest is returned unchanged', () => {
        const html = '<html><body>hi</body></html>'
        expect(buildEditableSrcdoc(html, {})).toEqual({ srcdoc: html, onPreview: [], offPreview: [], repeaters: {} })
    })

    it('buildEditableSrcdoc returns repeaters from the manifest', () => {
        const fb = { count: 2, max: 3, item_label: 'khách', fields: [{ name: 'quote', type: 'text', label: 'Lời' }] }
        const html =
            '<body><script type="application/json" id="ispa-edit-fields">' +
            JSON.stringify({ on_preview: ['fb'], off_preview: [], repeaters: { fb } }) +
            '</script></body>'
        const { srcdoc, onPreview, repeaters } = buildEditableSrcdoc(html, {})
        expect(onPreview).toEqual(['fb'])
        expect(repeaters).toEqual({ fb })
        expect(srcdoc).toContain('__ISPA_REPEATERS__=')
        expect(buildEditableSrcdoc(html.replace('"repeaters"', '"x"'), {}).repeaters).toEqual({})
    })
})

describe('isBridgeMessage', () => {
    it('rejects foreign source and wrong window', () => {
        const win = {} as Window
        const ok = { source: win, data: { source: BRIDGE_SOURCE, type: 'size' } } as unknown as MessageEvent
        expect(isBridgeMessage(ok, win)).toBe(true)
        expect(isBridgeMessage({ source: win, data: { source: 'other' } } as unknown as MessageEvent, win)).toBe(false)
        expect(isBridgeMessage({ source: {}, data: { source: BRIDGE_SOURCE } } as unknown as MessageEvent, win)).toBe(false)
        expect(isBridgeMessage(ok, null)).toBe(false)
    })
})

describe('FRAME_SCRIPT', () => {
    let post: ReturnType<typeof vi.fn>
    let added: Array<[EventTarget, string, any, any]> = []
    const run = () => {
        // Track listeners so each test starts with a clean document and window.
        for (const target of [document, window] as EventTarget[]) {
            const orig = target.addEventListener.bind(target)
            target.addEventListener = ((t: string, l: any, o: any) => {
                added.push([target, t, l, o])
                orig(t, l, o)
            }) as any
        }
        ;(window as any).__ISPA_LABELS__ = { title: 'Title' }
        ;(0, eval)(FRAME_SCRIPT)
    }
    const msgs = () => post.mock.calls.map((c) => c[0])

    beforeEach(() => {
        post = vi.fn()
        ;(window as any).parent = { postMessage: post }
        document.body.innerHTML =
            '<ispa-field data-field="title" data-type="text">Hello</ispa-field>' +
            '<ispa-field data-field="title" data-type="text">Hello</ispa-field>' +
            '<ispa-field data-field="body" data-type="textarea"><p>x</p></ispa-field>'
        run()
    })
    afterEach(() => {
        added.forEach(([t, type, l, o]) => t.removeEventListener(type, l, o))
        added = []
        document.body.innerHTML = ''
    })

    // jsdom has no contentEditable; stub the probe result the frame script reads.
    const stubPlain = (supported: boolean) => {
        if (supported) {
            Object.defineProperty(HTMLElement.prototype, 'contentEditable', {
                configurable: true,
                get() {
                    return this.getAttribute('contenteditable') || 'inherit'
                },
            })
        }
        document.body.innerHTML = '<ispa-field data-field="t" data-type="text">A</ispa-field>'
        run()
        return document.querySelector('ispa-field') as HTMLElement
    }
    afterEach(() => {
        delete (HTMLElement.prototype as any).contentEditable
    })

    it('uses contenteditable=true and blocks formatting when plaintext-only is unsupported', () => {
        const f = stubPlain(false)
        expect(f.getAttribute('contenteditable')).toBe('true')
        const fmt = new Event('beforeinput', { bubbles: true, cancelable: true }) as any
        fmt.inputType = 'formatBold'
        f.dispatchEvent(fmt)
        expect(fmt.defaultPrevented).toBe(true)
        const para = new Event('beforeinput', { bubbles: true, cancelable: true }) as any
        para.inputType = 'insertParagraph'
        f.dispatchEvent(para)
        expect(para.defaultPrevented).toBe(true)
        const text = new Event('beforeinput', { bubbles: true, cancelable: true }) as any
        text.inputType = 'insertText'
        f.dispatchEvent(text)
        expect(text.defaultPrevented).toBe(false)
    })

    it('uses plaintext-only when supported', () => {
        expect(stubPlain(true).getAttribute('contenteditable')).toBe('plaintext-only')
    })

    it('text field becomes plaintext editable', () => {
        document.body.innerHTML = '<ispa-field data-field="title" data-type="text">Hello</ispa-field>'
        Object.defineProperty(HTMLElement.prototype, 'contentEditable', {
            configurable: true,
            get() {
                return this.getAttribute('contenteditable') || 'inherit'
            },
        })
        run()
        const f = document.querySelector('ispa-field[data-type=text]')!
        expect(f.getAttribute('contenteditable')).toBe('plaintext-only')
        expect(f.getAttribute('tabindex')).toBe('0')
        expect(f.getAttribute('role')).toBe('textbox')
    })

    it('Enter is prevented', () => {
        const f = document.querySelector('ispa-field[data-type=text]')!
        const ev = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
        f.dispatchEvent(ev)
        expect(ev.defaultPrevented).toBe(true)
    })

    it('paste inserts text/plain only', () => {
        const f = document.querySelector('ispa-field[data-type=text]') as HTMLElement
        f.textContent = ''
        const ev = new Event('paste', { bubbles: true, cancelable: true }) as any
        ev.clipboardData = { getData: (t: string) => (t === 'text/plain' ? 'plain' : '<b>rich</b>') }
        f.dispatchEvent(ev)
        expect(ev.defaultPrevented).toBe(true)
        expect(f.textContent).toBe('plain')
        expect(f.innerHTML).not.toContain('<b>')
    })

    it('typing syncs duplicate fields and posts field-input', () => {
        const [a, b] = Array.from(document.querySelectorAll('ispa-field[data-type=text]'))
        a.textContent = 'New'
        a.dispatchEvent(new Event('input', { bubbles: true }))
        expect(b.textContent).toBe('New')
        expect(msgs()).toContainEqual({ source: BRIDGE_SOURCE, type: 'field-input', name: 'title', value: 'New' })
    })

    it('click on textarea field posts field-open with rect', () => {
        const f = document.querySelector('ispa-field[data-type=textarea]')!
        expect(f.getAttribute('role')).toBe('button')
        f.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
        const m = msgs().find((x) => x.type === 'field-open')
        expect(m.name).toBe('body')
        expect(m.rect).toHaveProperty('width')
        expect(m.source).toBe(BRIDGE_SOURCE)
    })

    it('sets aria-label from labels and normalises non-breaking spaces', () => {
        const a = document.querySelector('ispa-field[data-type=text]')!
        expect(a.getAttribute('aria-label')).toBe('Title')
        a.textContent = 'A\u00a0B'
        a.dispatchEvent(new Event('input', { bubbles: true }))
        expect(msgs()).toContainEqual({ source: BRIDGE_SOURCE, type: 'field-input', name: 'title', value: 'A B' })
    })

    it('caps the reported height', () => {
        Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, value: 99999 })
        post.mockClear()
        run()
        delete (document.documentElement as any).scrollHeight
        expect(msgs().find((m) => m.type === 'size').height).toBe(6000)
    })

    it('reports the fields present once on load', () => {
        expect(msgs().filter((m) => m.type === 'fields-present')).toEqual([
            { source: BRIDGE_SOURCE, type: 'fields-present', names: ['title', 'title', 'body'] },
        ])
    })

    it('focus-field focuses the first matching field', async () => {
        window.dispatchEvent(new MessageEvent('message', { data: { source: BRIDGE_SOURCE, type: 'focus-field', name: 'body' } }))
        expect(document.activeElement).toBe(document.querySelector('ispa-field[data-field=body]'))
        window.dispatchEvent(new MessageEvent('message', { data: { source: BRIDGE_SOURCE, type: 'focus-field', name: 'title' } }))
        expect(document.activeElement).toBe(document.querySelector('ispa-field[data-field=title]'))
    })

    it('mark-field outlines the open field and clears the previous one', () => {
        const send = (name: string | null) =>
            window.dispatchEvent(new MessageEvent('message', { data: { source: BRIDGE_SOURCE, type: 'mark-field', name } }))
        send('body')
        expect(document.querySelector('ispa-field[data-field=body]')!.hasAttribute('data-editing')).toBe(true)
        send('title')
        expect(document.querySelectorAll('[data-editing]')).toHaveLength(1)
        expect(document.querySelector('ispa-field[data-field=title]')!.hasAttribute('data-editing')).toBe(true)
        send(null)
        expect(document.querySelectorAll('[data-editing]')).toHaveLength(0)
    })

    it('text fields carry the typing hint from the injected strings', () => {
        ;(window as any).__ISPA_STRINGS__ = { typing: 'đang gõ' }
        document.body.innerHTML =
            '<ispa-field data-field="title" data-type="text">A</ispa-field><ispa-field data-field="body" data-type="textarea">B</ispa-field>'
        run()
        delete (window as any).__ISPA_STRINGS__
        expect(document.querySelector('[data-field=title]')!.getAttribute('data-typing')).toBe('đang gõ')
        expect(document.querySelector('[data-field=body]')!.hasAttribute('data-typing')).toBe(false)
    })

    it('shows a floating label chip over an image field', () => {
        ;(window as any).__ISPA_LABELS__ = { avatar: 'Ảnh đại diện' }
        document.body.innerHTML = '<img data-field="avatar" data-type="image" src="">'
        ;(0, eval)(FRAME_SCRIPT)
        window.dispatchEvent(new MessageEvent('message', { data: { source: BRIDGE_SOURCE, type: 'mark-field', name: 'avatar' } }))
        const chip = document.querySelector('ispa-chip') as HTMLElement
        expect(chip.textContent).toBe('Ảnh đại diện')
        expect(chip.style.display).toBe('block')
    })

    it('does not throw without ResizeObserver', () => {
        delete (window as any).ResizeObserver
        expect(run).not.toThrow()
    })
})

describe('FRAME_SCRIPT repeater items', () => {
    let post: ReturnType<typeof vi.fn>
    let added: Array<[EventTarget, string, any, any]> = []
    const item = (id: string, i: number) =>
        `<div class="card" data-repeat="fb" data-item-id="${id}" data-item-index="${i}">` +
        `<ispa-field data-field="fb.${id}.quote" data-type="text">Q${i}</ispa-field></div>`
    const run = (count: number, max: number) => {
        for (const target of [document, window] as EventTarget[]) {
            const orig = target.addEventListener.bind(target)
            target.addEventListener = ((t: string, l: any, o: any) => {
                added.push([target, t, l, o])
                orig(t, l, o)
            }) as any
        }
        document.body.innerHTML = `<section class="grid">${Array.from({ length: count }, (_, i) => item(`id${i}`, i)).join('')}</section>`
        ;(window as any).__ISPA_LABELS__ = {}
        ;(window as any).__ISPA_STRINGS__ = { add: '+ Thêm {item}', duplicate: 'Nhân bản', remove: 'Xoá', drag: 'Kéo' }
        ;(window as any).__ISPA_REPEATERS__ = { fb: { count, max, item_label: 'khách', fields: [] } }
        ;(0, eval)(FRAME_SCRIPT)
    }
    const msgs = () => post.mock.calls.map((c) => c[0])
    const click = (el: Element) => el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))

    beforeEach(() => {
        post = vi.fn()
        ;(window as any).parent = { postMessage: post }
    })
    afterEach(() => {
        added.forEach(([t, type, l, o]) => t.removeEventListener(type, l, o))
        added = []
        document.body.innerHTML = ''
        delete (window as any).__ISPA_REPEATERS__
        delete (window as any).__ISPA_STRINGS__
    })

    it('item toolbar posts item-remove/duplicate', () => {
        run(2, 5)
        const second = document.querySelectorAll('[data-repeat]')[1] as HTMLElement
        second.focus()
        const bar = document.querySelector('ispa-item-bar')!
        expect(bar.querySelector('ispa-item-chip')!.textContent).toBe('Khách 2 / 2')
        click(bar.querySelector('[data-action=remove]')!)
        click(bar.querySelector('[data-action=duplicate]')!)
        expect(msgs()).toContainEqual({ source: BRIDGE_SOURCE, type: 'item-remove', repeater: 'fb', id: 'id1' })
        expect(msgs()).toContainEqual({ source: BRIDGE_SOURCE, type: 'item-duplicate', repeater: 'fb', id: 'id1' })
    })

    it('add tile posts item-add and is absent at max', () => {
        run(2, 3)
        const tile = document.querySelector('ispa-add-tile')!
        expect(tile.previousElementSibling).toBe(document.querySelectorAll('[data-repeat]')[1])
        expect(tile.textContent).toBe('+ Thêm khách')
        click(tile)
        expect(msgs()).toContainEqual({ source: BRIDGE_SOURCE, type: 'item-add', repeater: 'fb' })
        added.forEach(([t, type, l, o]) => t.removeEventListener(type, l, o))
        added = []
        run(3, 3)
        expect(document.querySelector('ispa-add-tile')).toBeNull()
        ;(document.querySelector('[data-repeat]') as HTMLElement).focus()
        expect(document.querySelector('[data-action=duplicate]')!.getAttribute('aria-disabled')).toBe('true')
    })

    it('Alt+ArrowUp posts item-move', () => {
        run(3, 5)
        const third = document.querySelectorAll('[data-repeat]')[2]
        third.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', altKey: true, bubbles: true, cancelable: true }))
        expect(msgs()).toContainEqual({ source: BRIDGE_SOURCE, type: 'item-move', repeater: 'fb', id: 'id2', toIndex: 1 })
        const first = document.querySelectorAll('[data-repeat]')[0]
        first.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', altKey: true, bubbles: true, cancelable: true }))
        expect(msgs().filter((m) => m.type === 'item-move')).toHaveLength(1)
    })

    it('Alt+ArrowUp inside an item text field moves the caret, not the item', () => {
        run(3, 5)
        const field = document.querySelectorAll('[data-repeat] ispa-field')[2]
        const ev = new KeyboardEvent('keydown', { key: 'ArrowUp', altKey: true, bubbles: true, cancelable: true })
        field.dispatchEvent(ev)
        expect(msgs().filter((m) => m.type === 'item-move')).toHaveLength(0)
        expect(ev.defaultPrevented).toBe(false)
    })

    it('draws the item toolbar in a body overlay that overflow-hidden items cannot clip', () => {
        run(2, 5)
        const first = document.querySelectorAll('[data-repeat]')[0] as HTMLElement
        first.style.overflow = 'hidden'
        first.getBoundingClientRect = () => ({ left: 100, top: 200, right: 400, bottom: 500, width: 300, height: 300, x: 100, y: 200 }) as DOMRect
        first.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))
        const bar = document.querySelector('ispa-item-bar') as HTMLElement
        expect(bar.parentElement).toBe(document.body)
        expect(first.querySelector('ispa-item-bar')).toBeNull()
        expect(first.hasAttribute('data-item-active')).toBe(true)
        const chip = bar.querySelector('ispa-item-chip') as HTMLElement
        expect([chip.style.left, chip.style.top]).toEqual(['92px', '164px'])
        // The image-field label chip must not hide the item chip.
        first.querySelector('ispa-field')!.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))
        expect(chip.style.display).not.toBe('none')
        // Keyboard: root → toolbar → the item's own field; Esc in the toolbar goes back to the item.
        first.focus()
        const tab = (el: Element, shiftKey = false) =>
            el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true }))
        tab(first)
        const buttons = bar.querySelectorAll('button')
        expect(document.activeElement).toBe(buttons[0])
        ;(buttons[2] as HTMLElement).focus()
        tab(buttons[2])
        expect(document.activeElement).toBe(first.querySelector('ispa-field'))
        ;(buttons[1] as HTMLElement).focus()
        buttons[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
        expect(document.activeElement).toBe(first)
    })

    it('clears the hover chrome when the pointer leaves the preview, but not while the item has focus', () => {
        run(2, 5)
        const [first, second] = Array.from(document.querySelectorAll('[data-repeat]')) as HTMLElement[]
        first.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))
        const bar = document.querySelector('ispa-item-bar') as HTMLElement
        first.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, relatedTarget: null }))
        expect(bar.style.display).toBe('none')
        expect(first.hasAttribute('data-item-active')).toBe(false)
        second.focus()
        second.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, relatedTarget: null }))
        expect(bar.style.display).toBe('block')
        expect(second.hasAttribute('data-item-active')).toBe(true)
    })

    it('reports a repeater as present only when it has items', () => {
        run(1, 5)
        expect(msgs().find((m) => m.type === 'fields-present').names).toEqual(['fb.id0.quote', 'fb'])
    })
})
