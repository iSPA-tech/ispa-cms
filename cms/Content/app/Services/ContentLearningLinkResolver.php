<?php

declare(strict_types=1);

namespace Cms\Content\Services;

use App\Enums\StatusEnum;
use App\Models\Slug;
use App\Models\User;
use Cms\Content\Models\Post;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Collection;

final class ContentLearningLinkResolver
{
    /** @return array{title:string,url:string,available:bool}|null */
    public function resolve(string $uuid, string $projectKey, ?User $viewer): ?array
    {
        if ($projectKey === '') {
            return null;
        }

        $languageId = (int) lang('id', get_lang_id());
        $post = Post::query()->with('category')->where('uuid', $uuid)->where('type', 'post')->where('language_id', $languageId)
            ->where('status', StatusEnum::PUBLISHED->value)->whereNotNull('published_at')->where('published_at', '<=', now())->first();
        if (! $post instanceof Post || $post->category === null || $post->category->language_id !== $languageId) {
            return null;
        }
        $slug = Slug::query()->where('target_type', Post::class)->where('target_id', $post->id)->where('language_id', $languageId)->latest('id')->value('slug');
        $route = 'article.' . lang('path', 'vi');
        if (! is_string($slug) || $slug === '' || ! $post->category->slug || ! Route::has($route)) {
            return null;
        }

        return ['title' => (string) $post->name, 'url' => route($route, ['cate' => $post->category->slug, 'slug' => $slug]), 'available' => true];
    }

    /** @return Collection<int, array{uuid:string,target_type:string,label:string}> */
    public function options(string $projectKey, ?User $viewer, string $search): Collection
    {
        if ($projectKey === '' || ! Route::has('article.' . lang('path', 'vi'))) {
            return collect();
        }
        $languageId = (int) lang('id', get_lang_id());

        return Post::query()->with('category')->where('type', 'post')->where('language_id', $languageId)->where('status', StatusEnum::PUBLISHED->value)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->when($search !== '', static fn ($posts) => $posts->where('name', 'like', "%{$search}%"))->orderByDesc('published_at')->limit(100)->get()
            ->filter(function (Post $post) use ($languageId): bool {
                $slug = Slug::query()->where('target_type', Post::class)->where('target_id', $post->id)->where('language_id', $languageId)->exists();

                return $post->category !== null && $post->category->language_id === $languageId && $post->category->slug !== '' && $slug;
            })->map(static fn (Post $post): array => ['uuid' => (string) $post->uuid, 'target_type' => 'content_post', 'label' => (string) $post->name])->values();
    }
}
