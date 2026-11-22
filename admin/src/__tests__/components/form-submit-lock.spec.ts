import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { runSubmitOnce } from '@/mixins'

describe('runSubmitOnce', () => {
    it('runs one submit callback while the state is locked', async () => {
        const submitting = ref(false)
        let resolveSubmit: (value: string) => void = () => {}
        const submit = vi.fn(
            () =>
                new Promise<string>((resolve) => {
                    resolveSubmit = resolve
                })
        )

        const first = runSubmitOnce(submitting, submit)
        const second = runSubmitOnce(submitting, submit)

        expect(submit).toHaveBeenCalledTimes(1)
        expect(submitting.value).toBe(true)

        resolveSubmit('saved')

        await expect(first).resolves.toBe('saved')
        await expect(second).resolves.toBeUndefined()
        expect(submitting.value).toBe(false)
    })

    it('unlocks submit state when the callback rejects', async () => {
        const submitting = ref(false)
        const error = new Error('save failed')

        await expect(runSubmitOnce(submitting, () => Promise.reject(error))).rejects.toThrow(
            'save failed'
        )

        expect(submitting.value).toBe(false)
    })
})
