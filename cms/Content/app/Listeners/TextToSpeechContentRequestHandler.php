<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Listeners;

use App\Clients\CmsClient;
use Illuminate\Bus\Queueable;
use Google\ApiCore\ApiException;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\GuzzleException;
use App\Events\TextToSpeechContentRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Content\Interfaces\ContentInterface;

class TextToSpeechContentRequestHandler implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Create the event listener.
     *
     * @param ContentInterface $content
     * @param UploadInterface $upload
     * @param CmsClient $client
     */
    public function __construct(
        protected ContentInterface $content,
        protected UploadInterface  $upload,
        protected CmsClient        $client
    )
    {
    }

    /**
     * Handle the event.
     *
     * @param TextToSpeechContentRequest $event
     *
     * @return void
     * @throws ApiException|GuzzleException
     */
    public function handle(TextToSpeechContentRequest $event): void
    {
//        CallApiToTextToSpeechJob::dispatch($event->contentId, $event->uid);

        $content = $this->content->find($event->contentId, ['*'], ['language:id,name,path,path_alias']);

        if ($content) {
            $string = '';
            foreach ($content->content as $key => $ct) {
                if ($ct['type'] === 'text') {
                    if ($key > 0) {
                        $string .= '. ';
                    }
                    $string .= strip_tags($ct['value']);
                }
            }

            $langPath = $content->language->path;
            $path = 'audio/' . date('Y') . '/' . date('m');

            $filePath = $path . '/' . $content->uuid . '-' . $langPath . '-' . time() . '.mp3';
            $data = $this->client->textToSpeech($langPath, $string, $filePath);

            $content->audios()->detach();
            $classes = get_class($content);
            //  Delete old file
            $this->upload->deleteBy([
                'target_type' => $classes,
                'target_id' => $content->id,
                'target_folder' => 'Content',
            ]);
            //  Process to save upload file
            $upload = $this->upload->reset()->model();
            $upload->disk = 'local';
            $upload->type = 'mp3';
            $upload->mime_type = 'audio/mpeg';
            $upload->path = $path;
            $upload->name = $data['name'];
            $upload->target_type = $classes;
            $upload->target_id = $content->id;
            $upload->target_folder = 'Content';
            $upload->user_id = $content->user_id;
            $upload->category = 'audios';
            $upload->save();
            //  Link upload to content
            $content->audios()->attach($upload->id, [
                'target_type' => $classes,
                'category' => 'audios',
            ]);

            //  Remove cache file
            cache()->forget(make_key(['page' => 'scan', 'type' => 'place', 'uid' => $event->uid]));
        }
    }
}
