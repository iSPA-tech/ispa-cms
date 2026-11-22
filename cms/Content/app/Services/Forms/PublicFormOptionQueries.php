<?php

namespace Cms\Content\Services\Forms;

use App\Enums\StatusEnum;
use Cms\Content\Models\Post;
use Illuminate\Database\Eloquent\Builder;

final class PublicFormOptionQueries
{
    public static function posts(): Builder
    {
        return Post::query()
            ->where('status', StatusEnum::PUBLISHED->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()));
    }
}
