<?php

declare(strict_types=1);

use App\Models\Slug;
use Cms\Content\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill the category of slug rows that have none, from the record each slug points to (two
 * kinds of record may share a slug under different URLs, and each resolver looks a slug up by its category). Until now
 * only the admin post screen stored a category; galleries, categories, products and the rest were saved with NULL.
 * A post slug takes the post type (page, post, ...), any other the kind of its record (App\Models\Slug::categoryFor).
 * Rows that already have a category are left as they are. Idempotent; down() does nothing (the NULLs carried no data).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('slugs')) {
            return;
        }

        $empty = static fn ($query) => $query->where(fn ($q) => $q->whereNull('category')->orWhere('category', ''));

        $types = $empty(DB::table('slugs'))->distinct()->pluck('target_type')->filter()->all();
        foreach ($types as $targetType) {
            if (is_a($targetType, Post::class, true) && Schema::hasTable('posts')) {
                $this->fillPostSlugs($targetType, $empty);

                continue;
            }

            $category = Slug::categoryFor($targetType, null);
            if ($category !== null) {
                $empty(DB::table('slugs')->where('target_type', $targetType))->update(['category' => $category]);
            }
        }
    }

    public function down(): void
    {
    }

    /** A post slug takes its post's type; a post without a type (or a missing post) counts as an article. */
    private function fillPostSlugs(string $targetType, Closure $empty): void
    {
        foreach (DB::table('posts')->whereNotNull('type')->where('type', '!=', '')->distinct()->pluck('type') as $type) {
            $empty(DB::table('slugs')->where('target_type', $targetType))
                ->whereIn('target_id', DB::table('posts')->select('id')->where('type', $type))
                ->update(['category' => $type]);
        }

        $empty(DB::table('slugs')->where('target_type', $targetType))->update(['category' => 'post']);
    }
};
