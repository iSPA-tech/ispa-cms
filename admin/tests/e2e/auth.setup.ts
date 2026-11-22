import fs from 'node:fs'
import path from 'node:path'
import { chromium } from '@playwright/test'
import dayjs from 'dayjs'
import weekOfYear from 'dayjs/plugin/weekOfYear'

const authFile = path.join(__dirname, '.auth', 'storage.json')
const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8081'
const buildVersion = process.env.VITE_BUILD_VERSION || '250430'
const domain = process.env.VITE_BASE_URL || 'cms-dev.test'

dayjs.extend(weekOfYear)

const normalizeDomainSlug = (value: string) =>
    value
        .toString()
        .trim()
        .toLowerCase()
        .replace(/(^\w+:|^)\/\//, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '') || 'app'

const resolvedBaseHost = (() => {
    try {
        return new URL(baseURL).host
    } catch (error) {
        return 'localhost:8081'
    }
})()

const resolvedBaseHostname = (() => {
    try {
        return new URL(baseURL).hostname
    } catch (error) {
        return 'localhost'
    }
})()

const cacheKeyPrefixes = Array.from(new Set([
    `iSpa-app-${normalizeDomainSlug(domain)}-`,
    `iSpa-app-${normalizeDomainSlug(resolvedBaseHost)}-`,
    `iSpa-app-${normalizeDomainSlug(resolvedBaseHostname)}-`,
    `iSpa-app-${normalizeDomainSlug(`${resolvedBaseHostname}:3000`)}-`,
]))

export default async function globalSetup() {
    const browser = await chromium.launch()
    const context = await browser.newContext()
    const page = await context.newPage()

    await page.route('**/api/**', async (route) => {
        const url = route.request().url()

        if (url.includes('/api/v2/public/verify')) {
            await route.fulfill({
                status: 200,
                body: JSON.stringify({
                    status: 'success',
                    data: {
                        token: 'test-domain-token',
                    },
                }),
                contentType: 'application/json',
            })
            return
        }

        if (url.includes('/auth/sign-in')) {
            await route.fulfill({
                status: 200,
                body: JSON.stringify({
                    status: 'success',
                    data: {
                        token: 'test-token',
                        locale: 'en',
                        languages: [],
                    },
                    message: 'signed-in',
                }),
                contentType: 'application/json',
            })
            return
        }

        if (url.match(/i18n\/adm/)) {
            await route.fulfill({ status: 200, body: '{}' })
            return
        }

        await route.fulfill({ status: 200, body: JSON.stringify({}) })
    })

    await context.addCookies([
        ...cacheKeyPrefixes.flatMap((prefix) => ([
            { name: `${prefix}token`, value: 'test-token', url: baseURL },
            { name: `${prefix}locale-${buildVersion}`, value: 'en', url: baseURL },
            {
                name: `${prefix}domain-token-${dayjs().week()}`,
                value: JSON.stringify({
                    status: 'success',
                    data: {
                        token: 'test-domain-token',
                    },
                }),
                url: baseURL,
            },
        ])),
    ])

    await page.goto(`${baseURL}/iPanel/`)
    await page.evaluate(
        ([prefixes, version]) => {
            const account = {
                id: 1,
                uuid: 'playwright-admin-uuid',
                name: 'Playwright Admin',
                roles: [
                    {
                        id: 1,
                        permissions: [
                            { group: 'gameadmin.authority', name: 'view' },
                            { group: 'gameadmin.authority', name: 'review' },
                            { group: 'gameadmin.authority', name: 'reconcile' },
                            { group: 'gameadmin.authority', name: 'export' },
                            { group: 'gameadmin.authority', name: 'trace' },
                        ],
                    },
                ],
            }

            for (const prefix of prefixes) {
                localStorage.setItem(`${prefix}account-data-${version}`, JSON.stringify(account))
                localStorage.setItem(`${prefix}languages-data-${version}`, JSON.stringify([]))
                localStorage.setItem(`${prefix}global-settings-${version}`, JSON.stringify({}))
            }
        },
        [cacheKeyPrefixes, buildVersion]
    )

    fs.mkdirSync(path.dirname(authFile), { recursive: true })
    await context.storageState({ path: authFile })
    await browser.close()

    fs.writeFileSync(authFile, JSON.stringify(JSON.parse(fs.readFileSync(authFile, 'utf-8')), null, 2))
}
