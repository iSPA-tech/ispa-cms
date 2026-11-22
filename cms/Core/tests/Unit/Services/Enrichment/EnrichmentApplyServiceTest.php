<?php

use Cms\Core\Services\Enrichment\EnrichmentApplyService;
use Illuminate\Database\Eloquent\Model;

uses(\Cms\Core\Tests\TestCase::class);

test('auto applies confident enrichment fields while preserving confirmed target fields', function (): void {
    $target = new class extends Model
    {
        public bool $saved = false;

        public $timestamps = false;

        protected $guarded = [];

        protected $casts = [
            'custom' => 'array',
        ];

        public function save(array $options = []): bool
        {
            $this->saved = true;

            return true;
        }
    };

    $target->forceFill([
        'custom' => [
            'sample_module' => [
                'profile' => [
                    'website' => 'https://confirmed.example.test',
                    'summary' => null,
                    'history' => null,
                ],
            ],
        ],
    ]);

    $result = (new EnrichmentApplyService())->apply($target, [
        'profile.website' => [
            'value' => 'https://new.example.test',
            'confidence' => 0.97,
        ],
        'profile.summary' => [
            'value' => 'A short parish profile from the official website.',
            'confidence' => 0.82,
        ],
        'profile.history' => [
            'value' => 'Low-confidence historical note.',
            'confidence' => 0.4,
        ],
    ], [
        'field_map' => [
            'profile.website' => 'custom.sample_module.profile.website',
            'profile.summary' => 'custom.sample_module.profile.summary',
            'profile.history' => 'custom.sample_module.profile.history',
        ],
        'thresholds' => [
            'profile.website' => 0.85,
            'profile.summary' => 0.75,
            'profile.history' => 0.75,
        ],
        'confirmed_fields' => [
            'custom.sample_module.profile.website',
        ],
    ]);

    expect(data_get($target->custom, 'sample_module.profile.website'))
        ->toBe('https://confirmed.example.test')
        ->and(data_get($target->custom, 'sample_module.profile.summary'))
        ->toBe('A short parish profile from the official website.')
        ->and(data_get($target->custom, 'sample_module.profile.history'))
        ->toBeNull()
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['applied'][0]['field'])->toBe('profile.summary')
        ->and($result['skipped'])->toHaveCount(2)
        ->and(collect($result['skipped'])->pluck('reason')->all())
        ->toContain('confirmed_field', 'confidence_below_threshold')
        ->and($target->saved)->toBeTrue();
});

test('confirmed target rows do not overwrite existing values but can receive missing enrichment fields', function (): void {
    $target = new class extends Model
    {
        public bool $saved = false;

        public $timestamps = false;

        protected $guarded = [];

        protected $casts = [
            'custom' => 'array',
            'is_confirmed' => 'boolean',
        ];

        public function save(array $options = []): bool
        {
            $this->saved = true;

            return true;
        }
    };

    $target->forceFill([
        'is_confirmed' => true,
        'custom' => [
            'profile' => [
                'website' => 'https://old.example.test',
                'summary' => null,
            ],
        ],
    ]);

    $result = (new EnrichmentApplyService())->apply($target, [
        'website' => [
            'value' => 'https://new.example.test',
            'confidence' => 0.99,
        ],
        'summary' => [
            'value' => 'Missing summary can still be enriched.',
            'confidence' => 0.9,
        ],
    ], [
        'field_map' => [
            'website' => 'custom.profile.website',
            'summary' => 'custom.profile.summary',
        ],
        'thresholds' => [
            'website' => 0.8,
            'summary' => 0.8,
        ],
        'protect_confirmed_target' => true,
    ]);

    expect(data_get($target->custom, 'profile.website'))
        ->toBe('https://old.example.test')
        ->and(data_get($target->custom, 'profile.summary'))
        ->toBe('Missing summary can still be enriched.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['reason'])->toBe('confirmed_target_value');
});
