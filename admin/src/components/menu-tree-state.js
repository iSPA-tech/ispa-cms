export const cloneMenu = (value) =>
    value === undefined ? undefined : JSON.parse(JSON.stringify(value))
export const menuNodeKey = (node) => node._key ?? node.id ?? node.key

export function flattenMenu(items, parentId = null, depth = 0, trail = []) {
    return items.flatMap((node) => [
        { node, parentId, depth, path: [...trail, node.name].join(' / ') },
        ...flattenMenu(node.items || [], menuNodeKey(node), depth + 1, [...trail, node.name]),
    ])
}

export function locateMenu(items, key) {
    for (let index = 0; index < items.length; index++) {
        if (menuNodeKey(items[index]) === key) return { node: items[index], siblings: items, index }
        const found = locateMenu(items[index].items || [], key)
        if (found) return found
    }
    return null
}

export function moveMenuItem(items, key, targetKey, position = 'inside') {
    const source = locateMenu(items, key)
    const target = targetKey === null ? null : locateMenu(items, targetKey)
    if (
        !source ||
        (targetKey !== null && !target) ||
        !['inside', 'before', 'after'].includes(position)
    )
        return false
    if (target && flattenMenu([source.node]).some(({ node }) => menuNodeKey(node) === targetKey))
        return false
    source.siblings.splice(source.index, 1)
    if (!target) items.push(source.node)
    else if (position === 'inside') target.node.items.push(source.node)
    else
        target.siblings.splice(
            target.siblings.indexOf(target.node) + (position === 'after' ? 1 : 0),
            0,
            source.node
        )
    return true
}
