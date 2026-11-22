import { defineConfig, devices } from '@playwright/test'

// Dedicated config for the LIVE Widget Site Layout suite (plan Chunk 3 Task
// 32). Structurally mirrors playwright.config.ts (same testDir, same
// baseURL/project shape) but is intentionally ISOLATED from it:
//
// - `testMatch` scopes this run to ONLY widget-site-layout.spec.ts -- the
//   default playwright.config.ts's own globalSetup/storageState (auth.setup.ts,
//   a fully MOCKED session) must never be substituted underneath the other
//   specs in this directory (login.spec.ts, education-list.spec.ts,
//   game-authority-*.spec.ts), which all assume that mocked shape.
// - `globalSetup`/`storageState` point at this suite's OWN files
//   (widget-site-layout.auth.setup.ts, a REAL login + a real backend fixture
//   run), never the shared mocked tests/e2e/.auth/storage.json.
// - `workers: 1`, `fullyParallel: false`, `retries: 0`: every test in
//   widget-site-layout.spec.ts is deliberately stateful and ordered
//   (test.describe.configure({ mode: 'serial' })) against ONE real, mutating
//   backend Layout -- a retry would replay a step against already-mutated
//   state left behind by the failed attempt, producing confusing cascading
//   failures rather than a clean signal. Fix forward and re-run the whole
//   file (its own globalSetup resets the fixture on every invocation).
//
// Run (from base-admin/):
//   rtk pnpm exec playwright test --config playwright.widget-site-layout.config.ts
//
// Prerequisites are documented in widget-site-layout.auth.setup.ts's own
// header docblock and docs/runbooks/widget-site-layout-rollout.md (ispa-cms).

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8081'

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: 'widget-site-layout.spec.ts',
    timeout: 60 * 1000,
    expect: {
        timeout: 10000,
    },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report-widget-site-layout' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        video: 'off',
        screenshot: 'only-on-failure',
        storageState: 'tests/e2e/.auth/widget-site-layout-storage.json',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    globalSetup: 'tests/e2e/widget-site-layout.auth.setup.ts',
})
