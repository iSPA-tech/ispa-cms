import { i18n } from '@/config/i18n'

const categoryLabelKeys = {
    service: 'text.setting.blueprint.category_service',
    job: 'text.setting.blueprint.category_job',
    command: 'text.setting.blueprint.category_command',
    event: 'nav.a.events',
}

const allowedJobMethods = new Set([
    'handle',
    'dispatch',
    'dispatchSync',
    'dispatchAfterResponse',
    'onQueue',
    'onConnection',
    'delay',
])

const allowedEventMethods = new Set(['dispatch', 'broadcast'])
const allowedCommandMethods = new Set(['handle'])

const hasRequestParam = (fn) =>
    (fn?.parameters || []).some((p) => (p?.type || '') === 'Illuminate\\Http\\Request')

/**
 * Normalize blueprint catalog to a compact, UI-friendly structure.
 */
export function normalizeBlueprints(raw = [], opts = {}) {
    const showHidden = !!opts.showHidden
    const byCategory = {}

    const ensureGroup = (category, clazz, title) => {
        if (!byCategory[category]) byCategory[category] = {}
        if (!byCategory[category][clazz]) {
            byCategory[category][clazz] = {
                groupKey: clazz,
                title,
                subtitle: clazz,
                items: [],
            }
        }
        return byCategory[category][clazz]
    }

    const allowCategory = (cat) => ['service', 'job', 'command', 'event'].includes(cat || '')

    raw.forEach((entry) => {
        const category = entry?.category || 'unknown'
        if (!allowCategory(category)) return

        const cls = entry?.class || entry?.classname || ''
        const short = entry?.short_name || entry?.display_name || cls?.split('\\').pop() || cls
        const functions = entry?.functions || entry?.function_list || []

        functions.forEach((fn) => {
            if (!fn?.name) return
            let keep = true
            if (category === 'job' && !allowedJobMethods.has(fn.name)) keep = false
            if (category === 'command' && !allowedCommandMethods.has(fn.name)) keep = false
            if (category === 'event' && !allowedEventMethods.has(fn.name)) keep = false
            if (!keep) return

            const hidden = hasRequestParam(fn) || fn?.ui?.hidden
            const disallowed = hasRequestParam(fn)
            if (hidden && !showHidden) return

            const group = ensureGroup(category, cls, short)
            group.items.push({
                key: `${cls}::${fn.name}`,
                label: fn.name,
                meta: { params: fn.parameters || [] },
                hidden,
                reason: hidden ? i18n.global.t('text.setting.blueprint.request_not_supported') : undefined,
                disallowed_reason: disallowed
                    ? i18n.global.t('text.setting.blueprint.requires_request', {
                          type: 'Illuminate\\Http\\Request',
                      })
                    : undefined,
            })
        })
    })

    return Object.entries(byCategory).map(([category, groupsMap]) => ({
        category,
        groupLabel: categoryLabelKeys[category] ? i18n.global.t(categoryLabelKeys[category]) : category,
        groups: Object.values(groupsMap).filter((g) => g.items.length),
    }))
}
