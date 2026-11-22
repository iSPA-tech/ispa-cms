<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use App\Support\Modules\ModuleStatus;
use JsonException;
use App\Enums\StatusEnum;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Cms\Core\Models\TagData;
use Cms\Content\Services\Cache\ContentPublicTagCacheService;
use App\Enums\ContentStatusEnum;
use App\Events\TranslateContentRequest;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Content\Interfaces\ContentInterface;
use Cms\Core\Repositories\Interfaces\SeoInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Psr\Container\ContainerExceptionInterface;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Illuminate\Contracts\Foundation\Application;
use Cms\Core\Interfaces\CategoryTargetInterface;
use RuntimeException;

class AdminUpdateContentService
{
    /** @var bool */
    public bool $isDiscovery = false;

    /** @var UploadInterface|Application|mixed $upload */
    protected UploadInterface $upload;
    /** @var CategoryTargetInterface|Application|mixed $target */
    protected CategoryTargetInterface $target;
    /** @var ContentInterface|Application|mixed|null $content */
    protected ?ContentInterface $content = null;
    /** @var SeoInterface|Application|mixed $seo */
    protected SeoInterface $seo;
    /** @var SlugInterface|Application|mixed $slug */
    protected SlugInterface $slug;
    /** @var CategoryInterface|Application|mixed */
    protected CategoryInterface $category;
    /** @var LanguageInterface $language */
    protected LanguageInterface $language;

    public function __construct()
    {
        $this->upload = app(UploadInterface::class);
        $this->target = app(CategoryTargetInterface::class);
        $this->seo = app(SeoInterface::class);
        $this->slug = app(SlugInterface::class);
        $this->category = app(CategoryInterface::class);
        $this->language = app(LanguageInterface::class);
    }

    protected function content(): ContentInterface
    {
        if ($this->content instanceof ContentInterface) {
            return $this->content;
        }

        if (!$this->contentModuleEnabled() || !app()->bound(ContentInterface::class)) {
            throw new RuntimeException('Content module repository is not available.');
        }

        return $this->content = app(ContentInterface::class);
    }

    protected function contentModuleEnabled(): bool
    {
        // Legacy guard: this service still mixes Core update helpers with Content-owned work.
        // Move content/tag sync into the Content module before removing this boundary check.
        return ModuleStatus::isEnabled('Content');
    }

    /**
     * Update single content by language
     *
     * @param Request $request
     * @param mixed $model
     *
     * @throws ContainerExceptionInterface
     * @since 6.62.6 - 2023-01-28, 14:35 ICT
     * @author malayvuong
     */
    public function syncContent(Request $request, mixed $model): void
    {
        if (!$this->contentModuleEnabled()) {
            return;
        }

        $content = $request->input('content', []);
        $languageId = (int)($content['language_id'] ?? 1); //  Default language is Vietnamese
        $targetType = $this->contentTargetType($model);
        $save = $this->contentForTarget($content, $model, $targetType, $languageId);
        $save->fill(collect($content)->only($this->content()->model()->getFillable())->toArray());
        if (empty($save->name)) {
            $save->name = $request->input('name');
        }
        //  This is create new
        if (empty($save->uuid)) {
            $save->uuid = $this->content()->checkUnique('uuid', str()->uuid());
            $save->custom = [
                'name' => 'none',
                'description' => 'none',
                'content' => 'none',
                'audio' => 'none',
                'translated_time' => null,
                'converted_time' => null,
                'on_queue_time' => null,
            ];
        }
        $save->target_type = $targetType;
        $save->target_id = $model->id;
        $save->user_id = $model->user_id ?? auth()->id();
        $save->language_id = $languageId;
        //  Set content status
        $save->status = isset($content['status']) && $content['status'] === StatusEnum::PUBLISHED->value
            ? ContentStatusEnum::CONTENT_PUBLISHED->value : ContentStatusEnum::CONTENT_DRAFT->value;

        if (isset($content['is_confirmed'])) {
            $save->is_confirmed = $content['is_confirmed'];
            //  Replaced status
            $save->status = ContentStatusEnum::CONTENT_PUBLISHED->value;
        }
        if (!empty($request->input('published_at'))) {
            $save->published_at = now(get_timezone())->parse($request->input('published_at'))->toDateTimeString();
        }

        $save->save();
        $save->fresh();

        //  Check if model is Place or Item, then create new content by each languageId
        //  Then process to translate it to new language if it is confirmed
        if (
            strpos(get_class($model), 'Place', true) !== false
            || strpos(get_class($model), 'Item', true) !== false
        ) {
            $languages = $this->language->get([
                'select' => ['id'],
                'latest' => true,
                'where' => [
                    ['id', '!=', $save->language_id],
                ],
            ])->pluck('id')->toArray();

            foreach ($languages as $language) {
                //  Must get single content by language to update or create
                $languageContent = $this->content()->findBy([
                    'language_id' => $language,
                    'target_type' => $targetType,
                    'target_id' => $model->id,
                ]);
                //  Check if content is not exists, then create new
                if (empty($languageContent)) {
                    $languageContent = $this->content()->reset()->model();
                    $languageContent->fill([
                        'language_id' => $language,
                        'target_type' => $targetType,
                        'target_id' => $model->id,
                        'uuid' => $this->content()->checkUnique('uuid', str()->uuid()),
                        'status' => !empty($content['is_confirmed']) ? ContentStatusEnum::CONTENT_PENDING_TRANSLATION->value : ContentStatusEnum::CONTENT_DRAFT->value,
                    ]);
                }
                $languageContent->published_at = $save->published_at;
                $languageContent->save();
                $languageContent->fresh();

                //  Process to translate if it is confirmed
                if (isset($content['is_confirmed']) && $languageContent->status === ContentStatusEnum::CONTENT_PENDING_TRANSLATION->value) {

                    event(new TranslateContentRequest($save->id, $languageContent->id, ['name', 'description'], $model->uid, $request->input('token')));
                    event(new TranslateContentRequest($save->id, $languageContent->id, ['content'], $model->uid, $request->input('token')));

                    //  Then turn status of this content into on queue
                    $languageContent->status = ContentStatusEnum::CONTENT_ON_QUEUE_TRANSLATION->value;
                    $languageContent->save();
                }
            }
        }

        //  Save Audio
        if (isset($content['audios']) && count($content['audios']) > 0) {
            $save->syncAudios($content['audios']);
        }
    }

