import { describe, expect, it, vi } from 'vitest'
import {
    PROBLEM_CODES,
    bindingPayload,
    bindingRows,
    copyName,
    copySlug,
    credentialOptions,
    defaultBindingValues,
    describeProblem,
    describeProblems,
    dryRunRows,
    dryRunState,
    fileRefusal,
    findCopySlug,
    installState,
    packageSummary,
    parsePackage,
    stepNamesOf,
} from '@core-admin/workflow-package-report'

const t = (key: string, params?: Record<string, unknown>) => (params && Object.keys(params).length ? `${key}${JSON.stringify(params)}` : key)

const pkg = {
    workflow: {
        slug: 'sync-crm',
        steps: [
            { step_code: 'fetch', name: 'Call the CRM', step_type: 'http_request' },
            { step_code: 'ok', name: 'Wait for approval', step_type: 'approval' },
            { step_code: 'push', name: '', step_type: 'push_send' },
        ],
    },
}
const names = stepNamesOf(pkg)
const UUID = '3f0c2a0e-1b7d-4c55-9a43-0d6a1f2b3c4d'

describe('workflow package: reading the file', () => {
    it('parses a JSON object and refuses anything else', () => {
        expect(parsePackage('{"a":1}')).toEqual({ a: 1 })
        expect(parsePackage('not json')).toBeNull()
        expect(parsePackage('[1]')).toBeNull()
        expect(parsePackage('')).toBeNull()
    })

    it('maps step codes to the names the author gave, falling back to the code', () => {
        expect(names).toEqual({ fetch: 'Call the CRM', ok: 'Wait for approval', push: 'push' })
        expect(stepNamesOf(null)).toEqual({})
    })

    it('says why a refused file could not be read', () => {
        expect(fileRefusal({ size: 3 * 1024 * 1024 }, null)).toBe('too_large')
        expect(fileRefusal({ size: 10 }, null)).toBe('not_a_package')
        expect(fileRefusal({ size: 10 }, {})).toBe('unreadable')
    })
})

describe('workflow package: report rows as sentences', () => {
    it('has a translated sentence for every known code', () => {
        PROBLEM_CODES.forEach((code) => {
            const row = describeProblem({ code, message: 'English server text' }, { t, names, pkg })
            expect(row.known).toBe(true)
            expect(row.text.startsWith('text.workflow.package.problem.')).toBe(true)
            expect(row.text).not.toContain('English server text')
        })
    })

    it('names the step of a step problem instead of its code', () => {
        const row = describeProblem({ code: 'taint_needs_approval', message: 'fetch: …', step_code: 'fetch' }, { t, names, pkg })
        expect(row.text).toBe('text.workflow.package.problem.taint_needs_approval')
        expect(row.steps).toEqual(['Call the CRM'])
    })

    it('names the steps that use an unavailable node type', () => {
        const row = describeProblem({ code: 'unknown_node_type', message: 'Node type push_send is not available on this site (missing module or newer package).' }, { t, names, pkg })
        expect(row.steps).toEqual(['push'])
    })

    it('reads the node type, host and step from the row fields when the server sends them', () => {
        const typed = describeProblem({ code: 'unknown_node_type', message: 'reworded', node_type: 'push_send' }, { t, names, pkg })
        expect(typed.steps).toEqual(['push'])
        const host = describeProblem({ code: 'host_not_allowlisted', message: 'reworded', host: 'crm.example.com' }, { t })
        expect(host.text).toBe('text.workflow.package.problem.host_not_allowlisted{"host":"crm.example.com"}')
    })

    it('puts the host of an allowlist warning in the sentence', () => {
        const message = "Outbound host crm.example.com is not on this site's allowlist; its steps fail until a Webmaster adds it."
        expect(describeProblem({ code: 'host_not_allowlisted', message }, { t }).text).toBe('text.workflow.package.problem.host_not_allowlisted{"host":"crm.example.com"}')
        expect(describeProblem({ code: 'host_not_allowlisted', message: 'reworded' }, { t }).text).toBe('text.workflow.package.problem.host_not_allowlisted_plain')
    })

    it('falls back to the server message for a code it does not know', () => {
        const row = describeProblem({ code: 'brand_new_code', message: 'Something new.' }, { t, names })
        expect(row).toMatchObject({ known: false, text: 'Something new.', detail: 'brand_new_code' })
    })

    it('keeps one row when two server checks report the same unavailable step type', () => {
        const rows = describeProblems(
            [
                { code: 'unknown_node_type', message: 'Node type push_send is not available on this site (missing module or newer package).' },
                { code: 'unknown_node_type', message: 'push: unknown step_type push_send' },
            ],
            { t, names, pkg }
        )
        expect(rows).toHaveLength(1)
        expect(rows[0].steps).toEqual(['push'])
    })

    it('does not repeat the same sentence for the same step', () => {
        const rows = describeProblems(
            [
                { code: 'bad_envelope', message: 'Missing key "manifest".' },
                { code: 'bad_envelope', message: 'Missing key "checksum".' },
                { code: 'config_invalid', message: 'a', step_code: 'fetch' },
                { code: 'config_invalid', message: 'b', step_code: 'ok' },
            ],
            { t, names }
        )
        expect(rows.map((row) => [row.code, row.steps])).toEqual([
            ['bad_envelope', []],
            ['config_invalid', ['Call the CRM']],
            ['config_invalid', ['Wait for approval']],
        ])
    })
})

