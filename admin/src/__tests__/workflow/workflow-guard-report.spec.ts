import { describe, expect, it } from 'vitest'
import {
    canRollbackTo,
    describeChanges,
    diffRows,
    needsPublishBeforeOn,
    pausedInfo,
    pendingWhen,
    problemsForNodes,
    publishButtonKey,
    publishOutcome,
    publishPath,
    publishProblems,
    stepNames,
    versionState,
    versionStatus,
    workflowAbilities,
} from '@core-admin/workflow-guard-report'

/** Translator stand-in: the key, followed by its params when it has any. */
const t = (key: string, params?: Record<string, unknown>) => (params && Object.keys(params).length ? `${key}${JSON.stringify(params)}` : key)
const names = { fetch: 'Call the CRM', ok: 'Wait for approval', post: 'Post to the site' }
const NOW = new Date('2026-11-25T09:00:00')

describe('sensitive changes', () => {
    it('words every known kind, names the step and never shows a step code', () => {
        const rows = describeChanges(
            [
                { kind: 'sensitive_step_added', step_code: 'fetch', detail: 'http_request' },
                { kind: 'host_added', detail: 'api.example.com' },
                { kind: 'approval_removed', step_code: 'ok' },
            ],
            { t, names }
        )
        expect(rows.map((row) => row.text)).toEqual([
            'text.workflow.guard.change.sensitive_step_added',
            'text.workflow.guard.change.host_added',
            'text.workflow.guard.change.approval_removed',
        ])
        expect(rows[0].step).toBe('Call the CRM')
        // The node type of an added step is not a name an operator reads.
        expect(rows[0].detail).toBe('')
        expect(rows[1].detail).toBe('api.example.com')
    })

    it('uses the generic sentence for a kind it does not know and drops repeats', () => {
        const rows = describeChanges([{ kind: 'something_new' }, { kind: 'something_new' }, { kind: 'budget_raised' }], { t })
        expect(rows.map((row) => row.text)).toEqual(['text.workflow.guard.change.other', 'text.workflow.guard.change.budget_raised'])
    })

    it('never shows a config value or JSON as a detail', () => {
        const [row] = describeChanges([{ kind: 'credential_changed', detail: '{"token":"abc"}' }, { kind: 'host_added', detail: { host: 'x' } }], { t })
        expect(row.detail).toBe('')
    })

    it('leaves the step out when its code has no name', () => {
        expect(describeChanges([{ kind: 'approval_removed', step_code: 'step_9f2' }], { t, names })[0].step).toBe('')
    })
})

describe('what a publish does', () => {
    it('reads the path from a preview, an answer, or the version status of an older server', () => {
        expect(publishPath({ unchanged: true, path: 'published' })).toBe('unchanged')
        expect(publishPath({ path: 'pending_review' })).toBe('pending_review')
        expect(publishPath({ status: 'cooling_off' })).toBe('cooling_off')
        expect(publishPath({ status: 'published', number: 2 })).toBe('published')
    })

    it('says "goes live now" for a change that is not sensitive', () => {
        const outcome = publishOutcome({ path: 'published', sensitive: false }, { t, now: NOW })
        expect(outcome).toMatchObject({ path: 'published', tone: 'success' })
        expect(outcome.text).toContain('text.workflow.guard.outcome.will.published')
    })

    it('says "waits for another person" when a second publisher exists', () => {
        const outcome = publishOutcome({ path: 'pending_review', sensitive: true }, { t, now: NOW })
        expect(outcome).toMatchObject({ path: 'pending_review', tone: 'warning' })
        expect(outcome.text).toContain('text.workflow.guard.outcome.will.pending_review')
    })

    it('says the clock time a lone publisher\'s change goes live at, from the waiting minutes', () => {
        const outcome = publishOutcome({ path: 'cooling_off', cooling_off_minutes: 60 }, { t, now: NOW })
        expect(outcome.time).toBe('10:00')
        expect(outcome.text).toContain('text.workflow.guard.outcome.will.cooling_off')
        expect(outcome.text).toContain('"minutes":60')
    })

    it('uses the server time of a finished publish and the "done" sentences', () => {
        const outcome = publishOutcome({ path: 'cooling_off', number: 5, effective_at: '2026-11-25T10:30:00' }, { t, done: true, now: NOW })
        expect(outcome.time).toBe('10:30')
        expect(outcome.text).toContain('text.workflow.guard.outcome.done.cooling_off')
        expect(outcome.text).toContain('"number":5')
        expect(publishOutcome({ path: 'published', number: 5 }, { t, done: true }).text).toContain('text.workflow.guard.outcome.done.published')
        expect(publishOutcome({ path: 'unchanged' }, { t, done: true }).tone).toBe('info')
    })

    it('adds the date when the change goes live on another day', () => {
        expect(publishOutcome({ path: 'cooling_off', effective_at: '2026-11-26T00:20:00' }, { t, done: true, now: NOW }).time).toBe('00:20 26/11')
    })

    it('labels the confirm button after the path', () => {
        expect(publishButtonKey('published')).toBe('text.workflow.guard.publish.publish_now')
        expect(publishButtonKey('pending_review')).toBe('text.workflow.guard.publish.send_for_review')
        expect(publishButtonKey('cooling_off')).toBe('text.workflow.guard.publish.confirm_wait')
    })
})

