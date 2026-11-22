<?php

namespace Tests\Feature;

use App\View\Components\LazyImage;
use Cms\Core\Models\Upload;
use Cms\Core\View\Components\LazyImage as CoreLazyImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every lazy image rendered without width/height is an "unsized image element",
 * and they were most of acmeorg.org's 0.194 mobile CLS. The attributes only have
 * to carry the ratio — the loader still swaps in whichever variant fits.
 */
class LazyImageIntrinsicSizeTest extends TestCase
{
    public function test_legacy_srcset_preserves_serialized_upload_replication_identity(): void
    {
        config()->set('filesystems.disks.r2.url', 'https://cdn.example.test');
        $seen = [];
        $variants = \Mockery::mock(\Cms\Core\Services\ImageVariantService::class);
        $variants->shouldReceive('readyUrl')->times(3)->andReturnUsing(function ($upload) use (&$seen) {
            $seen[] = $upload;
            return null;
        });
        $variants->shouldReceive('dimensions')->times(3)->andReturn([768, 0]);
        $this->app->instance(\Cms\Core\Services\ImageVariantService::class, $variants);
        get_srcset(['id' => 42, 'type' => 'image', 'disk' => 'public', 'path' => 'image/test', 'name' => 'photo.jpg', 'r2_synced_at' => '2026-09-13 00:00:00'], 'scale');
        $this->assertSame(42, $seen[0]->id);
        $this->assertTrue($seen[0]->servedFromR2());
    }

    public function test_legacy_component_is_a_core_adapter(): void
    {
        $component = new LazyImage(image: $this->upload(), type: 'cover');

        $this->assertInstanceOf(CoreLazyImage::class, $component);
        $this->assertSame('core::components.lazy-image', $component->render()->name());
    }

    public function test_core_component_keeps_the_existing_lazy_image_contract(): void
    {
        $component = new CoreLazyImage(image: $this->upload(), type: 'cover');
        $component->render();

        $this->assertSame(1600, $component->width);
        $this->assertSame(900, $component->height);
        $this->assertSame([], $component->variantWidths);
    }

    private function upload(array $overrides = []): Upload
    {
        return new Upload(array_merge([
            'disk' => 'public',
            'type' => 'image',
            'path' => 'image/2026/08',
            'name' => 'source.jpg',
            'mime_type' => 'image/jpeg',
            'dimensions' => ['width' => 1600, 'height' => 900],
            'config' => [],
        ], $overrides));
    }

    /** An upload whose scale derivatives were generated and are on disk. */
    private function uploadWithVariants(): Upload
    {
        Storage::fake('public');

        $variants = [];
        foreach (['sm' => 300, 'md' => 768, 'lg' => 1200, 'xl' => 1440] as $preset => $width) {
            $path = "image/2026/08/source.jpg.{$width}x0.webp";
            Storage::disk('public')->put($path, 'variant');
            $variants[$preset] = ['path' => $path, 'status' => 'ready'];
        }

        return $this->upload(['config' => ['variants' => $variants]]);
    }

    private function uploadWithPartialVariants(): Upload
    {
        Storage::fake('public');
        $variants = [];
        foreach (['md' => 768, 'lg' => 1200] as $preset => $width) {
            $path = "image/2026/08/source.jpg.{$width}x0.webp";
            Storage::disk('public')->put($path, 'variant');
            $variants[$preset] = ['path' => $path, 'status' => 'ready'];
        }

        return $this->upload(['config' => ['variants' => $variants]]);
    }

    public function test_cover_fallback_keeps_the_original_ratio(): void
    {
        $component = new LazyImage(image: $this->upload(), type: 'cover');
        $component->render();

        $this->assertSame(1600, $component->width);
        $this->assertSame(900, $component->height);
    }

    public function test_thumbnail_fallback_keeps_the_original_ratio(): void
    {
        $component = new LazyImage(image: $this->upload(), type: 'thumbnail');
        $component->render();

        $this->assertSame(1600, $component->width);
        $this->assertSame(900, $component->height);
    }

    public function test_images_without_ready_variants_do_not_expose_requested_widths(): void
    {
        $component = new LazyImage(image: $this->upload(), type: 'cover');
        $component->render();

        $this->assertSame([], $component->variantWidths);
    }

