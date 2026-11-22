<?php

declare(strict_types=1);

namespace Cms\Content\Console\Commands;

use Cms\Content\Models\Post;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use Illuminate\Console\Command;

/**
 * Prints the `CONTENT_AGENT_ANSWER_APPROVED_POSTS` value for the given posts.
 *
 * The digest is computed exactly as `AgentAnswerCorpusService` does: the post is loaded without the appended-relations
 * scope, `categories.slugs` and `language` are loaded, and `AgentReadablePostProjector` projects it. Computing it by
 * hand without those relations gives another digest and the corpus silently drops the post.
 *
 * Only posts the corpus could serve are accepted: `post` type, published and public now, not deleted. When any given
 * uuid is refused nothing is printed on stdout, so a partial map is never pasted by mistake.
 *
 * Read only: nothing is written, nothing is approved until the operator puts the printed JSON in the environment.
 */
final class AgentAnswerDigestsCommand extends Command
{
    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    protected $signature = 'cms:agent-answer-digests {uuid* : Uuid of each published post to approve for agent answers}';

    protected $description = 'Print the approved-posts map (uuid to content digest) for the agent answer pilot, computed as the corpus does.';

    public function handle(AgentReadablePostProjector $projector): int
    {
        $uuids = array_values(array_unique(array_map(
            static fn (mixed $uuid): string => strtolower(trim((string) $uuid)),
            (array) $this->argument('uuid'),
        )));
        if ($uuids === []) {
            $this->error('Give at least one post uuid.');

            return self::FAILURE;
        }

        $map = [];
        $refused = [];
        foreach ($uuids as $uuid) {
            if (preg_match(self::UUID, $uuid) !== 1) {
                $refused[] = "{$uuid}: not a uuid";

                continue;
            }

            // Same read as the corpus: no appended relations, then the two relations the projection reads.
            $post = Post::query()
                ->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)
                ->with(['categories.slugs', 'language'])
                ->where('uuid', $uuid)
                ->first();

            $reason = $this->refusal($post);
            if ($reason !== null) {
                $refused[] = "{$uuid}: {$reason}";

                continue;
            }

            $map[$uuid] = $projector->project($post)['content_digest'];
        }

        if ($refused !== []) {
            foreach ($refused as $line) {
                $this->error("Refused {$line}");
            }
            $this->error('No map printed: fix the refused posts or leave them out, then run the command again.');

            return self::FAILURE;
        }

        $this->line(json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** Why the corpus would never serve this post, or null when it can. */
    private function refusal(?Post $post): ?string
    {
        if ($post === null) {
            return 'no such post (missing or deleted)';
        }
        if ($post->type !== 'post') {
            return "type is {$post->type}, only posts are answered from";
        }
        $public = Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)->publicAt(now())->whereKey($post->getKey())->exists();

        return $public ? null : 'not published, or not public yet';
    }
}
