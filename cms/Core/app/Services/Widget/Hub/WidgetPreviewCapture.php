<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Enums\StatusEnum;
use App\Models\Banner;
use App\Repositories\Eloquent\BannerRepository;
use App\Repositories\Interfaces\BannerInterface;
use App\Services\WidgetQueryService;
use App\Services\WidgetTemplatePathResolver;
use Closure;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Preview PNGs of a widget's designs for its hub bundle (`cms:widget-bundle-build`).
 *
 * Each design is rendered under a project it belongs to, the way that project's site renders it (input defaults, or
 * samples where an input has none, see {@see previewValues()}): the template comes
 * from {@see WidgetTemplatePathResolver} with that project active, and the page carries that project's built theme
 * CSS (Vite build of `projects/<Project>/dev/theme.css` into `public/build/widget-preview/<slug>`, git-ignored). A
 * design that renders nothing, or a page that captures blank, fails instead of shipping a blank image; only a design
 * made for one project alone (not usable on Default) that cannot render here, or one named as drawn by the site's
 * theme (`--no-preview-for`), is skipped with a warning and ships without a preview. A `{query:…}` without rows here renders sample rows ({@see sampleRows()}), and a banner list
 * without banners renders sample slides ({@see sampleBanners()}).
 *
 * A template missing for the project is written into the legacy generated set
 * (`storage/app/generated_widgets/<slug>/`, git-ignored, never an existing file) for the capture and removed after.
 * The generation pointer (`storage/app/widget_generations`) is only read.
 */
final class WidgetPreviewCapture
{
    /** Where a project's theme CSS is built for captures, under public/. */
    public const BUILD_ROOT = 'build/widget-preview';

    private const TAILWIND_BROWSER = 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4';

    /** Stand-in for an image input without a default. */
    private const PLACEHOLDER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">'
        . '<rect width="1200" height="800" fill="#cbd5e1"/><path d="M350 555l150-190 110 130 70-80 170 140z" fill="#94a3b8"/>'
        . '<circle cx="660" cy="295" r="50" fill="#94a3b8"/></svg>';

    /** Stand-in for a textarea input without a default. */
    private const SAMPLE_TEXT = 'Đây là nội dung mẫu. Bạn thay bằng nội dung của mình khi thêm khối này vào trang.';

    /** @var Closure(string): void */
    private Closure $warn;

    /** Image of the sample rows a data-driven design is previewed with ({@see sampleRows()}). */
    private string $sampleImageUrl = '';

    /** @var array<string, true> */
    private array $warnedProjects = [];

    public function __construct(?Closure $warn = null)
    {
        $this->warn = $warn ?? static function (string $message): void {
        };
    }

    /**
     * The project a design is previewed under: the publisher's own project when the design names it, else Default,
     * else the first project named. A scalar or missing project_name means Default (the generator's fan-out rule).
     */
    public static function projectFor(mixed $projectNames, string $publisher): string
    {
        if (is_string($projectNames) && str_starts_with(trim($projectNames), '[')) {
            $decoded = json_decode($projectNames, true);
            $projectNames = is_array($decoded) ? $decoded : $projectNames;
        }
        if (!is_array($projectNames)) {
            $projectNames = is_string($projectNames) && trim($projectNames) !== '' ? [trim($projectNames)] : [];
        }
        $names = array_values(array_filter(array_map(static fn ($name) => trim((string) $name), $projectNames), static fn ($name) => $name !== ''));

        foreach ([$publisher, 'Default'] as $preferred) {
            if (in_array($preferred, $names, true)) {
                return $preferred;
            }
        }

        return $names[0] ?? 'Default';
    }

    /**
     * Whether rendered widget HTML shows anything: empty, whitespace, comments, styles and scripts alone do not, and
     * the render service's exception marker `-n-` is a failure.
     */
    public static function hasVisibleMarkup(string $html): bool
    {
        $stripped = (string) preg_replace(
            ['/<!--.*?-->/s', '/<(script|style|template)\b[^>]*>.*?<\/\1\s*>/is', '/<link\b[^>]*>/i'],
            '',
            $html,
        );
        $stripped = trim($stripped);

        return $stripped !== '' && $stripped !== '-n-';
    }