describe('refused publish', () => {
    const problems = [
        { code: 'publish_needs_approval', step_code: 'post', message: 'post: publishes without an approval step before it.' },
        { code: 'host_not_allowlisted', step_code: 'fetch', message: 'fetch: outbound host api.example.com is not on this site\'s allowlist.' },
        { code: 'too_many_steps', message: 'The workflow has 300 steps; the limit is 200.' },
        { code: 'class_not_allowed', step_code: 'fetch', message: 'fetch: blueprint_callable: class not allowed' },
    ]

    it('turns every problem into a sentence with the step name, never the server text of a known code', () => {
        const rows = publishProblems(problems, { t, names })
        expect(rows.map((row) => row.text)).toEqual([
            'text.workflow.guard.problem.publish_needs_approval',
            'text.workflow.guard.problem.host_not_allowlisted{"host":"api.example.com"}',
            'text.workflow.guard.problem.too_many_steps',
            'text.workflow.package.problem.callable_not_allowed',
        ])
        expect(rows[0].steps).toEqual(['Post to the site'])
        expect(rows[1].steps).toEqual(['Call the CRM'])
        expect(rows[2].steps).toEqual([])
    })

    it('hands the node cards the translated sentence for each step at fault', () => {
        expect(problemsForNodes(problems, { t, names })).toEqual([
            { step_code: 'post', code: 'publish_needs_approval', message: 'text.workflow.guard.problem.publish_needs_approval' },
            { step_code: 'fetch', code: 'host_not_allowlisted', message: 'text.workflow.guard.problem.host_not_allowlisted{"host":"api.example.com"}' },
            { step_code: 'fetch', code: 'class_not_allowed', message: 'text.workflow.package.problem.callable_not_allowed' },
        ])
    })
})

