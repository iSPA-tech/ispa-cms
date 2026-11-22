import { emptyValueForType } from '@/components/widget-editor-state.js'

const ID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'

/** 8-character alphanumeric item id (server accepts /^[A-Za-z0-9]{1,16}$/). */
export const newItemId = () => {
    let id = ''
    for (let i = 0; i < 8; i++) id += ID_CHARS[Math.floor(Math.random() * ID_CHARS.length)]
    return id
}

const positiveOr = (v, fallback) => {
    const n = Number(v)
    return v === null || v === undefined || v === '' || !(n > 0) ? fallback : n
}

// Media sub-fields need the same options a top-level image input carries, or widget-input-fields
// shows a plain text box instead of the media picker. A field's own options win.
const MEDIA_DEFAULTS = { class: null, alt: 'Image', src: 'unknown', value: null }
const withMediaDefaults = (f) =>
    f && ['image', 'upload'].includes(f.type) ? { ...f, options: { ...MEDIA_DEFAULTS, ...(f.options || {}) } } : f

const VALID_ID = /^[A-Za-z0-9]{1,16}$/

/**
 * Gives every item a valid, unique `_id`: valid unique ids are kept, missing/invalid/duplicate ones
 * get a fresh id. Returns the same array when nothing changed.
 */
export const normalizeItemIds = (items) => {
    if (!Array.isArray(items)) return items
    const seen = new Set()
    let changed = false
    const next = items.map((item) => {
        if (!item || typeof item !== 'object' || Array.isArray(item)) return item
        if (typeof item._id === 'string' && VALID_ID.test(item._id) && !seen.has(item._id)) {
            seen.add(item._id)
            return item
        }
        let id = newItemId()
        while (seen.has(id)) id = newItemId()
        seen.add(id)
        changed = true
        return { ...item, _id: id }
    })
    return changed ? next : items
}

export const repeaterDefinition = (input = {}) => {
    const o = input?.options || {}
    return {
        itemLabel: o.item_label || 'mục',
        // The server caps max at 100 (WidgetInputSanitizer); clamp here so the UI never offers more.
        max: Math.min(100, positiveOr(o.max, 12)),
        min: Math.max(0, Number(o.min) || 0),
        fields: Array.isArray(o.fields) ? o.fields.map(withMediaDefaults) : [],
        titleField: o.title_field || '',
        defaultCount: Number(o.default_count) || 0,
    }
}

export const emptyItem = (definition) => {
    const item = { _id: newItemId() }
    for (const f of definition.fields || []) item[f.name] = emptyValueForType(f.type)
    return item
}

export const addItem = (items, definition) =>
    items.length >= definition.max ? [...items] : [...items, emptyItem(definition)]

export const duplicateItem = (items, id, definition) => {
    const index = items.findIndex((i) => i._id === id)
    if (index < 0 || items.length >= definition.max) return [...items]
    const copy = { ...JSON.parse(JSON.stringify(items[index])), _id: newItemId() }
    return [...items.slice(0, index + 1), copy, ...items.slice(index + 1)]
}

export const removeItem = (items, id) => {
    const index = items.findIndex((i) => i._id === id)
    if (index < 0) return { items: [...items], removed: null, index: -1 }
    return { items: items.filter((_, i) => i !== index), removed: items[index], index }
}

export const restoreItem = (items, removed, index) => {
    if (!removed) return [...items]
    const at = Math.max(0, Math.min(index, items.length))
    return [...items.slice(0, at), removed, ...items.slice(at)]
}

export const moveItem = (items, id, toIndex) => {
    const from = items.findIndex((i) => i._id === id)
    if (from < 0) return [...items]
    const rest = items.filter((_, i) => i !== from)
    const at = Math.max(0, Math.min(toIndex, rest.length))
    return [...rest.slice(0, at), items[from], ...rest.slice(at)]
}

// `t` is accepted for future i18n use; the label text comes from the definition.
// eslint-disable-next-line no-unused-vars
export const itemTitle = (item, definition, index, t) => {
    const base = `${definition.itemLabel} ${index + 1}`
    const raw = definition.titleField ? item?.[definition.titleField] : ''
    const title = typeof raw === 'string' ? raw.trim() : ''
    return title ? `${base} — ${title}` : base
}

export const initialItems = (definition) =>
    Array.from({ length: definition.defaultCount }, () => emptyItem(definition))
