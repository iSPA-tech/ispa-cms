import { describe, it, expect } from 'vitest'

import {
    getSettingValue,
    removeLeftTrailingSlash,
    translateNameHandle,
    strReplace,
    ucfirst,
    getLastItem,
    getNavText,
    getContentStatus,
    getRouteType,
    contentStatusColor,
    orderStatusColor,
    classNames,
    makeSlug,
    allowedQueryByType,
    initFilter,
    objectAssignOnlyByKeyHasValue,
    getDefaultLanguage,
    badgeColor,
    placeTypeBadgeColor,
    postTypeBadgeColor,
    numberFormat,
} from '@/mixins/utils'

describe('utils:basic string and array helpers', () => {
    it('translateNameHandle maps paths', () => {
        expect(translateNameHandle('attributes.name')).toBe('a.name')
        expect(translateNameHandle('between.max')).toBe('bw.max')
        expect(translateNameHandle('other.path')).toBe('other.path')
    })

    it('strReplace replaces placeholders', () => {
        const s = ':attribute must be between :min and :max, got :value.'
        expect(strReplace(s)).toBe('{a} must be between {min} and {max}, got {v}.')
    })

    it('ucfirst uppercases first letter safely', () => {
        expect(ucfirst('hello')).toBe('Hello')
        expect(ucfirst('')).toBe('')
    })

    it('getLastItem returns last element or value verbatim', () => {
        expect(getLastItem([1, 2, 3])).toBe(3)
        expect(getLastItem('plain')).toBe('plain')
    })
})

describe('utils:settings and routing', () => {
    it('getSettingValue handles numbers and defaults', () => {
        const settings = [
            { setting_key: 'site.title', type: 'text', value: 'Demo' },
            { setting_key: 'site.port', type: 'number', value: '8080' },
        ]
        expect(getSettingValue(settings, 'site.title', 'N/A')).toBe('Demo')
        expect(getSettingValue(settings, 'site.port', 0)).toBe(8080)
        expect(getSettingValue(settings, 'site.none', 'N/A')).toBe('N/A')
        expect(getSettingValue('not-array', 'site.none', 'N/A')).toBe('N/A')
    })

    it('removeLeftTrailingSlash strips leading slashes only', () => {
        expect(removeLeftTrailingSlash('/path')).toBe('path')
        expect(removeLeftTrailingSlash('///deep//')).toBe('deep//')
        expect(removeLeftTrailingSlash('no-slash')).toBe('no-slash')
    })

    it('getNavText finds text by route and supports last item', () => {
        const nav = [
            { name: 'dashboard', text: ['home'] },
            { children: [ { name: 'users', text: ['User', 'List'] } ] },
        ]
        expect(getNavText(nav, 'dashboard')).toEqual(['home'])
        expect(getNavText(nav, 'users', true)).toBe('List')
    })

    it('getContentStatus uses CONTENT_STATUSES lookup', async () => {
        const cfg = await import('@/config')
        cfg.CONTENT_STATUSES.content_published = 'Published'
        const val = getContentStatus('content_published')
        expect(val).toBe('Published')
        expect(getContentStatus('unknown')).toBe('')
    })

    it('getRouteType returns type-specific data', () => {
        expect(getRouteType('place-content', 'type')).toBe('Place')
        expect(getRouteType('post-comment', 'ids')).toBe('postId')
        expect(getRouteType('place-items-content', 'columnLabels')).toEqual(['nav.a.items'])
        expect(getRouteType('unknown', 'type')).toBe('')
    })
})

