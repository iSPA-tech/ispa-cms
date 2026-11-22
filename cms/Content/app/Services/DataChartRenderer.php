<?php

declare(strict_types=1);

namespace Cms\Content\Services;

/** Server-side chart HTML. Data stays escaped and readable without JavaScript. */
final class DataChartRenderer
{
    private const COLORS = [
        'orange' => '#d97706',
        'blue' => '#2563eb',
        'green' => '#16a36a',
        'teal' => '#0f766e',
        'indigo' => '#4f46e5',
        'gray' => '#a8a29e',
        'gray_light' => '#d6d3d1',
    ];

    /** Display divisors for grouped bar value labels, with the word placed before the axis label. */
    private const SCALES = [1 => '', 1000 => 'nghìn', 1_000_000 => 'triệu', 1_000_000_000 => 'tỷ'];

    /** @param array<string, mixed> $block */
    public static function render(array $block): string
    {
        $chart = $block['value'] ?? null;
        if (!is_array($chart) || ($chart['version'] ?? null) !== 1) {
            return '';
        }

        $type = $chart['chart_type'] ?? null;
        $labels = $chart['labels'] ?? null;
        $series = $chart['series'] ?? null;
        if (!in_array($type, ['line', 'horizontal_bar', 'stacked_bar', 'grouped_bar', 'column', 'pie', 'radar'], true)
            || !is_string($chart['title'] ?? null)
            || !is_array($labels) || !array_is_list($labels) || count($labels) < 2 || count($labels) > 20
            || !is_array($series) || !array_is_list($series) || count($series) < 1 || count($series) > 6
            || ($type === 'pie' && count($series) !== 1)
            || ($type === 'radar' && count($labels) < 3)) {
            return '';
        }

        $labels = array_map(static fn (mixed $label): ?string => is_string($label) && trim($label) !== '' ? trim($label) : null, $labels);
        if (in_array(null, $labels, true)) {
            return '';
        }

        $normalized = self::series($series, count($labels), $type === 'grouped_bar');
        if ($normalized === null) {
            return '';
        }

        $notes = self::notes($chart['notes'] ?? []);
        if ($notes === null) {
            return '';
        }

        $options = $block['options'] ?? [];
        if (!is_array($options) || !in_array($options['layout'] ?? 'wide', ['wide', 'split'], true)
            || !self::axisOption($options, 'axis_min') || !self::axisOption($options, 'axis_max')) {
            return '';
        }
        $axisMin = array_key_exists('axis_min', $options) ? (float) $options['axis_min'] : null;
        $axisMax = array_key_exists('axis_max', $options) ? (float) $options['axis_max'] : null;
        if ($axisMin !== null && $axisMax !== null && $axisMin >= $axisMax) {
            return '';
        }
        $groups = self::groups($chart['row_groups'] ?? null, count($labels));
        $highlight = $options['highlight_row'] ?? null;
        $scale = $options['value_scale'] ?? 1;
        $referenceLabel = $options['reference_label'] ?? '';
        $donut = $options['donut'] ?? false;
        if (!is_bool($donut) || $groups === null || !is_int($scale) || !isset(self::SCALES[$scale]) || !is_string($referenceLabel)
            || ($highlight !== null && (!is_int($highlight) || $highlight < 0 || $highlight >= count($labels)))) {
            return '';
        }
        $layout = $notes === [] ? 'wide' : ($options['layout'] ?? 'wide');
        $title = trim($chart['title']);
        $subtitle = is_string($chart['subtitle'] ?? null) ? trim($chart['subtitle']) : '';
        $unit = is_string($chart['unit'] ?? null) ? trim($chart['unit']) : '';
        $axis = is_string($chart['axis_label'] ?? null) ? trim($chart['axis_label']) : '';
        $source = is_string($chart['source'] ?? null) ? trim($chart['source']) : '';
        $svg = match ($type) {
            'line' => self::line($labels, $normalized, $title, $axis, $unit, $axisMin, $axisMax),
            'horizontal_bar' => self::horizontalBars($labels, $normalized, $title, $axis, $unit, $axisMin, $axisMax),
            'grouped_bar' => self::groupedBars($labels, $groups, $normalized, $axis, $unit, $axisMin, $axisMax, $highlight, trim($referenceLabel), $scale),
            'column' => self::columns($labels, $normalized, $title, $axis, $unit, $axisMin, $axisMax, $highlight, $scale),
            'pie' => self::pie($labels, $normalized, $title, $unit, $donut, $scale),
            'radar' => self::radar($labels, $normalized, $title, $axisMax),
            default => self::stackedBars($labels, $normalized, $title, $axis, $unit, $axisMin, $axisMax),
        };

        $html = '<section class="gx-data-chart not-prose gx-data-chart--' . str_replace('_', '-', $type) . ' gx-data-chart--' . $layout . '" aria-label="' . self::escape($title) . '">';
        $html .= '<div class="gx-data-chart__panel"><figure class="gx-data-chart__figure"><figcaption class="gx-data-chart__header" data-chart-outline-slot><span class="gx-data-chart__title">' . self::escape($title) . '</span>';
        if ($subtitle !== '') {
            $html .= '<p>' . self::escape($subtitle) . '</p>';
        }
        $html .= '</figcaption><div class="gx-data-chart__plot">' . $svg . '</div>' . self::legend(match ($type) {
                'grouped_bar' => self::segmentSeries($normalized),
                'pie' => self::sliceLegend($labels, $normalized[0], $scale),
                'column' => array_values(array_filter($normalized, static fn (array $item): bool => !$item['as_line'])),
                default => $normalized,
            }) . '</figure>'
            . self::table($title, $type === 'grouped_bar' ? self::groupedLabels($labels, $groups) : $labels, $normalized, $unit);
        if ($source !== '') {
            $html .= '<p class="gx-data-chart__source">Nguồn: ' . self::escape($source) . '</p>';
        }
        $html .= '</div>' . self::notesHtml($notes) . '</section>';

        return $html;
    }

