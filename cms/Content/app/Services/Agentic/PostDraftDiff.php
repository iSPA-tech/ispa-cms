<?php

declare(strict_types=1);

namespace Cms\Content\Services\Agentic;

use Cms\Content\Services\Workflow\PostDraftWriter;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Category;
use Illuminate\Support\Str;

/**
 * Approval diff of a draft proposal (`content_post_draft_patch`): one row per readable field that changes, with the
 * category shown by name and content as the HTML of its text blocks. Registered on Core's AgentApprovalDiffBuilder
 * by the Content provider.
 *
 * @package Cms\Content\Services\Agentic
 * @since   2026-11-23
 */
class PostDraftDiff
{
    /** Field order of the diff rows. */
    private const FIELDS = ['title', 'slug', 'description', 'content', 'category', 'tags', 'language', 'post_type', 'thumbnail_upload_id'];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(AgentArtifact $artifact): array
    {
        $before = is_array($artifact->original_data_snapshot) ? $artifact->original_data_snapshot : [];
        $after = (array) data_get($artifact->payload, 'proposed_change', []);

        $beforeView = [];
        $afterView = [];
        $changes = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $after) && !array_key_exists($field, $before)) {
                continue;
            }

            $old = $this->display($field, $before[$field] ?? null);
            $new = array_key_exists($field, $after) ? $this->display($field, $after[$field]) : $old;
            $beforeView[$field] = $old;
            $afterView[$field] = $new;
            if ($old !== $new) {
                $changes[] = ['field' => $field, 'before' => $old, 'after' => $new];
            }
        }

        return [
            'type' => 'json_patch',
            'field' => 'post_draft',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
                'uuid' => data_get($artifact->payload, 'post_uuid'),
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'is_new' => $before === [],
            'before' => $before === [] ? null : $beforeView,
            'after' => $afterView,
            'changes' => $changes,
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    protected function display(string $field, mixed $value): mixed
    {
        if ($field === 'content') {
            return PostDraftWriter::contentText($value);
        }
        if ($field === 'category' && is_string($value) && Str::isUuid($value)) {
            return Category::query()->where('uuid', $value)->value('name') ?? $value;
        }
        if ($field === 'tags' && is_array($value)) {
            $value = array_values(array_map('strval', $value));
            sort($value);

            return $value;
        }

        return $value;
    }
}
