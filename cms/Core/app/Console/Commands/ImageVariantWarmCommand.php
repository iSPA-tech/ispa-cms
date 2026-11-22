<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Generates missing derivatives for uploads that were ingested under a
 * narrower variant context than they are now served with.
 *
 * The case this exists for: vehicle images only ever warmed `media.grid`
 * (thumbnail_sm 80x80, cover_sm 160x96), so a listing card asking for
 * `cover_md` falls back to `cover_sm` — right at 1x for a 112x70 card, soft on
 * a retina screen. Nothing fills the gap on read, because a brand page
 * serializes 100+ models and encoding them inside a public request would
 * stall it.
 *
 * Safe to re-run and safe to interrupt: an upload whose manifest already
 * reports the preset is skipped without touching storage, so the next run
 * simply continues.
 *
 *   php artisan upload:warm-variants --preset=cover_md --path=image/vehicles --limit=500
 */
class ImageVariantWarmCommand extends Command
{
    protected $signature = 'upload:warm-variants
        {--preset=* : Preset to generate, e.g. --preset=cover_md (repeatable)}
        {--context= : Warm every preset of a context instead, e.g. --context=vehicle.card}
        {--path= : Only uploads whose path starts with this, e.g. image/vehicles}
        {--since= : Only uploads created on or after this date (Y-m-d)}
        {--limit=500 : Max uploads per run}
        {--chunk=100 : Uploads per database chunk}
        {--force : Re-encode derivatives that already exist (needs a CDN purge to be visible)}
        {--dry-run : Report what is missing, write nothing}';

    protected $description = 'Generate missing image derivatives for existing uploads (resumable, chunked).';

    public function handle(ImageVariantService $variants): int
    {
        $presets = $this->resolvePresets($variants);
        if ($presets === []) {
            $this->error('Nothing to do: pass --preset=<name> (repeatable) or --context=<name>.');

            return self::FAILURE;
        }

        foreach ($presets as $preset) {
            if (! $variants->preset($preset)) {
                $this->error("Unknown preset: {$preset}");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        // Encoding is CPU-bound and each new variant also replicates to R2;
        // two runs racing over the same uploads would pay that twice.
        $lock = Cache::lock('upload:warm-variants', 3600);
        if (! $lock->get()) {
            $this->error('Another warm run is already in progress.');

            return self::FAILURE;
        }

        $scanned = 0;
        $ensured = 0;
        $skipped = 0;
        $failed = 0;

        try {
            $this->uploadQuery()->chunkById($chunk, function (Collection $uploads) use (
                $variants, $presets, $dryRun, $force, $limit, &$scanned, &$ensured, &$skipped, &$failed
            ): bool {
                // One ledger query per chunk — existence probes for an
                // R2-served upload would otherwise hit the database per path.
                $variants->warmReplicationLedger($uploads);

                foreach ($uploads as $upload) {
                    if ($scanned >= $limit) {
                        return false;
                    }
                    $scanned++;

                    foreach ($presets as $preset) {
                        //  Manifest-only check, and deliberately not a disk
                        //  probe: it is one array read against a stat per
                        //  preset per upload. It is also only a fast path —
                        //  ensureVariant() still checks the disk before
                        //  encoding, so an upload predating the manifest is
                        //  correctly left alone, it just costs a little more
                        //  to find that out.
                        $manifested = data_get($upload->config, "variants.{$preset}.status") === 'ready';

                        if ($manifested && ! $force) {
                            $skipped++;

                            continue;
                        }

                        if ($dryRun) {
                            $this->line("  {$preset}: #{$upload->id} {$upload->path}/{$upload->name}");
                            $ensured++;

                            continue;
                        }

                        try {
                            //  $force, NOT $manifested: uploads predating the
                            //  variant manifest report nothing ready, so keying
                            //  the re-encode off the manifest made --force a
                            //  no-op on exactly the older sites it was written
                            //  for. Overwrites in place rather than deleting
                            //  first, so the URL never 404s mid-encode.
                            $variant = $variants->ensureVariant($upload, $preset, $force);

                            if (($variant['status'] ?? null) === 'ready') {
                                $ensured++;
                            } else {
                                $failed++;
                                $this->warn("  #{$upload->id} {$preset}: " . ($variant['reason'] ?? 'not generated'));
                            }
                        } catch (Throwable $e) {
                            $failed++;
                            $this->warn("  #{$upload->id} {$preset}: {$e->getMessage()}");
                        }
                    }
                }

                return true;
            });
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            '%s%d uploads scanned, %d derivatives %s, %d already present, %d failed.',
            $dryRun ? '[dry-run] ' : '',
            $scanned,
            $ensured,
            $dryRun ? 'queued' : 'written',
            $skipped,
            $failed
        ));

        if ($scanned >= $limit) {
            $this->comment('Hit --limit; run again to continue from where this stopped.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Images only, mirroring ImageVariantService::supports(). */
    private function uploadQuery()
    {
        return Upload::query()
            ->where(function ($sub): void {
                $sub->whereIn('type', ['image', 'avatar'])
                    ->orWhere('mime_type', 'like', 'image/%');
            })
            ->when(
                $this->option('path'),
                fn ($query, $path) => $query->where('path', 'like', rtrim((string) $path, '/') . '%')
            )
            ->when(
                $this->option('since'),
                fn ($query, $since) => $query->where('created_at', '>=', $since)
            );
    }

    /**
     * @return array<int, string>
     */
    private function resolvePresets(ImageVariantService $variants): array
    {
        $presets = array_values(array_filter((array) $this->option('preset')));

        if ($context = $this->option('context')) {
            $presets = array_merge($presets, $variants->contextPresets((string) $context));
        }

        return array_values(array_unique($presets));
    }
}
