import { describe, expect, it } from 'vitest'
import {
    cronFromSchedule,
    isRunInProgress,
    scheduleFromCron,
    splitDuration,
} from '../../../../cms/Core/admin/pages/setting/workflows-manage/workflow-schedule'

describe('workflow schedule presets', () => {
    it('writes a cron for every preset', () => {
        expect(cronFromSchedule({ mode: 'off' })).toBeNull()
        expect(cronFromSchedule({ mode: 'hourly' })).toBe('0 * * * *')
        expect(cronFromSchedule({ mode: 'daily', time: '06:30' })).toBe('30 6 * * *')
        expect(cronFromSchedule({ mode: 'weekly', time: '18:05', day: 0 })).toBe('5 18 * * 0')
        expect(cronFromSchedule({ mode: 'custom', custom: '  0 8  * * 1-5 ' })).toBe('0 8 * * 1-5')
    })

    it('reports an incomplete form instead of writing a wrong cron', () => {
        expect(cronFromSchedule({ mode: 'daily', time: '' })).toBeUndefined()
        expect(cronFromSchedule({ mode: 'daily', time: '25:00' })).toBeUndefined()
        expect(cronFromSchedule({ mode: 'weekly', time: '08:00', day: 9 })).toBeUndefined()
        expect(cronFromSchedule({ mode: 'custom', custom: '   ' })).toBeUndefined()
    })

    it('reads a stored cron back into the matching preset', () => {
        expect(scheduleFromCron(null).mode).toBe('off')
        expect(scheduleFromCron('').mode).toBe('off')
        expect(scheduleFromCron('0 * * * *').mode).toBe('hourly')
        expect(scheduleFromCron('30 6 * * *')).toMatchObject({ mode: 'daily', time: '06:30' })
        expect(scheduleFromCron('5 18 * * 3')).toMatchObject({ mode: 'weekly', time: '18:05', day: 3 })
        // Cron accepts 7 for Sunday; the form keeps one value per day.
        expect(scheduleFromCron('0 9 * * 7')).toMatchObject({ mode: 'weekly', day: 0 })
    })

    it('keeps anything else as a custom cron, unchanged', () => {
        expect(scheduleFromCron('*/15 * * * *')).toMatchObject({ mode: 'custom', custom: '*/15 * * * *' })
        expect(scheduleFromCron('0 8 * * 1-5')).toMatchObject({ mode: 'custom', custom: '0 8 * * 1-5' })
        expect(scheduleFromCron('0 8 1 * *')).toMatchObject({ mode: 'custom', custom: '0 8 1 * *' })
    })

    it('round-trips every preset', () => {
        for (const cron of ['0 * * * *', '30 6 * * *', '5 18 * * 0', '*/15 * * * *']) {
            expect(cronFromSchedule(scheduleFromCron(cron))).toBe(cron)
        }
    })

    it('knows which runs are still going and splits durations', () => {
        expect(isRunInProgress({ status: 'running' })).toBe(true)
        expect(isRunInProgress({ status: 'pending' })).toBe(true)
        expect(isRunInProgress({ status: 'awaiting_approval' })).toBe(false)
        expect(isRunInProgress({ status: 'completed' })).toBe(false)
        expect(splitDuration(null)).toBeNull()
        expect(splitDuration(4200)).toEqual({ minutes: 0, seconds: 4 })
        expect(splitDuration(125000)).toEqual({ minutes: 2, seconds: 5 })
    })
})