describe('workflow package: install as a copy', () => {
    it('derives copy keys from the package key, never from typing', () => {
        expect(copySlug('sync-crm')).toBe('sync-crm-copy')
        expect(copySlug('sync-crm', 3)).toBe('sync-crm-copy-3')
        expect(copySlug('Bad Slug!')).toBe('bad-slug-copy')
        expect(copySlug('')).toBe('workflow-copy')
        expect(copySlug('a'.repeat(191)).length).toBeLessThanOrEqual(191)
    })

    it('gives a copy its own name, numbered like its key', () => {
        expect(copyName('Sync CRM', 'sync-crm-copy', t)).toBe('text.workflow.package.copy_name{"name":"Sync CRM"}')
        expect(copyName('Sync CRM', 'sync-crm-copy-3', t)).toBe('text.workflow.package.copy_name_n{"name":"Sync CRM","n":3}')
        expect(copyName('', 'sync-crm-copy', t)).toBe('')
    })

    it('asks the server until a copy key is free', async () => {
        const preview = vi.fn(async (slug: string) => ({ target_slug: slug, slug_problems: slug === 'sync-crm-copy-2' ? [] : [{ code: 'slug_exists' }] }))
        const found = await findCopySlug('sync-crm', preview)
        expect(preview.mock.calls.map((call) => call[0])).toEqual(['sync-crm-copy', 'sync-crm-copy-2'])
        expect(found?.slug).toBe('sync-crm-copy-2')
        expect(found?.report.target_slug).toBe('sync-crm-copy-2')
    })

    it('gives up after a few taken keys', async () => {
        const preview = vi.fn(async () => ({ slug_problems: [{ code: 'slug_exists' }] }))
        expect(await findCopySlug('sync-crm', preview, 3)).toBeNull()
        expect(preview).toHaveBeenCalledTimes(3)
    })
})

describe('workflow package: bindings', () => {
    const credentials = [
        { name: 'crm_api', type: 'http_header' },
        { name: 'crm_login', type: 'http_basic' },
        { name: 'bot', type: 'telegram_bot' },
    ]
    const bindings = [
        { name: 'crm_api', kind: 'credential', credential_type: 'http_auth', steps: ['fetch'], resolved: true },
        { name: 'user_1', kind: 'user', steps: ['ok'], resolved: false },
        { name: 'record_1', kind: 'record', steps: ['push'], resolved: false },
    ]

    it('offers only credentials of a type the step accepts', () => {
        expect(credentialOptions(credentials, 'http_auth').map((o) => o.value)).toEqual(['crm_api', 'crm_login'])
        expect(credentialOptions(credentials, 'telegram_bot').map((o) => o.value)).toEqual(['bot'])
        expect(credentialOptions(credentials, undefined)).toHaveLength(3)
        expect(credentialOptions(credentials, 'http_auth', (type) => `T:${type}`)[0].label).toBe('crm_api (T:http_header)')
    })

    it('pre-selects a fitting credential of the same name, like the server default', () => {
        expect(defaultBindingValues(bindings, credentials)).toEqual({ crm_api: 'crm_api' })
        expect(defaultBindingValues(bindings, [{ name: 'crm_api', type: 'telegram_bot' }])).toEqual({})
        expect(defaultBindingValues(bindings, [])).toEqual({})
    })

    it('sends only chosen values, and a record code only once it is complete', () => {
        expect(bindingPayload(bindings, { crm_api: 'crm_login', user_1: '', record_1: 'abc' })).toEqual({ crm_api: 'crm_login' })
        expect(bindingPayload(bindings, { record_1: ` ${UUID} ` })).toEqual({ record_1: UUID })
    })

    it('builds rows with step names and a state per binding', () => {
        const report = { bindings, binding_problems: [{ code: 'binding_invalid', binding: 'user_1', message: 'x' }] }
        const rows = bindingRows(report, { crm_api: 'crm_api', user_1: UUID, record_1: 'abc' }, names)
        expect(rows.map((row) => [row.name, row.kind, row.state, row.steps])).toEqual([
            ['crm_api', 'credential', 'ok', ['Call the CRM']],
            ['user_1', 'user', 'invalid', ['Wait for approval']],
            ['record_1', 'record', 'invalid', ['push']],
        ])
        expect(bindingRows({ bindings }, {}, names).map((row) => row.state)).toEqual(['ok', 'empty', 'empty'])
        expect(bindingRows({ bindings: [{ name: 'x', kind: 'future_kind', steps: [], resolved: false }] }, {})[0].kind).toBe('record')
    })
})

