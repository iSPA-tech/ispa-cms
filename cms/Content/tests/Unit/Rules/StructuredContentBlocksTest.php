<?php

declare(strict_types=1);

use Cms\Content\Rules\StructuredContentBlocks;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('accepts one verified vehicle comparison block', function (): void {
    $block = structuredComparisonBlock();

    $validator = Validator::make(
        ['content' => [['type' => 'text', 'value' => '<p>Bài viết</p>'], $block]],
        ['content' => [new StructuredContentBlocks]],
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects mismatched or duplicate vehicle comparison blocks', function (): void {
    $mismatched = structuredComparisonBlock();
    $mismatched['value']['vehicles'][1]['engine_uuid'] = (string) Str::uuid();

    $mismatchValidator = Validator::make(
        ['content' => [$mismatched]],
        ['content' => [new StructuredContentBlocks]],
    );
    $duplicateValidator = Validator::make(
        ['content' => [structuredComparisonBlock(), structuredComparisonBlock()]],
        ['content' => [new StructuredContentBlocks]],
    );

    expect($mismatchValidator->fails())->toBeTrue()
        ->and($duplicateValidator->fails())->toBeTrue();
});

it('accepts multiple complete data charts alongside ordinary article blocks', function (): void {
    $charts = [
        structuredChartBlock('line'),
        structuredChartBlock('horizontal_bar'),
        structuredChartBlock('stacked_bar'),
    ];

    $validator = Validator::make(
        ['content' => [['type' => 'text', 'value' => '<p>Giới thiệu</p>'], ...$charts]],
        ['content' => [new StructuredContentBlocks]],
    );

    expect($validator->passes())->toBeTrue();
});

it('accepts the battery-chart axis range, area fill and indigo series palette', function (): void {
    $block = structuredChartBlock();
    $block['value']['series'][0]['fill'] = true;
    $block['value']['series'][1]['color'] = 'indigo';
    $block['options']['axis_min'] = 60;
    $block['options']['axis_max'] = 100;

    $validator = Validator::make(['content' => [$block]], ['content' => [new StructuredContentBlocks]]);

    expect($validator->passes())->toBeTrue();
});

it('reports the specific incomplete chart note instead of blaming chart data', function (array $note, string $message): void {
    $block = structuredChartBlock();
    $block['value']['notes'] = [$note];

    $validator = Validator::make(['content' => [$block]], ['content' => [new StructuredContentBlocks]]);

    expect($validator->errors()->first('content'))->toBe($message);
})->with([
    'both fields blank' => [['title' => '', 'body' => '', 'tone' => 'neutral'], 'Chú thích số 1 cần tiêu đề và nội dung.'],
    'title blank' => [['title' => '', 'body' => 'Giả định 15.000 km/năm.', 'tone' => 'neutral'], 'Chú thích số 1 cần tiêu đề.'],
    'body blank' => [['title' => 'Giả định', 'body' => '', 'tone' => 'neutral'], 'Chú thích số 1 cần nội dung.'],
    'tone invalid' => [['title' => 'Giả định', 'body' => '15.000 km/năm.', 'tone' => 'custom'], 'Chú thích số 1 có màu không hợp lệ.'],
]);

it('allows a chart without optional notes', function (): void {
    $block = structuredChartBlock();
    $block['value']['notes'] = [];

    $validator = Validator::make(['content' => [$block]], ['content' => [new StructuredContentBlocks]]);

    expect($validator->passes())->toBeTrue();
});

it('accepts a formatted chart note and optional badge without changing legacy notes', function (): void {
    $block = structuredChartBlock();
    $block['value']['notes'][] = [
        'title' => 'Pin LFP',
        'badge' => 'Độ bền cao',
        'body_format' => 'html',
        'body' => '<ul><li><p><strong>Tuổi thọ:</strong> 2.000 chu kỳ sạc.</p></li></ul><ol start="3"><li><p>Bước tiếp theo</p></li></ol>',
        'tone' => 'green',
    ];

    $validator = Validator::make(['content' => [$block]], ['content' => [new StructuredContentBlocks]]);

    expect($validator->passes())->toBeTrue();
});

it('rejects empty or unsafe formatted chart notes with a note-specific error', function (string $body, string $message): void {
    $block = structuredChartBlock();
    $block['value']['notes'] = [[
        'title' => 'Pin LFP', 'body_format' => 'html', 'body' => $body, 'tone' => 'green',
    ]];

    $validator = Validator::make(['content' => [$block]], ['content' => [new StructuredContentBlocks]]);

    expect($validator->errors()->first('content'))->toBe($message);
})->with([
    'empty markup' => ['<p><br></p>', 'Chú thích số 1 cần nội dung.'],
    'script' => ['<p>Pin tốt</p><script>alert(1)</script>', 'Chú thích số 1 có nội dung định dạng không hợp lệ.'],
    'event attribute' => ['<p onclick="alert(1)">Pin tốt</p>', 'Chú thích số 1 có nội dung định dạng không hợp lệ.'],
    'unsafe list start' => ['<ol start="javascript:alert(1)"><li>Pin tốt</li></ol>', 'Chú thích số 1 có nội dung định dạng không hợp lệ.'],
]);

it('rejects malformed or incomplete chart series rather than publishing misleading numbers', function (string $path, mixed $badValue): void {
    $block = structuredChartBlock();
    data_set($block, $path, $badValue);

    $validator = Validator::make(
        ['content' => [$block]],
        ['content' => [new StructuredContentBlocks]],
    );

    expect($validator->fails())->toBeTrue();
})->with([
    'missing point' => ['value.series.0.values', [29]],
    'numeric string' => ['value.series.0.values.0', '29'],
    'unsupported type' => ['value.chart_type', 'pie'],
    'unbounded value' => ['value.series.0.values.0', INF],
    'missing title' => ['value.title', '   '],
    'invalid color' => ['value.series.0.color', 'url(javascript:alert(1))'],
    'invalid note tone' => ['value.notes.0.tone', 'custom'],
    'invalid layout' => ['options.layout', 'overflow'],
    'invalid fill' => ['value.series.0.fill', 'yes'],
    'invalid axis minimum' => ['options.axis_min', '60'],
    'infinite axis maximum' => ['options.axis_max', INF],
]);

it('rejects a reversed chart axis range', function (): void {
    $block = structuredChartBlock();
    $block['options']['axis_min'] = 100;
    $block['options']['axis_max'] = 60;

    $validator = Validator::make(['content' => [$block]], ['content' => [new StructuredContentBlocks]]);

    expect($validator->fails())->toBeTrue();
});

/** @return array<string, mixed> */
it('accepts grouped bars with empty segments and rejects an all-empty row or nulls elsewhere', function (): void {
    $block = structuredChartBlock('grouped_bar');
    $block['value']['row_groups'] = ['VF 6', 'Xe xăng'];
    $block['value']['series'][0]['values'] = [null, 58.5];
    $block['value']['series'][1]['color'] = 'gray_light';
    $block['options'] += ['highlight_row' => 1, 'reference_label' => 'Mốc', 'value_scale' => 1000];
    $emptyRow = $block;
    $emptyRow['value']['series'][1]['values'] = [null, 17.4];
    $lineWithNull = $block;
    $lineWithNull['value']['chart_type'] = 'line';
    $badHighlight = $block;
    $badHighlight['options']['highlight_row'] = 2;
    $badGroups = $block;
    $badGroups['value']['row_groups'] = ['Một'];

    $passes = fn (array $chart): bool => Validator::make(['content' => [$chart]], ['content' => [new StructuredContentBlocks]])->passes();

    expect($passes($block))->toBeTrue()
        ->and($passes($emptyRow))->toBeFalse()
        ->and($passes($lineWithNull))->toBeFalse()
        ->and($passes($badHighlight))->toBeFalse()
        ->and($passes($badGroups))->toBeFalse();
});

it('accepts a column chart with a line series and rejects a non-boolean as_line', function (): void {
    $block = structuredChartBlock('column');
    $block['value']['series'][1]['as_line'] = true;
    $bad = $block;
    $bad['value']['series'][1]['as_line'] = 'yes';
    $passes = fn (array $chart): bool => Validator::make(['content' => [$chart]], ['content' => [new StructuredContentBlocks]])->passes();

    expect($passes($block))->toBeTrue()->and($passes($bad))->toBeFalse();
});

it('accepts pie with one series and radar with three labels, rejecting the opposite shapes', function (): void {
    $passes = fn (array $chart): bool => Validator::make(['content' => [$chart]], ['content' => [new StructuredContentBlocks]])->passes();
    $pie = structuredChartBlock('pie');
    $pie['value']['series'] = [$pie['value']['series'][0]];
    $pie['options']['donut'] = true;
    $twoSeriesPie = structuredChartBlock('pie');
    $radar = structuredChartBlock('radar');
    $radar['value']['labels'] = ['A', 'B', 'C'];
    foreach ($radar['value']['series'] as &$item) $item['values'] = [1, 2, 3];
    unset($item);
    $twoAxisRadar = structuredChartBlock('radar');

    expect($passes($pie))->toBeTrue()
        ->and($passes($twoSeriesPie))->toBeFalse()
        ->and($passes($radar))->toBeTrue()
        ->and($passes($twoAxisRadar))->toBeFalse();
});

function structuredChartBlock(string $type = 'line'): array
{
    return [
        'type' => 'data_chart',
        'value' => [
            'version' => 1,
            'chart_type' => $type,
            'title' => 'Chi phí nhiên liệu tích lũy',
            'subtitle' => 'Ước tính theo năm',
            'unit' => 'Triệu VND',
            'axis_label' => 'Chi phí',
            'labels' => ['Năm 1', 'Năm 2'],
            'series' => [
                ['name' => 'Xe xăng', 'color' => 'orange', 'line_style' => 'solid', 'values' => [29, 58.5]],
                ['name' => 'Xe điện', 'color' => 'green', 'line_style' => 'dashed', 'values' => [8.7, 17.4]],
            ],
            'notes' => [
                ['title' => 'Giả định', 'body' => '15.000 km mỗi năm.', 'tone' => 'neutral'],
            ],
            'source' => 'Bảng tính biên tập',
        ],
        'options' => ['layout' => 'split'],
    ];
}

/**
 * @return array<string, mixed>
 */
function structuredComparisonBlock(): array
{
    $engineUuids = [(string) Str::uuid(), (string) Str::uuid()];

    return [
        'type' => 'vehicle_comparison',
        'value' => [
            'contract_version' => 1,
            'engine_uuids' => $engineUuids,
            'vehicles' => [
                structuredComparisonVehicle('a', $engineUuids[0]),
                structuredComparisonVehicle('b', $engineUuids[1]),
            ],
            'evidence_hash' => str_repeat('a', 64),
            'captured_at' => '2026-07-30T12:00:00+07:00',
        ],
        'options' => [
            'title' => 'So sánh thông số kỹ thuật',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function structuredComparisonVehicle(string $suffix, string $engineUuid): array
{
    $node = static fn (string $level): array => [
        'uuid' => (string) Str::uuid(),
        'slug' => $level . '-' . $suffix,
        'name' => ucfirst($level) . ' ' . strtoupper($suffix),
    ];

    return [
        'engine_uuid' => $engineUuid,
        'make' => $node('make'),
        'model' => [...$node('model'), 'segment' => 'C-SUV'],
        'generation' => $node('generation'),
        'trim' => $node('trim'),
        'engine' => [
            'slug' => 'engine-' . $suffix,
            'name' => 'Engine ' . strtoupper($suffix),
            'year' => 2026,
        ],
        'specs_source' => 'canonical',
        'fact_count' => 20,
    ];
}
