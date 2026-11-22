/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

const isNumericValue = (value) => {
    if (typeof value === 'number') {
        return Number.isFinite(value)
    }

    if (typeof value === 'string') {
        const trimmed = value.trim()
        if (!trimmed) {
            return false
        }
        return Number.isFinite(Number(trimmed))
    }

    return false
}

const toNumberIfNumeric = (value) => {
    if (typeof value === 'number') {
        return value
    }

    if (typeof value === 'string') {
        const trimmed = value.trim()
        if (!trimmed) {
            return value
        }
        const parsed = Number(trimmed)
        return Number.isFinite(parsed) ? parsed : value
    }

    return value
}

export const isEmptyFilterValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return true
    }

    if (Array.isArray(value)) {
        return value.length === 0
    }

    return false
}

const normalizePrimitiveValue = (value, defaultValue) => {
    if (value === null || value === undefined || value === '') {
        if (defaultValue === 'all') {
            return 'all'
        }
        return null
    }

    if (value === 'all') {
        return 'all'
    }

    const numericDefault = isNumericValue(defaultValue)
    const numericValue = isNumericValue(value)

    if (numericDefault && numericValue) {
        return toNumberIfNumeric(value)
    }

    return value
}

export const normalizeFilterValue = (value, defaultValue) => {
    if (Array.isArray(value)) {
        return value
            .map((item) => normalizePrimitiveValue(item, defaultValue))
            .filter((item) => item !== null && item !== undefined && item !== '')
    }

    return normalizePrimitiveValue(value, defaultValue)
}

export const areFilterValuesEqual = (currentValue, defaultValue) => {
    const normalizedCurrent = normalizeFilterValue(currentValue, defaultValue)
    const normalizedDefault = normalizeFilterValue(defaultValue, currentValue)

    if (Array.isArray(normalizedCurrent) || Array.isArray(normalizedDefault)) {
        const currentArray = Array.isArray(normalizedCurrent) ? normalizedCurrent : []
        const defaultArray = Array.isArray(normalizedDefault) ? normalizedDefault : []

        if (currentArray.length !== defaultArray.length) {
            return false
        }

        return currentArray.every((value, index) => value === defaultArray[index])
    }

    return normalizedCurrent === normalizedDefault
}

export const buildSchemaDefaults = (schema = []) => {
    return (schema || []).reduce((acc, field) => {
        if (!field || !field.key) {
            return acc
        }

        if (Object.prototype.hasOwnProperty.call(field, 'defaultValue')) {
            acc[field.key] = field.defaultValue
        } else if (Object.prototype.hasOwnProperty.call(field, 'default')) {
            acc[field.key] = field.default
        } else if (Object.prototype.hasOwnProperty.call(field, 'value')) {
            acc[field.key] = field.value
        } else {
            acc[field.key] = field.type === 'cascader' ? [] : undefined
        }

        return acc
    }, {})
}

export const defaultParseQuery = (query = {}, { allowedKeys = [], arrayKeys = [] } = {}) => {
    const parsed = {}
    const allowedSet = allowedKeys.length ? new Set(allowedKeys) : null
    const arraySet = new Set(arrayKeys)

    Object.entries(query || {}).forEach(([key, rawValue]) => {
        if (allowedSet && !allowedSet.has(key)) {
            return
        }

        if (arraySet.has(key)) {
            if (Array.isArray(rawValue)) {
                parsed[key] = rawValue.filter((item) => item !== 'all' && item !== '')
                return
            }

            if (typeof rawValue === 'string') {
                parsed[key] = rawValue
                    .split(',')
                    .map((item) => item.trim())
                    .filter((item) => item && item !== 'all')
                return
            }

            parsed[key] = []
            return
        }

        parsed[key] = rawValue
    })

    return parsed
}

export const defaultSerializeQuery = (form = {}, { allowedKeys = [], arrayKeys = [] } = {}) => {
    const serialized = {}
    const allowedSet = allowedKeys.length ? new Set(allowedKeys) : null
    const arraySet = new Set(arrayKeys)

    Object.entries(form || {}).forEach(([key, value]) => {
        if (allowedSet && !allowedSet.has(key)) {
            return
        }

        if (arraySet.has(key)) {
            if (Array.isArray(value) && value.length) {
                serialized[key] = value.join(',')
            }
            return
        }

        if (isEmptyFilterValue(value)) {
            return
        }

        serialized[key] = value
    })

    return serialized
}

export const normalizeFilterValues = (form = {}, defaults = {}) => {
    const normalized = {}

    Object.entries(form || {}).forEach(([key, value]) => {
        if (isEmptyFilterValue(value)) {
            return
        }

        const normalizedValue = normalizeFilterValue(value, defaults[key])

        if (!isEmptyFilterValue(normalizedValue)) {
            normalized[key] = normalizedValue
        }
    })

    return normalized
}
