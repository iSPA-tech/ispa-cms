<?php

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;

uses(TestCase::class, RefreshDatabase::class);

it('renders a saved data chart in the shared article content view', function (): void {
    $content = [
        ['type' => 'text', 'value' => '<p>Nội dung kiểm thử.</p>'],
        [
            'type' => 'data_chart',
            'value' => [
                'version' => 1,
                'chart_type' => 'line',
                'title' => 'Tỷ lệ giữ giá',
                'labels' => ['Mới', 'Năm 1'],
                'series' => [
                    ['name' => 'Xe xăng', 'color' => 'orange', 'line_style' => 'solid', 'values' => [100, 85]],
                ],
                'notes' => [['title' => 'Ghi chú nhanh', 'body' => 'Dữ liệu minh họa.', 'tone' => 'neutral']],
            ],
            'options' => ['layout' => 'wide'],
        ],
    ];

    $html = view('components.contents-view', ['content' => $content])->render();

    expect($html)->toContain('Nội dung kiểm thử.')
        ->toContain('gx-data-chart')
        ->toContain('<svg')
        ->toContain('<table')
        ->toContain('Tỷ lệ giữ giá');
    expect(strpos($html, 'Nội dung kiểm thử.'))->toBeLessThan(strpos($html, 'gx-data-chart'));

    $chart = (new Crawler($html))->filter('.gx-data-chart');
    expect($chart->filter('h2, h3, h4')->count())->toBe(0)
        ->and($chart->filter('figure > figcaption')->text())->toContain('Tỷ lệ giữ giá')
        ->and($chart->filter('.gx-data-chart__note-label')->text())->toBe('Ghi chú nhanh');
});