    private function contentTargetType(mixed $model): string
    {
        if (is_object($model) && method_exists($model, 'getMorphClass')) {
            $morphClass = $model->getMorphClass();

            if (is_string($morphClass) && $morphClass !== '') {
                return $morphClass;
            }
        }

        return get_class($model);
    }

    private function contentForTarget(array $content, mixed $model, string $targetType, int $languageId): mixed
    {
        $targetId = (int)$model->id;

        if (isset($content['id']) && (int)$content['id'] > 0) {
            $submitted = $this->content()->find((int)$content['id']);

            if ($this->contentMatchesTarget($submitted, $targetId, $targetType, $languageId)) {
                return $submitted;
            }
        }

        $existing = $this->content()->findBy([
            'language_id' => $languageId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        if ($existing) {
            return $existing;
        }

        return $this->content()->reset()->model();
    }

    private function contentMatchesTarget(mixed $content, int $targetId, string $targetType, int $languageId): bool
    {
        return $content
            && (int)$content->target_id === $targetId
            && (string)$content->target_type === $targetType
            && (int)$content->language_id === $languageId;
    }

    /**
     * Sync audios
     *
     * @param $model
     * @param array $data
     *
     * @return bool
     * @author vuongnm
     * @since 6.62.6 - 2023-01-31, 09:32 ICT
     */
    public function syncAudios($model, array $data): bool
    {
        if (count($data) > 0) {
            $this->upload->reset()->model()->whereIn('id', collect($data)->pluck('id'))->update(['target_id' => $model->id]);
        }

        return true;
    }

    /**
     * Update content
     *
     * @param Request $request
     * @param $model
     *
     * @author malayvuong
     * @since 6.47.5 - 2022-08-10, 01:11 ICT
     */
    public function syncContents(Request $request, $model): void
    {
        if (!$this->contentModuleEnabled()) {
            return;
        }

        foreach ($request->get('contents', []) as $ct) {
            if (isset($ct['name'])) {
                if (isset($ct['id']) && $ct['id'] > 0) {
                    $save = $this->content()->find($ct['id']);
                    $save->fill(collect($ct)->only($this->content()->model()->getFillable())->toArray())->save();
                    if (empty($save->name)) {
                        $save->name = request()?->input('name');
                    }
                } else {
                    $save = $this->content()->reset()->model();
                    $save->fill(collect($ct)->only($this->content()->model()->getFillable())->toArray());

                    $save->uuid = $this->content()->checkUnique('uuid', Str::uuid());
                    $save->target_type = $this->contentTargetType($model);
                    $save->target_id = $model->id;
                    $save->user_id = auth()->id();

                    $save->save();
                }
                $save->fresh();
                //  Save Audio
                if (isset($ct['audios']) && count($ct['audios']) > 0) {
                    $this->upload->reset()->model()->whereIn('id', collect($ct['audios'])->pluck('id'))->update(['target_id' => $save->id]);
                }
            }
        }
    }

    /**
     * Update Slug to content
     *
     * @param Request $request
     * @param $model
     *
     * @return bool
     * @author malayvuong
     * @since 6.19.6 - 2022-06-19, 15:52 ICT
     */
    public function updateSlug(Request $request, $model): bool
    {
        if ($request->has('slugs') && user()?->can('viewAny', $this->slug->model())) {
            $data = $request->get('slugs', []);

            if (empty($data)) {
                return false;
            }

            return (bool)$this->slug->storeSlug($model,
                (new SlugService())->create($data),
                $request->get('language_id'),
                config('setting.prefix.' . str(get_class($model))->replace('\\', '_'))
            );
        }

        return false;
    }

    /**
     * Update SEO to content
     *
     * @param Request $request
     * @param $model
     *
     * @return bool
     * @author malayvuong
     * @since 6.19.6 - 2022-06-19, 15:54 ICT
     */
    public function updateSeo(Request $request, $model): bool
    {
        if ($request->has('seo') && user()?->can('viewAny', $this->seo->model())) {
            $data = $request->get('seo', []);

            if (empty($data)) {
                return false;
            }

            return $this->seo->storeSeo($model, $data);
        }

        return false;
    }

    /**
     * Update Category for model
     *
     * @param Request $request
     * @param $model
     *
     * @return bool
     * @author malayvuong
     * @since 6.19.6 - 2022-06-19, 16:56 ICT
     * @last_updated 6.62.6 - 2023-01-18, 15:55 ICT
     */
    public function syncCategory(Request $request, $model): bool
    {
        if ($request->has('category_id') && count($request->get('category_id', [])) >= 0) {
            //  First step is delete all category belong this targetID
            $this->target->deleteBy([
                'target_type' => get_class($model),
                'target_id' => $model->id,
            ]);
            //  Init new data
            $data = [];
            foreach ($request->get('category_id', []) as $categories) {
                $category = is_array($categories) ? collect($categories)->last() : $categories;
                //  Get all children categories and pluck id to array
                $children = $this->category->getChildrenIds($category);
                //  Push to data
                foreach ($children as $key => $cateId) {
                    $data[] = [
                        'is_root' => $key === 0,
                        'is_leaf' => $key > 0,
                        'target_type' => get_class($model),
                        'target_id' => $model->id ?? 0,
                        'category_id' => $cateId,
                        'created_at' => now(),
                    ];
                }
            }
            //  Process to insert to db
            return (bool)$this->target->insert($data);
        }

        //  Remove all category belong this targetID
        return $this->target->deleteBy([
            'target_type' => get_class($model),
            'target_id' => $model->id,
        ]);
    }

    /**
     * Sync tags
     *
     * @param Request $request
     * @param $model
     *
     * @return bool|mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-06, 16:25 ICT
     */
    public function syncTag(Request $request, $model): mixed
    {
        if (!$this->contentModuleEnabled()) {
            return false;
        }

        $tagQuery = TagData::query()->where([
            'target_type' => get_class($model),
            'target_id' => $model->id,
        ]);
        $previousTagIds = (clone $tagQuery)->pluck('tag_id')->all();

        if ($request->has('tag_id') && count($request->get('tag_id', [])) >= 0) {
            //  First step is delete all category belong this targetID
            $tagQuery->delete();
            //  Init new data
            $data = [];
            $nextTagIds = [];
            // Only post tags can be attached to content; user-type ids in the request are ignored.
            $postTagIds = \Cms\Core\Models\Tag::query()->ofType(\Cms\Core\Models\Tag::TYPE_POST)
                ->whereIn('id', array_map('intval', (array) $request->get('tag_id', [])))->pluck('id')->all();
            foreach ($postTagIds as $tag) {
                $nextTagIds[] = (int) $tag;
                $data[] = [
                    'target_type' => get_class($model),
                    'target_id' => $model->id,
                    'tag_id' => $tag,
                    'created_at' => now(),
                ];
            }

            //  Process to insert to db
            $saved = $data === [] || (bool) TagData::query()->insert($data);
            $affectedTagIds = array_merge($previousTagIds, $nextTagIds);
            DB::afterCommit(static fn () => app(ContentPublicTagCacheService::class)->invalidatePosts($affectedTagIds));

            return $saved;
        }

        //  Remove all category belong this targetID
        $deleted = $tagQuery->delete();
        DB::afterCommit(static fn () => app(ContentPublicTagCacheService::class)->invalidatePosts($previousTagIds));

        return $deleted;
    }

    /**
     * Update upload by target
     *
     * @param $model
     * @param array $data
     *
     * @return bool
     * @author vuongnm
     * @since 6.62.6 - 2023-01-18, 16:08 ICT
     */
    public function syncImages($model, array $data): bool
    {
        if (count($data) > 0) {
            $this->upload->reset()->model()->whereIn('id', collect($data)->pluck('id'))->update(['target_id' => $model->id]);
        }

        return true;
    }

    /**
     * Sync meta data for model
     *
     * @param Request $request
     * @param mixed $model
     *
     * @return void
     * @throws JsonException
     * @since 6.62.6 - 2023-02-08, 11:57 ICT
     * @author vuongnm
     */
    public function syncMetaData(Request $request, mixed $model): void
    {
        $list = [];
        foreach ($request->input('meta', []) as $name => $value) {
            if (empty($value) || !isset($value['attr_id']) || !is_array($value)) {
                continue;
            }
            $valueId = $value['value']['id'] ?? null;
            if (!is_array($value['value']) || in_array($value['type'] ?? '', ['text', 'string', 'date', 'url'])) {
                $valueId = optional($this->setMetaValue($value, $model, ($value['type'] ?? '') === 'date', $name))->id;
            }

            if (!$valueId) {
                continue;
            }
            $list[] = [
                'meta_class_id' => $value['class_id'],
                'meta_attribute_id' => $value['attr_id'],
                'value_type' => get_value_class($value['type']),
                'value_id' => $valueId,
            ];
        }

        $model->meta()->delete();
        $model->meta()->createMany($list);
    }

    /**
     * Set meta value
     *
     * @param mixed $value
     * @param mixed $model
     * @param bool $isDate
     * @param string|null $name
     *
     * @return mixed
     * @throws JsonException
     * @since 6.62.6 - 2023-02-08, 11:55 ICT
     * @author vuongnm
     */
    public function setMetaValue(mixed $value, mixed $model, bool $isDate = false, string $name = null): mixed
    {
        if (!isset($value['type'])) {
            return null;
        }

        $valueId = $value['value']['id'] ?? $value['id'] ?? $model->id;
        $isValueId = !empty($value['value']['id']);
        //  Check if value['value'] is an array => Update value of Value
        if ($value['value'] && is_array($value['value'])) {
            $val = (new (get_value_class($value['type']))())
                ->where(
                    $value['id'] ? 'id' : 'user_id',
                    $valueId
                )->first();

            if (!$val) {
                $val = new (get_value_class($value['type']))();
            }
        } else {
            //  Create new value
            $val = new (get_value_class('string'))();
        }


        if ($value['type'] === 'text') {
            $val->value = $isValueId ? $value['value']['value'] : $value['value'];
        } else if ($isDate) {
            $dateValue = $isValueId ? $value['value']['value'] : $value['value'];

            $val->value = now()->parse($dateValue)->setTimezone(get_timezone());
        } else {
            $val->value = $isValueId ? $value['value']['value'] : $value['value'];
        }
        $val->user_id = auth()->id();

        if (
            !$isDate &&
            (is_countable($val->value) || is_object($val->value) || is_array($val->value) ||
                in_array($name, ['address_object', 'hashtag']))
        ) {
            $val->value = json_encode($val->value, JSON_THROW_ON_ERROR);

            $val->label = '';
        } else {
            $val->label = $isValueId ? ($value['value']['label'] ?? $value['value']['value']) : $value['value'];
        }

        //  Trim label for 250 characters
        $val->label = str($val->label)->limit(250);

        $val->save();

        return $val;
    }

    /**
     * Check content exists by language
     *  if content not exists by language_id, create new content
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-07-27, 09:17 ICT
     */
    public function checkContentByLanguage(mixed $model, Collection $languages): void
    {
        if (!$this->contentModuleEnabled()) {
            return;
        }

        $contents = collect($model->contents);
        $targetType = $this->contentTargetType($model);

        //  check content exists by language_id
        $languages->each(function ($language) use ($contents, $model, $targetType) {
            $content = $contents->where('language_id', $language->id)->first();
            if (!$content) {
                $model->contents()->create([
                    'uuid' => $this->content()->checkUnique('uuid', str()->uuid()),
                    'name' => '',
                    'language_id' => $language->id,
                    'target_type' => $targetType,
                    'target_id' => $model->id,
                    'custom' => [
                        'name' => 'none',
                        'description' => 'none',
                        'content' => 'none',
                        'audio' => 'none',
                        'translated_time' => null,
                        'converted_time' => null,
                        'on_queue_time' => null,
                    ],
                    'content' => [[
                        'type' => 'text',
                        'value' => '',
                    ]],
                    'user_id' => $model->user_id ?? auth()->id() ?? 1,
                    'status' => $model->is_confirmed ? ContentStatusEnum::CONTENT_PENDING_TRANSLATION->value : ContentStatusEnum::CONTENT_DRAFT->value,
                    'published_at' => now(),
                ]);
            }
        });
    }
}
