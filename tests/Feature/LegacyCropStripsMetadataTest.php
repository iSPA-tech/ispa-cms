<?php

namespace Tests\Feature;

use App\Helpers\AppHelpers;
use App\Jobs\RequestCropImageJob;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Tests\TestCase;

/**
 * `crop_image()` is the legacy sibling of ImageVariantService: same
 * `<name>.<W>x<H>.webp` output, its own encoder. It kept copying the source's
 * EXIF/XMP into every crop long after the service stopped, which on a small
 * thumbnail is most of the file.
 */
class LegacyCropStripsMetadataTest extends TestCase
{
    private function seedFatSource(): string
    {
        Storage::fake('public');

        $image = new Imagick();
        $image->newImage(640, 400, new \ImagickPixel('rgb(180,60,40)'));
        $image->setImageFormat('jpeg');
        $image->setImageProfile('exif', str_repeat('E', 8000));
        $image->setImageProfile('xmp', str_repeat('X', 4000));
        Storage::disk('public')->put('image/2026/08/source.jpg', $image->getImageBlob());

        return 'image/2026/08/source.jpg';
    }

    public function test_the_synchronous_crop_helper_strips_metadata(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Metadata copying is an Imagick-driver behaviour.');
        }

        $source = $this->seedFatSource();
        $out = 'image/2026/08/source.jpg.160x96.webp';

        AppHelpers::cropImage('public', $source, $out, 160, 96, 'webp');

        $variant = new Imagick();
        $variant->readImageBlob(Storage::disk('public')->get($out));

        $this->assertSame([], $variant->getImageProfiles('*', false));
        $this->assertLessThan(8000, strlen(Storage::disk('public')->get($out)));
    }

    public function test_the_queued_crop_job_strips_metadata(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Metadata copying is an Imagick-driver behaviour.');
        }

        $source = $this->seedFatSource();
        $out = 'image/2026/08/source.jpg.600x360.webp';

        (new RequestCropImageJob('public', $source, $out, 600, 360, 'webp'))->handle();

        $variant = new Imagick();
        $variant->readImageBlob(Storage::disk('public')->get($out));

        $this->assertSame([], $variant->getImageProfiles('*', false));
    }

    public function test_the_queued_crop_job_never_lowers_the_memory_limit_of_the_process(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Metadata copying is an Imagick-driver behaviour.');
        }

        // A queue worker (or a test run) keeps the process: a crop that set 256M left every later job with less memory.
        $before = ini_get('memory_limit');
        ini_set('memory_limit', '1024M');

        try {
            $source = $this->seedFatSource();
            (new RequestCropImageJob('public', $source, 'image/2026/08/source.jpg.300x180.webp', 300, 180, 'webp'))->handle();

            $this->assertSame('1024M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', (string) $before);
        }
    }
}