describe('version history', () => {
    it('tags the version that runs, a replaced one and the waiting ones', () => {
        expect(versionStatus({ status: 'published', is_published: true }).key).toBe('text.workflow.guard.status.running')
        expect(versionStatus({ status: 'published', is_published: false }).key).toBe('text.workflow.guard.status.superseded')
        expect(versionStatus({ status: 'pending_review' })).toEqual({ color: 'orange', key: 'text.workflow.guard.status.pending_review' })
        expect(versionStatus({ status: 'rejected' }).color).toBe('red')
        expect(versionStatus({ status: 'brand_new' }).key).toBe('text.workflow.guard.status.other')
    })

    it('offers "return to" only for versions that ran before', () => {
        expect(canRollbackTo({ status: 'superseded', is_published: false })).toBe(true)
        expect(canRollbackTo({ status: 'published', is_published: true })).toBe(false)
        expect(canRollbackTo({ status: 'pending_review' })).toBe(false)
        expect(canRollbackTo({ status: 'cancelled' })).toBe(false)
        expect(canRollbackTo({ status: 'rejected' })).toBe(false)
    })

    it('reads step names from a version graph and from the designer nodes', () => {
        expect(stepNames([{ step_code: 'a', name: 'First' }, { data: { step_code: 'b', name: 'Second' } }, { step_code: 'c' }])).toEqual({ a: 'First', b: 'Second' })
    })

    const diff = {
        steps: {
            added: [{ step_code: 'fetch', step_type: 'http_request', name: 'Call the CRM' }],
            removed: [{ step_code: 'ok', step_type: 'approval', name: null }],
            changed: [
                { step_code: 'post', step_type: 'publish', fields: ['name', 'position_x'], config_keys: ['prompt', 'maxItems', 'target_field'] },
                { step_code: 'moved_1', fields: ['position_x', 'position_y'], config_keys: [] },
                { step_code: 'moved_2', fields: ['position_y'], config_keys: [] },
            ],
        },
        connections: { added: [{ from: 'fetch', to: 'post', handle: null }], removed: [{ from: 'ok', to: 'gone', handle: 'approved' }] },
        settings: [
            { key: 'schedule_cron', before: null, after: '0 8 * * *' },
            { key: 'budget_max_cost_per_run', before: '1.00', after: '5.00' },
            { key: 'something_else', before: 1, after: 2 },
        ],
        identical: false,
    }

    it('writes one plain sentence per change: steps, links, then run settings', () => {
        const rows = diffRows(diff, { t, names })
        expect(rows.map((row) => row.text)).toEqual([
            'text.workflow.guard.diff.step_added{"step":"Call the CRM"}',
            'text.workflow.guard.diff.step_removed{"step":"Wait for approval"}',
            'text.workflow.guard.diff.step_changed{"step":"Post to the site","what":"text.workflow.guard.diff.field.renamed, text.workflow.guard.diff.field.settings{\\"count\\":3}"}',
            'text.workflow.guard.diff.link_added{"from":"Call the CRM","to":"Post to the site"}',
            'text.workflow.guard.diff.link_removed{"from":"Wait for approval","to":"text.workflow.guard.diff.unnamed_step"}',
            'text.workflow.guard.diff.setting.schedule_cron',
            'text.workflow.guard.diff.setting.budget_max_cost_per_run',
            'text.workflow.guard.diff.setting.other',
            'text.workflow.guard.diff.moved_only{"count":2}',
        ])
        expect(rows.map((row) => row.tone)).toEqual(['added', 'removed', 'changed', 'added', 'removed', 'changed', 'changed', 'changed', 'muted'])
    })

    it('lists changed settings by name only, never a value', () => {
        const rows = diffRows(diff, { t, names })
        expect(rows[2].detail).toBe('prompt, max items, target field')
        const all = JSON.stringify(rows)
        expect(all).not.toContain('0 8 * * *')
        expect(all).not.toContain('5.00')
    })

    it('reads a link between the same two steps on both sides as one changed link, and skips the designer\'s own keys', () => {
        const rows = diffRows(
            {
                steps: { added: [], removed: [], changed: [{ step_code: 'post', fields: [], config_keys: ['node_type'] }, { step_code: 'fetch', fields: [], config_keys: ['node_type', 'url'] }] },
                connections: { added: [{ from: 'fetch', to: 'post', handle: 'success' }], removed: [{ from: 'fetch', to: 'post', handle: null }, { from: 'ok', to: 'post', handle: null }] },
                settings: [],
            },
            { t, names }
        )
        expect(rows.map((row) => row.text)).toEqual([
            'text.workflow.guard.diff.step_changed{"step":"Call the CRM","what":"text.workflow.guard.diff.field.settings{\\"count\\":1}"}',
            'text.workflow.guard.diff.link_changed{"from":"Call the CRM","to":"Post to the site"}',
            'text.workflow.guard.diff.link_removed{"from":"Wait for approval","to":"Post to the site"}',
        ])
        expect(rows[0].detail).toBe('url')
    })

    it('answers nothing for two identical versions', () => {
        expect(diffRows({ steps: { added: [], removed: [], changed: [] }, connections: { added: [], removed: [] }, settings: [], identical: true }, { t })).toEqual([])
        expect(diffRows(undefined, { t })).toEqual([])
    })
})

