import { describe, it, expect } from 'vitest'

import { getInputVariableByTemplate, mergeInput } from '@/mixins/utils'

describe('utils:getInputVariableByTemplate', () => {
    it('parses query blocks', () => {
        const templates = [
            {
                content:
                    '{query:posts variable="$item" limit="5" template=`<div>{title}</div>`}',
            },
        ]
        const res = getInputVariableByTemplate([], templates)

        const q = res.find((i) => i.name === 'query:posts')
        expect(q).toBeTruthy()
        expect(q?.type).toBe('query')
        expect(q?.default_value).toBe('5')
        expect(q?.options).toMatchObject({ variable: '$item', innerTemplate: '<div>{title}</div>' })
    })

    it('parses upload blocks with and without name', () => {
        const templates = [
            { content: '{upload name="hero"}{upload}' },
        ]
        const res = getInputVariableByTemplate([], templates)

        const named = res.find((i) => i.name === 'hero')
        expect(named).toBeTruthy()
        expect(named?.type).toBe('upload')

        const anon = res.find((i) => i.name?.startsWith('upload_'))
        expect(anon).toBeTruthy()
        expect(anon?.type).toBe('upload')
    })

    it('parses image blocks and maps config source', () => {
        const templates = [
            { content: '{image name="logo" source="config:site.logo" class="w-8" alt="MyLogo"}' },
            { content: '{image source="/uploads/file.png"}' },
        ]
        const res = getInputVariableByTemplate([], templates)

        const logo = res.find((i) => i.name === 'logo')
        expect(logo).toBeTruthy()
        expect(logo?.type).toBe('image')
        expect(logo?.options).toMatchObject({ class: 'w-8', alt: 'MyLogo', src: 'config', value: 'site.logo' })
        expect(logo?.default_value).toBe('site.logo')

        const uploaded = res.find((i) => i.type === 'image' && i.options?.src === 'upload')
        expect(uploaded).toBeTruthy()
    })

    it('parses iSeo tokens', () => {
        const templates = [{ content: '{iSeo:meta_title}{iSeo:meta_description}' }]
        const res = getInputVariableByTemplate([], templates)

        const s1 = res.find((i) => i.name === 'iSeo:meta_title')
        const s2 = res.find((i) => i.name === 'iSeo:meta_description')
        expect(s1?.type).toBe('iSeo')
        expect(s1?.default_value).toBe('meta_title')
        expect(s2?.default_value).toBe('meta_description')
    })

    it('parses list blocks', () => {
        const templates = [
            { content: '{list:tags variable="$tag" template=`<li>{name}</li>`}' },
        ]
        const res = getInputVariableByTemplate([], templates)
        const l = res.find((i) => i.name === 'list_tags')
        expect(l).toBeTruthy()
        expect(l?.type).toBe('list')
        expect(l?.default_value).toEqual([])
        expect(l?.options).toMatchObject({ variable: '$tag', template: '<li>{name}</li>' })
    })

    it('creates normal text inputs and deduplicates across templates', () => {
        const templates = [
            { content: '{title}{subtitle}' },
            { content: '{title}{another}' },
        ]
        const res = getInputVariableByTemplate([], templates)
        const names = res.map((i) => i.name)
        expect(names.filter((n) => n === 'title').length).toBe(1)
        expect(res.find((i) => i.name === 'subtitle')?.type).toBe('text')
        expect(res.find((i) => i.name === 'another')?.type).toBe('text')
    })

    it('merges existing inputs with precedence on existing.type', () => {
        const existing = [
            { name: 'query:posts', type: 'custom', options: { a: 1 } },
        ]
        const templates = [
            {
                content:
                    '{query:posts variable="$item" limit="3" template=`<div>{title}</div>`}',
            },
        ]
        const res = getInputVariableByTemplate(existing, templates)
        const q = res.find((i) => i.name === 'query:posts')
        expect(q?.type).toBe('custom')
        expect(q?.default_value).toBe('3')
        expect(q?.options).toMatchObject({ a: 1, variable: '$item', innerTemplate: '<div>{title}</div>' })
    })
})

describe('utils:mergeInput', () => {
    it('preserves existing type and merges options', () => {
        const existing = { type: 'keep', options: { a: 1, x: 0 } }
        const updated = { type: 'new', default_value: 'dv', options: { b: 2, x: 9 } }
        const r = mergeInput(existing, updated)
        expect(r.type).toBe('keep')
        expect(r.default_value).toBe('dv')
        expect(r.options).toEqual({ a: 1, x: 9, b: 2 })
    })
})
