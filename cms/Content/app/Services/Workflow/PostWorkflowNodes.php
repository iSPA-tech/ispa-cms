<?php

declare(strict_types=1);

namespace Cms\Content\Services\Workflow;

use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use Cms\Content\Services\Agentic\PostDraftArtifactApplier;
use Cms\Content\Services\Agentic\PostProposalToolService;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Workflows\Nodes\Attributes\WorkflowNode;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Content workflow nodes: save a draft post, publish a draft, set a draft's categories and tags.
 *
 * Run by Core's ModuleNodeRunner, which checks the node permission against the user the run acts for and validates the
 * config before calling a method here. Extra rights a single call needs (updating an existing post, creating tags) are
 * checked here against the same user. Text fields accept expressions (`vars.*`, `steps.*`; literal text in quotes).
 *
 * save_post_draft returns a `review` envelope (artifact type `content_post_draft_patch`) so an approval step placed
 * after it can show the draft as a before/after diff; approving that envelope re-applies the (possibly edited) draft
 * through PostDraftArtifactApplier.
 *
 * @package Cms\Content\Services\Workflow
 * @since   2026-11-23
 */
class PostWorkflowNodes
{
    private const NULLABLE_TEXT = ['type' => ['string', 'null'], 'x-expr' => true];
    private const NAMES = ['type' => ['array', 'string', 'null'], 'x-expr' => true, 'items' => ['type' => 'string']];

    public function __construct(protected PostDraftWriter $writer)
    {
    }

