const HtmlWebpackPlugin = require('html-webpack-plugin')

const publicPath =
    process.env.NODE_ENV === 'production'
        ? '/iPanel/' + process.env.VERSION
        : process.env.NODE_ENV === 'dev'
        ? '/iPanel/' + process.env.VERSION
        : '/'

module.exports = {
    publicPath: publicPath,
    outputDir: '../build/iPanel/' + process.env.VERSION,
    // productionSourceMap: true,
    productionSourceMap: process.env.NODE_ENV !== 'production',
    lintOnSave: false,
    configureWebpack: {
        devtool: 'source-map',
        output: {
            libraryExport: 'default',
        },
        devServer: {
            watchOptions: {
                poll: true,
            },
            proxy: 'http://localhost:8080/',
            // proxy: 'https://localhost:8080/',
            // https: true,
        },
        plugins: [
            new HtmlWebpackPlugin({
                inject: true,
                template: './src/index.tpl',
                filename: './index.html',
                favicon: './src/assets/logo-square.png',
            }),
        ],
        optimization: {
            concatenateModules: true,
            providedExports: true,
            usedExports: true,
            runtimeChunk: 'multiple',
            splitChunks: {
                chunks: 'all',
                maxInitialRequests: Infinity,
                enforceSizeThreshold: 500000,
                minSize: 200000,
                maxSize: 500000,
                minChunks: 1,
                maxAsyncRequests: 30,
                automaticNameDelimiter: '~',
                automaticNameMaxLength: 30,
                name: true,
                cacheGroups: {
                    vendors: {
                        test: /[\\/]node_modules[\\/]/,
                        priority: -10,
                        reuseExistingChunk: true,
                    },
                    default: {
                        minChunks: 2,
                        priority: -20,
                        reuseExistingChunk: true,
                    },
                },
            },
        },
    },
    css: {
        extract: true,
    },
    chainWebpack: (config) => {
        // config.plugins.delete('prefetch')

        config.resolve.extensions
            .clear()
            .add('.vue')
            .add('.tsx')
            .add('.ts')
            .add('.mjs')
            .add('.js')
            .add('.jsx')
            .add('.json')
            .add('.wasm')
    },
}
