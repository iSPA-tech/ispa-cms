<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Repositories\AuditLogRepository;

test('AuditLogRepository getAuditLogListByUserId builds paginated query', function () {
    cache()->flush();

    $cacheHelper = new class extends CacheHelper {
        public array $stored = [];

        public function store(string $cacheKey, string $title, int $ttl = 0): void
        {
            $this->stored = [$cacheKey, $title, $ttl];
        }
    };

    CacheHelper::swap($cacheHelper);

    $repo = new class extends AuditLogRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };

    $repo->getAuditLogListByUserId(10, 2, 25, true);

    expect($repo->lastGet['where'])->toBe(['user_id' => 10]);
    expect($repo->lastGet['latest'])->toBeTrue();
    expect($repo->lastGet['page'])->toBe(25);
    expect($repo->lastGet['when'][0][0])->toBe('target_id > 0');
    expect($cacheHelper->stored[1])->toContain('Audit log list by user: 10');
});