describe('waiting changes', () => {
    it('says a change waits for a person, or when it goes live with the minutes left', () => {
        expect(pendingWhen({ status: 'pending_review' }, NOW).key).toBe('text.workflow.guard.pending.waits_for_person')
        expect(pendingWhen({ status: 'cooling_off', effective_at: '2026-11-25T09:42:00' }, NOW)).toEqual({
            key: 'text.workflow.guard.pending.goes_live_at',
            params: { time: '09:42', minutes: 42 },
        })
        expect(pendingWhen({ status: 'cooling_off', effective_at: '2026-11-25T08:59:00' }, NOW).key).toBe('text.workflow.guard.pending.goes_live_soon')
        expect(pendingWhen({ status: 'cooling_off' }, NOW).key).toBe('text.workflow.guard.pending.waits_for_time_unknown')
    })
})

describe('paused workflows', () => {
    it('is not paused without a reason', () => {
        expect(pausedInfo({ paused_reason: null })).toBeNull()
        expect(pausedInfo({})).toBeNull()
    })

    it.each(['runs_per_hour', 'actions_per_hour', 'cost_per_hour', 'error_rate'])('words the %s safety limit and offers "turn back on"', (limit) => {
        expect(pausedInfo({ paused_reason: `breaker:${limit}` })).toEqual({ kind: 'breaker', reasonKey: `text.workflow.guard.paused.reason.${limit}`, canResume: true })
    })

    it('falls back to a generic safety-limit sentence for a limit it does not know', () => {
        expect(pausedInfo({ paused_reason: 'breaker:new_limit' })?.reasonKey).toBe('text.workflow.guard.paused.reason.breaker_other')
    })

    it('says an integrity pause needs a review and a new publish, not a plain "turn back on"', () => {
        expect(pausedInfo({ paused_reason: 'integrity' })).toEqual({ kind: 'integrity', reasonKey: 'text.workflow.guard.paused.reason.integrity', canResume: false })
    })

    it('never shows an unknown reason code', () => {
        expect(pausedInfo({ paused_reason: 'manual_x' })).toEqual({ kind: 'other', reasonKey: 'text.workflow.guard.paused.reason.other', canResume: false })
    })
})

describe('abilities and version state of a row', () => {
    const rules = (...keys: string[]) => (key: string) => keys.includes(key)

    it('follows the row\'s own answers over the account permissions', () => {
        const row = { can_publish: false, can_run: true }
        expect(workflowAbilities(row, rules('workflow_project.update', 'workflow_project.publish'))).toEqual({ canEdit: true, canPublish: false, canRun: true })
    })

    it('falls back to the permissions when the row does not say', () => {
        expect(workflowAbilities({}, rules('workflow_project.publish'))).toEqual({ canEdit: false, canPublish: true, canRun: false })
        expect(workflowAbilities({}, rules('workflow_project.update', 'workflow_project.run'))).toEqual({ canEdit: true, canPublish: false, canRun: true })
    })

    it('never lets a platform-managed workflow be edited or published', () => {
        expect(workflowAbilities({ system_managed: true, can_publish: true, can_run: true }, () => true)).toEqual({ canEdit: false, canPublish: false, canRun: true })
    })

    it('reads published, draft-differs and waiting counts, and guesses nothing on an older server', () => {
        expect(versionState({ published_version_id: 9, published_version_number: 3, draft_differs: true, pending_versions: 2 })).toEqual({
            known: true,
            published: true,
            number: 3,
            draftDiffers: true,
            pending: 2,
        })
        expect(versionState({ published_version_id: null, draft_differs: true })).toMatchObject({ known: true, published: false, draftDiffers: false })
        expect(versionState({ id: 1 })).toMatchObject({ known: false, published: false, pending: 0 })
    })

    it('refuses turning on a workflow that was never published, and only then', () => {
        expect(needsPublishBeforeOn({ published_version_id: null })).toBe(true)
        expect(needsPublishBeforeOn({ published_version_id: 4 })).toBe(false)
        expect(needsPublishBeforeOn({ id: 1 })).toBe(false)
    })
})