describe('utils:status colors and classes', () => {
    it('badgeColor maps permission-related keys', () => {
        expect(badgeColor('viewAny')).toBe('success')
        expect(badgeColor('update')).toBe('primary')
        expect(badgeColor('delete')).toBe('danger')
        expect(badgeColor('restore')).toBe('info')
        expect(badgeColor('email')).toBe('warning')
        expect(badgeColor('unknown')).toBe('default')
    })

    it('placeTypeBadgeColor maps place types', () => {
        expect(placeTypeBadgeColor('store')).toBe('success')
        expect(placeTypeBadgeColor('warehouse')).toBe('primary')
        expect(placeTypeBadgeColor('museum')).toBe('danger')
        expect(placeTypeBadgeColor('office')).toBe('info')
        expect(placeTypeBadgeColor('service-center')).toBe('warning')
        expect(placeTypeBadgeColor('x')).toBe('default')
    })

    it('postTypeBadgeColor maps post types', () => {
        expect(postTypeBadgeColor('video')).toBe('success')
        expect(postTypeBadgeColor('document')).toBe('primary')
        expect(postTypeBadgeColor('page')).toBe('red')
        expect(postTypeBadgeColor('admission')).toBe('info')
        expect(postTypeBadgeColor('notification')).toBe('warning')
        expect(postTypeBadgeColor('x')).toBe('default')
    })
    it('contentStatusColor maps known statuses', () => {
        expect(contentStatusColor('content_published')).toBe('green')
        expect(contentStatusColor('content_moderated')).toBe('purple')
        expect(contentStatusColor('unknown')).toBe('default')
    })

    it('orderStatusColor maps order statuses', () => {
        expect(orderStatusColor('order_awaiting_payment')).toBe('pink')
        expect(orderStatusColor('order_paid')).toBe('green')
        expect(orderStatusColor('unknown')).toBe('default')
    })

    it('classNames composes truthy class names', () => {
        expect(classNames('a', false && 'b', null, 'c')).toBe('a c')
    })

    it('makeSlug normalizes strings', () => {
        expect(makeSlug('Hello World!')).toBe('hello-world')
        expect(makeSlug('  Many  Spaces  ')).toBe('--many--spaces--')
        expect(makeSlug('')).toBe('')
    })

    it('numberFormat formats with provided pattern', () => {
        expect(numberFormat(12345, '0,0')).toBe('12,345')
    })
})

describe('utils:filters and query helpers', () => {
    it('allowedQueryByType keeps only allowed keys by type', () => {
        const q = { page: 1, pageSize: 20, keywords: 'x', categoryId: [1], extra: 'x' }
        // numeric values are treated as empty by lodash.isEmpty and get removed
        expect(allowedQueryByType({ ...q }, 'post')).toEqual({ keywords: 'x', categoryId: [1] })
    })

    it('allowedQueryByType for product preserves allowed non-empty fields', () => {
        const q = { page: 2, pageSize: 50, keywords: 'k', categoryId: [1], brandId: [2], status: 'active', extra: 0 }
        expect(allowedQueryByType({ ...q }, 'product')).toEqual({ keywords: 'k', categoryId: [1], brandId: [2], status: 'active' })
    })

    it('initFilter merges defaults and parses ids', () => {
        const defaults = { status: 'active', page: 1, pageSize: 10, dateRange: [new Date(), new Date()] }
        const q1 = { categoryId: '1,2,all', userId: '5' }
        const res = initFilter(defaults, q1)
        expect(res.categoryId).toEqual([1, 2])
        expect(res.userId).toBe(5)
        expect(res.status).toBe('active')
        expect(res.page).toBe(1)
    })

    it('initFilter parses placeId/storageId and keeps 0 values', () => {
        const defaults = { status: 'active', page: 1, pageSize: 10 }
        const q = { placeId: '10', storageId: '0' }
        const res = initFilter(defaults, q)
        expect(res.placeId).toBe(10)
        expect(res.storageId).toBe(0)
    })
})

describe('utils:objectAssignOnlyByKeyHasValue and language helpers', () => {
    it('objectAssignOnlyByKeyHasValue deep-merges only provided keys', () => {
        const form = { a: 1, b: { x: 1, y: 2 }, c: 3 }
        const data = { b: { y: 9 }, c: null }
        const merged = objectAssignOnlyByKeyHasValue(form, data)
        expect(merged).toEqual({ a: 1, b: { x: 1, y: 9 }, c: null })
    })

    it('getDefaultLanguage picks default web language if present', () => {
        const data = { data: [
            { id: 1, for_web: false, default_web: false },
            { id: 2, for_web: true, default_web: true },
        ]}
        expect(getDefaultLanguage(data)).toBe(2)
        const data2 = { data: [ { id: 7 }, { id: 8 } ] }
        expect(getDefaultLanguage(data2)).toBe(7)
    })
})
