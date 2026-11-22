<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Admin;

use App\Events\SendEmail;
use Cms\Core\Responses\AppResponse;

use Exception;
use JsonException;
use App\Helpers\Crawler\ArticleCrawler;
use App\Clients\CmsClient;
use App\Constants\Constant;
use Illuminate\Http\Request;
use App\Clients\OpenAIClient;
use Cms\Content\Jobs\CountPostTypeJob;
use Cms\Content\Jobs\GetPostIdsByTypeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Pipeline;
use GuzzleHttp\Exception\GuzzleException;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Models\Upload;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Cms\Core\Services\Agentic\OpenAiAgenticAiProvider;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Services\Widget\LegacyWidgetReferenceResolver;
use Cms\Content\Constants\ContentTableConstant as Tables;
use Cms\Content\Http\Resources\Post\PostCollection;
use App\Services\AdminUpdateContentService;
use Cms\Content\Http\Requests\Post\StorePostRequest;
use Cms\Content\Events\PostCrawlDataSubmitted;
use Cms\Content\Http\Requests\Post\UpdatePostRequest;
use Cms\Content\Http\Resources\Post\PostEditResource;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Content\Http\Requests\Post\PostCrawlDataRequest;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Content\Models\Post;
use Cms\Content\Events\AdminPostSaving;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Content\Http\Requests\Post\QuickUpdatePostRequest;
use Cms\Content\Http\Requests\Post\PostSaveCrawlDataRequest;
use App\Services\Pipelines\DetachImagesInsideContent;
use App\Services\Pipelines\AttachImagesInsideContent;
use App\Services\Upload\AttachAndDetachImages;
use Throwable;

