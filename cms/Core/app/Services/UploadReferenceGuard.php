<?php

namespace Cms\Core\Services;

use Cms\Core\Models\Upload;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UploadReferenceGuard
{
    /** @param list<int> $uploadIds */
    public function lockActive(array $uploadIds): void
    {
        $ids = collect($uploadIds)->map(static fn (mixed $id): int => (int) $id)
            ->filter()->unique()->sort()->values()->all();
        if ($ids === []) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Upload reference validation requires a transaction.');
        }

        $active = Upload::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($active !== $ids) {
            throw new RuntimeException('An image is unavailable for attachment.');
        }
    }
}
