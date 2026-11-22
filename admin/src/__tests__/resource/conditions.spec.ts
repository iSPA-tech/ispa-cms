import { describe, expect, it } from 'vitest'
import cases from '../../../../cms/Core/tests/Fixtures/resource-conditions.json'
import { evaluateCondition, validateCondition } from '@/utils/resource/conditions'

describe('resource conditions (shared fixture)', () => {
    it.each(cases as any[])('$name', ({ condition, values, expected }) => {
        expect(evaluateCondition(condition, values)).toBe(expected)
    })

    it('validateCondition throws for an unknown operator', () => {
        expect(() => validateCondition({ field: 'a', op: 'contains', value: 'x' })).toThrow(Error)
    })

    it('validateCondition throws for malformed nodes', () => {
        expect(() => validateCondition({})).toThrow()
        expect(() => validateCondition({ field: 'a', op: 'eq' })).toThrow()
        expect(() => validateCondition({ field: 'a', op: 'in', value: 'x' })).toThrow()
        expect(() => validateCondition({ all: [], any: [] })).toThrow()
        expect(() => validateCondition({ all: {} })).toThrow()
        expect(() => validateCondition({ all: ['x'] })).toThrow()
        expect(() => validateCondition({ field: '', op: 'empty' })).toThrow()
        expect(() => validateCondition({ field: 'a', all: [] })).toThrow()
    })

    it('validateCondition accepts valid trees', () => {
        expect(() => validateCondition({ all: [{ field: 'a', op: 'eq', value: null }, { any: [] }] })).not.toThrow()
    })
})
