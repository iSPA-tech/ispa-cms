import { describe, expect, it } from 'vitest'
import { coreAdminModule } from '../../../../cms/Core/admin/module'
import { resolveNotificationTarget } from '@/utils/notifications'

const types = (coreAdminModule as any).notifications || []
const open = (type: string, payload: Record<string, unknown> = {}) => resolveNotificationTarget({ type, payload }, types).location

describe('Core workflow notification targets', () => {
    it('declares the workflow types with a label key', () => {
        expect(types.map((entry: any) => entry.type).sort()).toEqual([
            'workflow.approval.overdue',
            'workflow.approval.requested',
            'workflow.change.activation_changed',
            'workflow.change.auto_paused',
            'workflow.change.credential_changed',
            'workflow.change.global_pause_changed',
            'workflow.change.integrity_violated',
            'workflow.change.version_pending',
            'workflow.change.version_published',
            'workflow.change.version_rolled_back',
            'workflow.notice',
            'workflow.question.asked',
        ])
        for (const entry of types as any[]) expect(entry.text).toMatch(/^text\.workflow\.notifications\.[a-z_]+$/)
    })

    it('opens the workflows page for every guardrail alert (publish, waiting change, rollback, pause, integrity, credential)', () => {
        const alerts = (types as any[]).filter((entry) => entry.type.startsWith('workflow.change.'))
        expect(alerts).toHaveLength(8)
        for (const entry of alerts) {
            expect(entry.text).toBe(`text.workflow.notifications.change_${entry.type.split('.').pop()}`)
            expect(open(entry.type, { project: 'Daily post' })).toEqual({ name: 'setting-workflows-manage' })
        }
    })

    it('opens the approval detail for an approval request or a question', () => {
        const detail = { name: 'approval-detail', params: { id: '12' } }
        expect(open('workflow.approval.requested', { approval_id: 12, title: 'Check' })).toEqual(detail)
        expect(open('workflow.question.asked', { approval_id: 12 })).toEqual(detail)
        // An overdue reminder opens the approval that is late.
        expect(open('workflow.approval.overdue', { approval_id: 12 })).toEqual(detail)
    })

    it('opens the approval for a notice that names one, else the workflows page', () => {
        expect(open('workflow.notice', { approval_id: 3, workflow_instance_id: 9 })).toEqual({ name: 'approval-detail', params: { id: '3' } })
        expect(open('workflow.notice', { workflow_instance_id: 9, title: 'Done' })).toEqual({ name: 'setting-workflows-manage' })
    })

    it('gives no target for an approval request without an id', () => {
        expect(open('workflow.approval.requested', {})).toBeNull()
        expect(open('workflow.question.asked', { approval_id: 'x' })).toBeNull()
    })
})
