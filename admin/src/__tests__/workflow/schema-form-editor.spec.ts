import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@/services/workflowApi', () => ({ fetchNodeCatalog: vi.fn(), validateExpression: vi.fn() }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import SchemaFormEditor from '@/components/workflow/node-editors/SchemaFormEditor.vue'
import { resolveEditorComponent } from '@/components/workflow/node-editors/editorRegistry.js'
import { bindingName, bindingValue, emptyValue, fieldKind, fieldLabel } from '@/components/workflow/node-editors/schemaForm.js'
import { applyNodeCatalog } from '@/components/workflows/nodeCatalog'
import { antdStubs } from './antd-stubs'

const schema = {
    type: 'object',
    required: ['url'],
    properties: {
        url: { type: 'string', title: 'Address', description: 'The page to fetch.' },
        timeout: { type: ['integer', 'string', 'null'], minimum: 1 },
        follow: { type: ['boolean', 'string', 'integer', 'null'] },
        method: { type: 'string', enum: ['GET', 'POST'] },
        when: { type: ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr': true },
        auth: { type: 'string', 'x-secret': 'http_auth' },
        storeAs: { type: ['string', 'null'], 'x-store': true },
        options: { type: 'object', properties: { retries: { type: 'integer' }, note: { type: 'string' } } },
        headers: { type: ['array', 'null'], items: { type: 'object', properties: { name: { type: 'string' }, value: { type: 'string' } } } },
        context: { type: ['object', 'array', 'null'] },
    },
}

const mountEditor = (modelValue: Record<string, unknown> = {}, props: Record<string, unknown> = {}) =>
    mount(SchemaFormEditor, { props: { schema, modelValue, ...props }, global: { stubs: antdStubs } })

const lastConfig = (wrapper: ReturnType<typeof mountEditor>) => {
    const events = wrapper.emitted('update:modelValue') || []
    return events[events.length - 1]?.[0] as Record<string, any>
}

describe('schema form helpers', () => {
    it('picks a widget from the schema and the stored value', () => {
        expect(fieldKind({ type: 'string' })).toBe('string')
        expect(fieldKind({ type: ['integer', 'string', 'null'] })).toBe('number')
        expect(fieldKind({ type: ['integer', 'string', 'null'] }, '{{ vars.n }}')).toBe('string')
        expect(fieldKind({ type: ['boolean', 'string', 'integer', 'null'] }, true)).toBe('boolean')
        expect(fieldKind({ type: 'string', enum: ['a'] })).toBe('enum')
        expect(fieldKind({ type: 'string', 'x-expr': true })).toBe('expr')
        expect(fieldKind({ type: 'string', 'x-secret': 'smtp' })).toBe('binding')
        expect(fieldKind({ type: 'object', properties: { a: { type: 'string' } } })).toBe('object')
        expect(fieldKind({ type: ['object', 'array', 'null'] })).toBe('json')
        expect(fieldKind({ type: ['array', 'null'], items: { type: 'string' } })).toBe('array')
        expect(fieldKind({ type: ['array', 'object', 'null'] }, [])).toBe('json')
        expect(fieldKind({ type: ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'] })).toBe('json')
    })

    it('labels fields and handles credential names', () => {
        expect(fieldLabel('collectionExpr')).toBe('Collection expr')
        expect(fieldLabel('store_as')).toBe('Store as')
        expect(fieldLabel('url', { title: 'Address' })).toBe('Address')
        expect(bindingName('$bind:shop-api')).toBe('shop-api')
        expect(bindingName('plain')).toBe('')
        expect(bindingValue(' shop-api ')).toBe('$bind:shop-api')
        expect(bindingValue('')).toBe('')
        expect(emptyValue({ type: 'object', properties: { name: { type: 'string' }, on: { type: 'boolean' } } })).toEqual({ name: '', on: false })
    })
})

describe('SchemaFormEditor', () => {
    it('renders one field per property with label, required mark and help text', () => {
        const wrapper = mountEditor({ url: 'https://example.org' })

        expect(wrapper.findAll('[data-testid="schema-form-editor"] > [data-field]')).toHaveLength(10)
        const url = wrapper.find('[data-field="url"]')
        expect(url.attributes('data-kind')).toBe('string')
        expect(url.text()).toContain('Address')
        expect(url.text()).toContain('*')
        expect(url.text()).toContain('The page to fetch.')
        expect((url.find('input').element as HTMLInputElement).value).toBe('https://example.org')
        expect(wrapper.find('[data-field="timeout"]').attributes('data-kind')).toBe('number')
        expect(wrapper.find('[data-field="follow"]').attributes('data-kind')).toBe('boolean')
        expect(wrapper.find('[data-field="method"]').attributes('data-kind')).toBe('enum')
        expect(wrapper.find('[data-field="when"]').attributes('data-kind')).toBe('template')
        expect(wrapper.find('[data-field="context"]').attributes('data-kind')).toBe('json')
    })

    it('emits the whole config and keeps keys the schema does not describe', async () => {
        const wrapper = mountEditor({ url: 'a', _v: 2, on_error: 'route' })

        await wrapper.find('[data-field="url"] input').setValue('https://example.org/feed')
        expect(lastConfig(wrapper)).toEqual({ url: 'https://example.org/feed', _v: 2, on_error: 'route' })

        await wrapper.find('[data-field="timeout"] input').setValue('30')
        expect(lastConfig(wrapper).timeout).toBe(30)

        await wrapper.find('[data-field="follow"] input').setValue(true)
        expect(lastConfig(wrapper).follow).toBe(true)

        await wrapper.find('[data-field="method"] select').setValue('POST')
        expect(lastConfig(wrapper)).toMatchObject({ url: 'https://example.org/feed', timeout: 30, follow: true, method: 'POST', _v: 2 })
    })

    it('uses the data input, without a check button, for x-expr fields', async () => {
        const wrapper = mountEditor({ when: 'Total: {{ root.total }}' })
        const field = wrapper.find('[data-field="when"]')
        const editor = field.get('[data-testid="data-token-editor"]')

        expect(editor.get('[data-raw]').attributes('data-raw')).toBe('{{ root.total }}')
        expect(field.find('textarea').exists()).toBe(false)
        expect(field.text()).not.toContain('text.workflow.editor.validate')
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()

        editor.element.appendChild(document.createTextNode(' VND'))
        await editor.trigger('input')
        expect(lastConfig(wrapper).when).toBe('Total: {{ root.total }} VND')
    })

    it('stores a credential as $bind:<name> and shows only the name', async () => {
        const wrapper = mountEditor({ auth: '$bind:shop-api' })
        const field = wrapper.find('[data-field="auth"]')

        expect((field.find('input').element as HTMLInputElement).value).toBe('shop-api')
        expect(field.text()).toContain('text.workflow.schema_form.binding_help')

        await field.find('input').setValue('news-feed')
        expect(lastConfig(wrapper).auth).toBe('$bind:news-feed')
    })

    it('warns when a saved result name cannot be read by later steps', async () => {
        const wrapper = mountEditor({ storeAs: 'result' })
        expect(wrapper.find('[data-field="storeAs"]').text()).toContain('text.workflow.schema_form.store_hint')

        await wrapper.setProps({ modelValue: { storeAs: 'vars.result' } })
        expect(wrapper.find('[data-field="storeAs"]').text()).not.toContain('text.workflow.schema_form.store_hint')
    })

    it('edits nested objects and lists of rows', async () => {
        const wrapper = mountEditor({ options: { retries: 1 }, headers: [{ name: 'Accept', value: 'text/html' }] })

        await wrapper.find('[data-field="options"] [data-field="note"] input').setValue('be gentle')
        expect(lastConfig(wrapper).options).toEqual({ retries: 1, note: 'be gentle' })

        const headers = wrapper.find('[data-field="headers"]')
        await headers.find('[data-field="0"] [data-field="value"] input').setValue('application/json')
        expect(lastConfig(wrapper).headers).toEqual([{ name: 'Accept', value: 'application/json' }])

        const buttons = headers.findAll('button')
        await buttons[buttons.length - 1].trigger('click')
        expect(lastConfig(wrapper).headers).toEqual([{ name: 'Accept', value: 'application/json' }, { name: '', value: '' }])

        await wrapper.find('[data-field="headers"] button').trigger('click')
        expect(lastConfig(wrapper).headers).toEqual([{ name: '', value: '' }])
    })

    it('edits free-form values as JSON and keeps the stored value while the text is invalid', async () => {
        const wrapper = mountEditor({ context: { source: 'feed' } })
        const area = wrapper.find('[data-field="context"] textarea')

        expect((area.element as HTMLTextAreaElement).value).toContain('"source": "feed"')

        await area.setValue('{ not json')
        await area.trigger('blur')
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        expect(wrapper.find('[data-field="context"]').text()).toContain('text.workflow.schema_form.json_invalid')

        await area.setValue('{"source":"site"}')
        await area.trigger('blur')
        expect(lastConfig(wrapper).context).toEqual({ source: 'site' })
    })

    it('disables every input when read-only and hides row buttons', () => {
        const wrapper = mountEditor({ headers: [{ name: 'a', value: 'b' }] }, { readOnly: true })

        expect(wrapper.findAll('input, textarea, select').every((el) => (el.element as HTMLInputElement).disabled)).toBe(true)
        expect(wrapper.find('[data-field="headers"]').findAll('button')).toHaveLength(0)
    })

    it('reads the schema from the node catalog by step type and says when a step has no settings', async () => {
        applyNodeCatalog({
            groups: [],
            nodes: [
                { type: 'http_request', config_schema: { type: 'object', properties: { url: { type: 'string' } } } },
                { type: 'note', config_schema: { type: 'object', properties: [] } },
            ],
        })
        const wrapper = mount(SchemaFormEditor, { props: { stepType: 'http_request', modelValue: {} }, global: { stubs: antdStubs } })
        expect(wrapper.findAll('[data-field]')).toHaveLength(1)

        await wrapper.setProps({ stepType: 'note' })
        expect(wrapper.findAll('[data-field]')).toHaveLength(0)
        expect(wrapper.text()).toContain('text.workflow.schema_form.no_settings')
    })
})

describe('editor choice', () => {
    const definition = { type: 'http_request', editor: null, config_schema: { type: 'object', properties: { url: { type: 'string' } } } }

    it('keeps dedicated editors and uses the schema form for the rest', () => {
        const retrieve = resolveEditorComponent('retrieve', { type: 'retrieve', editor: null, config_schema: definition.config_schema })

        expect(retrieve).not.toBe(SchemaFormEditor)
        expect(retrieve).toBeTruthy()
        expect(resolveEditorComponent('http_request', definition)).toBe(SchemaFormEditor)
        expect(resolveEditorComponent('http_request', { ...definition, editor: 'RetrieveEditor' })).toBe(retrieve)
        // An editor name this admin build does not ship falls back to the schema form.
        expect(resolveEditorComponent('http_request', { ...definition, editor: 'ai/PromptEditor' })).toBe(SchemaFormEditor)
        // No definition, or one without settings: the shell shows the JSON view.
        expect(resolveEditorComponent('mystery', null)).toBeNull()
        expect(resolveEditorComponent('mystery', { type: 'mystery', config_schema: { type: 'object', properties: [] } })).toBeNull()
    })
})
