<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\Cache;

class TrackingClaimStore
{
    public function claim(string $key, string $owner, int $ttlSeconds): bool
    {
        if (Cache::add($key, $owner, now()->addSeconds($ttlSeconds))) {
            return true;
        }

        $current = Cache::get($key);

        return is_string($current) && hash_equals($current, $owner);
    }

    public function release(string $key, string $owner): void
    {
        Cache::lock('tracking:claim-release:' . hash('sha256', $key), 5)
            ->block(2, function () use ($key, $owner): void {
                $current = Cache::get($key);
                if (is_string($current) && hash_equals($current, $owner)) {
                    Cache::forget($key);
                }
            });
    }
}
