const copy = (value) => JSON.parse(JSON.stringify(value))

export function serializeFormDraft(form) {
    const draft = copy(form)
    draft.items = (draft.items || []).map(({ isShow, ...item }) => item)
    return draft
}

export function draftFingerprint(form) {
    const canonical = (value) => Array.isArray(value)
        ? value.map(canonical)
        : value && typeof value === 'object'
            ? Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]))
            : value
    return JSON.stringify(canonical(serializeFormDraft(form)))
}

export function outlineGroups(items, search = '') {
    const groups = []
    let group
    const query = search.trim().toLocaleLowerCase()
    items.forEach((item, index) => {
        if (!group || item.custom?.section) {
            group = { key: item.custom?.section ? item.field_name : 'ungrouped', label: item.custom?.section || null, rows: [] }
            groups.push(group)
        }
        if (!query || `${item.name || ''} ${group.label || ''}`.toLocaleLowerCase().includes(query)) {
            group.rows.push({ item, key: item.field_name, index })
        }
    })
    return groups.filter(group => group.rows.length)
}

export function fieldReferences(items, key) {
    return items.filter(item => item.field_name !== key && (
        item.custom?.visibleWhen?.field === key || Object.values(item.custom?.optionsSource?.dependsOn || {}).includes(key)
    ))
}

function invalidDependencies(items) {
    const preceding = new Map()
    const issues = []
    items.forEach((item, index) => {
        const dependencies = [
            ...(item.custom?.visibleWhen?.field ? [{ attribute: 'custom.visibleWhen.field', key: item.custom.visibleWhen.field }] : []),
            ...Object.values(item.custom?.optionsSource?.dependsOn || {}).map(key => ({ attribute: 'custom.optionsSource', key })),
        ]
        dependencies.forEach(dependency => {
            const parent = preceding.get(dependency.key)
            if (!parent || !parent.status || !['select', 'radio'].includes(parent.type)) {
                issues.push({ key: item.field_name, index, attribute: dependency.attribute, reason: 'workspace_error_dependency' })
            }
        })
        preceding.set(item.field_name, item)
    })
    return issues
}

export function moveFormField(items, key, beforeKey = null) {
    const item = items.find(item => item.field_name === key)
    if (!item || beforeKey === key) return { items, blockedBy: [] }
    const next = items.filter(item => item.field_name !== key)
    const target = beforeKey === null ? next.length : next.findIndex(item => item.field_name === beforeKey)
    if (target < 0) return { items, blockedBy: [] }
    next.splice(target, 0, item)
    const prior = new Set(invalidDependencies(items).map(issue => `${issue.key}:${issue.attribute}`))
    const blockedBy = invalidDependencies(next)
        .filter(issue => !prior.has(`${issue.key}:${issue.attribute}`))
        .map(issue => next[issue.index])
    return { items: blockedBy.length ? items : next, blockedBy: [...new Set(blockedBy)] }
}

export function duplicateFormField(items, key, newKey) {
    const index = items.findIndex(item => item.field_name === key)
    if (index < 0) return items
    const duplicate = copy(items[index])
    duplicate.id = 0
    duplicate.field_id = 0
    duplicate.field_name = newKey
    duplicate.custom = { ...duplicate.custom, section: null }
    const next = [...items]
    next.splice(index + 1, 0, duplicate)
    return next
}

export function removeFormField(items, key) {
    const blockedBy = fieldReferences(items, key)
    return { items: blockedBy.length ? items : items.filter(item => item.field_name !== key), blockedBy }
}

export function formItemIssues(items) {
    const issues = invalidDependencies(items)
    const add = (item, index, attribute, reason) => issues.push({ key: item.field_name, index, attribute, reason })
    items.forEach((item, index) => {
        if (!String(item.name || '').trim()) add(item, index, 'name', 'workspace_error_name')
        if (!item.type) add(item, index, 'type', 'workspace_error_type')
        const source = item.custom?.optionsSource
        if (source && !source.source) add(item, index, 'custom.optionsSource', 'workspace_error_source')
        if (['select', 'radio', 'checkbox'].includes(item.type) && !source) {
            if (!item.options?.length || item.options.some(option => !String(option.label || '').trim())) {
                add(item, index, 'options', 'workspace_error_choices')
            }
        }
    })
    return issues.sort((a, b) => a.index - b.index)
}
