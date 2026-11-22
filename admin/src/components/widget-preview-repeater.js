// Parent-side handling of repeater item messages from the live preview frame. Pure: every helper
// returns new values and never trusts the frame — the repeater, the item id and the field must
// all exist in the editor's own inputs and values.
import {
    addItem,
    duplicateItem,
    moveItem,
    removeItem,
    repeaterDefinition,
    restoreItem,
} from '@/components/widget-repeater-state'

export const itemsOf = (values, name) => (Array.isArray(values?.[name]) ? values[name] : [])

export function repeaterOf(inputs, name) {
    const input = inputs.find((i) => i.name === name && i.type === 'repeater')
    return input ? { input, definition: repeaterDefinition(input) } : null
}

// "NAME.ID.FIELD" → the item field it names, or null when any part is unknown.
export function resolveItemField(inputs, values, name) {
    const parts = typeof name === 'string' ? name.split('.') : []
    if (parts.length !== 3) return null
    const [repeater, id, fieldName] = parts
    const rep = repeaterOf(inputs, repeater)
    if (!rep) return null
    const index = itemsOf(values, repeater).findIndex((i) => i._id === id)
    const field = rep.definition.fields.find((f) => f.name === fieldName)
    if (index < 0 || !field) return null
    return { repeater, id, index, field, definition: rep.definition }
}

export const itemFieldValue = (values, ref) => itemsOf(values, ref.repeater)[ref.index]?.[ref.field.name]

export function setItemField(values, ref, value) {
    const items = itemsOf(values, ref.repeater).map((i) => (i._id === ref.id ? { ...i, [ref.field.name]: value } : i))
    return { ...values, [ref.repeater]: items }
}

export function replaceItem(values, repeater, id, item) {
    const items = itemsOf(values, repeater).map((i) => (i._id === id ? { ...item, _id: id } : i))
    return { ...values, [repeater]: items }
}

// Applies item-add / item-duplicate / item-remove / item-move. Returns null for anything invalid;
// a removal also returns what the undo bar needs to put the item back.
export function applyItemMessage(inputs, values, d) {
    const rep = repeaterOf(inputs, d?.repeater)
    if (!rep) return null
    const items = itemsOf(values, d.repeater)
    const known = items.some((i) => i._id === d.id)
    const put = (next) => ({ values: { ...values, [d.repeater]: next } })
    if (d.type === 'item-add') return items.length < rep.definition.max ? put(addItem(items, rep.definition)) : null
    if (!known) return null
    if (d.type === 'item-duplicate') return items.length < rep.definition.max ? put(duplicateItem(items, d.id, rep.definition)) : null
    if (d.type === 'item-move') {
        const to = Number(d.toIndex)
        return Number.isInteger(to) && to >= 0 && to < items.length ? put(moveItem(items, d.id, to)) : null
    }
    if (d.type === 'item-remove') {
        const result = removeItem(items, d.id)
        return {
            ...put(result.items),
            removed: { repeater: d.repeater, item: result.removed, index: result.index, label: rep.definition.itemLabel },
        }
    }
    return null
}

export const undoRemoval = (values, removed) => ({
    ...values,
    [removed.repeater]: restoreItem(itemsOf(values, removed.repeater), removed.item, removed.index),
})

// Frame labels for every item field ("NAME.ID.FIELD" → field label).
export function itemFieldLabels(inputs, values) {
    const out = {}
    for (const input of inputs) {
        if (input.type !== 'repeater') continue
        const { fields } = repeaterDefinition(input)
        for (const item of itemsOf(values, input.name)) {
            for (const f of fields) out[`${input.name}.${item._id}.${f.name}`] = f.label || f.name
        }
    }
    return out
}