    #[WorkflowNode(
        type: 'content.save_post_draft',
        group: 'cms',
        safety: ['internal_write'],
        // drafts are not reviewed, only the release is. A draft is never public (the writer refuses
        // a published post and sets no publish date), so the taint lint asks for the approval before publish_post.
        approval: 'off',
        taintSink: false,
        permission: 'post.create',
        label: 'text.workflow.node.content_save_post_draft.title',
        description: 'text.workflow.node.content_save_post_draft.desc',
        schema: ['type' => 'object', 'properties' => [
            'post_uuid' => self::NULLABLE_TEXT,
            'title' => ['type' => 'string', 'x-expr' => true],
            'slug' => self::NULLABLE_TEXT,
            'description' => self::NULLABLE_TEXT,
            'content' => ['type' => ['string', 'array', 'null'], 'x-expr' => true],
            'category' => self::NAMES,
            'tags' => self::NAMES,
            'language' => self::NULLABLE_TEXT,
            'post_type' => ['type' => ['string', 'null']],
            'thumbnail_upload_id' => ['type' => ['integer', 'string', 'null'], 'x-expr' => true],
            'create_missing_tags' => ['type' => 'boolean'],
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['title' => 'vars.article.title', 'description' => 'vars.article.description', 'content' => 'vars.article.content', 'post_type' => 'post', 'create_missing_tags' => false, 'storeAs' => 'vars.draft'],
        icon: 'file-text',
        result: [
            ['key' => 'post_uuid', 'type' => 'string', 'label' => 'Post ID'],
            ['key' => 'created', 'type' => 'boolean', 'label' => 'Newly created'],
            ['key' => 'status', 'type' => 'string', 'label' => 'Status'],
            ['key' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['key' => 'slug', 'type' => 'string', 'label' => 'Slug'],
            ['key' => 'review', 'type' => 'object', 'label' => 'Review request'],
        ],
    )]
    public function savePostDraft(array $config, WorkflowContext $context): array
    {
        $postUuid = $this->optionalString($config['post_uuid'] ?? null);
        $user = $this->delegate($context);

        $before = null;
        if ($postUuid !== null) {
            $this->requireRight($user, 'post.update', 'update an existing post');
            $before = $this->writer->draftView($this->writer->findByUuid($postUuid));
        }

        $fields = array_intersect_key($config, array_flip(PostDraftWriter::DRAFT_FIELDS));
        if ($this->wantsTagCreation($config)) {
            $this->requireRight($user, 'tag.create', 'create tags');
            $fields['create_missing_tags'] = true;
        }

        $post = $this->writer->saveDraft($fields, $postUuid, $user?->getKey());
        $after = $this->writer->draftView($post);

        return [
            'post_uuid' => $post->uuid,
            'created' => $postUuid === null,
            'status' => $post->status,
            'title' => $post->name,
            'slug' => $after['slug'] ?? null,
            'review' => self::reviewEnvelope($post, $before, $after),
        ];
    }

    #[WorkflowNode(
        type: 'content.publish_post',
        group: 'cms',
        safety: ['publish'],
        approval: 'required',
        permission: 'post.update',
        label: 'text.workflow.node.content_publish_post.title',
        description: 'text.workflow.node.content_publish_post.desc',
        schema: ['type' => 'object', 'required' => ['post_uuid'], 'properties' => [
            'post_uuid' => ['type' => 'string', 'x-expr' => true],
            'publish_at' => self::NULLABLE_TEXT,
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['post_uuid' => 'vars.draft.post_uuid', 'storeAs' => 'vars.published'],
        icon: 'send',
        result: [
            ['key' => 'post_uuid', 'type' => 'string', 'label' => 'Post ID'],
            ['key' => 'status', 'type' => 'string', 'label' => 'Status'],
            ['key' => 'published_at', 'type' => 'datetime', 'label' => 'Published at'],
            ['key' => 'slug', 'type' => 'string', 'label' => 'Slug'],
        ],
    )]
    public function publishPost(array $config, WorkflowContext $context): array
    {
        $uuid = $this->optionalString($config['post_uuid'] ?? null)
            ?? throw new InvalidArgumentException('post_uuid is required.');

        $post = $this->writer->publish($uuid, $this->optionalString($config['publish_at'] ?? null));

        return [
            'post_uuid' => $post->uuid,
            'status' => $post->status,
            'published_at' => optional($post->published_at)->toIso8601String(),
            'slug' => $post->slugs()->value('slug'),
        ];
    }

    #[WorkflowNode(
        type: 'content.assign_taxonomy',
        group: 'cms',
        safety: ['internal_write'],
        approval: 'on',
        permission: 'post.update',
        label: 'text.workflow.node.content_assign_taxonomy.title',
        description: 'text.workflow.node.content_assign_taxonomy.desc',
        schema: ['type' => 'object', 'required' => ['post_uuid'], 'properties' => [
            'post_uuid' => ['type' => 'string', 'x-expr' => true],
            'categories' => self::NAMES,
            'tags' => self::NAMES,
            'mode' => ['type' => 'string', 'enum' => ['replace', 'add']],
            'create_missing_tags' => ['type' => 'boolean'],
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['post_uuid' => 'vars.draft.post_uuid', 'categories' => 'vars.classified.categories', 'tags' => 'vars.classified.tags', 'mode' => 'replace', 'create_missing_tags' => false, 'storeAs' => 'vars.taxonomy'],
        icon: 'tags',
        result: [
            ['key' => 'post_uuid', 'type' => 'string', 'label' => 'Post ID'],
            ['key' => 'category_ids', 'type' => 'list', 'label' => 'Category numbers'],
            ['key' => 'tag_ids', 'type' => 'list', 'label' => 'Tag numbers'],
            ['key' => 'created_tags', 'type' => 'list', 'label' => 'Tags created'],
        ],
    )]
    public function assignTaxonomy(array $config, WorkflowContext $context): array
    {
        $uuid = $this->optionalString($config['post_uuid'] ?? null)
            ?? throw new InvalidArgumentException('post_uuid is required.');

        $create = $this->wantsTagCreation($config);
        if ($create) {
            $this->requireRight($this->delegate($context), 'tag.create', 'create tags');
        }

        $result = $this->writer->assignTaxonomy($uuid, $config['categories'] ?? null, $config['tags'] ?? null, $create, (string) ($config['mode'] ?? 'replace'));

        return ['post_uuid' => $uuid] + $result;
    }

    #[WorkflowNode(
        type: 'content.read_post',
        group: 'cms',
        safety: ['read'],
        approval: 'off',
        permission: 'post.view',
        // Post text may come from crawlers or contributors: an AI or write step that uses it is linted like web text.
        taint: true,
        label: 'text.workflow.node.content_read_post.title',
        description: 'text.workflow.node.content_read_post.desc',
        schema: ['type' => 'object', 'required' => ['post_uuid'], 'properties' => [
            'post_uuid' => ['type' => 'string', 'x-expr' => true],
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['post_uuid' => 'vars.trigger.record.uuid', 'storeAs' => 'vars.post'],
        icon: 'file-text',
        result: [
            ['key' => 'post_uuid', 'type' => 'string', 'label' => 'Post ID'],
            ['key' => 'status', 'type' => 'string', 'label' => 'Status'],
            ['key' => 'type', 'type' => 'string', 'label' => 'Type'],
            ['key' => 'slug', 'type' => 'string', 'label' => 'Slug'],
            ['key' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['key' => 'summary', 'type' => 'string', 'label' => 'Summary'],
            ['key' => 'text', 'type' => 'string', 'label' => 'Text'],
            ['key' => 'sections', 'type' => 'list', 'label' => 'Sections'],
            ['key' => 'categories', 'type' => 'list', 'label' => 'Categories', 'item' => [
                ['key' => 'id', 'type' => 'number', 'label' => 'Number'],
                ['key' => 'name', 'type' => 'string', 'label' => 'Name'],
                ['key' => 'slug', 'type' => 'string', 'label' => 'Slug'],
            ]],
            ['key' => 'language', 'type' => 'string', 'label' => 'Language'],
            ['key' => 'content_digest', 'type' => 'string', 'label' => 'Content check code'],
            ['key' => 'published_at', 'type' => 'datetime', 'label' => 'Published at'],
        ],
    )]
    public function readPost(array $config, WorkflowContext $context): array
    {
        $post = $this->postByUuid($config['post_uuid'] ?? null);
        $view = app(AgentReadablePostProjector::class)->project($post);
        $sections = (array) ($view['sections'] ?? []);

        return [
            'post_uuid' => $post->uuid,
            'status' => $post->status,
            'type' => $post->type,
            'slug' => $post->slugs()->value('slug'),
            'title' => $view['title'] ?? '',
            'summary' => $view['summary'] ?? '',
            'text' => implode("\n\n", array_column($sections, 'text')),
            'sections' => $sections,
            'categories' => $view['categories'] ?? [],
            'language' => $view['language'] ?? null,
            'content_digest' => $view['content_digest'] ?? null,
            'published_at' => $view['published_at'] ?? null,
        ];
    }

    /**
     * SEO proposal: nothing is written here. The returned `review` envelope (`content_post_seo_patch`) is applied by
     * PostSeoArtifactApplier only when a person approves it in the `approval` node placed after this one, so this node
     * is not a taint sink (an approval is required after it, not before it).
     */
    #[WorkflowNode(
        type: 'content.propose_post_seo',
        group: 'cms',
        safety: ['internal_write'],
        approval: 'required',
        permission: 'post.update',
        taintSink: false,
        label: 'text.workflow.node.content_propose_post_seo.title',
        description: 'text.workflow.node.content_propose_post_seo.desc',
        schema: ['type' => 'object', 'required' => ['post_uuid'], 'properties' => [
            'post_uuid' => ['type' => 'string', 'x-expr' => true],
            'title' => self::NULLABLE_TEXT,
            'description' => self::NULLABLE_TEXT,
            'hashtag' => self::NULLABLE_TEXT,
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['post_uuid' => 'vars.post.post_uuid', 'title' => 'vars.seo.data.title', 'description' => 'vars.seo.data.description', 'hashtag' => null, 'storeAs' => 'vars.seo_proposal'],
        icon: 'search',
        result: [
            ['key' => 'post_uuid', 'type' => 'string', 'label' => 'Post ID'],
            ['key' => 'proposed', 'type' => 'object', 'label' => 'Proposed change'],
            ['key' => 'review', 'type' => 'object', 'label' => 'Review request'],
        ],
    )]
    public function proposePostSeo(array $config, WorkflowContext $context): array
    {
        $post = $this->postByUuid($config['post_uuid'] ?? null);
        $envelope = app(PostProposalToolService::class)->propose_post_seo_refresh_tool(
            (int) $post->getKey(),
            null,
            $this->optionalString($config['title'] ?? null),
            $this->optionalString($config['description'] ?? null),
            $this->optionalString($config['hashtag'] ?? null),
        );

        return [
            'post_uuid' => $post->uuid,
            'proposed' => (array) data_get($envelope, 'payload.proposed_change', []),
            'review' => self::proposalReview($envelope, ['proposed_change.title', 'proposed_change.description', 'proposed_change.hashtag']),
        ];
    }

    /**
     * Description (excerpt) proposal: like content.propose_post_seo, nothing is written until a person approves the
     * `content_post_description_patch` envelope (PostDescriptionArtifactApplier).
     */
    #[WorkflowNode(
        type: 'content.propose_post_description',
        group: 'cms',
        safety: ['internal_write'],
        approval: 'required',
        permission: 'post.update',
        taintSink: false,
        label: 'text.workflow.node.content_propose_post_description.title',
        description: 'text.workflow.node.content_propose_post_description.desc',
        schema: ['type' => 'object', 'required' => ['post_uuid', 'description'], 'properties' => [
            'post_uuid' => ['type' => 'string', 'x-expr' => true],
            'description' => ['type' => 'string', 'x-expr' => true],
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['post_uuid' => 'vars.post.post_uuid', 'description' => 'vars.summary.text', 'storeAs' => 'vars.description_proposal'],
        icon: 'align-left',
        result: [
            ['key' => 'post_uuid', 'type' => 'string', 'label' => 'Post ID'],
            ['key' => 'proposed', 'type' => 'object', 'label' => 'Proposed change'],
            ['key' => 'review', 'type' => 'object', 'label' => 'Review request'],
        ],
    )]
    public function proposePostDescription(array $config, WorkflowContext $context): array
    {
        $post = $this->postByUuid($config['post_uuid'] ?? null);
        $description = $this->optionalString($config['description'] ?? null)
            ?? throw new InvalidArgumentException('description is required.');

        $envelope = app(PostProposalToolService::class)->propose_post_description_refresh_tool((int) $post->getKey(), $description);

        return [
            'post_uuid' => $post->uuid,
            'proposed' => (array) data_get($envelope, 'payload.proposed_change', []),
            'review' => self::proposalReview($envelope, ['proposed_change.description']),
        ];
    }

    /**
     * An agent-tool proposal turned into an approval `review` envelope (the shape ApprovalRunner accepts).
     *
     * @param array<string, mixed> $envelope
     * @param list<string>         $editable
     * @return array<string, mixed>
     */
    protected static function proposalReview(array $envelope, array $editable): array
    {
        return [
            'artifact_type' => (string) $envelope['artifact_type'],
            'subject_type' => $envelope['subject_type'] ?? Post::class,
            'subject_id' => $envelope['subject_id'] ?? null,
            'original_data_snapshot' => $envelope['original_data_snapshot'] ?? null,
            'payload' => (array) ($envelope['payload'] ?? []),
            'editable_fields' => $editable,
            'render_hint' => $envelope['render_hint'] ?? 'json_view',
        ];
    }

    protected function postByUuid(mixed $uuid): Post
    {
        $uuid = $this->optionalString($uuid) ?? throw new InvalidArgumentException('post_uuid is required.');

        return $this->writer->findByUuid($uuid) ?? throw new InvalidArgumentException("Post {$uuid} was not found.");
    }

    /**
     * The approval envelope of a saved draft: before (null for a new post) and after views of the readable fields.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return array<string, mixed>
     */
    public static function reviewEnvelope(Post $post, ?array $before, ?array $after): array
    {
        $proposed = array_intersect_key((array) $after, array_flip(PostDraftWriter::DRAFT_FIELDS));
        if (is_string($after['category_uuid'] ?? null) && $after['category_uuid'] !== '') {
            // Re-applying resolves the category by uuid, so a name shared by two categories stays unambiguous.
            $proposed['category'] = $after['category_uuid'];
        }

        return [
            'artifact_type' => PostDraftArtifactApplier::ARTIFACT_TYPE,
            'subject_type' => Post::class,
            'subject_id' => $post->getKey(),
            'original_data_snapshot' => $before,
            'payload' => [
                'summary' => ($before === null ? 'New draft: ' : 'Draft update: ') . $post->name,
                'post_uuid' => $post->uuid,
                'draft_updated_at' => $after['updated_at'] ?? null,
                'draft_hash' => PostDraftWriter::draftHash($after),
                'proposed_change' => $proposed,
            ],
            'render_hint' => 'json_view',
        ];
    }

    /**
     * Tag creation needs the node flag and the module switch `content.workflow_nodes.allow_create_missing_tags`.
     *
     * @param array<string, mixed> $config
     */
    protected function wantsTagCreation(array $config): bool
    {
        if (($config['create_missing_tags'] ?? false) !== true) {
            return false;
        }
        if (!(bool) config('content.workflow_nodes.allow_create_missing_tags', true)) {
            throw new InvalidArgumentException('Creating tags from workflows is turned off on this site.');
        }

        return true;
    }

    protected function delegate(WorkflowContext $context): ?User
    {
        $instanceId = $context->getVarPath('__runtime.instance_id');

        return $instanceId ? WorkflowInstance::query()->find($instanceId)?->delegatedBy : null;
    }

    protected function requireRight(?User $user, string $permission, string $what): void
    {
        if ($user === null || !Gate::forUser($user)->allows($permission)) {
            throw new InvalidArgumentException("The user this run acts for may not {$what} (needs {$permission}).");
        }
    }

    protected function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Expected text, got ' . get_debug_type($value) . '.');
        }

        return trim($value) === '' ? null : trim($value);
    }
}
