<?php

declare(strict_types=1);

namespace Cms\Skeleton\Tests\Unit;

use Cms\Skeleton\Data\SkeletonThingData;
use Cms\Skeleton\Enums\SkeletonThingStatus;
use PHPUnit\Framework\TestCase;

final class SkeletonThingDataTest extends TestCase
{
    public function test_input_is_normalised(): void
    {
        $attributes = SkeletonThingData::fromArray(require dirname(__DIR__) . '/Fixtures/thing.php')->toArray();

        $this->assertSame('Sample name', $attributes['name']);
        $this->assertNull($attributes['description']);
        $this->assertSame(SkeletonThingStatus::Published, $attributes['status']);
    }

    public function test_only_sent_keys_are_kept(): void
    {
        $this->assertSame(['name' => 'Only name'], SkeletonThingData::fromArray(['name' => 'Only name'])->toArray());
    }
}
