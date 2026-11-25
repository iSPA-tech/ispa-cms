<?php

declare(strict_types=1);

namespace Cms\Content\Services\Workflow;

use App\Enums\StatusEnum;
use App\Services\AdminUpdateContentService;
use Carbon\CarbonImmutable;
use Cms\Content\Models\Post;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Models\Category;
use Cms\Core\Models\Language;
use Cms\Core\Models\Tag;
use Cms\Core\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Writes Content posts for workflow nodes and approved draft proposals.
 *
 * Every write here keeps a post a draft, except publish(), which turns a draft into a published post and nothing else.
 * Categories and tags go through AdminUpdateContentService (the admin editor's own sync), slugs through the model's
 * syncSlug, the thumbnail through syncImages, and the public caches through ContentPostCacheInvalidationService.
 *
 * @package Cms\Content\Services\Workflow
 * @since   2026-11-23
 */
class PostDraftWriter
{
    public const TITLE_MAX = 255;
    public const DESCRIPTION_MAX = 500;

    /** Fields a draft proposal may carry (payload `proposed_change`). */
    public const DRAFT_FIELDS = ['title', 'slug', 'description', 'content', 'post_type', 'language', 'category', 'tags', 'thumbnail_upload_id'];

    public function __construct(protected ContentPostCacheInvalidationService $cache)
    {
    }

    /**
     * Create a draft, or update a post that is still a draft. Never publishes.
     *
     * @param array<string, mixed> $fields title (required on create), slug, description, content (HTML string or block
     *                                     list), post_type, language (code/path), category (name or uuid), tags (names),
     *                                     thumbnail_upload_id, create_missing_tags
     */
    public function saveDraft(array $fields, ?string $postUuid = null, ?int $userId = null): Post
    {
        $before = null;

        $post = DB::transaction(function () use ($fields, $postUuid, $userId, &$before): Post {
            if ($postUuid !== null && $postUuid !== '') {
                $post = $this->lockedPost($postUuid);
                $this->assertDraft($post);
                $before = $this->cache->snapshot($post);
            } else {
                $post = new Post();
                $post->uuid = (string) Str::uuid();
                $post->type = 'post';
                $post->user_id = $userId;
            }

            $title = array_key_exists('title', $fields) ? $this->text($fields['title'], 'title', self::TITLE_MAX) : null;
            if (!$post->exists && ($title === null || $title === '')) {
                throw new InvalidArgumentException('title is required to create a draft.');
            }
            if ($title !== null && $title !== '') {
                $post->name = $title;
            }
            if (array_key_exists('description', $fields) && $fields['description'] !== null) {
                $post->description = $this->text($fields['description'], 'description', self::DESCRIPTION_MAX);
            }
            if (array_key_exists('content', $fields) && $fields['content'] !== null) {
                $post->content = $this->contentBlocks($fields['content']);
            }
            if (is_string($fields['post_type'] ?? null) && trim($fields['post_type']) !== '') {
                $type = trim($fields['post_type']);
                if (!preg_match('/^[a-z0-9_\-]{1,20}$/', $type)) {
                    throw new InvalidArgumentException('post_type must be a short lowercase type name.');
                }
                $post->type = $type;
            }
            if (is_string($fields['language'] ?? null) && trim($fields['language']) !== '') {
                $post->language_id = $this->languageId(trim($fields['language']));
            } elseif (!$post->exists) {
                $post->language_id = $this->defaultLanguageId();
            }

            $categoryIds = null;
            if (($fields['category'] ?? null) !== null && $fields['category'] !== '' && $fields['category'] !== []) {
                $categoryIds = $this->categoryIds($fields['category'], $post->language_id);
                $post->category_id = $categoryIds[0];
            }

            $post->status = StatusEnum::DRAFT->value;
            $post->published_at = null;
            $post->save();

            $slug = is_string($fields['slug'] ?? null) && trim($fields['slug']) !== '' ? trim($fields['slug']) : null;
            if ($slug !== null || $post->slugs()->doesntExist()) {
                $post->syncSlug(['slug' => $slug ?? (string) $post->name, 'language_id' => $post->language_id]);
            }

            if ($categoryIds !== null) {
                $this->syncCategories($post, $categoryIds);
            }
            if (($fields['tags'] ?? null) !== null) {
                $this->syncTags($post, $this->tagIds($fields['tags'], (bool) ($fields['create_missing_tags'] ?? false)));
            }
            if (($fields['thumbnail_upload_id'] ?? null) !== null && $fields['thumbnail_upload_id'] !== '') {
                $this->setThumbnail($post, $fields['thumbnail_upload_id']);
            }

            return $post;
        }, 1);

        $this->invalidate($before ?? [], $post);

        return $post->fresh() ?? $post;
    }

    /**
     * Publish a draft (now, or at `publishAt`). Refuses anything that is not a draft.
     */
    public function publish(string $postUuid, ?string $publishAt = null): Post
    {
        $when = $this->publishTime($publishAt);
        $before = [];

        $post = DB::transaction(function () use ($postUuid, $when, &$before): Post {
            $post = $this->lockedPost($postUuid);
            $this->assertDraft($post);
            if (trim((string) $post->name) === '') {
                throw new InvalidArgumentException('A draft without a title cannot be published.');
            }

            $before = $this->cache->snapshot($post);
            $post->forceFill(['status' => StatusEnum::PUBLISHED->value, 'published_at' => $when])->save();

            return $post;
        }, 1);

        $this->invalidate($before, $post);

        return $post->fresh() ?? $post;
    }

    /**
     * Set categories and tags of a draft by name or uuid (`mode` replace or add). New tags only with `createMissing`.
     *
     * @param list<string>|string|null $categories
     * @param list<string>|string|null $tags
     * @return array{category_ids: list<int>, tag_ids: list<int>, created_tags: list<string>}
     */
    public function assignTaxonomy(string $postUuid, mixed $categories, mixed $tags, bool $createMissing, string $mode = 'replace'): array
    {
        if (!in_array($mode, ['replace', 'add'], true)) {
            throw new InvalidArgumentException('mode must be replace or add.');
        }

        $result = ['category_ids' => [], 'tag_ids' => [], 'created_tags' => []];
        $before = [];

        $post = DB::transaction(function () use ($postUuid, $categories, $tags, $createMissing, $mode, &$result, &$before): Post {
            $post = $this->lockedPost($postUuid);
            $this->assertDraft($post);
            $before = $this->cache->snapshot($post);

            if ($categories !== null && $categories !== [] && $categories !== '') {
                $ids = $this->categoryIds($categories, $post->language_id);
                if ($mode === 'add') {
                    $ids = array_values(array_unique([...$this->currentCategoryIds($post), ...$ids]));
                }
                if ($post->category_id === null || $mode === 'replace') {
                    $post->category_id = $ids[0];
                    $post->save();
                }
                $this->syncCategories($post, $ids);
                $result['category_ids'] = $ids;
            }

            if ($tags !== null && $tags !== [] && $tags !== '') {
                $ids = $this->tagIds($tags, $createMissing, $result['created_tags']);
                if ($mode === 'add') {
                    $ids = array_values(array_unique([...$post->tags()->pluck((new Tag())->qualifyColumn('id'))->map('intval')->all(), ...$ids]));
                }
                $this->syncTags($post, $ids);
                $result['tag_ids'] = $ids;
            }

            return $post;
        }, 1);

        $this->invalidate($before, $post);

        return $result;
    }

    /**
     * The readable draft fields of a post (for proposals and approval diffs), or null for a post that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function draftView(?Post $post): ?array
    {
        if ($post === null) {
            return null;
        }

        $post->loadMissing(['category', 'language']);

        return [
            'uuid' => $post->uuid,
            'status' => $post->status,
            'title' => $post->name,
            'slug' => $post->slugs()->value('slug'),
            'description' => $post->description,
            'content' => self::contentText($post->content),
            'post_type' => $post->type,
            'language' => $post->language?->code ?? $post->language?->path,
            'category' => $post->category?->name,
            'category_uuid' => $post->category?->uuid,
            'tags' => $post->tags()->pluck((new Tag())->qualifyColumn('name'))->values()->all(),
            'thumbnail_upload_id' => $post->images()->wherePivot('main', true)->value((new Upload())->qualifyColumn('id')),
            'updated_at' => optional($post->updated_at)->toJSON(),
        ];
    }

    /**
     * Fingerprint of a draft view without its timestamp: a proposal is stale when the post's fingerprint moved.
     *
     * @param array<string, mixed>|null $view
     */
    public static function draftHash(?array $view): string
    {
        unset($view['updated_at']);

        return hash('sha256', (string) json_encode($view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Content blocks as the HTML of their text blocks joined by blank lines (for diffs).
     */
    public static function contentText(mixed $content): ?string
    {
        if (!is_array($content)) {
            return is_string($content) ? $content : null;
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['value'] ?? null)) {
                $parts[] = $block['value'];
            } elseif (is_array($block) && is_string($block['type'] ?? null)) {
                $parts[] = '[' . $block['type'] . ']';
            }
        }

        return implode("\n\n", $parts);
    }

    public function findByUuid(string $uuid): ?Post
    {
        return Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)->where('uuid', $uuid)->first();
    }

    protected function lockedPost(string $uuid): Post
    {
        $post = Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)
            ->where('uuid', $uuid)->lockForUpdate()->first();
        if ($post === null) {
            throw new InvalidArgumentException("Post {$uuid} was not found.");
        }

        return $post;
    }

    protected function assertDraft(Post $post): void
    {
        if ($post->status !== StatusEnum::DRAFT->value) {
            throw new InvalidArgumentException("Post {$post->uuid} is not a draft (status " . ($post->status ?? 'none') . ').');
        }
    }

    protected function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException("{$field} must be text.");
        }

        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} is longer than {$max} characters.");
        }

        return $value;
    }

    /**
     * An HTML string becomes one text block; a block list is kept when every block has a string `type`.
     *
     * @return list<array<string, mixed>>
     */
    protected function contentBlocks(mixed $content): array
    {
        if (is_string($content)) {
            return trim($content) === '' ? [] : [['type' => 'text', 'value' => $content]];
        }

        if (is_array($content) && array_is_list($content)) {
            foreach ($content as $block) {
                if (!is_array($block) || !is_string($block['type'] ?? null) || $block['type'] === '') {
                    throw new InvalidArgumentException('content blocks must each have a type.');
                }
            }

            return $content;
        }

        throw new InvalidArgumentException('content must be HTML text or a list of blocks.');
    }

    protected function languageId(string $code): int
    {
        $id = Language::query()
            ->where(static fn ($q) => $q->where('code', $code)->orWhere('path', $code)->orWhere('path_alias', $code))
            ->value('id');
        if ($id === null) {
            throw new InvalidArgumentException("Language {$code} was not found.");
        }

        return (int) $id;
    }

    protected function defaultLanguageId(): ?int
    {
        $id = Language::query()->where('default_web', true)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve categories (uuid or exact name, case-insensitive) to ids; the first is the primary category.
     *
     * @return non-empty-list<int>
     */
    protected function categoryIds(mixed $categories, ?int $languageId): array
    {
        $ids = [];
        foreach ($this->names($categories, 'category') as $ref) {
            if (Str::isUuid($ref)) {
                $id = Category::query()->where('uuid', $ref)->value('id');
            } else {
                $matches = Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($ref)])->pluck('language_id', 'id');
                if ($matches->count() > 1 && $languageId !== null) {
                    $matches = $matches->filter(static fn ($lang): bool => (int) $lang === $languageId);
                }
                if ($matches->count() > 1) {
                    throw new InvalidArgumentException("Category name {$ref} matches more than one category; use its uuid.");
                }
                $id = $matches->keys()->first();
            }

            if ($id === null) {
                throw new InvalidArgumentException("Category {$ref} was not found.");
            }
            $ids[] = (int) $id;
        }

        if ($ids === []) {
            throw new InvalidArgumentException('No category given.');
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    protected function currentCategoryIds(Post $post): array
    {
        return $post->editableCategories()->pluck((new Category())->qualifyColumn('id'))->map('intval')->all();
    }

    /**
     * Resolve post tags by name or slug; create missing ones only when allowed.
     *
     * @param list<string> $created names of the tags this call created
     * @return list<int>
     */
    protected function tagIds(mixed $tags, bool $createMissing, array &$created = []): array
    {
        $ids = [];
        foreach ($this->names($tags, 'tag') as $name) {
            if (mb_strlen($name) > 191) {
                throw new InvalidArgumentException("Tag {$name} is too long.");
            }
            $slug = (string) Str::slug($name);
            $tag = Tag::query()->ofType(Tag::TYPE_POST)
                ->where(static fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($slug !== '', static fn ($q) => $q->orWhere('slug', $slug)))
                ->first();

            if ($tag === null) {
                if (!$createMissing || $slug === '') {
                    throw new InvalidArgumentException("Tag {$name} does not exist and creating tags is not allowed here.");
                }
                $trashed = Tag::query()->onlyTrashed()->ofType(Tag::TYPE_POST)->where('slug', $slug)->first();
                if ($trashed !== null) {
                    $trashed->restore();
                    $tag = $trashed;
                } else {
                    $tag = Tag::query()->create(['name' => $name, 'slug' => $slug, 'type' => Tag::TYPE_POST]);
                }
                $created[] = $name;
            }

            $ids[] = (int) $tag->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    protected function names(mixed $value, string $field): array
    {
        $list = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($list)) {
            throw new InvalidArgumentException("{$field} must be a name, a uuid or a list of them.");
        }

        $names = [];
        foreach ($list as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                throw new InvalidArgumentException("{$field} entries must be text.");
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $names[] = $item;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<int> $ids
     */
    protected function syncCategories(Post $post, array $ids): void
    {
        app(AdminUpdateContentService::class)->syncCategory(new Request(['category_id' => $ids]), $post);
    }

    /**
     * @param list<int> $ids
     */
    protected function syncTags(Post $post, array $ids): void
    {
        app(AdminUpdateContentService::class)->syncTag(new Request(['tag_id' => $ids]), $post);
    }

    protected function setThumbnail(Post $post, mixed $uploadId): void
    {
        if (!is_numeric($uploadId) || (int) $uploadId < 1 || Upload::query()->whereKey((int) $uploadId)->doesntExist()) {
            throw new InvalidArgumentException('thumbnail_upload_id must be an existing upload.');
        }

        $uploadId = (int) $uploadId;
        $others = $post->images()->pluck((new Upload())->qualifyColumn('id'))->map('intval')
            ->reject(static fn (int $id): bool => $id === $uploadId)->values();

        $images = [['id' => $uploadId, 'main' => true]];
        foreach ($others as $id) {
            $images[] = ['id' => $id, 'main' => false];
        }

        $post->syncImages($images);
    }

    protected function publishTime(?string $publishAt): CarbonImmutable
    {
        if ($publishAt === null || trim($publishAt) === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($publishAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('publish_at must be a date and time.');
        }
    }

    /**
     * @param array<string, mixed> $before
     */
    protected function invalidate(array $before, Post $post): void
    {
        try {
            $this->cache->invalidateSnapshots($before, $this->cache->snapshot($post->fresh()));
        } catch (Throwable) {
            // Cache invalidation never undoes a committed write; caches expire on their own.
        }
    }
}
