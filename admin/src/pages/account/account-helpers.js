// Callers pass their own translated empty label; without one the value renders empty rather than as raw text.
const DEFAULT_EMPTY_LABEL = ''

// Without a translator (pure unit use) the key itself is returned, never hard-coded text.
const translateKey = (t, key, params = {}) => {
    return typeof t === 'function' ? t(key, params) : key
}

export const resolveAccountAvatarUrl = (account = {}) => {
    const avatar = account?.avatar || {}

    return (
        avatar.thumbnailSm ||
        avatar.thumbnail_sm ||
        avatar.url ||
        avatar.path ||
        null
    )
}

export const getCurrentMetaMap = (account = {}) => {
    return account?.currentMeta || account?.current_meta || {}
}

export const formatAccountValue = (value, fallback = DEFAULT_EMPTY_LABEL) => {
    if (Array.isArray(value)) {
        const normalized = value
            .map((item) => formatAccountValue(item, ''))
            .filter(Boolean)

        return normalized.length > 0 ? normalized.join(', ') : fallback
    }

    if (value && typeof value === 'object') {
        return (
            value.name ||
            value.title ||
            value.label ||
            value.value ||
            value.path ||
            fallback
        )
    }

    if (value === null || value === undefined || value === '') {
        return fallback
    }

    return String(value)
}

export const getSettingValue = (settings = {}, group, key, fallback = null) => {
    return settings?.[group]?.[key]?.value ?? fallback
}

export const getSettingOptionLabel = (settings = {}, group, key, fallback = DEFAULT_EMPTY_LABEL) => {
    const value = getSettingValue(settings, group, key, null)
    const options = settings?.[group]?.[key]?.options || []

    const option = options.find((item) => {
        if (typeof item === 'string') {
            return item === value
        }

        return item?.path === value || item?.value === value
    })

    if (!option) {
        return value || fallback
    }

    if (typeof option === 'string') {
        return option
    }

    return option.name || option.label || option.path || option.value || fallback
}

export const buildAccountChecklist = (account = {}, settings = {}, t = null) => {
    return [
        {
            key: 'avatar',
            label: translateKey(t, 'text.acc.checklist.avatar.label'),
            description: translateKey(t, 'text.acc.checklist.avatar.desc'),
            done: Boolean(resolveAccountAvatarUrl(account)),
            routeName: 'account-update',
            actionLabel: translateKey(t, 'text.acc.action.update-profile'),
        },
        {
            key: 'mobile',
            label: translateKey(t, 'text.acc.checklist.mobile.label'),
            description: translateKey(t, 'text.acc.checklist.mobile.desc'),
            done: Boolean(account?.mobile),
            routeName: 'account-update',
            actionLabel: translateKey(t, 'text.acc.action.add-number'),
        },
        {
            key: 'email-verification',
            label: translateKey(t, 'text.acc.checklist.email-verification.label'),
            description: translateKey(t, 'text.acc.checklist.email-verification.desc'),
            done: Boolean(account?.emailVerifiedAt),
            routeName: 'account-update',
            actionLabel: translateKey(t, 'text.acc.action.review-email'),
        },
        {
            key: 'password-history',
            label: translateKey(t, 'text.acc.checklist.password-history.label'),
            description: translateKey(t, 'text.acc.checklist.password-history.desc'),
            done: Boolean(account?.lastChangePassword),
            routeName: 'account-password',
            actionLabel: translateKey(t, 'text.acc.action.change-password'),
        },
        {
            key: 'two-factor',
            label: translateKey(t, 'text.acc.checklist.two-factor.label'),
            description: translateKey(t, 'text.acc.checklist.two-factor.desc'),
            done: Boolean(getSettingValue(settings, 'security', 'two_fa', false)),
            routeName: 'account-setting',
            actionLabel: translateKey(t, 'text.acc.action.open-preferences'),
        },
        {
            key: 'password-alert',
            label: translateKey(t, 'text.acc.checklist.password-alert.label'),
            description: translateKey(t, 'text.acc.checklist.password-alert.desc'),
            done: Boolean(getSettingValue(settings, 'security', 'email_change_password', false)),
            routeName: 'account-setting',
            actionLabel: translateKey(t, 'text.acc.action.open-preferences'),
        },
    ]
}

export const countCompletedItems = (items = []) => {
    return items.filter((item) => item?.done === true || item?.met === true).length
}

export const buildPasswordRequirementItems = ({
    currentPassword = '',
    password = '',
    passwordConfirmation = '',
} = {}, t = null) => {
    return [
        {
            key: 'length',
            label: translateKey(t, 'text.acc.requirement.length'),
            met: password.length >= 8,
        },
        {
            key: 'lowercase',
            label: translateKey(t, 'text.acc.requirement.lowercase'),
            met: /[a-z]/.test(password),
        },
        {
            key: 'uppercase',
            label: translateKey(t, 'text.acc.requirement.uppercase'),
            met: /[A-Z]/.test(password),
        },
        {
            key: 'number',
            label: translateKey(t, 'text.acc.requirement.number'),
            met: /[0-9]/.test(password),
        },
        {
            key: 'symbol',
            label: translateKey(t, 'text.acc.requirement.symbol'),
            met: /[!@#$%^&*()_-]/.test(password),
        },
        {
            key: 'confirmation',
            label: translateKey(t, 'text.acc.requirement.confirmation'),
            met: password.length > 0 && password === passwordConfirmation,
        },
        {
            key: 'different',
            label: translateKey(t, 'text.acc.requirement.different'),
            met: password.length > 0 && password !== currentPassword,
        },
    ]
}

export const resolvePasswordStrength = (items = [], t = null) => {
    const total = items.length || 1
    const completed = countCompletedItems(items)
    const percent = Math.round((completed / total) * 100)

    if (percent >= 100) {
        return {
            label: translateKey(t, 'text.acc.strength.strong'),
            color: 'var(--color-success)',
            percent,
        }
    }

    if (percent >= 71) {
        return {
            label: translateKey(t, 'text.acc.strength.good'),
            color: 'var(--color-info)',
            percent,
        }
    }

    if (percent >= 43) {
        return {
            label: translateKey(t, 'text.acc.strength.fair'),
            color: 'var(--color-warning)',
            percent,
        }
    }

    return {
        label: translateKey(t, 'text.acc.strength.weak'),
        color: 'var(--color-danger)',
        percent,
    }
}
