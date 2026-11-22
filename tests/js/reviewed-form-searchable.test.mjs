import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const blade = readFileSync(new URL('../../resources/views/components/form-generate-reviewed.blade.php', import.meta.url), 'utf8')
const start = blade.indexOf('function reviewedForm{{ $fields->id }}()')
const end = blade.indexOf('</script>', start)
const script = blade.slice(start, end)
    .replaceAll('{{ $fields->id }}', '1')
    .replaceAll('@js($form)', '"admission"')
    .replaceAll('@js($advancedItems)', '[]')
    .replaceAll('@js($actionUrl)', '"/submit"')
    .replaceAll('@js(csrf_token())', '"csrf"')
    .replaceAll('@js($initialValues)', '{}')
    .replaceAll('@js($existingFileNames)', '{}')
    .replaceAll('@js($previewOnly)', 'false')

function fakeSelect() {
    const select = { value: '', dataset: {}, options: [{ text: 'Chọn giáo phận' }], dispatched: [], classes: new Set() }
    select.classList = { contains: (name) => select.classes.has(name), add: (name) => select.classes.add(name) }
    select.dispatchEvent = (event) => select.dispatched.push(event.type)
    return select
}

function formWith(select) {
    const calls = { select2: [], handlers: {}, triggered: [] }
    const $ = (element) => ({
        select2(options) { calls.select2.push({ element, options }); element.classList.add('select2-hidden-accessible'); return this },
        on(name, handler) { calls.handlers[name] = handler; return this },
        trigger(name) { calls.triggered.push(name); return this },
    })
    $.fn = { select2: true }
    const searchField = { focused: 0, focus() { this.focused++ } }
    const context = {
        window: { jQuery: $, __reviewedFormSelect2: (callback) => callback(), setTimeout: (callback) => callback() },
        document: { addEventListener() {}, querySelector: () => searchField },
        Event: class { constructor(type) { this.type = type } },
        URL,
    }
    const form = vm.runInNewContext(`${script}; reviewedForm1`, context)()
    form.$refs = {
        captchaToken: { value: '' },
        form: { querySelectorAll: (selector) => (selector.includes(':not(') ? !select.classes.has('select2-hidden-accessible') : select.classes.has('select2-hidden-accessible')) ? [select] : [] },
    }
    form.$watch = () => {}
    form.$nextTick = (callback) => callback()
    form.init()
    return { form, calls, searchField }
}

test('searchable selects get Select2 once and forward picks to Alpine as native change events', () => {
    const select = fakeSelect()
    const { form, calls, searchField } = formWith(select)
    assert.equal(calls.select2.length, 1)
    assert.equal(calls.select2[0].options.width, '100%')
    assert.equal(calls.select2[0].options.placeholder, 'Chọn giáo phận')
    form.enhanceSelects()
    assert.equal(calls.select2.length, 1, 'already enhanced selects are skipped')

    calls.handlers['select2:open']()
    assert.equal(searchField.focused, 1, 'opening focuses the search box')
    select.value = 'Kon Tum'
    calls.handlers['select2:select']()
    assert.deepEqual(select.dispatched, ['change'])
    form.syncSearchable()
    assert.deepEqual(calls.triggered, [], 'a pick made through Select2 does not re-trigger itself')
})

test('programmatic value changes re-render Select2 once', () => {
    const select = fakeSelect()
    const { form, calls } = formWith(select)
    select.value = 'Hà Nội' // e.g. initial values on a correction link or a form reset
    form.syncSearchable()
    form.syncSearchable()
    assert.deepEqual(calls.triggered, ['change.select2'])
})