    /**
     * Grouped bars allow a null segment where a series does not apply; every row still needs one number.
     *
     * @param list<mixed> $items
     * @return list<array{name:string,color:string,style:string,fill:bool,values:list<float|null>}>|null
     */
    private static function series(array $items, int $labelCount, bool $allowEmpty = false): ?array
    {
        $result = [];
        $filled = array_fill(0, $labelCount, false);
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || trim($item['name']) === ''
                || !isset(self::COLORS[$item['color'] ?? ''])
                || !in_array($item['line_style'] ?? 'solid', ['solid', 'dashed', 'dotted'], true)
                || (array_key_exists('fill', $item) && !is_bool($item['fill']))
                || (array_key_exists('as_line', $item) && !is_bool($item['as_line']))
                || !is_array($item['values'] ?? null) || !array_is_list($item['values']) || count($item['values']) !== $labelCount) {
                return null;
            }
            $values = [];
            foreach ($item['values'] as $index => $value) {
                if ($allowEmpty && $value === null) {
                    $values[] = null;
                    continue;
                }
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                    || $value < 0 || $value > 1_000_000_000) {
                    return null;
                }
                $values[] = (float) $value;
                $filled[$index] = true;
            }
            $result[] = ['name' => trim($item['name']), 'color' => self::COLORS[$item['color']], 'style' => $item['line_style'] ?? 'solid', 'fill' => $item['fill'] ?? false, 'as_line' => $item['as_line'] ?? false, 'values' => $values];
        }

        return in_array(false, $filled, true) ? null : $result;
    }

    /** @return list<string>|null One trimmed group name per row ('' = no group heading). */
    private static function groups(mixed $groups, int $labelCount): ?array
    {
        if ($groups === null) {
            return array_fill(0, $labelCount, '');
        }
        if (!is_array($groups) || !array_is_list($groups) || count($groups) !== $labelCount) {
            return null;
        }
        $result = [];
        foreach ($groups as $group) {
            if ($group !== null && !is_string($group)) {
                return null;
            }
            $result[] = trim((string) $group);
        }

        return $result;
    }

    /** @param mixed $items @return list<array{title:string,body:string,tone:string,badge:string,body_format:string}>|null */
    private static function notes(mixed $items): ?array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > 6) {
            return null;
        }
        $result = [];
        foreach ($items as $note) {
            if (!is_array($note) || !is_string($note['title'] ?? null) || !is_string($note['body'] ?? null)
                || !is_string($note['badge'] ?? '')
                || !in_array($note['body_format'] ?? 'plain', ['plain', 'html'], true)
                || !in_array($note['tone'] ?? null, ['neutral', 'blue', 'green', 'amber'], true)) {
                return null;
            }
            $result[] = [
                'title' => trim($note['title']),
                'body' => trim($note['body']),
                'tone' => $note['tone'],
                'badge' => trim($note['badge'] ?? ''),
                'body_format' => $note['body_format'] ?? 'plain',
            ];
        }

        return $result;
    }

    /** @param list<string> $labels @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series */
    private static function line(array $labels, array $series, string $title, string $axis, string $unit, ?float $axisMin, ?float $axisMax): string
    {
        [$min, $max] = self::range($series, true, $axisMin, $axisMax);
        $grid = self::grid($min, $max, $axis, $unit);
        $count = count($labels);
        $paths = '';
        foreach ($series as $item) {
            $points = [];
            foreach ($item['values'] as $index => $value) {
                $points[] = self::number(64 + (620 * $index / ($count - 1))) . ',' . self::number(self::y($value, $min, $max));
            }
            $dash = self::dash($item['style']);
            if ($item['fill']) {
                $paths .= '<polygon fill="' . $item['color'] . '" fill-opacity=".12" points="' . implode(' ', $points) . ' 684,196 64,196"/>';
            }
            $paths .= '<polyline fill="none" stroke="' . $item['color'] . '" stroke-width="3"' . $dash . ' points="' . implode(' ', $points) . '"/>';
            foreach ($points as $index => $point) {
                [$x, $y] = explode(',', $point);
                $paths .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" fill="' . $item['color'] . '"/>';
            }
        }

        $targets = '';
        $step = 620 / ($count - 1);
        foreach ($labels as $index => $label) {
            $x = 64 + $step * $index;
            $left = $index === 0 ? 64 : $x - $step / 2;
            $right = $index === $count - 1 ? 684 : $x + $step / 2;
            $targets .= self::mark($x, $label, $series, $index, $unit,
                '<rect x="' . self::number($left) . '" y="26" width="' . self::number($right - $left) . '" height="170" fill="transparent"/>');
        }

        return self::svg($title, $grid . $paths . self::xLabels($labels) . $targets);
    }

    /** @param list<string> $labels @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series */
    private static function horizontalBars(array $labels, array $series, string $title, string $axis, string $unit, ?float $axisMin, ?float $axisMax): string
    {
        [$min, $max] = self::range($series, true, $axisMin, $axisMax);
        $grid = self::horizontalGrid($min, $max, $axis, $unit, 200, 484);
        $row = 170 / count($labels);
        $bars = '';
        $targets = '';
        foreach ($labels as $index => $label) {
            $base = 26 + $index * $row;
            $bars .= '<text x="190" y="' . self::number($base + $row / 2 + 4) . '" text-anchor="end" class="gx-data-chart__svg-label">' . self::escape($label) . '</text>';
            $height = max(1, min(20, ($row - 4) / count($series)));
            foreach ($series as $seriesIndex => $item) {
                $width = 484 * self::ratio($item['values'][$index], $min, $max);
                $y = $base + ($row - $height * count($series)) / 2 + $seriesIndex * $height;
                $bars .= '<rect x="200" y="' . self::number($y) . '" width="' . self::number($width) . '" height="' . self::number(max(1, $height - 2)) . '" rx="2" fill="' . $item['color'] . '"/>';
            }
            $targets .= self::mark(200 + 484 * .6, $label, $series, $index, $unit,
                '<rect x="200" y="' . self::number($base) . '" width="484" height="' . self::number($row) . '" fill="transparent"/>');
        }

        return self::svg($title, $grid . $bars . $targets);
    }

    /** @param list<string> $labels @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series */
    private static function stackedBars(array $labels, array $series, string $title, string $axis, string $unit, ?float $axisMin, ?float $axisMax): string
    {
        $sums = array_fill(0, count($labels), 0.0);
        foreach ($series as $item) {
            foreach ($item['values'] as $index => $value) {
                $sums[$index] += max(0, $value);
            }
        }
        $min = $axisMin ?? 0.0;
        $max = $axisMax ?? max($min + 1.0, ...$sums);
        $grid = self::grid($min, $max, $axis, $unit);
        $bars = '';
        $targets = '';
        $step = 620 / count($labels);
        foreach ($labels as $index => $label) {
            $x = 64 + $step * $index + $step * .18;
            $width = $step * .64;
            $stack = 0.0;
            foreach ($series as $item) {
                $next = $stack + max(0, $item['values'][$index]);
                $top = self::y($next, $min, $max);
                $bottom = self::y($stack, $min, $max);
                $bars .= '<rect x="' . self::number($x) . '" y="' . self::number($top) . '" width="' . self::number($width) . '" height="' . self::number($bottom - $top) . '" fill="' . $item['color'] . '"/>';
                $stack = $next;
            }
            $bars .= '<text x="' . self::number($x + $width / 2) . '" y="224" text-anchor="middle" class="gx-data-chart__svg-label">' . self::escape($label) . '</text>';
            $targets .= self::mark($x + $width / 2, $label, $series, $index, $unit,
                '<rect x="' . self::number(64 + $step * $index) . '" y="26" width="' . self::number($step) . '" height="170" fill="transparent"/>');
        }

        return self::svg($title, $grid . $bars . $targets);
    }

    /** Slice colours in order; a pie cycles through them, so neighbouring slices never share a colour. */
    private const SLICE_COLORS = ['blue', 'orange', 'green', 'teal', 'indigo', 'gray', 'gray_light'];

    /**
     * One series of slices around (200,111); a donut keeps a hole. Slices of 6% or more carry their percentage.
     *
     * @param list<string> $labels
     * @param list<array{name:string,color:string,style:string,fill:bool,as_line:bool,values:list<float>}> $series
     */
    private static function pie(array $labels, array $series, string $title, string $unit, bool $donut, int $scale): string
    {
        $values = $series[0]['values'];
        $total = array_sum($values);
        $cx = 360.0;
        $cy = 111.0;
        $r = 85.0;
        $inner = $donut ? 50.0 : 0.0;
        $html = '';
        $targets = '';
        $angle = -M_PI / 2;
        foreach ($values as $index => $value) {
            $share = $total > 0 ? $value / $total : 1 / count($values);
            $sweep = $share * 2 * M_PI;
            if ($sweep <= 0) {
                continue;
            }
            $end = $angle + ($share >= 1 ? $sweep - 0.0001 : $sweep);
            $color = self::COLORS[self::SLICE_COLORS[$index % count(self::SLICE_COLORS)]];
            $large = $sweep > M_PI ? 1 : 0;
            $path = 'M' . self::point($cx, $cy, $r, $angle) . ' A' . self::number($r) . ' ' . self::number($r) . ' 0 ' . $large . ' 1 ' . self::point($cx, $cy, $r, $end);
            $path .= $inner > 0
                ? ' L' . self::point($cx, $cy, $inner, $end) . ' A' . self::number($inner) . ' ' . self::number($inner) . ' 0 ' . $large . ' 0 ' . self::point($cx, $cy, $inner, $angle) . ' Z'
                : ' L' . self::number($cx) . ',' . self::number($cy) . ' Z';
            $html .= '<path d="' . $path . '" fill="' . $color . '" stroke="#fff" stroke-width="1.5"/>';
            if ($share >= .06) {
                $mid = ($angle + $end) / 2;
                $labelR = $inner > 0 ? ($r + $inner) / 2 : $r * .62;
                $html .= '<text x="' . self::number($cx + $labelR * cos($mid)) . '" y="' . self::number($cy + $labelR * sin($mid) + 4) . '" text-anchor="middle" class="gx-data-chart__svg-label" style="fill:#fff" font-weight="700">' . self::escape(self::format(round($share * 100))) . '%</text>';
            }
            $targets .= self::mark($cx + $r * cos(($angle + $end) / 2), $labels[$index], $series, $index, $unit, '<path d="' . $path . '" fill="transparent"/>');
            $angle = $end;
        }

        return self::svg($title, $html . $targets);
    }

    /**
     * @param list<string> $labels
     * @param array{name:string,color:string,style:string,fill:bool,as_line:bool,values:list<float>} $series
     * @return list<array{name:string,color:string,style:string}>
     */
    private static function sliceLegend(array $labels, array $series, int $scale): array
    {
        $total = array_sum($series['values']);
        $items = [];
        foreach ($labels as $index => $label) {
            $value = $series['values'][$index];
            $items[] = [
                'name' => $label . ' — ' . self::scaled($value, $scale) . ($total > 0 ? ' (' . self::format(round($value / $total * 100)) . '%)' : ''),
                'color' => self::COLORS[self::SLICE_COLORS[$index % count(self::SLICE_COLORS)]],
                'style' => 'solid',
            ];
        }

        return $items;
    }

    private static function point(float $cx, float $cy, float $r, float $angle): string
    {
        return self::number($cx + $r * cos($angle)) . ',' . self::number($cy + $r * sin($angle));
    }

    /**
     * Spider chart around (360,118): one axis per label, four grid rings, one translucent polygon per series.
     *
     * @param list<string> $labels
     * @param list<array{name:string,color:string,style:string,fill:bool,as_line:bool,values:list<float>}> $series
     */
    private static function radar(array $labels, array $series, string $title, ?float $axisMax): string
    {
        $cx = 360.0;
        $cy = 118.0;
        $r = 78.0;
        $count = count($labels);
        $max = $axisMax ?? max(1.0, ...array_merge(...array_map(static fn (array $item): array => $item['values'], $series)));
        $angleAt = static fn (int $index): float => -M_PI / 2 + 2 * M_PI * $index / $count;
        $html = '';
        for ($ring = 1; $ring <= 4; $ring++) {
            $points = [];
            foreach ($labels as $index => $_) {
                $points[] = self::point($cx, $cy, $r * $ring / 4, $angleAt($index));
            }
            $html .= '<polygon points="' . implode(' ', $points) . '" fill="none" class="gx-data-chart__grid"/>';
        }
        $targets = '';
        foreach ($labels as $index => $label) {
            $angle = $angleAt($index);
            $anchor = abs(cos($angle)) < .2 ? 'middle' : (cos($angle) > 0 ? 'start' : 'end');
            $html .= '<line x1="' . self::number($cx) . '" y1="' . self::number($cy) . '" x2="' . self::number($cx + $r * cos($angle)) . '" y2="' . self::number($cy + $r * sin($angle)) . '" class="gx-data-chart__grid"/>'
                . '<text x="' . self::number($cx + ($r + 14) * cos($angle)) . '" y="' . self::number($cy + ($r + 14) * sin($angle) + 4) . '" text-anchor="' . $anchor . '" class="gx-data-chart__svg-label">' . self::escape($label) . '</text>';
            $targets .= self::mark($cx + $r * cos($angle), $label, $series, $index, '',
                '<circle cx="' . self::number($cx + $r * cos($angle)) . '" cy="' . self::number($cy + $r * sin($angle)) . '" r="18" fill="transparent"/>');
        }
        $html .= '<text x="' . self::number($cx + 4) . '" y="' . self::number($cy - $r + 10) . '" class="gx-data-chart__svg-axis">' . self::escape(self::format($max)) . '</text>';
        foreach ($series as $item) {
            $points = [];
            foreach ($item['values'] as $index => $value) {
                $points[] = self::point($cx, $cy, $r * self::ratio($value, 0.0, $max), $angleAt($index));
            }
            $html .= '<polygon points="' . implode(' ', $points) . '" fill="' . $item['color'] . '" fill-opacity=".15" stroke="' . $item['color'] . '" stroke-width="2"' . self::dash($item['style']) . '/>';
            foreach ($points as $point) {
                [$x, $y] = explode(',', $point);
                $html .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" fill="' . $item['color'] . '"/>';
            }
        }

        return self::svg($title, $html . $targets);
    }

    /**
     * Vertical bars side by side per label with the value printed above each bar. A series flagged as_line is drawn
     * as a dashed rule over the bars with "name: value" at the left edge (a constant reference level such as a
     * competitor's cost); value labels follow value_scale. The highlighted label is bold and its first bar takes the
     * accent colour. No value ticks: the bar labels carry the numbers.
     *
     * @param list<string> $labels
     * @param list<array{name:string,color:string,style:string,fill:bool,as_line:bool,values:list<float>}> $series
     */
    private static function columns(array $labels, array $series, string $title, string $axis, string $unit, ?float $axisMin, ?float $axisMax, ?int $highlight, int $scale): string
    {
        [$min, $max] = self::range($series, true, $axisMin, $axisMax);
        $max = $min + ($max - $min) * 1.12; // headroom for the value labels
        $left = 200;
        $width = 484;
        $bars = array_values(array_filter($series, static fn (array $item): bool => !$item['as_line']));
        $lines = array_values(array_filter($series, static fn (array $item): bool => $item['as_line']));
        $count = count($labels);
        $step = $width / $count;
        $caption = trim(self::SCALES[$scale] . ' ' . trim($axis . ($axis !== '' && $unit !== '' ? ' (' . $unit . ')' : $unit)));
        $html = $caption === '' ? '' : '<text x="' . $left . '" y="18" class="gx-data-chart__svg-axis">' . self::escape($caption) . '</text>';
        $html .= '<line x1="' . $left . '" y1="196" x2="' . ($left + $width) . '" y2="196" class="gx-data-chart__grid"/>';
        $targets = '';
        foreach ($labels as $index => $label) {
            $slot = $left + $step * $index;
            $groupWidth = $step * .64;
            $barWidth = count($bars) > 0 ? $groupWidth / count($bars) : $groupWidth;
            foreach ($bars as $barIndex => $item) {
                $x = $slot + $step * .18 + $barIndex * $barWidth;
                $top = self::y($item['values'][$index], $min, $max);
                $fill = $highlight === $index && $barIndex === 0 ? self::COLORS['blue'] : $item['color'];
                $html .= '<rect x="' . self::number($x) . '" y="' . self::number($top) . '" width="' . self::number(max(1, $barWidth - 2)) . '" height="' . self::number(196 - $top) . '" rx="2" fill="' . $fill . '"/>'
                    . '<text x="' . self::number($x + ($barWidth - 2) / 2) . '" y="' . self::number($top - 4) . '" text-anchor="middle" class="gx-data-chart__svg-label"' . ($highlight === $index && $barIndex === 0 ? ' font-weight="700"' : '') . '>' . self::escape(self::scaled($item['values'][$index], $scale)) . '</text>';
            }
            $html .= '<text x="' . self::number($slot + $step / 2) . '" y="224" text-anchor="middle" class="gx-data-chart__svg-label"' . ($highlight === $index ? ' font-weight="700"' : '') . '>' . self::escape($label) . '</text>';
            $targets .= self::mark($slot + $step / 2, $label, $series, $index, $unit,
                '<rect x="' . self::number($slot) . '" y="26" width="' . self::number($step) . '" height="170" fill="transparent"/>');
        }
        foreach ($lines as $item) {
            $points = [];
            foreach ($item['values'] as $index => $value) {
                $points[] = self::number($left + $step * $index + $step / 2) . ',' . self::number(self::y($value, $min, $max));
            }
            [, $firstY] = explode(',', $points[0]);
            [, $lastY] = explode(',', $points[count($points) - 1]);
            $constant = count(array_unique($item['values'], SORT_REGULAR)) === 1;
            $html .= '<polyline fill="none" stroke="' . $item['color'] . '" stroke-width="1.5"' . self::dash($item['style']) . ' points="' . $left . ',' . $firstY . ' ' . implode(' ', $points) . ' ' . ($left + $width) . ',' . $lastY . '"/>'
                . '<text x="' . ($left - 8) . '" y="' . self::number((float) $firstY + 4) . '" text-anchor="end" class="gx-data-chart__svg-label">' . self::escape($item['name'] . ($constant ? ': ' . self::scaled($item['values'][0], $scale) : '')) . '</text>';
        }

        return self::svg($title, $html . $targets);
    }

    /**
     * Horizontal rows grouped under headings; each row stacks its non-empty segments. A row with
     * several segments shows a "first–total" range label, and the highlighted row draws a dashed
     * reference line across every row. Rendered as HTML so long row labels wrap on small screens.
     *
     * @param list<string> $labels
     * @param list<string> $groups
     * @param list<array{name:string,color:string,style:string,fill:bool,values:list<float|null>}> $series
     */
    private static function groupedBars(array $labels, array $groups, array $series, string $axis, string $unit, ?float $axisMin, ?float $axisMax, ?int $highlight, string $referenceLabel, int $scale): string
    {
        $totals = [];
        foreach ($labels as $index => $_) {
            $totals[] = array_sum(array_map(static fn (array $item): float => $item['values'][$index] ?? 0.0, $series));
        }
        $min = $axisMin ?? 0.0;
        $max = $axisMax ?? max($min + 1.0, ...$totals);
        if ($min >= $max) {
            $max = $min + 1.0;
        }

        $unitText = trim($axis . ($axis !== '' && $unit !== '' ? ' (' . $unit . ')' : $unit));
        $caption = trim(self::SCALES[$scale] . ' ' . $unitText);
        $html = $caption === '' ? '' : '<p class="gx-data-chart__axis-caption">' . self::escape($unitText === '' ? 'Đơn vị: ' . $caption : $caption) . '</p>';
        $html .= '<div class="gx-data-chart__rows">';
        $gridRows = 0;
        $previous = null;
        foreach ($labels as $index => $label) {
            $group = $groups[$index];
            if ($group !== '' && $group !== $previous) {
                $html .= '<p class="gx-data-chart__group">' . self::escape($group) . '</p>';
                $gridRows++;
            }
            $previous = $group;
            $state = $highlight === $index ? ' is-highlight' : ($highlight !== null && $groups[$highlight] === $group ? ' is-muted' : '');
            $end = self::ratio($totals[$index], $min, $max);
            $segments = '';
            $parts = [];
            $stack = 0.0;
            foreach ($series as $item) {
                $value = $item['values'][$index];
                if ($value === null) {
                    continue;
                }
                $from = self::ratio($stack, $min, $max);
                $stack += $value;
                $parts[] = $value;
                if ($end > 0) {
                    $segments .= '<span style="inline-size:' . self::percent((self::ratio($stack, $min, $max) - $from) / $end) . ';background:' . $item['color'] . '"></span>';
                }
            }
            $text = self::scaled($parts[0], $scale) . (count($parts) > 1 ? '–' . self::scaled($stack, $scale) : '');
            $html .= '<div class="gx-data-chart__row' . $state . '"><span class="gx-data-chart__row-label">' . self::escape($label) . '</span>'
                . '<span class="gx-data-chart__track"><span class="gx-data-chart__bar" style="inline-size:' . self::percent($end) . '">' . $segments . '</span>'
                . '<b class="gx-data-chart__value" style="inset-inline-start:' . self::percent($end) . '">' . self::escape($text) . '</b></span></div>';
            $gridRows++;
        }
        if ($highlight !== null) {
            $at = self::percent(self::ratio($totals[$highlight], $min, $max));
            $html .= '<span class="gx-data-chart__reference" style="grid-row:1 / span ' . $gridRows . '" aria-hidden="true"><span style="inset-inline-start:' . $at . '"></span></span>';
            if ($referenceLabel !== '') {
                $html .= '<span class="gx-data-chart__reference-label"><span style="inset-inline-start:' . $at . ';transform:translateX(-' . $at . ')">' . self::escape($referenceLabel) . '</span></span>';
            }
        }

        return $html . '</div>';
    }

    /**
     * Series drawn as extra segments of a multi-segment row; single-segment series are named by their row.
     *
     * @param list<array{name:string,color:string,style:string,fill:bool,values:list<float|null>}> $series
     * @return list<array{name:string,color:string,style:string,fill:bool,values:list<float|null>}>
     */
    private static function segmentSeries(array $series): array
    {
        $count = count($series[0]['values']);
        $shared = [];
        for ($index = 0; $index < $count; $index++) {
            $present = array_keys(array_filter($series, static fn (array $item): bool => $item['values'][$index] !== null));
            if (count($present) > 1) {
                $shared += array_flip($present);
            }
        }
        ksort($shared);

        return array_values(array_intersect_key($series, $shared));
    }

    /** @param list<string> $labels @param list<string> $groups @return list<string> */
    private static function groupedLabels(array $labels, array $groups): array
    {
        return array_map(static fn (string $label, string $group): string => $group === '' ? $label : $group . ' – ' . $label, $labels, $groups);
    }

    private static function scaled(float $value, int $scale): string
    {
        $value /= $scale;

        return self::format($scale > 1 ? round($value, $value < 10 ? 1 : 0) : $value);
    }

    private static function percent(float $ratio): string
    {
        return self::number($ratio * 100) . '%';
    }

    /** @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series @return array{float,float} */
    private static function range(array $series, bool $fromZero, ?float $axisMin, ?float $axisMax): array
    {
        $values = array_merge(...array_map(static fn (array $item): array => $item['values'], $series));
        $min = $axisMin ?? ($fromZero ? min(0.0, min($values)) : min($values));
        $max = $axisMax ?? max($values);
        if ($min >= $max) {
            $max = $min + 1.0;
        }

        return [$min, $max];
    }

    private static function y(float $value, float $min, float $max): float
    {
        return 196 - (self::ratio($value, $min, $max) * 170);
    }

    private static function ratio(float $value, float $min, float $max): float
    {
        return min(1.0, max(0.0, ($value - $min) / ($max - $min)));
    }

    private static function grid(float $min, float $max, string $axis, string $unit, int $scale = 1): string
    {
        $html = '';
        for ($i = 0; $i <= 5; $i++) {
            $y = 26 + 34 * $i;
            $value = $max - (($max - $min) * $i / 5);
            $html .= '<line x1="64" y1="' . $y . '" x2="684" y2="' . $y . '" class="gx-data-chart__grid"/><text x="56" y="' . ($y + 4) . '" text-anchor="end" class="gx-data-chart__svg-label">' . self::escape(self::scaled($value, $scale)) . '</text>';
        }
        $caption = trim(self::SCALES[$scale] . ' ' . trim($axis . ($axis !== '' && $unit !== '' ? ' (' . $unit . ')' : $unit)));
        if ($caption !== '') {
            $html .= '<text x="18" y="111" transform="rotate(-90 18 111)" text-anchor="middle" class="gx-data-chart__svg-axis">' . self::escape($caption) . '</text>';
        }

        return $html;
    }

    private static function horizontalGrid(float $min, float $max, string $axis, string $unit, float $left, float $width): string
    {
        $html = '';
        for ($i = 0; $i <= 5; $i++) {
            $x = $left + $width * $i / 5;
            $value = $min + (($max - $min) * $i / 5);
            $html .= '<line x1="' . $x . '" y1="26" x2="' . $x . '" y2="196" class="gx-data-chart__grid"/><text x="' . $x . '" y="224" text-anchor="middle" class="gx-data-chart__svg-label">' . self::escape(self::format($value)) . '</text>';
        }
        if ($axis !== '' || $unit !== '') {
            $html .= '<text x="' . self::number($left + $width / 2) . '" y="246" text-anchor="middle" class="gx-data-chart__svg-axis">' . self::escape(trim($axis . ($axis !== '' && $unit !== '' ? ' (' . $unit . ')' : $unit))) . '</text>';
        }

        return $html;
    }

    /** @param list<string> $labels */
    private static function xLabels(array $labels): string
    {
        $html = '';
        $count = count($labels);
        foreach ($labels as $index => $label) {
            $html .= '<text x="' . self::number(64 + (620 * $index / ($count - 1))) . '" y="224" text-anchor="middle" class="gx-data-chart__svg-label">' . self::escape($label) . '</text>';
        }

        return $html;
    }

    /** @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series */
    private static function legend(array $series): string
    {
        if ($series === []) {
            return '';
        }
        $html = '<div class="gx-data-chart__legend"><ul>';
        foreach ($series as $item) {
            $html .= '<li><span style="--gx-chart-color:' . $item['color'] . '" class="gx-data-chart__key gx-data-chart__key--' . $item['style'] . '" aria-hidden="true"></span>' . self::escape($item['name']) . '</li>';
        }

        return $html . '</ul></div>';
    }

    /** @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series */
    private static function mark(float $x, string $label, array $series, int $index, string $unit, string $hit): string
    {
        $description = [];
        $rows = '';
        foreach ($series as $item) {
            $value = self::format($item['values'][$index]) . ($unit !== '' ? ' ' . $unit : '');
            $description[] = $item['name'] . ': ' . $value;
            $rows .= '<li><i style="--gx-chart-color:' . $item['color'] . '" aria-hidden="true"></i><span>'
                . self::escape($item['name']) . '</span><b>' . self::escape($value) . '</b></li>';
        }
        $tooltipX = max(4, min(496, $x - 110));
        $tooltipHeight = 32 + count($series) * 23;

        $description = $label . ' — ' . implode('; ', $description);

        return '<g class="gx-data-chart__mark" tabindex="0" role="img" aria-label="' . self::escape($description) . '">'
            . $hit
            . '<foreignObject class="gx-data-chart__tooltip" x="' . self::number($tooltipX) . '" y="32" width="220" height="'
            . self::number($tooltipHeight) . '" aria-hidden="true"><div xmlns="http://www.w3.org/1999/xhtml" class="gx-data-chart__tooltip-card"><strong>'
            . self::escape($label) . '</strong><ul>' . $rows . '</ul></div></foreignObject></g>';
    }

    /** @param list<string> $labels @param list<array{name:string,color:string,style:string,fill:bool,values:list<float>}> $series */
    private static function table(string $title, array $labels, array $series, string $unit): string
    {
        $html = '<div class="gx-data-chart__table"><table><caption>' . self::escape($title . ($unit !== '' ? ' (' . $unit . ')' : '')) . '</caption><thead><tr><th scope="col">Nhãn</th>';
        foreach ($series as $item) {
            $html .= '<th scope="col">' . self::escape($item['name']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($labels as $index => $label) {
            $html .= '<tr><th scope="row">' . self::escape($label) . '</th>';
            foreach ($series as $item) {
                $value = $item['values'][$index];
                $html .= '<td>' . ($value === null ? '—' : self::escape(self::format($value))) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** @param list<array{title:string,body:string,tone:string,badge:string,body_format:string}> $notes */
    private static function notesHtml(array $notes): string
    {
        if ($notes === []) {
            return '';
        }
        $html = '<aside class="gx-data-chart__notes" aria-label="Ghi chú biểu đồ">';
        foreach ($notes as $note) {
            $html .= '<section class="gx-data-chart__note gx-data-chart__note--' . $note['tone'] . '"><header class="gx-data-chart__note-heading"><strong class="gx-data-chart__note-label" data-chart-outline-slot>' . self::escape($note['title']) . '</strong>';
            if ($note['badge'] !== '') {
                $html .= '<span class="gx-data-chart__note-badge">' . self::escape($note['badge']) . '</span>';
            }
            $body = $note['body_format'] === 'html'
                ? DataChartNoteHtml::render($note['body'])
                : '<p>' . self::escape($note['body']) . '</p>';
            $html .= '</header><div class="gx-data-chart__note-body">' . $body . '</div></section>';
        }

        return $html . '</aside>';
    }

    private static function svg(string $title, string $content): string
    {
        return '<svg viewBox="0 0 720 252" role="img" aria-label="' . self::escape($title) . '" xmlns="http://www.w3.org/2000/svg"><title>' . self::escape($title) . '</title>' . $content . '</svg>';
    }

    private static function dash(string $style): string
    {
        return match ($style) {
            'dashed' => ' stroke-dasharray="7 6"',
            'dotted' => ' stroke-dasharray="2 6"',
            default => '',
        };
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $options */
    private static function axisOption(array $options, string $key): bool
    {
        return !array_key_exists($key, $options)
            || ((is_int($options[$key]) || is_float($options[$key]))
                && is_finite((float) $options[$key])
                && $options[$key] >= 0
                && $options[$key] <= 1_000_000_000);
    }
}
