export const normalizeCategoryTreeRows = (rows = []) => {
    if (!Array.isArray(rows)) {
        return []
    }

    return rows.map((row) => {
        const normalized = { ...row }
        const children = normalizeCategoryTreeRows(row?.children)

        if (children.length > 0) {
            normalized.children = children
        } else {
            delete normalized.children
        }

        return normalized
    })
}
