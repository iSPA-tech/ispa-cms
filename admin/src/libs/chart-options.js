/**
 * chart-options - pure, framework-free helpers that turn design-system colours into vue3-apexcharts options.
 * Palette rules: categorical series use the fixed chart slots in order; the status palette is only for
 * series that really are statuses (success / warning / danger / info).
 */

/** Default chart heights by chart type. */
const DEFAULT_HEIGHTS = { trend: 260, bars: 260, donut: 280, spark: 32 }

/**
 * Read the chart-related CSS custom properties from a computed style.
 *
 * @param {CSSStyleDeclaration} computedStyle Result of `getComputedStyle(document.documentElement)`.
 * @returns {{categorical: string[], other: string, status: {success: string, warning: string, danger: string, info: string}, surface: string, grid: string, axis: string}}
 */
export function resolveChartColors(computedStyle) {
    const read = (name) => String(computedStyle.getPropertyValue(name) || '').trim()

    return {
        categorical: [1, 2, 3, 4, 5].map((n) => read(`--color-chart-${n}`)),
        other: read('--color-chart-other'),
        status: {
            success: read('--color-success'),
            warning: read('--color-warning'),
            danger: read('--color-danger'),
            info: read('--color-info'),
        },
        surface: read('--color-surface'),
        grid: read('--color-chart-grid'),
        axis: read('--color-chart-axis'),
        ink: read('--color-ink'),
    }
}

/**
 * Build a vue3-apexcharts options object.
 *
 * @param {object} config
 * @param {'trend'|'bars'|'donut'|'spark'} config.type Chart kind.
 * @param {'categorical'|'status'} [config.palette] Colour palette, default categorical.
 * @param {ReturnType<typeof resolveChartColors>} [config.colors] Resolved colours (alternative to the loose fields).
 * @param {string[]} [config.statusKeys] Status key per series when palette is `status`.
 * @param {object} [config.statusColors] Status colour map (defaults to `colors.status`).
 * @param {string} [config.surface] Surface colour (stroke gap).
 * @param {string} [config.grid] Grid line colour.
 * @param {string} [config.axis] Axis label colour.
 * @param {string[]} [config.categories] X axis categories.
 * @param {number} [config.height] Height override.
 * @param {number} [config.seriesCount] Number of series (legend shows from 2).
 * @param {string} [config.locale] Admin locale, `vi` or `en`.
 * @param {boolean} [config.horizontal] Bars only: horizontal bars with the category names on the left.
 * @param {number} [config.firstSlot] Categorical slot (1-5) the first series takes; later series follow in order.
 * @returns {object} ApexCharts options.
 */
export function buildChartOptions({
    type = 'trend',
    palette = 'categorical',
    colors = {},
    statusKeys = [],
    statusColors,
    surface,
    grid,
    axis,
    categories,
    height,
    seriesCount = 1,
    locale = 'vi',
    firstSlot = 1,
    horizontal = false,
} = {}) {
    const status = statusColors || colors.status || {}
    const surfaceColor = surface ?? colors.surface
    const gridColor = grid ?? colors.grid
    const axisColor = axis ?? colors.axis
    // Text drawn inside the chart (legend, donut centre) follows the theme like the axis labels do.
    const inkColor = colors.ink || axisColor
    const localeTag = locale === 'vi' ? 'vi-VN' : 'en-US'
    const nf = new Intl.NumberFormat(localeTag)
    const fmt = (v) => (typeof v === 'number' && Number.isFinite(v) ? nf.format(v) : v)

    const categorical = colors.categorical || []
    const start = Math.min(Math.max(Math.trunc(firstSlot) || 1, 1), categorical.length || 1) - 1
    const seriesColors =
        palette === 'status'
            ? statusKeys.map((key) => status[key])
            : [...categorical.slice(start), ...categorical.slice(0, start)]

    const options = {
        chart: {
            type: 'area',
            height: height ?? DEFAULT_HEIGHTS[type] ?? DEFAULT_HEIGHTS.trend,
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            animations: { enabled: true, speed: 200 },
        },
        colors: seriesColors,
        grid: { borderColor: gridColor, strokeDashArray: 3 },
        xaxis: {
            labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: axisColor } },
            axisTicks: { show: false },
        },
        yaxis: { min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: axisColor }, formatter: fmt } },
        tooltip: { shared: true, intersect: false, y: { formatter: fmt } },
        legend: { show: seriesCount >= 2, position: 'top', horizontalAlign: 'left', labels: { colors: axisColor } },
        dataLabels: { enabled: false },
    }

    if (Array.isArray(categories) && categories.length) {
        options.xaxis.categories = categories
        // Long date ranges: about eight labels instead of one per day, so they do not run into each other.
        if (categories.length > 10 && !horizontal) options.xaxis.tickAmount = 8
    }

    if (type === 'trend') {
        options.chart.type = 'area'
        options.stroke = { curve: 'smooth', width: 2 }
        options.fill = { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } }
        options.markers = { size: 0, hover: { size: 5 } }
    } else if (type === 'bars') {
        options.chart.type = 'bar'
        // Horizontal bars keep long category names (people, periods) readable on the value axis side.
        options.plotOptions = horizontal
            ? { bar: { horizontal: true, barHeight: '60%', borderRadius: 2, borderRadiusApplication: 'end' } }
            : { bar: { columnWidth: '70%', borderRadius: 2, borderRadiusApplication: 'end' } }
        options.stroke = { width: 2, colors: [surfaceColor] }
        if (horizontal) {
            // ApexCharts swaps the axes for horizontal bars: `yaxis` still scales the values, `xaxis` labels them.
            // Counts: a value axis of 0,2 0,4 … reads as nonsense, so fractional ticks get no label.
            options.xaxis.labels.formatter = (v) => (Number.isFinite(Number(v)) && !Number.isInteger(Number(v)) ? '' : fmt(Number.isFinite(Number(v)) ? Number(v) : v))
            options.yaxis = { min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: axisColor }, maxWidth: 160 } }
        }
    } else if (type === 'donut') {
        options.chart.type = 'donut'
        options.legend.show = false
        options.stroke = { width: 2, colors: [surfaceColor] }
        options.plotOptions = {
            pie: {
                donut: {
                    size: '70%',
                    labels: { name: { color: axisColor }, value: { color: inkColor }, total: { color: axisColor } },
                },
            },
        }
        options.tooltip = { shared: false, intersect: true, y: { formatter: fmt } }
    } else if (type === 'spark') {
        options.chart.type = 'area'
        options.chart.sparkline = { enabled: true }
        options.stroke = { curve: 'smooth', width: 2 }
        options.tooltip = { ...options.tooltip, enabled: false }
        options.legend.show = false
    }

    return options
}
