import { parse, parseExpressionAt } from 'acorn'

// Parse failures carry an i18n key and its params instead of display text, so this module stays free
// of the i18n instance: the screen that shows the failure translates `error.key` with `error.params`
// (`error.message` is the key, which only reaches the screen if nobody translated it).
export class ChartImportError extends Error {
    constructor(key, params = {}) {
        super(key)
        this.name = 'ChartImportError'
        this.key = key
        this.params = params
    }
}

// The field a value belongs to; it selects the matching `text.chart-import.error.<kind>_<field>` key.
const FIELDS = {
    numbers: 'numbers',
    labels: 'labels',
    chartType: 'chart_type',
    series: 'series',
    seriesName: 'series_name',
    axis: 'axis',
}

const errorKey = (kind, fieldName) => `text.chart-import.error.${kind}_${fieldName}`

const COLORS = {
    '#D97706': 'orange',
    '#F59E0B': 'orange',
    '#2563EB': 'blue',
    '#10B981': 'green',
    '#059669': 'teal',
    '#6366F1': 'indigo',
}

const PALETTE = {
    orange: [217, 119, 6],
    blue: [37, 99, 235],
    green: [16, 185, 129],
    teal: [5, 150, 105],
    indigo: [99, 102, 241],
}

// Parses #rgb, #rrggbb, rgb() and rgba(); anything else (named colours, url(), gradients) is ignored.
function rgbaOf(text) {
    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(text.trim())
    if (hex) {
        const digits = hex[1].length === 3 ? [...hex[1]].map((digit) => digit + digit) : hex[1].match(/../g)
        return [...digits.map((pair) => parseInt(pair, 16)), 1]
    }
    const rgb = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/i.exec(text.trim())
    return rgb ? [Number(rgb[1]), Number(rgb[2]), Number(rgb[3]), rgb[4] === undefined ? 1 : Number(rgb[4])] : null
}

// Maps a CSS colour to the closest stored palette name; low-saturation colours become gray / gray_light.
function paletteColor(text) {
    const exact = COLORS[text.toUpperCase()]
    if (exact) return exact
    const rgba = rgbaOf(text)
    if (!rgba) return 'blue'
    const [r, g, b] = rgba
    if (Math.max(r, g, b) - Math.min(r, g, b) < 24) return 0.299 * r + 0.587 * g + 0.114 * b < 205 ? 'gray' : 'gray_light'
    let best = 'blue'
    let bestDistance = Infinity
    for (const [name, [pr, pg, pb]] of Object.entries(PALETTE)) {
        const distance = (r - pr) ** 2 + (g - pg) ** 2 + (b - pb) ** 2
        if (distance < bestDistance) [best, bestDistance] = [name, distance]
    }
    return best
}

// A dataset colour: one string, or a per-bar array whose visible (non-transparent) entries give the
// colour and whose alphas reveal an emphasised bar (the only fully opaque one among fainter bars).
function datasetColor(node) {
    if (node?.type === 'Literal' && typeof node.value === 'string') return { color: paletteColor(node.value), emphasis: null }
    if (node?.type !== 'ArrayExpression') return { color: 'blue', emphasis: null }
    const entries = node.elements
        .map((item, index) => (item?.type === 'Literal' && typeof item.value === 'string' ? { index, text: item.value, rgba: rgbaOf(item.value) } : null))
        .filter((entry) => entry && (!entry.rgba || entry.rgba[3] > 0))
    if (!entries.length) return { color: 'blue', emphasis: null }
    const opaque = entries.filter((entry) => !entry.rgba || entry.rgba[3] >= 1)
    // Otherwise the one bar coloured differently from all the others (e.g. three greys and one blue).
    const distinct = new Map()
    for (const entry of entries) distinct.set(entry.text.toLowerCase(), (distinct.get(entry.text.toLowerCase()) || 0) + 1)
    const odd = distinct.size === 2 ? entries.find((entry) => distinct.get(entry.text.toLowerCase()) === 1) : null
    const regular = odd ? entries.find((entry) => entry !== odd) : entries[0]
    return {
        color: paletteColor(regular.text),
        emphasis: opaque.length === 1 && entries.length > 1 ? opaque[0].index : (odd && entries.length > 2 ? odd.index : null),
    }
}

// Chart.js titles may be a string or an array of lines; over-long text is dropped rather than rejected.
function plainTitle(node, limit) {
    const lines = node?.type === 'ArrayExpression' ? node.elements : [node]
    if (!node || lines.some((item) => item?.type !== 'Literal' || typeof item.value !== 'string')) return ''
    const text = lines.map((item) => item.value.trim()).filter(Boolean).join(' ')
    return text.length <= limit ? text : ''
}

