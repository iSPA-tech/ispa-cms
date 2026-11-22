<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Unit-style tests for the attention provider registry.
 */
final class AttentionRegistryTest extends TestCase
{
    /**
     * A string ability is honoured through the Gate.
     */
    public function test_string_ability_filters_by_user(): void
    {
        Gate::define('tasks_view', static fn (User $u): bool => $u->id === 1);
        $registry = new AttentionRegistry();
        $registry->register('a', 'tasks_view', static fn (): int => 3, null, ['route' => 'x', 'tone' => 'warn']);

        $allowed = $registry->forUser((new User())->forceFill(['id' => 1]));
        self::assertSame([['key' => 'a', 'count' => 3, 'route' => 'x', 'tone' => 'warn']], $allowed);
        self::assertSame([], $registry->forUser((new User())->forceFill(['id' => 2])));
    }

    /**
     * A closure ability receives the user.
     */
    public function test_closure_ability(): void
    {
        $registry = new AttentionRegistry();
        $registry->register('c', static fn ($u): bool => $u->id === 5, static fn (): int => 1, null, ['route' => 'r']);

        self::assertCount(1, $registry->forUser((new User())->forceFill(['id' => 5])));
        self::assertCount(0, $registry->forUser((new User())->forceFill(['id' => 6])));
    }

    /**
     * Zero counts are dropped.
     */
    public function test_zero_counts_dropped(): void
    {
        $registry = new AttentionRegistry();
        $registry->register('z', static fn (): bool => true, static fn (): int => 0, null, ['route' => 'r']);

        self::assertSame([], $registry->forUser((new User())->forceFill(['id' => 1])));
    }

    /**
     * A throwing provider is skipped while others remain.
     */
    public function test_throwing_provider_skipped(): void
    {
        $registry = new AttentionRegistry();
        $registry->register('bad', static fn (): bool => true, static function (): int {
            throw new \RuntimeException('boom');
        }, null, ['route' => 'r']);
        $registry->register('bad2', static function (): bool {
            throw new \RuntimeException('boom');
        }, static fn (): int => 1, null, ['route' => 'r']);
        $registry->register('ok', static fn (): bool => true, static fn (): int => 2, null, ['route' => 'r']);

        $user = (new User())->forceFill(['id' => 1]);
        $keys = array_column($registry->forUser($user), 'key');
        self::assertSame(['ok'], $keys);
        self::assertSame(['ok'], array_column($registry->forUser($user), 'key'));
    }

    /**
     * Items appear only on request and are capped at 10.
     */
    public function test_items_capped_at_ten(): void
    {
        $registry = new AttentionRegistry();
        $items = static fn (): array => array_map(static fn (int $i): array => ['title' => "t$i", 'route' => 'r'], range(1, 15));
        $registry->register('i', static fn (): bool => true, static fn (): int => 15, $items, ['route' => 'r']);

        $user = (new User())->forceFill(['id' => 1]);
        self::assertArrayNotHasKey('items', $registry->forUser($user)[0]);
        self::assertCount(10, $registry->forUser($user, true)[0]['items']);
    }
}
