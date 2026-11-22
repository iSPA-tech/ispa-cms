<?php

namespace Cms\Core\Console\Commands;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Enums\StatusEnum;
use App\View\Components\WidgetView;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Render parity snapshot: renders every published post's widget blocks through WidgetView (the same component the
 * page templates use) and writes `<out>/<post id>.html` plus `<out>/index.json` (post id => sha256 of the
 * whitespace-normalized HTML). Two snapshots of the same data taken on different code must have identical
 * index.json files. The hash is a coarse whitespace-normalized check; the per-post .html diff is authoritative.
 *
 * Exits 1 when no widget block is found at all, when any block's render result is not ok, when the render throws,
 * or when a block never reached the WidgetRenderer (a vacuous render). Blocks with empty HTML are counted in the
 * summary but are not errors: data-driven widgets may render nothing.
 *
 * Self-contained on purpose (only WidgetView and the WidgetRenderer contract), so the same file can run on older
 * checkouts for before/after comparisons. Alerting is silenced while rendering.
 */
class WidgetRenderSnapshotCommand extends Command
{
    protected $signature = 'cms:widget-render-snapshot {--out= : Output directory for <post id>.html and index.json}';

    protected $description = 'Render every published post\'s widget blocks and write a hash index for parity checks (the hash is a coarse whitespace-normalized check; the per-post .html diff is authoritative)';

    public function handle(): int
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            $this->error('--out is required.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists($out);

        $recorder = $this->installRecorder();
        $originalLogger = app('CMSLogger');
        CMSLogger::swap(new class {
            public function __call(string $name, array $arguments): void
            {
            }
        });

        $index = [];
        $failures = [];
        $blocks = 0;
        $emptyBlocks = 0;
        try {
            $posts = DB::table('posts')
                // Older runtimes (e.g. BHaku) still store the legacy 'published' status value.
                ->whereIn('status', [StatusEnum::PUBLISHED->value, 'published'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'content']);

            foreach ($posts as $post) {
                $content = is_string($post->content) ? json_decode($post->content, true) : null;
                if (!is_array($content)) {
                    continue;
                }
                $parts = [];
                foreach (array_values($content) as $position => $item) {
                    if (!is_array($item) || ($item['type'] ?? null) !== 'widget'
                        || (empty($item['widget_id']) && empty($item['widget_key']))) {
                        continue;
                    }
                    $blocks++;
                    [$html, $error] = $this->renderBlock($item, $recorder);
                    if ($error !== null) {
                        $failures[] = "post {$post->id} block {$position}: {$error}";
                    } elseif (trim($html) === '') {
                        $emptyBlocks++;
                    }
                    $parts[] = "<!-- block {$position} -->\n" . $html;
                }
                if ($parts === []) {
                    continue;
                }
                $html = implode("\n", $parts);
                File::put($out . '/' . $post->id . '.html', $html);
                $index[(string) $post->id] = hash('sha256', trim((string) preg_replace('/\s+/', ' ', $html)));
            }
        } finally {
            CMSLogger::swap($originalLogger);
            app()->instance(WidgetRenderer::class, $recorder->inner);
        }

        File::put($out . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->info(sprintf('Rendered %d widget blocks (%d empty) in %d posts to %s', $blocks, $emptyBlocks, count($index), $out));
        if ($blocks === 0) {
            $failures[] = 'no widget blocks found in published posts (nothing to compare)';
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{string, ?string} rendered HTML and an error description (null when the render was ok) */
    private function renderBlock(array $item, object $recorder): array
    {
        $recorder->results = [];
        try {
            // Resolved through the container so a test can stand in a component that skips the renderer.
            $html = Blade::renderComponent(app()->make(WidgetView::class, [
                'widget' => (int) ($item['widget_id'] ?? 0),
                'templateId' => (int) ($item['template_id'] ?? 0),
                'value' => is_array($item['value'] ?? null) ? $item['value'] : [],
                'inputs' => is_array($item['inputs'] ?? null) ? $item['inputs'] : [],
                'template' => true,
                'widgetKey' => (string) ($item['widget_key'] ?? ''),
                'themeKey' => (string) ($item['theme_key'] ?? ''),
            ]));
        } catch (Throwable $e) {
            return ['<!-- render exception -->', 'threw ' . $e::class . ': ' . $e->getMessage()];
        }
        if ($recorder->results === []) {
            return [$html, 'never reached the widget renderer (vacuous render)'];
        }
        foreach ($recorder->results as $result) {
            if (!$result->ok) {
                return [$html, 'not ok (' . ($result->errorCode ?? 'unknown') . ')'];
            }
        }

        return [$html, null];
    }

    /** Wrap the bound renderer so every WidgetRenderResult of the current block is visible to the command. */
    private function installRecorder(): object
    {
        $recorder = new class (app(WidgetRenderer::class)) implements WidgetRenderer {
            /** @var list<WidgetRenderResult> */
            public array $results = [];

            public function __construct(public readonly WidgetRenderer $inner)
            {
            }

            public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
            {
                return $this->results[] = $this->inner->render($spec, $context);
            }
        };
        app()->instance(WidgetRenderer::class, $recorder);

        return $recorder;
    }
}