    public function test_scale_images_fall_back_to_the_source_dimensions(): void
    {
        // The scale presets are height 0 by definition, so only the original
        // can say what shape it is.
        $component = new LazyImage(image: $this->upload(), type: 'scale');
        $component->render();

        $this->assertSame(1600, $component->width);
        $this->assertSame(900, $component->height);
    }

    public function test_an_unmeasured_source_reports_nothing_rather_than_guessing(): void
    {
        $component = new LazyImage(image: $this->upload(['dimensions' => []]), type: 'scale');
        $component->render();

        $this->assertSame(0, $component->width);
        $this->assertSame(0, $component->height);
    }

    /**
     * The lazy path cannot carry an LCP: its `src` is assigned by an
     * IntersectionObserver callback after DOMContentLoaded, so the preload
     * scanner never sees the request. An eager image describes its variants
     * up front instead.
     */
    public function test_an_eager_image_describes_its_variants_by_width(): void
    {
        $component = new LazyImage(image: $this->uploadWithVariants(), type: 'scale', eager: true);
        $component->render();

        $this->assertStringContainsString(' 300w', $component->srcset);
        $this->assertStringContainsString(' 768w', $component->srcset);
        $this->assertStringContainsString(' 1200w', $component->srcset);
        $this->assertStringContainsString(' 1440w', $component->srcset);

        // Widths ascend, which is what a browser expects to choose from.
        $widths = array_map(
            static fn (string $candidate): int => (int) trim(explode(' ', trim($candidate))[1] ?? '0'),
            explode(',', $component->srcset)
        );
        $sorted = $widths;
        sort($sorted);
        $this->assertSame($sorted, $widths);
    }

    public function test_an_eager_image_exports_a_string_srcset_to_blade(): void
    {
        $component = new CoreLazyImage(
            image: $this->uploadWithVariants(),
            type: 'scale',
            eager: true,
            sizes: '(min-width: 640px) 66.667vw, 300px',
        );
        $component->render();

        $data = $component->data();

        $this->assertIsString($data['srcset']);
        $this->assertSame($component->srcset, $data['srcset']);
        $this->assertSame('(min-width: 640px) 66.667vw, 300px', $data['sizes']);
    }

    /** The plain `src` of an eager image is the widest variant, not the mask. */
    public function test_an_eager_image_falls_back_to_its_widest_variant(): void
    {
        $upload = $this->uploadWithVariants();

        $lazy = new LazyImage(image: $upload, type: 'scale');
        $lazy->render();

        $eager = new LazyImage(image: $upload, type: 'scale', eager: true);
        $eager->render();

        $this->assertNotSame($lazy->url['sm'], $lazy->url['xl']);
        $this->assertSame($lazy->url['sm'], $lazy->maskUrl);
        $this->assertSame($eager->url['xl'], $eager->maskUrl);
    }

    /**
     * An upload with no generated derivatives answers every preset with the
     * original file. Four width descriptors over one URL would be a lie, so
     * the eager image ships a plain `src` instead.
     */
    public function test_an_eager_image_without_variants_ships_no_srcset(): void
    {
        $component = new LazyImage(image: $this->upload(), type: 'scale', eager: true);
        $component->render();

        $this->assertSame('', $component->srcset);
    }

    public function test_an_eager_image_only_advertises_ready_derivatives(): void
    {
        $component = new LazyImage(image: $this->uploadWithPartialVariants(), type: 'scale', eager: true);
        $component->render();

        $this->assertStringContainsString(' 768w', $component->srcset);
        $this->assertStringContainsString(' 1200w', $component->srcset);
        $this->assertStringNotContainsString(' 1440w', $component->srcset);
        $this->assertSame(2, substr_count($component->srcset, '.webp'));
    }

    public function test_legacy_srcset_helper_uses_the_ready_scale_dimensions(): void
    {
        $srcset = get_srcset($this->uploadWithVariants()->toArray(), 'scale');

        $this->assertStringContainsString(' 768w', $srcset);
        $this->assertStringContainsString(' 1200w', $srcset);
        $this->assertStringContainsString(' 1440w', $srcset);
        $this->assertStringNotContainsString(' 200w', $srcset);
    }

    /** A lazy image keeps the small mask and gains no srcset. */
    public function test_a_lazy_image_is_left_alone(): void
    {
        $component = new LazyImage(image: $this->upload(), type: 'cover');
        $component->render();

        $this->assertSame('', $component->srcset);
        $this->assertSame($component->url['sm'], $component->maskUrl);
    }
}
