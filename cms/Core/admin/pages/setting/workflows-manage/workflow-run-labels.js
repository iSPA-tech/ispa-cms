/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import dayjs from 'dayjs'
import { useI18n } from 'vue-i18n'
import { splitDuration } from './workflow-schedule'

/** Labels, colours and formats shared by the recent-runs table and the step log. */
const STATUS_COLORS = {
    pending: 'orange',
    awaiting_approval: 'orange',
    running: 'cyan',
    completed: 'green',
    failed: 'red',
    apply_failed: 'red',
    approval_stale: 'red',
}

const KNOWN_STATUSES = ['pending', 'running', 'awaiting_approval', 'completed', 'failed', 'cancelling', 'cancelled', 'skipped', 'paused']
const KNOWN_SOURCES = ['manual', 'cli', 'schedule', 'agent_schedule']

/** a-tag colour of a run or step status; unknown codes stay neutral. */
export const runStatusColor = (status) => STATUS_COLORS[status] || 'default'

export const formatRunDate = (value) => (value ? dayjs(value).format('DD/MM/YYYY HH:mm') : '—')

export const formatRunDuration = (durationMs, t) => {
    const parts = splitDuration(durationMs)
    if (!parts) return '—'
    return parts.minutes > 0
        ? t('text.workflow.runs.duration_minutes', { minutes: parts.minutes, seconds: parts.seconds })
        : t('text.workflow.runs.duration_seconds', { seconds: parts.seconds })
}

export function useRunLabels() {
    const { t } = useI18n()

    /** A status the page does not know is shown as "Other", never as a raw code. */
    const runStatusLabel = (status) => t(`text.workflow.runs.status.${KNOWN_STATUSES.includes(status) ? status : 'other'}`)
    const runSourceLabel = (source) => t(`text.workflow.runs.source.${KNOWN_SOURCES.includes(source) ? source : 'other'}`)

    return { runStatusLabel, runSourceLabel }
}
