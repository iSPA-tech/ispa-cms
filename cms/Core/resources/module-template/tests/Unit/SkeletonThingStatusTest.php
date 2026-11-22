<?php

declare(strict_types=1);

namespace Cms\Skeleton\Tests\Unit;

use Cms\Skeleton\Enums\SkeletonThingStatus;
use PHPUnit\Framework\TestCase;

final class SkeletonThingStatusTest extends TestCase
{
    public function test_values_match_the_admin_and_the_database(): void
    {
        $this->assertSame(['draft', 'published'], array_map(static fn (SkeletonThingStatus $s): string => $s->value, SkeletonThingStatus::cases()));
    }

    public function test_each_status_has_a_translation_key(): void
    {
        $this->assertSame('text.skeleton.things.status_published', SkeletonThingStatus::Published->labelKey());
    }
}