    /**
     * theme_key => base64 PNG of each design.
     *
     * @param list<string> $themeDrawn keys of designs the site's theme draws (they call a theme component the
     *                                 authoring copy may lack): one that cannot render here ships without a preview
     * @return array<string, string>
     */
    public function captureAll(Widget $widget, string $publisher, string $workRoot, array $themeDrawn = []): array
    {
        $work = $workRoot . '/.preview-' . bin2hex(random_bytes(4));
        if (!mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException('Cannot create ' . $work);
        }

        $previews = [];
        try {
            $placeholder = $work . '/placeholder.svg';
            file_put_contents($placeholder, self::PLACEHOLDER_SVG);
            $this->sampleImageUrl = 'file://' . $placeholder;
            $data = $this->previewValues($widget, $this->sampleImageUrl);
            $themes = WidgetTheme::query()->where('widget_id', $widget->id)->orderBy('theme_key')->get()->values();

            // Render every design first: a design that renders nothing fails before any build or browser run.
            $pages = [];
            foreach ($themes as $theme) {
                $project = self::projectFor($theme->project_name, $publisher);
                try {
                    $pages[] = [$theme, $project, $this->renderHtml($widget, $theme, $project, $data)];
                } catch (RuntimeException $e) {
                    // A design made for one project can need that project's routes or views, which an authoring copy
                    // running another project lacks: it ships without a preview. A shared design must render.
                    $drawnByTheme = in_array((string) $theme->theme_key, $themeDrawn, true);
                    if (!$drawnByTheme && WidgetTheme::projectListAllows($theme->project_name, 'Default')) {
                        throw $e;
                    }
                    ($this->warn)("No preview for design {$theme->theme_key} (" . ($drawnByTheme ? 'drawn by the site theme' : "only for {$project}") . '): ' . $e->getMessage());
                }
            }

            foreach (array_values($pages) as $index => [$theme, $project, $html]) {
                $cssProject = $this->cssProject($project);
                $buildDirectory = $this->ensureThemeBuild($cssProject);
                // Index-based names: a design key never becomes part of a file path.
                $htmlPath = $work . '/design-' . $index . '.html';
                $pngPath = $work . '/design-' . $index . '.png';
                file_put_contents($htmlPath, $this->page($html, $cssProject, $buildDirectory));
                $this->capture($htmlPath, $pngPath, "design {$theme->theme_key} ({$project})");
                $previews[(string) $theme->theme_key] = base64_encode((string) file_get_contents($pngPath));
            }
        } finally {
            foreach (glob($work . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }

        return $previews;
    }

    /**
     * The values a preview renders with: each input's default, else a sample ({@see sampleText()} for a text input; a
     * sample sentence for a textarea; three numbered items for a list; `#` for a link; a grey placeholder image), so
     * a widget whose inputs have no defaults still shows its layout and type.
     *
     * @return array<string, mixed> input name => value
     */
    public function previewValues(Widget $widget, string $placeholderUrl): array
    {
        $data = [];
        $inputs = WidgetInput::query()->where('widget_id', $widget->id)->orderBy('id')->get();
        $listsProducts = $inputs->contains(static fn (WidgetInput $input): bool => str_starts_with((string) $input->name, 'query:product.'));
        foreach ($inputs as $input) {
            $default = $input->default_value;
            $type = (string) $input->type;
            // A list stores "no items" as the string "[]".
            if ($default !== null && $default !== '' && $default !== [] && !($type === 'list' && $default === '[]')) {
                $data[$input->name] = $default;
                continue;
            }
            $name = (string) $input->name;
            $label = trim((string) (is_array($input->options) ? ($input->options['label'] ?? '') : '')) ?: Str::headline($name);
            $isLink = preg_match('/(url|link|href)$/i', $name) === 1;
            $data[$input->name] = match ($type) {
                'image' => ['url' => $placeholderUrl, 'name' => Str::headline($name)],
                'upload' => [],
                'text' => $isLink ? '#' : self::sampleText($name, $label, $listsProducts),
                'textarea' => $isLink ? '#' : self::SAMPLE_TEXT,
                'list' => [$label . ' 1', $label . ' 2', $label . ' 3'],
                default => '',
            };
        }

        return $data;
    }

    /**
     * Sample copy of a text input, by what its name says it holds: a colour value for `…_color`, the highlighted
     * words that continue a title for `color_…` (templates print them in the accent colour), a button caption, a
     * title; else the editor label.
     */
    private static function sampleText(string $name, string $label, bool $listsProducts): string
    {
        return match (true) {
            preg_match('/(^|_)colou?r$/i', $name) === 1 => '#155ea2',
            preg_match('/^colou?r_/i', $name) === 1 => 'của chúng tôi',
            preg_match('/(^|_)(btn|button|cta)(_|$)/i', $name) === 1 => 'Xem thêm',
            preg_match('/(^|_)(title|heading)$/i', $name) === 1 => $listsProducts ? 'Sản phẩm nổi bật' : 'Câu chuyện',
            default => $label,
        };
    }

    /**
     * The design's HTML rendered under $project like that project's admin preview (CSS left to the capture page).
     *
     * @param array<string, mixed> $data
     */
    public function renderHtml(Widget $widget, WidgetTheme $theme, string $project, array $data): string
    {
        $created = [];
        // A data-driven design shows sample rows where this copy has none, instead of an empty list.
        WidgetQueryService::$sampleQueryData = fn (string $query, int $limit): array => $this->sampleRows($query, $limit);
        $restoreBanners = $this->sampleBanners();

        try {
            return $this->asProject($project, function () use ($widget, $theme, $project, $data, &$created): string {
                $created = $this->ensureTemplate($theme, $project);
                $result = app(WidgetRenderer::class)->render(
                    new WidgetRenderSpec((int) $widget->id, (int) $theme->id, $data),
                    new WidgetRenderContext(projectName: $project, useCss: false, isPreview: true, editMarkers: false),
                );
                if (!$result->ok) {
                    throw new RuntimeException("design {$theme->theme_key} did not render under project {$project} (" . ($result->errorCode ?? 'render error') . ')');
                }
                if (!self::hasVisibleMarkup($result->html)) {
                    throw new RuntimeException("design {$theme->theme_key} rendered empty HTML under project {$project} (template missing or render error, see the log); no preview captured");
                }

                return $result->html;
            });
        } finally {
            WidgetQueryService::$sampleQueryData = null;
            $restoreBanners();
            foreach (array_reverse($created) as $path) {
                is_dir($path) ? @rmdir($path) : @unlink($path);
            }
            WidgetTemplatePathResolver::clearCache();
        }
    }

    /**
     * Sample rows for a `{query:…}` without rows here. Products only (name, description, image, one priced variant, no
     * slug so no link is built); other queries stay empty.
     *
     * @return list<object>
     */
    private function sampleRows(string $query, int $limit): array
    {
        if (!str_starts_with($query, 'product.')) {
            return [];
        }
        $image = (object) ['thumbnail' => $this->sampleImageUrl, 'url' => $this->sampleImageUrl, 'name' => 'Sản phẩm mẫu'];

        return array_map(static fn (int $i): object => (object) [
            'id' => 0,
            'name' => 'Sản phẩm mẫu ' . $i,
            'description' => 'Mô tả ngắn về sản phẩm, một hoặc hai dòng.',
            'slug' => null,
            'image' => $image,
            'variants' => collect([(object) [
                'status' => StatusEnum::PUBLISHED->value,
                'sell_price' => 90000 + 30000 * $i,
                'sale_price' => 0,
                'sale_from_date' => null,
                'sale_to_date' => null,
            ]]),
        ], range(1, max(1, min($limit, 6))));
    }

    /**
     * Sample slides for a design that lists banners the way `<x-slider>` does (the banner repository's list by type,
     * cached): while a preview renders, a type without banners here answers three sample slides, and the cache is a
     * throwaway store, so no sample reaches the site's cache and no cached empty list hides the samples. Returns the
     * undo.
     *
     * @return Closure(): void
     */
    private function sampleBanners(): Closure
    {
        // A banner's image is a model; this one only carries the URLs a design prints.
        $image = (new class extends Model {
        })->forceFill(['url' => $this->sampleImageUrl, 'md' => $this->sampleImageUrl, 'lg' => $this->sampleImageUrl, 'name' => 'Ảnh mẫu']);
        $samples = new Collection(array_map(static fn (int $i): Banner => (new Banner())->forceFill([
            'name' => 'Tiêu đề ảnh ' . $i,
            'url' => '',
            'limit_by' => 0,
            'custom' => ['title' => 'Dòng giới thiệu ngắn'],
        ])->setRelation('image', $image), [1, 2, 3]));

        $hadInstance = app()->resolved(BannerInterface::class) && app()->isShared(BannerInterface::class);
        $previous = $hadInstance ? app(BannerInterface::class) : null;
        app()->instance(BannerInterface::class, new class($samples) extends BannerRepository {
            public function __construct(private readonly Collection $samples)
            {
                parent::__construct();
            }

            public function GetBannerList(string $type = 'slider_main'): Collection|LengthAwarePaginator|array
            {
                $banners = parent::GetBannerList($type);

                return count($banners) > 0 ? $banners : $this->samples;
            }
        });

        $defaultStore = config('cache.default');
        config(['cache.stores.widget-preview' => ['driver' => 'array', 'serialize' => false], 'cache.default' => 'widget-preview']);

        return static function () use ($previous, $defaultStore): void {
            config(['cache.default' => $defaultStore]);
            Cache::forgetDriver('widget-preview');
            app()->forgetInstance(BannerInterface::class);
            if ($previous !== null) {
                app()->instance(BannerInterface::class, $previous);
            }
        };
    }

    /**
     * The `public/` build directory holding $project's theme CSS, building it when missing or older than its sources.
     */
    public function ensureThemeBuild(string $project): string
    {
        $buildDirectory = self::BUILD_ROOT . '/' . (Str::slug($project) ?: 'default');
        $entries = $this->cssEntries($project);
        $manifest = public_path($buildDirectory . '/manifest.json');
        if ($this->buildIsCurrent($manifest, $project, $entries)) {
            return $buildDirectory;
        }

        $vite = base_path('node_modules/vite/bin/vite.js');
        if (!is_file($vite)) {
            throw new RuntimeException("theme CSS of {$project} is not built and Vite is missing (run pnpm install in ispa-cms)");
        }
        $process = new Process(
            ['node', $vite, 'build', '--outDir', public_path($buildDirectory), '--emptyOutDir'],
            base_path(),
            [
                'VITE_TEMPLATE_FOLDER' => $project,
                'VITE_TEMPLATE_VIEWS_PATH' => "projects/{$project}/views/",
                'VITE_TEMPLATE_TAILWIND_CSS' => "projects/{$project}/dev/theme.css",
                'VITE_TEMPLATE_TAILWIND_CONFIG' => "projects/{$project}/dev/tailwind.config.js",
            ],
        );
        $process->setTimeout(900);
        $process->run();
        clearstatcache();
        if (!$process->isSuccessful() || !$this->manifestHas($manifest, $entries)) {
            $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            throw new RuntimeException("building the theme CSS of {$project} failed: " . Str::limit($output, 2000));
        }

        return $buildDirectory;
    }

    /**
     * Runs $callback with $project as the active project (template resolution, project_path(), its view namespace).
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function asProject(string $project, Closure $callback): mixed
    {
        $keys = ['ispa-cms.folder_name', 'ispa-cms.folder', 'ispa-cms.template_tailwind_css'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = config($key);
        }
        config([
            'ispa-cms.folder_name' => $project,
            'ispa-cms.folder' => Str::lower($project) . '::',
            'ispa-cms.template_tailwind_css' => "projects/{$project}/dev/theme.css",
        ]);
        if (is_dir(base_path("projects/{$project}/views"))) {
            View::addNamespace(Str::lower($project), base_path("projects/{$project}/views"));
        }
        WidgetTemplatePathResolver::clearCache();

        try {
            return $callback();
        } finally {
            config($saved);
            WidgetTemplatePathResolver::clearCache();
        }
    }

    /**
     * Makes the design's template resolvable for the active project. A template the site already resolves is used as
     * is (with a warning when it differs from the design in the database); a missing one is generated as a new file
     * in the project's legacy generated set. Returns the paths created, deepest last.
     *
     * @return list<string>
     */
    private function ensureTemplate(WidgetTheme $theme, string $project): array
    {
        $publisher = app(LegacyWidgetArtifactSetPublisher::class);
        $content = $publisher->renderThemeContent($theme->loadMissing('widget'));
        $resolved = (new WidgetTemplatePathResolver())->resolve((int) $theme->widget_id, (int) $theme->id);
        if ($resolved !== null) {
            if ((string) file_get_contents($resolved) !== $content) {
                ($this->warn)("Design {$theme->theme_key}: the {$project} template ({$resolved}) differs from the design in the database; the preview shows what that site renders (regenerate widget templates to refresh it).");
            }

            return [];
        }

        $directory = storage_path('app/generated_widgets/' . (Str::slug($project) ?: 'default'));
        $path = $directory . "/widget_{$theme->widget_id}_theme_{$theme->id}.blade.php";
        if (file_exists($path)) {
            throw new RuntimeException("design {$theme->theme_key}: {$path} exists but does not resolve; not overwriting it");
        }

        $created = [];
        $missing = [];
        for ($dir = $directory; !is_dir($dir); $dir = dirname($dir)) {
            $missing[] = $dir;
        }
        foreach (array_reverse($missing) as $dir) {
            if (!@mkdir($dir, 0755) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create ' . $dir);
            }
            $created[] = $dir;
        }
        if (file_put_contents($path, $content) !== strlen($content)) {
            @unlink($path);
            throw new RuntimeException('Cannot write ' . $path);
        }
        $created[] = $path;
        WidgetTemplatePathResolver::clearCache();

        return $created;
    }

    /** The project whose theme CSS styles the capture: $project, or Default when this checkout has no theme for it. */
    private function cssProject(string $project): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $project) === 1 && is_file(base_path("projects/{$project}/dev/theme.css"))) {
            return $project;
        }
        if (!isset($this->warnedProjects[$project])) {
            $this->warnedProjects[$project] = true;
            ($this->warn)("Project {$project} has no projects/{$project}/dev/theme.css here; its designs are captured with the Default theme CSS.");
        }

