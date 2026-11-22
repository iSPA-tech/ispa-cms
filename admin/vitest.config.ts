import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import fs from 'node:fs'
import { adminI18nFallbackPlugin } from './src/config/admin-i18n-fallback.js'
import * as path from 'node:path'

const affiliateRecommendationAdminPath = path.resolve(
    __dirname,
    '../ispa-cms/cms/AffiliateRecommendation/admin'
)
const journalAdminPath = path.resolve(
    __dirname,
    '../ispa-cms/cms/Journal/admin'
)
const saleHuntingAdminPath = path.resolve(
    __dirname,
    '../ispa-cms/cms/SaleHunting/admin'
)
const workspaceChartStylesPath = path.resolve(__dirname, '../ispa-cms/resources/css/data-chart.css')
const sharedChartStylesPath = fs.existsSync(workspaceChartStylesPath)
    ? workspaceChartStylesPath
    : path.resolve(__dirname, '../resources/css/data-chart.css')
// Core admin registry (cms/Core/admin): workspace checkout first, release layout (`admin/` beside `cms/`) otherwise,
// so static imports of Core admin files build in both.
const workspaceCoreAdminPath = path.resolve(__dirname, '../ispa-cms/cms/Core/admin')
const coreAdminPath = fs.existsSync(workspaceCoreAdminPath)
    ? workspaceCoreAdminPath
    : path.resolve(__dirname, '../cms/Core/admin')
const nodeModulePath = (name: string) => path.resolve(__dirname, 'node_modules', name)

export default defineConfig({
    plugins: [adminI18nFallbackPlugin(coreAdminPath), vue()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
            '@shared-data-chart-css': sharedChartStylesPath,
            '@core-admin': coreAdminPath,
            '@affiliate-recommendation-admin': affiliateRecommendationAdminPath,
            '@journal-admin': journalAdminPath,
            '@sale-hunting-admin': saleHuntingAdminPath,
            '@ant-design/icons-vue': nodeModulePath('@ant-design/icons-vue'),
            // Widget site layout (cms/Widget/admin) imports these packages from outside base-admin.
            '@vueuse/core': nodeModulePath('@vueuse/core'),
            axios: nodeModulePath('axios'),
            'lodash-es': nodeModulePath('lodash-es'),
            vuedraggable: nodeModulePath('vuedraggable'),
            '@tanstack/vue-query': nodeModulePath('@tanstack/vue-query'),
            // Core workflows builder (cms/Core/admin) imports the graph editor from outside base-admin.
            '@vue-flow/core': nodeModulePath('@vue-flow/core'),
            'ant-design-vue': nodeModulePath('ant-design-vue'),
            dayjs: nodeModulePath('dayjs'),
            // Widget library design editor (cms/Core/admin) captures design pictures with it.
            'html-to-image': nodeModulePath('html-to-image'),
            // Content post screens (cms/Content/admin) import these packages from outside base-admin.
            numeral: nodeModulePath('numeral'),
            clsx: nodeModulePath('clsx'),
            icons: path.resolve(__dirname, 'node_modules/vue-material-design-icons'),
            vue: nodeModulePath('vue'),
            'vue-i18n': nodeModulePath('vue-i18n'),
            'vue-router': nodeModulePath('vue-router'),
        },
        extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json', '.vue'],
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./tests/setup/vitest.setup.ts', './src/__tests__/setup.ts', './src/mocks/setup.ts'],
        css: true,
        exclude: ['tests/e2e/**'],
        include: [
            'tests/**/*.{test,spec}.{js,ts,jsx,tsx}',
            'src/__tests__/**/*.{test,spec}.{js,ts,jsx,tsx}',
        ],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html', 'lcov'],
        },
    },
})
