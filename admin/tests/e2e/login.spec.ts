import { test, expect } from '@playwright/test'

test.describe('auth smoke', () => {
    test.use({ storageState: undefined })

    test('login then logout by clearing state', async ({ page, context }) => {
        const errors: string[] = []
        page.on('console', (msg) => {
            if (msg.type() === 'error') errors.push(msg.text())
        })

        await page.route('**/api/**', (route) => {
            const url = route.request().url()
            if (url.includes('/auth/sign-in')) {
                return route.fulfill({
                    status: 200,
                    body: JSON.stringify({ status: 'success', data: { token: 'test-token' }, message: 'ok' }),
                    contentType: 'application/json',
                })
            }
            if (url.match(/i18n\/adm/)) {
                return route.fulfill({ status: 200, body: '{}' })
            }
            return route.fulfill({ status: 200, body: '{}' })
        })

        await page.goto('/auth/login')
        await page.fill('input[placeholder="v.a.username"]', 'admin')
        await page.fill('input[placeholder="v.a.password"]', 'password')
        await page.click('button:has-text("n.b.sign-in")')
        await expect(page.context().cookies()).resolves.toBeTruthy()

        await context.clearCookies()
        await page.goto('/education/subjects')
        await expect(page.locator('h2:has-text("t.auth.sign-in"), h1:has-text("t.auth.sign-in")')).toBeVisible()
        expect(errors).toEqual([])
    })
})