/**
 * Admin API controller for managing blog posts and articles.
 *
 * Provides full lifecycle management for posts: listing by type, creation, editing,
 * updating, soft-deletion, restoration, quick status updates, full-text search,
 * URL crawling, and content translation (via Google or ChatGPT). Handles image
 * syncing, slug generation, SEO metadata, category/tag associations, and scoped
 * post cache invalidation after successful mutations.
 *
 * @see PostInterface
 * @see CategoryInterface
 * @see AdminController
 * @package Cms\Content\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostController extends AdminController
{
    /** @var string $modelClass */
    protected string $modelClass;

    /**
     * Controller constructor
     *
     * @param CategoryInterface $category
     * @param CategoryTargetInterface $target
     * @param PostInterface $model
     * @param CmsClient $client
     * @param OpenAIClient $openAiClient
     *
     * @author malayvuong
     * @since  5.0.0
     */
    public function __construct(
        protected CategoryInterface       $category,
        protected CategoryTargetInterface $target,
        protected PostInterface           $model,
        protected CmsClient               $client,
        protected OpenAIClient            $openAiClient
    )
    {
        $this->modelClass = get_class($model->model());

        parent::__construct();
    }

    /**
     * Search product
     *
     * @return JsonResponse|Response
     * @author malayvuong
     * @since 6.62.6 - 2022-09-25, 10:11 ICT
     */
    public function search()
    {
        $data = $this->model->get([
            'select' => [
                'name', 'id', 'type',
            ],
            'when' => [
                [
                    request('query') && request('query') !== '',
                    function ($q) {
                        $q->whereFulltext(['name', 'description', 'content'], request('query'));
                    },
                ],
                [
                    !empty(request('type', 'all')) && request('type', 'all') !== 'all',
                    function ($q): void {
                        $q->where('type', request('type'));
                    },
                ],
            ],
            'with' => [
                'slugs',
            ],
            'page' => request('limit', 20),
            'latest' => true,
        ]);

        return ires()->data(new PostCollection($data));
    }

    /**
     * Save Crawl data
     *
     * @param  PostSaveCrawlDataRequest $request
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-07-27, 16:16 ICT
     */
    public function saveCrawlData(PostSaveCrawlDataRequest $request): mixed
    {
        //  Nobody on this site turns a crawled article into a post: refuse instead of answering a success that saves nothing
        if (! self::crawledArticlesCanBeSaved()) {
            return ires()->error()->message('Saving crawled articles is not available on this site.');
        }

        //  Check if the request already exists in the database
        $isExists = $this->model->model()->where('config->crawler_from', $request->input('url'))->exists();

        if ($isExists) {
            return ires()->error()->message('This article has already been queued for crawling or has already been crawled!');
        }

        //  Hand the article to the project or module that owns the crawl pipeline (it listens to this event)
        event(new PostCrawlDataSubmitted($request->all()));

        return ires()->data([])->message('Save success!');
    }

    /**
     * Translate post content from request via Google or ChatGPT.
     *
     * @param  PostCrawlDataRequest  $request  Request with original content and translation type
     * @return mixed                           Translated title, description, and contents
     *
     * @throws GuzzleException
     * @throws JsonException
     * @since 7.160.1175 - 2024-07-22, 21:50 ICT
     * @author malayvuong
     */
    public function translate(PostCrawlDataRequest $request): mixed
    {
        //  Process to translate original data into Vietnamese
        $original = $request->input('original', []);

        if (empty($original) || empty($original['title'])) {
            return ires()->error()->message(__('mgs.data.404'));
        }

        if ($request->input('type', 'google') === 'chatGPT') {
            $prompt = [
                // chatgpt-4o-latest was shut down on 2026-02-17; the admin task row can override the model.
                'model' => 'gpt-5.6-luna',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => self::translateSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => '',
                    ],
                ],
                'temperature' => 0.5,
            ];

            //  Combine title and description into one json for one request
            $combinedContent = [
                'title' => $original['title'],
                'description' => $original['description'],
            ];
            $combinedPrompt = $prompt;
            $combinedPrompt['messages'][1]['content'] = json_encode($combinedContent, JSON_THROW_ON_ERROR);

            $combinedRun = app(AiTaskRuntime::class)->run(
                'content_post_translate_title_desc',
                $combinedPrompt,
                new OpenAiAgenticAiProvider($this->openAiClient)
            );
            $combinedTranslated = json_decode(
                $combinedRun['content'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $data = [
                'title' => $combinedTranslated['title'] ?? '',
                'description' => $combinedTranslated['description'] ?? '',
            ];
            //  Translate content by combine text into one json for one or more request by each (8192 x 4) characters
//            $translated = [];
//            $contentPrompt = $prompt;
//            $onlyTextContent = [];
//            //  Get only text content in original data with keeping the key in original contents
//            foreach ($original['contents'] as $key => $ct) {
//                if ($ct['type'] === 'text') {
//                    $onlyTextContent['key_' . $key] = $ct['value'];
//                }
//            }
//            //  If total characters of text content is less than (8192 x 4) characters
//            if (strlen($onlyTextContentJson = json_encode($onlyTextContent, JSON_THROW_ON_ERROR)) < (8192 * 4)) {
//                $contentPrompt['messages'][1]['content'] = $onlyTextContentJson;
//                $translated = json_decode(
//                    $this->openAiClient->getContent($this->openAiClient->createCompletion($contentPrompt)),
//                    true,
//                    512,
//                    JSON_THROW_ON_ERROR
//                );
//            } else {
//                //  Split $onlyTextContent into multiple jsons with each json has max (8192 x 4) characters
//                $maxChars = 8192 * 4;  // 32,768 characters max per array
//                $splitTextContent = [];  // This will store all the sub-arrays
//                $currentChunk = [];  // Current sub-array
//                $currentLength = 0;  // Track character count in current sub-array
//
//                // Iterate over $onlyTextContent
//                foreach ($onlyTextContent as $key => $text) {
//                    $textLength = strlen($text);  // Get the length of the text value
//
//                    // Check if adding this text would exceed the max characters
//                    if ($currentLength + $textLength > $maxChars) {
//                        // Save the current chunk if it exceeds the max limit
//                        $splitTextContent[] = $currentChunk;
//                        // Reset the current chunk and length counter
//                        $currentChunk = [];
//                        $currentLength = 0;
//                    }
//
//                    // Add text to the current chunk
//                    $currentChunk[$key] = $text;
//                    $currentLength += $textLength;
//                }
//
//                // Add the last chunk if it's not empty
//                if (!empty($currentChunk)) {
//                    $splitTextContent[] = $currentChunk;
//                }
//
//                //  Based on $splitTextContent, translate each json content
//                foreach ($splitTextContent as $textContent) {
//                    $contentPrompt['messages'][1]['content'] = json_encode($textContent, JSON_THROW_ON_ERROR);
//                    $translated = [
//                        ...$translated,
//                        ...json_decode(
//                            $this->openAiClient->getContent($this->openAiClient->createCompletion($contentPrompt)),
//                            true,
//                            512,
//                            JSON_THROW_ON_ERROR
//                        ),
//                    ];
//                }
//            }
//            //  Map the translated content to the original contents
//            $newTranslatedContent = [];
//            foreach ($original['contents'] as $key => $ct) {
//                if ($ct['type'] === 'text') {
//                    $newTranslatedContent[$key] = [
//                        'type' => 'text',
//                        'value' => $translated['key_' . $key] ?? '',
//                    ];
//                } else {
//                    $newTranslatedContent[$key] = $ct;
//                }
//            }
            $data['contents'] = $original['contents'];

            return ires()->data($data);
        }

        $data = [
            'title' => $this->client->translate('vi', $original['title']),
            'description' => $this->client->translate('vi', $original['description']),
        ];
        //  Translate content
        $translated = [];

        foreach ($original['contents'] as $key => $ct) {
            if ($ct['type'] === 'text') {
                $translated[$key] = $this->client->translate('vi', $ct['value']);
            }
        }

        $newContent = [];
        foreach ($original['contents'] as $key => $ct) {
            if ($ct['type'] === 'text') {
                $newContent[$key] = [
                    'type' => 'text',
                    'value' => $translated[$key],
                ];
            } else {
                $newContent[$key] = $ct;
            }
        }

        $data['contents'] = $newContent;

        return ires()->data($data);
    }

    /**
     * Crawl data
     *
     * Reads the article at the URL: through the extractor a module registered for its host, else the generic one.
     *
     * @param PostCrawlDataRequest $request
     * @param ArticleCrawler $crawler
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-06-30, 13:39 ICT
     */
    public function crawlData(PostCrawlDataRequest $request, ArticleCrawler $crawler): mixed
    {
        return ires()->data([
            ...$crawler->crawl((string) $request->input('url')),
            //  The page shows Save only where a module or project saves crawled articles
            'can_save' => self::crawledArticlesCanBeSaved(),
        ]);
    }

    /**
     * True when a module or project listens to PostCrawlDataSubmitted (it owns turning a crawled article into a post).
     */
    private static function crawledArticlesCanBeSaved(): bool
    {
        return Event::hasListeners(PostCrawlDataSubmitted::class);
    }

    /**
     * Quick Update Post
     *
     * @param string $uuid
     * @param QuickUpdatePostRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-25, 21:58 ICT
     */
    public function quickUpdate(string $uuid, QuickUpdatePostRequest $request): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $cacheBefore = $this->postCacheSnapshot($model);

        $model->status = $request->input('status');
        event(new AdminPostSaving($model));

        DB::beginTransaction();
        try {
            $model->save();
            $cacheAfter = $this->postCacheSnapshot($model);
            $this->schedulePostCacheInvalidation($cacheBefore, $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.posts')]));
    }

    /**
     * Show the item
     *
     * @param string $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-23, 11:38
     */
    public function show(string $id): mixed
    {
        if (!$id) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $id], ['*'], [
            'images:id,target_id,name,path,mime_type,category', 'author:id,name,username',
            'categories:id,name', 'language:id,name,flag',
            'slugs:target_id,slug', 'seo:target_id,title,description,hashtag,facebook,twitter,custom,config',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('view', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->setAttribute('convert_content', view('components.contents-view', ['content' => $model->content])->render());

        return ires()->data($model);
    }

    /**
     * Delete a post
     *
     * @param string $uuid
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 14:15
     */
    public function destroy(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['id']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheBefore = $this->postCacheSnapshot($model);

        Db::beginTransaction();

        try {
            $model->delete();
            $cacheAfter = $this->postCacheSnapshot($model, true);
            $this->schedulePostCacheInvalidation($cacheBefore, $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }
        DB::commit();
        //  Update cache
        dispatch(new CountPostTypeJob());
        dispatch(new GetPostIdsByTypeJob());

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.posts')]));
    }

    /**
     * Restore product from trash
     *
     * @param string $uuid
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-12-13, 21:23 ICT
     */
    public function restore(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->model()->withTrashed()->where('uuid', $uuid)->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('restore', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheBefore = $this->postCacheSnapshot($model, true);

        DB::beginTransaction();
        try {
            if (!$model->restore()) {
                DB::rollBack();

                return ires()->error()->message(__('mgs.failed.restore', ['name' => __('nav.a.posts')]));
            }

            $cacheAfter = $this->postCacheSnapshot($model);
            $this->schedulePostCacheInvalidation($cacheBefore, $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        return ires()->message(__('mgs.ok.restore', ['name' => __('nav.a.posts')]));
    }

    /**
     * @param string $id
     * @param UpdatePostRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 13:54
     */
    public function update(string $id, UpdatePostRequest $request): mixed
    {
        if (!$id) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        if (is_numeric($id)) {
            $model = $this->model->find((int) $id);
        } else {
            $model = $this->model->findBy(['uuid' => $id], ['*']);
        }

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheBefore = $this->postCacheSnapshot($model);

        $model = $this->fill($model);

        event(new AdminPostSaving($model));

        // Block publish if image blocks still have raw URLs (not yet downloaded)
        $publishBlockError = $this->validateImageBlocksBeforePublish($model);
        if ($publishBlockError) {
            return ires()->error()->message($publishBlockError);
        }

        DB::beginTransaction();
        $qaAnswerEmailPayload = [];

        try {
            $model->save();

            //  Update Content
            $this->contentUpdate($request, $model);
            //  Sync images
            $model->syncImages($request->input('images', []));
            //  Sync Attachments
            $model->syncAttachments($request->input('attachments', []));
            //  Sync slug
            $model->syncSlug([
                ...$request->input('slugs', []), 'language_id' => $model->language_id,
                'type' => $model->type,
            ]);
            //  Sync SEO
            $model->syncSeo($request->input('seos', []));
            $this->warmPostCoverVariants($model);
            $this->warmPostContentImageVariants($model);

            $qaAnswerEmailPayload = $this->buildQaAnswerEmailPayload($model);

            $cacheAfter = $this->postCacheSnapshot($model);
            $this->schedulePostCacheInvalidation($cacheBefore, $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        Db::commit();

        if (!empty($qaAnswerEmailPayload)) {
            $emailPost = $this->model->find((int) $model->id);
            $qaAnswerEmailPayload = $this->buildQaAnswerEmailPayload($emailPost ?: $model);
            if (!empty($qaAnswerEmailPayload)) {
                event(new SendEmail($qaAnswerEmailPayload));
                $this->markQaAnswerEmailSent($emailPost ?: $model);
            }
        }

        return ires()->data(sprintf(Constant::CACHE_KEY_DETAIL, Constant::CACHE_KEY_POSTS, $model->slug))->message(__('mgs.ok.update', ['name' => __('nav.a.posts')]));
    }

    /**
     * Build Q&A answer reply email payload.
     *
     * @param  mixed  $post
     * @return array|null
     */
    protected function buildQaAnswerEmailPayload($post): ?array
    {
        if (!is_object($post) || ($post->type ?? null) !== 'qa') {
            return null;
        }

        $content = is_array($post->content) ? $post->content : [];
        $questionBlock = $this->findPostContentBlock($content, 'question');
        $answerBlock = $this->findPostContentBlock($content, 'answer');

        $questionEmail = trim((string) ($questionBlock['question_email'] ?? ''));
        if (!$questionEmail || !filter_var($questionEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $sendEmail = (bool) ($answerBlock['send_email'] ?? false);
        $emailSentAt = trim((string) ($answerBlock['email_sent_at'] ?? ''));
        $answerContent = trim((string) ($answerBlock['answer_content'] ?? $answerBlock['content'] ?? ''));
        if (!$sendEmail || $answerContent === '' || $emailSentAt !== '') {
            return null;
        }

        $questionName = trim((string) ($questionBlock['question_name'] ?? ''));
        $questionContent = trim((string) ($questionBlock['question_content'] ?? $questionBlock['content'] ?? ''));
        $questionPostId = (int) ($post->id ?? 0);

        return [
            'email' => $questionEmail,
            'subject' => 'Q&A reply: ' . ($post->name ?? ''),
            'user_id' => user()?->id,
            'content' => $this->renderQaAnswerEmailContent(
                $questionName,
                $questionEmail,
                $questionContent,
                $answerContent,
            ),
            'target_type' => Post::class,
            'target_id' => $questionPostId > 0 ? $questionPostId : null,
            'qa_post_type' => Post::class,
            'qa_post_id' => $questionPostId > 0 ? $questionPostId : null,
        ];
    }

    /**
     * Mark Q&A answer as emailed to prevent duplicated sends.
     *
     * @param mixed $post
     * @return void
     */
    protected function markQaAnswerEmailSent($post): void
    {
        if (!is_object($post)) {
            return;
        }

        $content = $post->content;
        if (is_string($content)) {
            try {
                $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return;
            }
        }

        if (!is_array($content)) {
            return;
        }

        $hasChanged = false;
        foreach ($content as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['block_type'] ?? null) !== 'answer' && ($block['type'] ?? null) !== 'answer') {
                continue;
            }

            $content[$index]['send_email'] = false;
            $content[$index]['email_sent_at'] = now()->toISOString();
            $hasChanged = true;
            break;
        }

        if (!$hasChanged) {
            return;
        }

        $post->content = $content;
        $post->save();
    }

    /**
     * Find a content block by block type.
     *
     * @param array $content
     * @param string $type
     * @return array
     */
    protected function findPostContentBlock(array $content, string $type): array
    {
        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['block_type'] ?? null) === $type || ($item['type'] ?? null) === $type) {
                return $item;
            }
        }

        return [];
    }

    /**
     * Render email body for Q&A answer.
     *
     * @param string $questionName
     * @param string $questionEmail
     * @param string $questionContent
     * @param string $answerContent
     * @return string
     */
    protected function renderQaAnswerEmailContent(
        string $questionName,
        string $questionEmail,
        string $questionContent,
        string $answerContent
    ): string {
        return sprintf(
            '<p>Xin chào %s</p><p><strong>Câu hỏi:</strong></p><div style="white-space: pre-wrap;">%s</div><p><strong>Trả lời:</strong></p><div style="white-space: pre-wrap;">%s</div><p>Gửi từ website.</p><p>Email: %s</p>',
            e($questionName ?: 'Quý bạn đọc'),
            e($questionContent),
            e($answerContent),
            e($questionEmail),
        );
    }

    /**
     * Fill the model
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-23, 2:51
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }
        $request = request();

        $model->fill(request()->only($model->getFillable()));

        if (is_array($model->content)) {
            $model->content = app(LegacyWidgetReferenceResolver::class)->enrichBlocks($model->content);
        }

        if (request('parent_id', 0) < 1) {
            $model->parent_id = null;
        }
        if (request('language_id', 0) < 1) {
            $model->language_id = null;
        }
        if (empty($model->user_id) || $model->user_id < 1) {
            $model->user_id = user()->id;
        }

        $model->category_id = $this->resolveCategoryId($request->input('category_id', null));

        //  Set & check unique column
        if (is_null($model->id) || $model->id < 1 || empty($model->uuid)) {
            /** Set uuid string */
            $model->uuid = $this->model->checkUnique('uuid', str()->uuid());
        }

        //  Remove unused widget content
//        $model->content = WidgetQueryService::removeUnusedData($model->content);

        return $model;
    }

    /**
     * Normalize category_id input to the last selected category.
     *
     * Supported payloads:
     * - `category_id = 5`
     * - `category_id = "5"`
     * - `category_id = [1, 5]`
     * - `category_id = [[1, 5]]`
     */
    protected function resolveCategoryId($categoryId): ?int
    {
        if (is_null($categoryId)) {
            return null;
        }

        if (is_array($categoryId)) {
            if (count($categoryId) < 1) {
                return null;
            }

            $flattenedCategory = collect($categoryId)
                ->filter(fn($value) => !is_null($value) && $value !== '')
                ->last();

            if (is_array($flattenedCategory)) {
                $flattenedCategory = collect($flattenedCategory)
                    ->filter(fn($value) => !is_null($value) && $value !== '')
                    ->last();
            }

            $categoryId = $flattenedCategory;
        }

        if (is_null($categoryId) || $categoryId === '') {
            return null;
        }

        if (!is_numeric((string)$categoryId)) {
            return null;
        }

        return (int) $categoryId;
    }

    /**
     * model Update
     *
     * @param Request $request
     * @param $model
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-24, 17:29
     * @last_updated 6.19.6 - 2022-05-01, 21:06 ICT - by malayvuong
     * - change request from `slug` to `slugs`
     */
    protected function contentUpdate(Request $request, $model): void
    {
        $service = new AdminUpdateContentService();
        $service->syncCategory($request, $model);
        $service->syncTag($request, $model);

        //  Attach and detach image inside content using Concurrency Service
//        Concurrency::defer(static function () use ($model) {
        // Using app pipeline to detach before attach
        Pipeline::send($model)->through([
            DetachImagesInsideContent::class,
            AttachImagesInsideContent::class,
        ])->thenReturn();
//        });
    }

    protected function removeCache(mixed $model): void
    {
        $snapshot = $this->postCacheSnapshot($model, true);

        $this->postCacheInvalidator()->invalidateSnapshots($snapshot, []);
    }

    protected function schedulePostCacheInvalidation(array $before, array $after): void
    {
        DB::afterCommit(static function () use ($before, $after): void {
            app(ContentPostCacheInvalidationService::class)->invalidateSnapshots($before, $after);
        });
    }

    protected function postCacheSnapshot(mixed $model, bool $withTrashed = false): array
    {
        $post = $this->freshPostForCacheSnapshot($model, $withTrashed);

        return $this->postCacheInvalidator()->snapshot($post);
    }

    protected function freshPostForCacheSnapshot(mixed $model, bool $withTrashed = false): ?Post
    {
        $id = $model instanceof Post ? $model->getKey() : $model;

        if (empty($id)) {
            return null;
        }

        $query = Post::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->with([
                'slugs:id,slug,target_id,target_type,language_id',
                'categories:id,parent_id,language_id,type,status',
                'images:id',
            ])
            ->find((int) $id);
    }

    protected function postCacheInvalidator(): ContentPostCacheInvalidationService
    {
        return app(ContentPostCacheInvalidationService::class);
    }

    /**
     * Get edit post
     *
     * @param string $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 13:45
     */
    public function edit(string $id): mixed
    {
        if (!$id) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy(['uuid' => $id], ['*'], [
            'slugs', 'seos', 'images', 'language', 'editableCategories', 'attachments', 'tags',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (empty($model->content)) {
            $model->content = [
                ['type' => 'text', 'value' => ''],
            ];
        }

        $model->tag_id = !empty($model->tags) ? $model->tags->pluck('id')->toArray() : [];

        return ires()->data(new PostEditResource($model));
    }

    /**
     * Get the post list by type
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 12:24
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $postType = (string) request('type', 'post');
        $data = $this->model->index($postType, $this->category);

        if ($this->isQaListType($postType)) {
            $data = $this->normalizeQaListData($data);
        }

        return ires()->data(new PostCollection($data));
    }

    protected function isQaListType(string $postType = ''): bool
    {
        if (!$postType) {
            return false;
        }

        if ($postType === 'qa') {
            return true;
        }

        if (!str($postType)->contains(',')) {
            return false;
        }

        return in_array('qa', array_map('trim', explode(',', $postType)), true);
    }

    protected function normalizeQaListData(mixed $data): mixed
    {
        if (method_exists($data, 'getCollection')) {
            $data->setCollection($data->getCollection()->map(function ($post) {
                return $this->normalizeQaItem($post);
            }));

            return $data;
        }

        if ($data instanceof \Illuminate\Support\Collection) {
            return $data->map(function ($post) {
                return $this->normalizeQaItem($post);
            });
        }

        return $data;
    }

    protected function normalizeQaItem(mixed $post): mixed
    {
        if (!is_object($post)) {
            return $post;
        }

        $content = $post->content;
        if (is_string($content)) {
            try {
                $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $content = [];
            }
        }

        $content = is_array($content) ? $content : [];
        $questionBlock = $this->findPostContentBlock($content, 'question');
        $answerBlock = $this->findPostContentBlock($content, 'answer');

        $post->question_name = trim((string) (
            $questionBlock['question_name'] ??
            $questionBlock['name'] ??
            $questionBlock['asker_name'] ??
            ''
        ));
        $post->question_email = trim((string) (
            $questionBlock['question_email'] ??
            $questionBlock['asker_email'] ??
            $questionBlock['email'] ??
            ''
        ));
        $post->has_answer = (int) (
            trim((string) ($answerBlock['answer_content'] ?? $answerBlock['content'] ?? '')) !== '' ||
            trim((string) ($answerBlock['responder_name'] ?? '')) !== '' ||
            trim((string) ($answerBlock['answered_at'] ?? '')) !== ''
        );

        unset($post->content);

        return $post;
    }

    /**
     * Store new post
     *
     * @param StorePostRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 12:5
     * @last_updated 6.19.6 - 2022-05-01, 21:05 ICT - by malayvuong
     * - change commit position
     * - update docblock
     */
    public function store(StorePostRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can(str(Tables::POSTS)->singular() . '.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model->save();

            //  Update Content
            $this->contentUpdate($request, $model);
            //  Sync images
            $model->syncImages($request->input('images', []));
            //  Sync Attachments
            $model->syncAttachments($request->input('attachments', []));
            //  Sync slug
            $model->syncSlug([
                ...$request->input('slugs', []), 'language_id' => $model->language_id,
                'type' => $model->type,
            ]);
            //  Sync SEO
            $model->syncSeo($request->input('seos', []));
            $this->warmPostCoverVariants($model);
            $this->warmPostContentImageVariants($model);

            $cacheAfter = $this->postCacheSnapshot($model);
            $this->schedulePostCacheInvalidation([], $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        Db::commit();

        return ires()->message(__('mgs.ok.save', ['name' => __('nav.a.posts')]));
    }

    /**
     * Validate that image blocks don't contain raw URLs before publishing.
     *
     * Only applies to crawled posts (old_value > 100 chars in any text block).
     * Prevents publishing posts where images haven't been downloaded yet.
     *
     * @param mixed $model Post model after fill
     * @return string|null Error message if validation fails, null if OK
     */
    private function validateImageBlocksBeforePublish(mixed $model): ?string
    {
        // Only check when publishing (status = published)
        if (($model->status ?? '') !== 'status_published') {
            return null;
        }

        $content = $model->content;
        if (! is_array($content)) {
            return null;
        }

        // Only apply to crawled posts — check if any text block has old_value > 100 chars
        $isCrawledPost = false;
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text' && mb_strlen($block['old_value'] ?? '') > 100) {
                $isCrawledPost = true;
                break;
            }
        }

        if (! $isCrawledPost) {
            return null;
        }

        // Check image blocks for raw URL strings
        $undownloaded = 0;
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'image' || ! is_array($block['value'] ?? null)) {
                continue;
            }
            foreach ($block['value'] as $img) {
                if (is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) {
                    $undownloaded++;
                }
            }
        }

        if ($undownloaded > 0) {
            return "Cannot publish: {$undownloaded} image(s) have not been downloaded yet. Please wait for image processing to complete or re-parse the content.";
        }

        return null;
    }

    protected function warmPostCoverVariants(Post $post): void
    {
        if ($post->exists && method_exists($post, 'unsetRelation')) {
            $post->unsetRelation('image');
        }

        $image = $post->relationLoaded('image') ? $post->getRelation('image') : ($post->image ?? null);

        if (!$image instanceof Upload) {
            return;
        }

        try {
            app(ImageVariantService::class)->ensureContext($image, 'post.cover');
        } catch (Throwable $e) {
            Log::warning('Post cover variant warmup failed', [
                'post_id' => $post->id,
                'upload_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function warmPostContentImageVariants(Post $post): void
    {
        $imageIds = AttachAndDetachImages::imageIdsFromContent($post->content ?? []);

        if (empty($imageIds)) {
            return;
        }

        Upload::query()
            ->whereIn('id', $imageIds)
            ->get()
            ->each(function (Upload $upload) use ($post): void {
                try {
                    app(ImageVariantService::class)->ensureContext($upload, 'post.content');
                } catch (Throwable $e) {
                    Log::warning('Post content image variant warmup failed', [
                        'post_id' => $post->id,
                        'upload_id' => $upload->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    /**
     * System prompt of the ChatGPT post translation: the one a module registered in
     * `content.translate_system_prompt`, else a neutral translator prompt.
     */
    private static function translateSystemPrompt(): string
    {
        $prompt = config('content.translate_system_prompt');

        return is_string($prompt) && trim($prompt) !== ''
            ? $prompt
            : 'You are a professional translator. Translate the JSON values into Vietnamese, keep the JSON keys and structure unchanged, keep technical details accurate and the tone clear and friendly. Return only the JSON.';
    }
}
