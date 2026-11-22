<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Upload\AttachAndDetachImages;

class AttachAndDetachImagesTest extends TestCase
{
    public function test_get_image_ids(): void
    {
        $method = new \ReflectionMethod(AttachAndDetachImages::class, 'getImageIds');
        $method->setAccessible(true);
        $content = [
            ['type' => 'text', 'value' => 'A'],
            ['type' => 'image', 'value' => [['id' => 1], ['id' => 2]]],
        ];
        $ids = $method->invoke(null, $content);
        $this->assertEquals([1,2], $ids);
    }

    public function test_get_image_ids_extracts_inline_media_images_from_text_blocks(): void
    {
        $method = new \ReflectionMethod(AttachAndDetachImages::class, 'getImageIds');
        $method->setAccessible(true);

        $content = [
            ['type' => 'image', 'value' => [['id' => 1], ['id' => 2]]],
            [
                'type' => 'text',
                'value' => '<p>Lead</p><figure class="content-media content-media--right"><img src="/storage/right.jpg" data-upload-id="12" alt="Right image"><figcaption>Right caption</figcaption></figure>',
            ],
            [
                'type' => 'glossary',
                'value' => '<p>Glossary <img src="/storage/inline.jpg" data-upload-id="15" data-media-align="left"></p><img src="/external.jpg" data-upload-id="external">',
            ],
        ];

        $ids = $method->invoke(null, $content);

        $this->assertEquals([1, 2, 12, 15], $ids);
    }

    public function test_public_content_image_id_extractor_reuses_block_and_inline_media_rules(): void
    {
        $content = [
            ['type' => 'image', 'value' => [['id' => 7], ['id' => 8]]],
            [
                'type' => 'text',
                'value' => '<p><img src="/storage/inline.jpg" data-upload-id="21"></p>',
            ],
        ];

        $this->assertSame([7, 8, 21], AttachAndDetachImages::imageIdsFromContent($content));
    }
}