function field(object, key) {
    if (object?.type !== 'ObjectExpression')
        throw new ChartImportError('text.chart-import.error.config_not_static')
    let result
    for (const property of object.properties) {
        if (property.type !== 'Property' || property.computed || property.kind !== 'init') {
            throw new ChartImportError('text.chart-import.error.config_dynamic')
        }
        const name = property.key.type === 'Identifier' ? property.key.name : property.key.value
        if (name === key) {
            if (result) {
                throw new ChartImportError('text.chart-import.error.duplicate_property', {
                    name: key,
                })
            }
            result = property.value
        }
    }
    return result
}

function literal(node, fieldName, kind, limit) {
    if (node?.type !== 'Literal' || typeof node.value !== kind) {
        throw new ChartImportError(errorKey('not_static', fieldName))
    }
    if (kind === 'string' && (node.value.length > limit || !node.value.trim())) {
        throw new ChartImportError(errorKey('bad_length', fieldName))
    }
    return node.value
}

function number(node) {
    const value =
        node?.type === 'UnaryExpression' && node.operator === '-'
            ? -literal(node.argument, FIELDS.numbers, 'number')
            : literal(node, FIELDS.numbers, 'number')
    if (!Number.isFinite(value) || value < 0 || value > 1_000_000_000) {
        throw new ChartImportError('text.chart-import.error.number_range')
    }
    return value
}

function items(node, fieldName, min, max) {
    if (
        node?.type !== 'ArrayExpression' ||
        node.elements.length < min ||
        node.elements.length > max ||
        node.elements.some((item) => !item || item.type === 'SpreadElement')
    ) {
        throw new ChartImportError(errorKey('item_count', fieldName), { min, max })
    }
    return node.elements
}

function labelsOf(node) {
    if (
        node?.type === 'CallExpression' &&
        node.callee.type === 'MemberExpression' &&
        !node.callee.computed &&
        node.callee.property.name === 'map' &&
        node.arguments.length === 1 &&
        node.arguments[0].type === 'Identifier' &&
        node.arguments[0].name === 'wrapLabel'
    ) {
        node = node.callee.object
    }
    return items(node, FIELDS.labels, 2, 20).map((item) =>
        literal(item, FIELDS.labels, 'string', 80)
    )
}

function optionalString(node, fieldName, limit) {
    return node ? literal(node, fieldName, 'string', limit) : ''
}

