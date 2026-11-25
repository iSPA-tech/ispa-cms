/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Turns the schedule presets of the workflows page into a cron expression and back.
 *
 * Presets: off, hourly, daily at HH:MM, weekly on a day at HH:MM, custom cron.
 * Days follow cron: 0 = Sunday ... 6 = Saturday.
 */
export const SCHEDULE_MODES = ['off', 'hourly', 'daily', 'weekly', 'custom']

export const DEFAULT_SCHEDULE_TIME = '08:00'

const pad = (value) => String(value).padStart(2, '0')

const parseTime = (time) => {
    const match = /^(\d{1,2}):(\d{2})$/.exec(String(time || '').trim())
    if (!match) return null
    const hour = Number(match[1])
    const minute = Number(match[2])
    if (hour > 23 || minute > 59) return null
    return { hour, minute }
}

/**
 * The cron expression for a preset; null means the schedule is off.
 * Returns undefined when the form is incomplete (no time, empty custom cron).
 */
export const cronFromSchedule = ({ mode, time, day, custom } = {}) => {
    if (mode === 'off') return null
    if (mode === 'hourly') return '0 * * * *'
    if (mode === 'custom') {
        const expression = String(custom || '').trim().replace(/\s+/g, ' ')
        return expression === '' ? undefined : expression
    }

    const parsed = parseTime(time)
    if (!parsed) return undefined
    if (mode === 'daily') return `${parsed.minute} ${parsed.hour} * * *`
    if (mode === 'weekly') {
        const weekday = Number(day)
        if (!Number.isInteger(weekday) || weekday < 0 || weekday > 6) return undefined
        return `${parsed.minute} ${parsed.hour} * * ${weekday}`
    }

    return undefined
}

/** The preset form values that match a stored cron expression; anything else is shown as custom. */
export const scheduleFromCron = (cron) => {
    const base = { mode: 'off', time: DEFAULT_SCHEDULE_TIME, day: 1, custom: '' }
    const expression = String(cron || '').trim().replace(/\s+/g, ' ')
    if (expression === '') return base
    if (expression === '0 * * * *') return { ...base, mode: 'hourly' }

    const match = /^(\d{1,2}) (\d{1,2}) \* \* (\*|[0-7])$/.exec(expression)
    if (match) {
        const minute = Number(match[1])
        const hour = Number(match[2])
        if (minute <= 59 && hour <= 23) {
            const time = `${pad(hour)}:${pad(minute)}`
            if (match[3] === '*') return { ...base, mode: 'daily', time }
            return { ...base, mode: 'weekly', time, day: Number(match[3]) % 7 }
        }
    }

    return { ...base, mode: 'custom', custom: expression }
}

/** Runs that are still going: the page keeps refreshing while one is listed. */
export const isRunInProgress = (run) => ['pending', 'running', 'cancelling'].includes(run?.status)

/** Splits a duration in milliseconds into whole minutes and seconds; null when the run has not finished. */
export const splitDuration = (durationMs) => {
    if (durationMs === null || durationMs === undefined || Number.isNaN(Number(durationMs))) return null
    const totalSeconds = Math.max(0, Math.round(Number(durationMs) / 1000))
    return { minutes: Math.floor(totalSeconds / 60), seconds: totalSeconds % 60 }
}
