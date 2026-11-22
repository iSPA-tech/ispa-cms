<?php

declare(strict_types=1);

namespace Cms\Content\Rules;

use Cms\Content\Services\DataChartNoteHtml;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

final class StructuredContentBlocks implements ValidationRule
{
    private const VEHICLE_COMPARISON_TYPE = 'vehicle_comparison';
    private const DATA_CHART_TYPE = 'data_chart';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $comparisonBlocks = array_values(array_filter(
            $value,
            static fn (mixed $block): bool => is_array($block)
                && ($block['type'] ?? null) === self::VEHICLE_COMPARISON_TYPE,
        ));

        if (count($comparisonBlocks) > 1) {
            $fail('Mỗi bài viết chỉ được có một bảng so sánh xe.');

            return;
        }

        if ($comparisonBlocks !== [] && ! $this->validVehicleComparison($comparisonBlocks[0])) {
            $fail('Bảng so sánh xe không đúng cấu trúc hoặc chưa được xác minh.');
        }

        foreach ($value as $block) {
            if (is_array($block) && ($block['type'] ?? null) === self::DATA_CHART_TYPE) {
                if ($noteError = $this->chartNoteError($block)) {
                    $fail($noteError);

                    return;
                }
                if (! $this->validDataChart($block)) {
                    $fail('Biểu đồ cần tiêu đề, nhãn và các chuỗi số hợp lệ.');

                    return;
                }
            }
        }
    }

    /** @param array<string, mixed> $block */
    private function chartNoteError(array $block): ?string
    {
        $value = $block['value'] ?? null;
        if (! is_array($value)) {
            return null;
        }

        $notes = $value['notes'] ?? [];
        if (! is_array($notes) || ! array_is_list($notes) || count($notes) > 6) {
            return 'Danh sách chú thích biểu đồ không hợp lệ (tối đa 6 chú thích).';
        }

        foreach ($notes as $index => $note) {
            $number = $index + 1;
            if (! is_array($note)) {
                return "Chú thích số {$number} không hợp lệ.";
            }
            $format = $note['body_format'] ?? 'plain';
            if (! in_array($format, ['plain', 'html'], true)) {
                return "Chú thích số {$number} có định dạng nội dung không hợp lệ.";
            }
            $titleValid = $this->chartText($note['title'] ?? null, 120, true);
            $body = $note['body'] ?? null;
            $bodyValid = $format === 'html'
                ? is_string($body) && DataChartNoteHtml::hasText($body)
                : $this->chartText($body, 1000, true);
            if (! $titleValid && ! $bodyValid) {
                return "Chú thích số {$number} cần tiêu đề và nội dung.";
            }
            if (! $titleValid) {
                return "Chú thích số {$number} cần tiêu đề.";
            }
            if (! $bodyValid) {
                return "Chú thích số {$number} cần nội dung.";
            }
            if ($format === 'html' && ! DataChartNoteHtml::valid($body)) {
                return "Chú thích số {$number} có nội dung định dạng không hợp lệ.";
            }
            if (! $this->chartText($note['badge'] ?? '', 50)) {
                return "Chú thích số {$number} có nhãn phụ không hợp lệ.";
            }
            if (! in_array($note['tone'] ?? null, ['neutral', 'blue', 'green', 'amber'], true)) {
                return "Chú thích số {$number} có màu không hợp lệ.";
            }
        }

        return null;
    }

    /** @param array<string, mixed> $block */
    private function validDataChart(array $block): bool
    {
        $value = $block['value'] ?? null;
        $options = $block['options'] ?? [];
        if (! is_array($value) || ! is_array($options)
            || ($value['version'] ?? null) !== 1
            || ! in_array($value['chart_type'] ?? null, ['line', 'horizontal_bar', 'stacked_bar', 'grouped_bar', 'column', 'pie', 'radar'], true)
            || ! $this->chartText($value['title'] ?? null, 180, true)
            || ! $this->chartText($value['subtitle'] ?? null, 300)
            || ! $this->chartText($value['unit'] ?? null, 60)
            || ! $this->chartText($value['axis_label'] ?? null, 80)
            || ! $this->chartText($value['source'] ?? null, 300)
            || ! in_array($options['layout'] ?? 'wide', ['wide', 'split'], true)
            || (array_key_exists('axis_min', $options) && ! $this->chartNumber($options['axis_min']))
            || (array_key_exists('axis_max', $options) && ! $this->chartNumber($options['axis_max']))
            || (isset($options['axis_min'], $options['axis_max']) && $options['axis_min'] >= $options['axis_max'])
            || (array_key_exists('value_scale', $options) && ! in_array($options['value_scale'], [1, 1000, 1_000_000, 1_000_000_000], true))
            || ! $this->chartText($options['reference_label'] ?? null, 80)
            || (array_key_exists('donut', $options) && ! is_bool($options['donut']))) {
            return false;
        }
        $grouped = $value['chart_type'] === 'grouped_bar';

        $labels = $value['labels'] ?? null;
        $series = $value['series'] ?? null;
        if (! is_array($labels) || ! array_is_list($labels) || count($labels) < 2 || count($labels) > 20
            || ! is_array($series) || ! array_is_list($series) || count($series) < 1 || count($series) > 6
            // A pie is one series of slices; a radar needs at least three axes.
            || ($value['chart_type'] === 'pie' && count($series) !== 1)
            || ($value['chart_type'] === 'radar' && count($labels) < 3)) {
            return false;
        }

        foreach ($labels as $label) {
            if (! $this->chartText($label, 80, true)) {
                return false;
            }
        }

        // Grouped bars: optional per-row group heading and one emphasised row (also the reference line).
        $groups = $value['row_groups'] ?? null;
        if ($groups !== null && (! is_array($groups) || ! array_is_list($groups) || count($groups) !== count($labels))) {
            return false;
        }
        foreach ($groups ?? [] as $group) {
            if (! $this->chartText($group, 80)) {
                return false;
            }
        }
        if (array_key_exists('highlight_row', $options)
            && (! is_int($options['highlight_row']) || $options['highlight_row'] < 0 || $options['highlight_row'] >= count($labels))) {
            return false;
        }
        $filledRows = array_fill(0, count($labels), false);

        foreach ($series as $item) {
            if (! is_array($item)
                || ! $this->chartText($item['name'] ?? null, 80, true)
                || ! in_array($item['color'] ?? null, ['orange', 'blue', 'green', 'teal', 'indigo', 'gray', 'gray_light'], true)
                || ! in_array($item['line_style'] ?? 'solid', ['solid', 'dashed', 'dotted'], true)
                || (array_key_exists('fill', $item) && ! is_bool($item['fill']))
                || (array_key_exists('as_line', $item) && ! is_bool($item['as_line']))
                || ! is_array($item['values'] ?? null)
                || ! array_is_list($item['values'])
                || count($item['values']) !== count($labels)) {
                return false;
            }

            foreach ($item['values'] as $index => $point) {
                // Grouped bars leave a segment empty (null) where a series does not apply to that row.
                if ($grouped && $point === null) {
                    continue;
                }
                if (! $this->chartNumber($point)) {
                    return false;
                }
                $filledRows[$index] = true;
            }
        }

        return ! in_array(false, $filledRows, true);
    }

    private function chartText(mixed $value, int $limit, bool $required = false): bool
    {
        return ($value === null && ! $required)
            || (is_string($value) && mb_strlen($value) <= $limit && (! $required || trim($value) !== ''));
    }

    private function chartNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= 0
            && $value <= 1_000_000_000;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function validVehicleComparison(array $block): bool
    {
        $value = $block['value'] ?? null;
        $options = $block['options'] ?? [];
        if (! is_array($value) || ! is_array($options)) {
            return false;
        }

        $engineUuids = $value['engine_uuids'] ?? null;
        $vehicles = $value['vehicles'] ?? null;
        if (
            ($value['contract_version'] ?? null) !== 1
            || ! is_array($engineUuids)
            || ! array_is_list($engineUuids)
            || count($engineUuids) !== 2
            || count(array_unique($engineUuids)) !== 2
            || ! is_array($vehicles)
            || ! array_is_list($vehicles)
            || count($vehicles) !== 2
        ) {
            return false;
        }

        foreach ($engineUuids as $engineUuid) {
            if (! is_string($engineUuid) || ! Str::isUuid($engineUuid)) {
                return false;
            }
        }

        foreach ($vehicles as $index => $vehicle) {
            if (
                ! is_array($vehicle)
                || ($vehicle['engine_uuid'] ?? null) !== $engineUuids[$index]
                || ! $this->validVehicleIdentity($vehicle)
            ) {
                return false;
            }
        }

        $hash = $value['evidence_hash'] ?? null;
        $capturedAt = $value['captured_at'] ?? null;
        $title = $options['title'] ?? null;

        return is_string($hash)
            && preg_match('/^[a-f0-9]{64}$/', $hash) === 1
            && is_string($capturedAt)
            && strtotime($capturedAt) !== false
            && ($title === null || (is_string($title) && mb_strlen($title) <= 120));
    }

    /**
     * @param array<string, mixed> $vehicle
     */
    private function validVehicleIdentity(array $vehicle): bool
    {
        foreach (['make', 'model', 'generation', 'trim'] as $level) {
            $node = $vehicle[$level] ?? null;
            if (
                ! is_array($node)
                || ! is_string($node['uuid'] ?? null)
                || ! Str::isUuid($node['uuid'])
                || ! $this->validSlug($node['slug'] ?? null)
                || ! is_string($node['name'] ?? null)
                || trim($node['name']) === ''
            ) {
                return false;
            }
        }

        $engine = $vehicle['engine'] ?? null;

        return is_array($engine)
            && $this->validSlug($engine['slug'] ?? null)
            && is_string($engine['name'] ?? null)
            && trim($engine['name']) !== '';
    }

    private function validSlug(mixed $value): bool
    {
        return is_string($value)
            && mb_strlen($value) <= 255
            && preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $value) === 1;
    }
}
