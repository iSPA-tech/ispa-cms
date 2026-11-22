<?php

namespace App\Services\Tracking;

class TrackingCounterDeltaResolver
{
    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    public function resolve(array $tags): array
    {
        $tags = array_values(array_unique(array_map(
            static fn (mixed $tag): string => strtolower(trim((string) $tag)),
            $tags
        )));

        return [
            'legacy_views' => 1,
            'page_views' => in_array('page_view', $tags, true) ? 1 : 0,
            'scans' => in_array('scan', $tags, true) ? 1 : 0,
            'place_scans' => in_array('scan', $tags, true) && in_array('place', $tags, true) ? 1 : 0,
            'item_scans' => in_array('scan', $tags, true) && in_array('item', $tags, true) ? 1 : 0,
            'downloads' => count(array_intersect($tags, ['download', 'downloads'])) > 0 ? 1 : 0,
        ];
    }

    /**
     * @param array<string, int> $deltas
     */
    public function contract(array $deltas): string
    {
        ksort($deltas);

        return hash('sha256', json_encode($deltas, JSON_THROW_ON_ERROR));
    }
}
