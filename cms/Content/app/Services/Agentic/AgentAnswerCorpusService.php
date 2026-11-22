<?php

declare(strict_types=1);

namespace Cms\Content\Services\Agentic;

use Carbon\CarbonInterface;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Illuminate\Database\Eloquent\Collection;
use JsonException;

/**
 * Builds the answer corpus: projection snapshots of Posts whose projection digest still equals the
 * deployment-approved digest in `content.agent_answers.approved_posts`.
 *
 * The returned arrays are the in-request snapshot handed to the answer model; their digests describe that
 * snapshot only, not the database row afterwards.
 *
 * Not final: the answer tool tests replace it with a mock.
 */
class AgentAnswerCorpusService
{
    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private const DIGEST = '/\A[0-9a-f]{64}\z/';

    public function __construct(
        private readonly PostInterface $posts,
        private readonly AgentReadablePostProjector $projector,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function retrieve(string $keywords, CarbonInterface $asOf, int $limit = 5): array
    {
        $approved = $this->approvedDigests();
        if ($approved === []) {
            return [];
        }

        $posts = $this->posts->searchAgentEligible($keywords, $asOf, $limit);
        if ($posts instanceof Collection) {
            $posts->loadMissing(['categories.slugs', 'language']);
        }

        $budget = max(0, (int) config('content.agent_answers.max_corpus_chars', 60000));
        $documents = [];
        foreach ($posts as $post) {
            if (! $post instanceof Post) {
                continue;
            }

            $expected = $approved[strtolower((string) $post->uuid)] ?? null;
            $document = $this->projector->project($post);
            if ($expected === null || ! hash_equals($expected, (string) $document['content_digest'])) {
                continue;
            }

            $size = $this->size($document);
            if ($size === null || $size > $budget) {
                break;
            }

            $budget -= $size;
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * The approval map, or an empty map when any entry is invalid (never a partial map).
     *
     * @return array<string, string>
     */
    private function approvedDigests(): array
    {
        $map = config('content.agent_answers.approved_posts', []);
        if (! is_array($map)) {
            return [];
        }

        $approved = [];
        foreach ($map as $uuid => $digest) {
            if (! is_string($uuid) || ! is_string($digest)
                || preg_match(self::UUID, strtolower($uuid)) !== 1
                || preg_match(self::DIGEST, $digest) !== 1) {
                return [];
            }

            $approved[strtolower($uuid)] = $digest;
        }

        return $approved;
    }

    /** @param array<string, mixed> $document */
    private function size(array $document): ?int
    {
        try {
            return mb_strlen(json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'UTF-8');
        } catch (JsonException) {
            return null;
        }
    }
}
