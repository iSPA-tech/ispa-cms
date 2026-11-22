<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin;

use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Http\Controllers\AdminController;

/**
 * Lists everything currently waiting for the signed-in admin.
 */
class MyTasksController extends AdminController
{
    /**
     * @param AttentionRegistry $registry Attention provider registry.
     */
    public function __construct(protected AttentionRegistry $registry)
    {
    }

    /**
     * Return the user's pending tasks with items and a total.
     *
     * @return mixed Admin API response.
     */
    public function index(): mixed
    {
        $user = request()->user();
        $items = $user ? $this->sortByUrgency($this->registry->forUser($user, true)) : [];

        return ires()->data(['items' => $items, 'total' => array_sum(array_column($items, 'count'))]);
    }

    /**
     * Most urgent group first: danger, then warning (also the default for a missing or unknown tone), then info. The sort is stable.
     *
     * @param array<int, array<string, mixed>> $entries Registry entries.
     * @return array<int, array<string, mixed>>
     */
    private function sortByUrgency(array $entries): array
    {
        $rank = static fn (array $e): int => match ($e['tone'] ?? null) {
            'danger' => 0,
            'info' => 2,
            default => 1,
        };
        $indexed = array_map(static fn (array $e, int $i): array => [$rank($e), $i, $e], $entries, array_keys($entries));
        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($indexed, 2);
    }
}
