<?php

namespace Cms\Content\Console\Commands;

use Cms\Content\Services\DeletedPostPurgeService;
use Illuminate\Console\Command;

final class PurgeDeletedPostsCommand extends Command
{
    protected $signature = 'content:purge-deleted-posts
        {--days=30 : Permanently delete posts soft-deleted at least this many days ago}
        {--limit=500 : Maximum posts per run}
        {--apply : Permanently delete eligible posts; without this flag only report candidates}';

    protected $description = 'Permanently purge old soft-deleted Content posts and orphaned media.';

    public function handle(DeletedPostPurgeService $purge): int
    {
        $stats = $purge->purge(
            days: (int) $this->option('days'),
            limit: (int) $this->option('limit'),
            apply: (bool) $this->option('apply'),
        );

        $this->components->info(sprintf(
            'Content post purge %s: %d candidate(s), %d post(s) purged, %d upload(s) purged.',
            $stats['apply'] ? 'applied' : 'dry-run',
            $stats['candidate_count'],
            $stats['purged'],
            $stats['upload_purged'],
        ));

        if (! $stats['apply']) {
            $this->warn('Dry-run only. Re-run with --apply to delete candidates.');
        }

        return self::SUCCESS;
    }
}
