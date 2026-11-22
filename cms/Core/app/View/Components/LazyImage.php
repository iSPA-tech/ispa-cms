<?php

declare(strict_types=1);

namespace Cms\Core\View\Components;

use Closure;
use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class LazyImage extends Component
{
    public array $url = [];
    public string $maskUrl = '';
    public int $width = 0;
    public int $height = 0;
    public string $srcset = '';
    public array $variantWidths = [];

    public function __construct(
        public mixed $image = null,
        public string $class = '',
        public string $alt = '',
        public string $type = 'cover',
        public string $minSize = 'sm',
        public bool $eager = false,
        public string $sizes = '100vw',
    ) {
    }

    public function render(): View|Closure|string
    {
        if (empty($this->image) || !$this->image instanceof Upload || $this->image->type !== 'image') {
            return '-no-data-';
        }

        $family = $this->variantFamily();
        $ready = self::readySources($this->image, array_values($family));
        $variants = app(ImageVariantService::class);
        foreach ($family as $key => $preset) {
            $this->url[$key] = $ready[$preset]['url'] ?? $variants->url($this->image, $preset, false);
            if (isset($ready[$preset])) {
                $this->variantWidths[$key] = $ready[$preset]['width'];
            }
        }
        $this->maskUrl = $this->url['sm'];
        $this->url['url'] = $this->image->url;
        $this->resolveIntrinsicSize($ready);

        if ($this->eager) {
            $this->resolveSrcset();
        }

        return view('core::components.lazy-image');
    }

    /**
     * @param list<string> $presets
     * @return array<string, array{url: string, width: int, height: int}>
     */
    public static function readySources(Upload $image, array $presets): array
    {
        $variants = app(ImageVariantService::class);
        $sources = [];

        foreach ($presets as $preset) {
            $url = $variants->readyUrl($image, $preset);
            [$width, $height] = $variants->dimensions($preset);
            if ($url !== null && $width > 0) {
                $sources[$preset] = ['url' => $url, 'width' => $width, 'height' => $height];
            }
        }

        return $sources;
    }

    /** @param list<string> $presets */
    public static function buildSrcset(Upload $image, array $presets): string
    {
        $candidates = [];
        foreach (self::readySources($image, $presets) as $source) {
            $candidates[$source['width']] = $source['url'];
        }

        if (count(array_unique($candidates)) < 2) {
            return '';
        }

        ksort($candidates);

        return implode(', ', array_map(
            static fn (string $url, int $width): string => "{$url} {$width}w",
            $candidates,
            array_keys($candidates),
        ));
    }

    private function resolveSrcset(): void
    {
        $candidates = [];
        foreach ($this->variantFamily() as $key => $preset) {
            $source = $this->url[$key] ?? null;
            $presetWidth = $this->variantWidths[$key] ?? 0;
            if (is_string($source) && $source !== '' && $presetWidth > 0) {
                $candidates[$presetWidth] = $source;
            }
        }

        if (count(array_unique($candidates)) < 2) {
            return;
        }

        ksort($candidates);
        $this->srcset = implode(', ', array_map(
            static fn (string $source, int $width): string => "{$source} {$width}w",
            $candidates,
            array_keys($candidates),
        ));
        $this->maskUrl = (string) end($candidates);
    }

    /** @return array<string, string> */
    private function variantFamily(): array
    {
        return match ($this->type) {
            'cover' => ['sm' => 'cover_sm', 'md' => 'cover_md', 'lg' => 'cover_lg', 'xl' => 'cover_xl'],
            'thumbnail' => ['sm' => 'thumbnail_sm', 'md' => 'thumbnail_md', 'lg' => 'thumbnail_lg', 'xl' => 'thumbnail_xl'],
            default => ['sm' => 'sm', 'md' => 'md', 'lg' => 'lg', 'xl' => 'xl'],
        };
    }

    /** @param array<string, array{url: string, width: int, height: int}> $ready */
    private function resolveIntrinsicSize(array $ready): void
    {
        foreach ($ready as $source) {
            if ($source['url'] === $this->maskUrl && $source['height'] > 0) {
                $this->width = $source['width'];
                $this->height = $source['height'];

                return;
            }
        }

        $dimensions = (array) $this->image->dimensions;
        $this->width = (int) ($dimensions['width'] ?? 0);
        $this->height = (int) ($dimensions['height'] ?? 0);
    }
}