function chartOf(config) {
    const dataField = field(config, 'data')
    const bareData = !dataField && field(config, 'labels')
    const rawType = bareData
        ? 'line'
        : literal(field(config, 'type'), FIELDS.chartType, 'string', 20)
    const options = field(config, 'options')
    const indexAxis = options && field(options, 'indexAxis')
    const scales = options && field(options, 'scales')
    const x = scales && field(scales, 'x')
    const y = scales && field(scales, 'y')
    const stacked = [x, y].some((axis) => axis && field(axis, 'stacked')?.value === true)
    let chartType
    if (rawType === 'line') chartType = 'line'
    else if (rawType === 'bar' && indexAxis?.value === 'y' && !stacked) chartType = 'horizontal_bar'
    else if (rawType === 'bar' && indexAxis?.value === 'y' && stacked) chartType = 'grouped_bar'
    else if (rawType === 'bar' && !indexAxis && !stacked) chartType = 'column'
    else if (rawType === 'pie' || rawType === 'doughnut') chartType = 'pie'
    else if (rawType === 'radar') chartType = 'radar'
    else if (rawType === 'bar' && !indexAxis && stacked) chartType = 'stacked_bar'
    else throw new ChartImportError('text.chart-import.error.unsupported_type')

    const data = bareData ? config : dataField
    let labels = labelsOf(field(data, 'labels'))
    const grouped = chartType === 'grouped_bar'
    const column = chartType === 'column'
    let emphasis = null
    // A pie is one dataset of slices; a radar needs at least three axes.
    const series = items(field(data, 'datasets'), FIELDS.series, 1, chartType === 'pie' ? 1 : 6).map((dataset) => {
        const points = field(dataset, 'data')
        if (points?.type === 'ArrayExpression' && points.elements.length !== labels.length) {
            throw new ChartImportError('text.chart-import.error.count_mismatch')
        }
        // Grouped bars leave a segment empty with null where the dataset does not apply to that row.
        const values = items(points, FIELDS.numbers, labels.length, labels.length).map((point) =>
            grouped && point.type === 'Literal' && point.value === null ? null : number(point)
        )
        const { color, emphasis: datasetEmphasis } = datasetColor(field(dataset, 'borderColor') || field(dataset, 'backgroundColor'))
        if ((grouped || column) && emphasis === null && datasetEmphasis !== null && values[datasetEmphasis] !== null) emphasis = datasetEmphasis
        // Mixed charts: a dataset typed "line" inside a column chart is drawn as a line over the bars.
        const datasetType = field(dataset, 'type')
        const asLine = column && datasetType?.type === 'Literal' && datasetType.value === 'line'
        const dashNode = field(dataset, 'borderDash')
        const dash =
            dashNode?.type === 'ArrayExpression' &&
            dashNode.elements.length > 0 &&
            dashNode.elements.every(
                (item) => item?.type === 'Literal' && typeof item.value === 'number'
            )
                ? dashNode.elements[0].value
                : null
        const fillNode = field(dataset, 'fill')
        return {
            name: literal(field(dataset, 'label'), FIELDS.seriesName, 'string', 80),
            color,
            line_style: dash === null ? 'solid' : dash <= 3 ? 'dotted' : 'dashed',
            fill: fillNode?.type === 'Literal' && fillNode.value === true,
            as_line: asLine,
            values,
        }
    })

    if (chartType === 'radar' && labels.length < 3) throw new ChartImportError(errorKey('item_count', FIELDS.labels), { min: 3, max: 20 })
    const axis = chartType === 'horizontal_bar' || grouped ? x : y
    const axisTitle = axis && field(axis, 'title')
    const axisLabel = axisTitle && field(axisTitle, 'text')
    const resultOptions = { layout: 'wide' }
    for (const [source, target] of [
        ['min', 'axis_min'],
        ['max', 'axis_max'],
    ]) {
        const bound = axis && field(axis, source)
        if (bound) resultOptions[target] = number(bound)
    }
    if (
        resultOptions.axis_min !== undefined &&
        resultOptions.axis_max !== undefined &&
        resultOptions.axis_min >= resultOptions.axis_max
    ) {
        throw new ChartImportError('text.chart-import.error.axis_bounds')
    }
    let rowGroups
    if (grouped) {
        // "Group – row" labels (en/em dash) become a group heading plus a short row label.
        const split = labels.map((label) => /^(.+?)\s+[–—]\s+(.+)$/.exec(label))
        if (split.every(Boolean)) {
            rowGroups = split.map((match) => match[1].trim())
            labels = split.map((match) => match[2].trim())
        } else rowGroups = labels.map(() => '')
    }
    if (grouped || column) {
        if (emphasis !== null) resultOptions.highlight_row = emphasis
        const filled = series.flatMap((item) => item.values).filter((value) => value !== null)
        const smallest = filled.length ? Math.min(...filled) : 0
        if (smallest >= 10_000_000) resultOptions.value_scale = 1_000_000
        else if (smallest >= 10_000) resultOptions.value_scale = 1000
    }
    if (rawType === 'doughnut') resultOptions.donut = true
    const plugins = options && field(options, 'plugins')
    const titleNode = plugins && field(plugins, 'title')
    const subtitleNode = plugins && field(plugins, 'subtitle')
    return {
        value: {
            version: 1,
            chart_type: chartType,
            title: plainTitle(titleNode && field(titleNode, 'text'), 180),
            subtitle: plainTitle(subtitleNode && field(subtitleNode, 'text'), 300),
            unit: '',
            axis_label: optionalString(axisLabel, FIELDS.axis, 80),
            labels,
            ...(rowGroups ? { row_groups: rowGroups } : {}),
            series,
            notes: [],
            source: '',
        },
        options: resultOptions,
    }
}

export function parseChartJsSnippet(source) {
    if (typeof source !== 'string' || source.length > 100_000)
        throw new ChartImportError('text.chart-import.error.source_too_large')
    let tree
    let expression
    try {
        if (source.trimStart().startsWith('{')) {
            const start = source.search(/\S/)
            expression = parseExpressionAt(source, start, { ecmaVersion: 'latest' })
            if (source.slice(expression.end).trim()) throw new Error('extra input')
        } else {
            tree = parse(source, { ecmaVersion: 'latest', sourceType: 'script' })
        }
    } catch {
        throw new ChartImportError('text.chart-import.error.syntax')
    }
    if (expression) return [chartOf(expression)]
    const charts = []
    const visit = (node) => {
        if (!node || typeof node !== 'object') return
        if (
            node.type === 'NewExpression' &&
            node.callee?.type === 'Identifier' &&
            node.callee.name === 'Chart'
        ) {
            if (charts.length >= 20) throw new ChartImportError('text.chart-import.error.too_many')
            charts.push(chartOf(node.arguments[1]))
            return
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(visit)
            else if (value && typeof value === 'object') visit(value)
        }
    }
    visit(tree)
    if (!charts.length) throw new ChartImportError('text.chart-import.error.no_chart')
    return charts
}
