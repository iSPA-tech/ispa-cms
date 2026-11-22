import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import { AntDesignVueResolver } from 'unplugin-vue-components/resolvers'
import fs from 'fs'
import { adminI18nFallbackPlugin } from './src/config/admin-i18n-fallback.js'
import * as path from 'path'

const isProduction = process.env.NODE_ENV === 'production'
const versionFilePath = path.resolve(__dirname, 'VERSION')
const versionFromFile = fs.existsSync(versionFilePath)
    ? fs.readFileSync(versionFilePath, 'utf8').trim()
    : ''

if (versionFromFile) {
    process.env.VITE_VERSION = versionFromFile
}

const affiliateRecommendationAdminPath = path.resolve(
    __dirname,
    '../ispa-cms/cms/AffiliateRecommendation/admin'
)
const journalAdminPath = path.resolve(__dirname, '../ispa-cms/cms/Journal/admin')
const saleHuntingAdminPath = path.resolve(__dirname, '../ispa-cms/cms/SaleHunting/admin')
const mailHubAdminPath = path.resolve(__dirname, '../ispa-cms/cms/MailHub/admin')
const webmailAdminPath = path.resolve(__dirname, '../ispa-cms/cms/Webmail/admin')
const workspaceModulesPath = fs.existsSync(path.resolve(__dirname, '../ispa-cms/cms'))
    ? path.resolve(__dirname, '../ispa-cms/cms')
    : path.resolve(__dirname, '../cms')
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
const nodeModulePath = (name) => path.resolve(__dirname, 'node_modules', name)
// Packages the admin shell provides to module admin code outside base-admin. One list (src/config/shared-packages.json)
// feeds these aliases, the module package builder (cms/Core PackageBuilder) and the installer. It lives under src/ so
// the project deploy sync, which copies src/ but not other root files, ships it with this config.
const sharedPackages = JSON.parse(
    fs.readFileSync(path.resolve(__dirname, 'src/config/shared-packages.json'), 'utf8')
)
const sharedPackageAliases = Object.fromEntries(
    Object.keys(sharedPackages.packages).map((name) => [name, nodeModulePath(name)])
)
// Temml keeps lone UTF-16 surrogate escapes (`\uD800-\uDBFF`) inside its tokenizer regex strings.
// Vite 8's bundler re-emits those escapes as U+FFFD, which corrupts the tokenizer so every LaTeX
// command fails with "Unsupported function name". Double-escaping only the surrogate ranges hands
// the escape to the RegExp engine instead of the string literal: same pattern, plain ASCII source.
const temmlSurrogateRangePattern = /\\u(D[89A-F][0-9A-F]{2})-\\u(D[89A-F][0-9A-F]{2})/gi
const temmlSurrogateEscapeFix = {
    name: 'temml-surrogate-escape-fix',
    transform(code, id) {
        if (!/[\\/]temml[\\/]dist[\\/]temml\.mjs$/.test(id.split('?')[0])) {
            return null
        }

        return { code: code.replace(temmlSurrogateRangePattern, '\\\\u$1-\\\\u$2'), map: null }
    },
}

// index.html loads /iPanel/config.js (written by PHP in a real install). `vite dev` has no such file and would answer
// with index.html (a script syntax error in the console): answer with an empty script so the VITE_* values apply.
const devEmptyRuntimeConfig = {
    name: 'dev-empty-runtime-config',
    apply: 'serve',
    configureServer(server) {
        server.middlewares.use((req, res, next) => {
            if ((req.url || '').split('?')[0].endsWith('/iPanel/config.js')) {
                res.setHeader('Content-Type', 'application/javascript')
                res.end('')
                return
            }
            next()
        })
    },
}

const adminBuildOutputDir =
    process.env.ADMIN_BUILD_OUTPUT_DIR || (isProduction ? '../public/iPanel' : 'dist')

export default defineConfig({
    base: isProduction ? `/iPanel/` : './',
    server: {
        fs: {
            allow: [
                path.resolve(__dirname),
                affiliateRecommendationAdminPath,
                journalAdminPath,
                saleHuntingAdminPath,
                mailHubAdminPath,
                webmailAdminPath,
                // Every module admin folder (cms/<Module>/admin) of the workspace checkout or the release layout
                // (discovered, not listed).
                workspaceModulesPath,
                path.dirname(sharedChartStylesPath),
            ],
        },
        allowedHosts: ['admin.local'],
    },
    optimizeDeps: {
        rolldownOptions: {
            plugins: [temmlSurrogateEscapeFix],
        },
    },
    plugins: [
        temmlSurrogateEscapeFix,
        devEmptyRuntimeConfig,
        adminI18nFallbackPlugin(coreAdminPath),
        vue({ include: [/\.vue$/] }),
        Components({
            resolvers: [AntDesignVueResolver({ importStyle: false })],
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve('./src/'),
            '@shared-data-chart-css': sharedChartStylesPath,
            '@core-admin': coreAdminPath,
            '@affiliate-recommendation-admin': affiliateRecommendationAdminPath,
            '@journal-admin': journalAdminPath,
            '@sale-hunting-admin': saleHuntingAdminPath,
            ...sharedPackageAliases,
            // Legacy alias kept as it was; vue-material-design-icons is not installed and no admin code imports it.
            icons: path.resolve(__dirname, 'node_modules/vue-material-design-icons'),
        },
        extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json', '.vue'],
    },
    build: {
        chunkSizeWarningLimit: 4000,
        rolldownOptions: {
            onwarn(warning, defaultHandler) {
                if (
                    warning.code === 'INVALID_ANNOTATION' &&
                    warning.loc?.file?.includes('@vueuse/core')
                ) {
                    return
                }

                defaultHandler(warning)
            },
        },
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (id.includes('ant-design-vue')) return 'vendor-antdv'
                        if (id.includes('ispa-element')) return 'vendor-ispa'
                        if (id.includes('@tiptap')) return 'vendor-tiptap'
                        if (id.includes('apexcharts') || id.includes('vue3-apexcharts'))
                            return 'vendor-charts'
                        if (id.includes('@vue-flow')) return 'vendor-vueflow'
                        if (id.includes('vue-material-design-icons') || id.includes('@mdi'))
                            return 'vendor-icons'
                    }
                },
            },
            external: ['ant-design-vue/es/config-provider/style/css'],
        },
        // Vite 8 (rolldown) honours build.outDir, not rolldownOptions.output.dir: the deploy hook sets
        // ADMIN_BUILD_OUTPUT_DIR and reads the build from there (build-admin.sh), so the output dir must be set here.
        outDir: path.resolve(__dirname, adminBuildOutputDir),
        emptyOutDir: true,
        cssCodeSplit: true,
    },
})
