import { cloneMenu, flattenMenu, menuNodeKey } from '@/components/menu-tree-state'

const itemFields = [
    'id',
    'menu_id',
    'name',
    'title',
    'url',
    'type',
    'target_type',
    'target_id',
    'parent_id',
    'priority',
    'custom',
    'status',
]
let nextKey = 0
const key = () => `new-${++nextKey}`

export function prepareMenuItems(items = []) {
    return items.map((source) => {
        const node = cloneMenu(source)
        node._key = Number(node.id) > 0 ? `item-${node.id}` : key()
        node._originalCustom = cloneMenu(node.custom)
        node.custom =
            node.custom && typeof node.custom === 'object' && !Array.isArray(node.custom)
                ? node.custom
                : {}
        node._initialCustom = JSON.stringify(node.custom)
        node.items = prepareMenuItems(node.items || [])
        return node
    })
}

export function newMenuItem() {
    return {
        _key: key(),
        id: 0,
        menu_id: 0,
        name: '',
        title: '',
        url: '',
        type: 'url',
        status: 'status_published',
        target_type: '',
        target_id: 0,
        parent_id: 0,
        priority: 0,
        custom: {
            isExpandChild: false,
            expandChildLevel: 2,
            icon: null,
            color: null,
            action: '_self',
            slugs: {},
            categoryId: [],
            prefix: '',
        },
        items: [],
    }
}

export function serializeMenuItems(items) {
    return items.map((node) => ({
        ...Object.fromEntries(
            itemFields
                .filter((field) => Object.hasOwn(node, field))
                .map((field) => [
                    field,
                    cloneMenu(
                        field === 'custom' &&
                            node._initialCustom === JSON.stringify(node.custom) &&
                            Object.hasOwn(node, '_originalCustom')
                            ? node._originalCustom
                            : node[field]
                    ),
                ])
        ),
        items: serializeMenuItems(node.items || []),
    }))
}

export function duplicateMenuBranch(node) {
    const copy = cloneMenu(node)
    const reset = (item) => {
        item.id = 0
        item.menu_id = 0
        item.parent_id = 0
        item._key = key()
        item.items.forEach(reset)
    }
    reset(copy)
    return copy
}

export function removedMenuIds(node) {
    return flattenMenu([node])
        .reverse()
        .map(({ node }) => Number(node.id))
        .filter((id) => id > 0)
}

export function menuPrefix(node, locale = 'vi') {
    const prefix = node.custom?.prefix
    return typeof prefix === 'string'
        ? prefix
        : prefix?.[locale] || prefix?.[locale.split('-')[0]] || ''
}

export function menuUrl(node, source = null, locale = 'vi') {
    const target = source || node.target
    const prefix = menuPrefix(node, locale).replace(/^\/+|\/+$/g, '')
    const slug = target?.slug || node.custom?.slugs?.slug || node.url || ''
    if (node.type !== 'url' && target?.route && !prefix) return target.route
    if (node.type === 'url' && /^(https?:)?\/\//i.test(node.url || '')) return node.url
    return prefix
        ? `/${prefix}/${String(slug).replace(/^\/+/, '')}`
        : node.url || target?.route || ''
}

export function validateMenuItems(items) {
    const issue = menuItemIssues(items)[0]
    return issue
        ? { key: issue.key, reason: issue.fields[0] === 'type' ? 'link' : issue.fields[0] }
        : null
}

export function menuItemIssues(items, position = [], names = []) {
    return items.flatMap((node, index) => {
        const nextPosition = [...position, index + 1]
        const nextNames = [...names, node.name || '']
        const fields = []
        if (!String(node.name || '').trim()) fields.push('name')
        if (!node.type) fields.push('type')
        if (!String(node.url || '').trim()) fields.push('link')
        if (node.type === 'category' && node.custom?.isExpandChild) {
            const level = Object.hasOwn(node.custom, 'expandChildLevel')
                ? node.custom.expandChildLevel
                : 2
            if (!Number.isInteger(level) || level < 0) fields.push('level')
        }
        return [
            ...(fields.length
                ? [{ key: menuNodeKey(node), position: nextPosition, names: nextNames, fields }]
                : []),
            ...menuItemIssues(node.items || [], nextPosition, nextNames),
        ]
    })
}