describe('workflow package: install button', () => {
    const ok = { installable: true, problems: [], binding_problems: [], slug_problems: [], bindings: [] }

    it('is on only for an installable report with nothing running', () => {
        expect(installState(ok)).toEqual({ enabled: true, blockers: [] })
        expect(installState(ok, { busy: true }).enabled).toBe(false)
        expect(installState(null).blockers).toEqual([{ code: 'checking' }])
        expect(installState({ ...ok, installable: false }).enabled).toBe(false)
    })

    it('says what is still in the way', () => {
        const report = { installable: false, problems: [{ code: 'checksum_mismatch' }], binding_problems: [], slug_problems: [{ code: 'slug_exists' }] }
        const rows = [{ state: 'ok' }, { state: 'empty' }, { state: 'invalid' }]
        expect(installState(report, { rows })).toEqual({ enabled: false, blockers: [{ code: 'problems' }] })
        expect(installState({ ...report, problems: [] }, { rows })).toEqual({ enabled: false, blockers: [{ code: 'bindings', count: 2 }, { code: 'slug' }] })
        expect(installState({ ...ok, installable: false, slug_problems: [{ code: 'slug_invalid' }] }).blockers).toEqual([{ code: 'slug_invalid' }])
        expect(installState({ ...ok, installable: false, binding_problems: [{ code: 'assignee_unknown', binding: null }] }).blockers).toEqual([{ code: 'loose' }])
    })
})

describe('workflow package: summary and test run', () => {
    const report = {
        manifest: {
            safety_classes: ['external_call', 'internal_write', 'read', 'ai_spend'],
            steps: [{ step_type: 'http_request' }, { step_type: 'approval' }],
            outbound_hosts: ['(expression)', 'crm.example.com'],
            modules: ['Core', 'PushNotification'],
        },
        dry_run: {
            completed: true,
            steps: [
                { step_code: 'fetch', step_type: 'http_request', safety: ['external_call'], mode: 'stubbed', outcome: 'would_run' },
                { step_code: 'ok', step_type: 'approval', safety: ['read'], mode: 'stubbed', outcome: 'would_run' },
                { step_code: 'calc', step_type: 'set_variable', safety: ['read'], mode: 'executed', outcome: 'success' },
                { step_code: 'bad', step_type: 'assert_condition', safety: ['read'], mode: 'executed', outcome: 'failed', error: 'no' },
            ],
        },
    }

    it('summarises what the workflow does, most consequential first', () => {
        expect(packageSummary(report)).toEqual({
            stepCount: 2,
            safety: ['ai_spend', 'external_call', 'internal_write', 'read'],
            hosts: ['crm.example.com'],
            hostFromData: true,
            modules: ['PushNotification'],
            needsApproval: true,
        })
        expect(packageSummary(null).stepCount).toBe(0)
    })

    it('tells apart steps that ran, steps only described, and approvals', () => {
        const rows = dryRunRows(report, names)
        expect(rows.map((row) => [row.name, row.mode, row.failed])).toEqual([
            ['Call the CRM', 'would', false],
            ['Wait for approval', 'approval', false],
            ['calc', 'ran', false],
            ['bad', 'ran', true],
        ])
        expect(rows[0].safety).toEqual(['external_call'])
    })

    it('knows when there was no test run', () => {
        expect(dryRunState({ dry_run: null })).toBe('none')
        expect(dryRunState(report)).toBe('completed')
        expect(dryRunState({ dry_run: { completed: false, steps: [] } })).toBe('stopped')
    })
})