        return 'Default';
    }

    /** @return list<string> */
    private function cssEntries(string $project): array
    {
        return ['resources/css/app.css', "projects/{$project}/dev/theme.css"];
    }

    /** @param list<string> $entries */
    private function buildIsCurrent(string $manifest, string $project, array $entries): bool
    {
        if (!$this->manifestHas($manifest, $entries)) {
            return false;
        }
        $built = (int) filemtime($manifest);
        foreach ([...$entries, "projects/{$project}/dev/tailwind.config.js"] as $source) {
            $path = base_path($source);
            if (is_file($path) && (int) filemtime($path) > $built) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $entries */
    private function manifestHas(string $manifest, array $entries): bool
    {
        if (!is_file($manifest)) {
            return false;
        }
        try {
            $data = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        foreach ($entries as $entry) {
            if (!is_array($data) || !isset($data[$entry]['file'])) {
                return false;
            }
        }

        return true;
    }

    /** The capture page: the admin preview's CSS (Tailwind browser build + the project's built theme) around the HTML. */
    private function page(string $html, string $cssProject, string $buildDirectory): string
    {
        // A fresh Vite with no hot file: a dev server running for another project never answers for this one.
        $tags = (new Vite())
            ->useHotFile(storage_path('framework/widget-preview-no-hot'))($this->cssEntries($cssProject), $buildDirectory)
            ->toHtml();
        // <base> resolves the widget's relative asset URLs against this site.
        $base = htmlspecialchars(rtrim((string) config('app.url'), '/') . '/', ENT_QUOTES);

        return "<!doctype html><html><head><meta charset=\"utf-8\"><base href=\"{$base}\">"
            . '<script src="' . self::TAILWIND_BROWSER . '"></script>' . $tags
            . "</head><body>{$html}</body></html>";
    }

    private function capture(string $htmlPath, string $pngPath, string $label): void
    {
        $process = new Process(
            ['node', base_path('scripts/widget-preview-capture.mjs'), '--html', $htmlPath, '--out', $pngPath, '--width', '1200'],
            base_path(),
        );
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful() || !is_file($pngPath) || filesize($pngPath) === 0) {
            throw new RuntimeException("preview capture failed for {$label}: " . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
