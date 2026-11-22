<?php

declare(strict_types=1);

namespace Cms\Skeleton\Data;

use Cms\Skeleton\Enums\SkeletonThingStatus;

/**
 * Validated input of a thing, normalised once (trimmed text, empty description as null, status as the enum). Only the
 * keys that were sent are kept, so an update leaves the other fields as they are.
 */
final class SkeletonThingData
{
    /** @param array<string, mixed> $attributes */
    private function __construct(private readonly array $attributes)
    {
    }

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $attributes = [];
        if (array_key_exists('name', $validated)) {
            $attributes['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('description', $validated)) {
            $description = trim((string) ($validated['description'] ?? ''));
            $attributes['description'] = $description === '' ? null : $description;
        }
        if (array_key_exists('status', $validated)) {
            $attributes['status'] = SkeletonThingStatus::from((string) $validated['status']);
        }

        return new self($attributes);
    }

    /** @return array<string, mixed> model attributes to fill */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
