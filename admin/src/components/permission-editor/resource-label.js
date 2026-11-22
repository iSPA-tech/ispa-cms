export const permissionResourceLabel = (group, t, te, fallback = group) => {
    const key = `text.permission.resource.${group}`
    return te?.(key) ? t(key) : fallback
}
