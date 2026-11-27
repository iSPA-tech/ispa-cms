import { beforeEach, describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import {
    batchError,
    confirmItems,
    countsByKind,
    fetchCurrent,
    fetchPlan,
    isOpen,
    reasonKey,
    startBatch,
} from '@core-admin/update-batch-api'

// Upgrade-all (spec 2026-11-26) admin API: requests and the rules the dialog and panel use.

const ok = (data: unknown) => ({ data: { status: 'success', data } })
const refused = (status: number, code?: string) => ({ response: { status, data: code ? { error_code: code } : {} } })

describe('update batch api', () => {
    beforeEach(() => vi.restoreAllMocks())

    it('asks the plan for the given kinds, quietly', async () => {
        const get = vi.spyOn(ajax, 'get').mockResolvedValue(ok({ items: [], left_out: [], runner_ready: true }) as any)

        const plan = await fetchPlan(['widget'])

        expect(plan).toEqual({ items: [], left_out: [], runner_ready: true })
        expect(get).toHaveBeenCalledWith('utilities/updates/batch/plan', { silent: true, params: { kinds: ['widget'] } })
    })

    it('starts a run with only what the server checks: kind, key and target version', async () => {
        const post = vi.spyOn(ajax, 'post').mockResolvedValue(ok({ uuid: 'u-1' }) as any)
        const items = [
            { kind: 'module', key: 'Account', label: 'Account', from: '2026.11.04', to: '2026.11.05', state: 'pending' },
            { kind: 'widget', key: 'ispa.news', label: 'Tin tức', from: '2026.11.01', to: '2026.11.02', state: 'pending', pages: 3 },
        ]

        await startBatch({ kinds: ['module', 'widget'], items, backup: false })

        expect(post).toHaveBeenCalledWith(
            'utilities/updates/batch',
            {
                kinds: ['module', 'widget'],
                items: [
                    { kind: 'module', key: 'Account', to: '2026.11.05' },
                    { kind: 'widget', key: 'ispa.news', to: '2026.11.02' },
                ],
                backup: false,
            },
            { silent: true },
        )
        expect(confirmItems(items)).toHaveLength(2)
    })

    it('reads the open or last run, or null', async () => {
        vi.spyOn(ajax, 'get').mockResolvedValue(ok(null) as any)

        expect(await fetchCurrent()).toBeNull()
    })

    it('counts items by kind', () => {
        expect(countsByKind([{ kind: 'module' }, { kind: 'module' }, { kind: 'workflow' }])).toEqual({ module: 2, widget: 0, workflow: 1 })
        expect(countsByKind(undefined)).toEqual({ module: 0, widget: 0, workflow: 0 })
    })

    it('knows an open run', () => {
        expect(isOpen({ status: 'queued' })).toBe(true)
        expect(isOpen({ status: 'running' })).toBe(true)
        expect(isOpen({ status: 'succeeded' })).toBe(false)
        expect(isOpen(null)).toBe(false)
    })

    it('names a refusal in the page terms', () => {
        expect(batchError(refused(409, 'UPDATE_BATCH_RUNNING')).kind).toBe('running')
        expect(batchError(refused(409, 'UPDATE_BATCH_PLAN_CHANGED')).kind).toBe('changed')
        expect(batchError(refused(422, 'MODULE_INSTALL_NO_RUNNER')).kind).toBe('no_runner')
        expect(batchError(refused(409, 'CORE_UPGRADE_RUNNING')).kind).toBe('core_running')
        expect(batchError(refused(409, 'MODULE_INSTALL_RUNNING')).kind).toBe('install_running')
        expect(batchError(refused(422, 'UPDATE_BATCH_EMPTY')).kind).toBe('empty')
        expect(batchError(refused(502, 'MODULE_CATALOG_UNAVAILABLE')).kind).toBe('unreachable')
        expect(batchError(refused(403)).kind).toBe('forbidden')
        expect(batchError(refused(429)).kind).toBe('busy')
        expect(batchError(refused(500)).kind).toBe('failed')
        expect(batchError(new Error('network')).kind).toBe('failed')
    })

    it('maps a reason code to its copy, with one line for anything else', () => {
        expect(reasonKey('NEEDS_NEWER_CORE')).toBe('text.update_batch.reason.needs_newer_core')
        expect(reasonKey('WIDGET_UPDATE_DESIGN_IN_USE')).toBe('text.update_batch.reason.widget_update_design_in_use')
        expect(reasonKey('MODULE_REQUIRES_UNMET')).toBe('text.update_batch.reason.module_requires_unmet')
        expect(reasonKey('MODULE_DOWNLOAD_FAILED')).toBe('text.update_batch.reason.other')
        expect(reasonKey(null)).toBe('text.update_batch.reason.other')
    })
})
