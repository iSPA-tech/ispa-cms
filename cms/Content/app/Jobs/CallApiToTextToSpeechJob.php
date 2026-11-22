<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Clients\CmsClient;
use Illuminate\Support\Facades\Log;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Content\Interfaces\ContentInterface;

class CallApiToTextToSpeechJob implements ShouldQueue
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var ContentInterface $content */
    protected ContentInterface $content;
    /** @var UploadInterface $upload */
    protected UploadInterface $upload;
    /** @var int $contentId */
    protected int $contentId;
    /** @var string $uid */
    protected string $uid;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $contentId, int|string|null $uid)
    {
        $this->setQueue('text-to-speech');
        $this->content = app(ContentInterface::class);
        $this->upload = app(UploadInterface::class);
        $this->contentId = $contentId;
        $this->uid = (string) $uid;
    }

    /**
     * @param string|null $queue
     */
    public function setQueue(?string $queue): void
    {
        $this->queue = $queue;
    }

    /**
     * Execute the job.
     *
     * Builds the CMS client, skips quietly when text-to-speech returns nothing. A site not connected to the platform hub
     * gets nothing without a request: CmsClient holds the domain token server-side (Core).
     *
     * @param CmsClient $client
     *
     * @return void
     */
    public function handle(CmsClient $client): void
    {
        $content = $this->content->find($this->contentId, ['*'], ['language:id,name,path,path_alias']);

        if ($content) {
            $string = '';
            foreach ($content->content ?? [] as $key => $ct) {
                if ($ct['type'] === 'text') {
                    if ($key > 0) {
                        $string .= '. ';
                    }
                    $string .= strip_tags($ct['value']);
                }
            }

            $filePath = 'audio/content/' . $content->uuid . '.mp3';
            $data = $client->textToSpeech((string) $content->language?->path_alias, $string, $filePath);
            if (empty($data['path'])) {
                Log::warning('CallApiToTextToSpeechJob: no audio returned.', ['content_id' => $this->contentId]);

                return;
            }
            //  Delete old file
            $this->upload->deleteBy([
                'target_type' => get_class($content),
                'target_id' => $content->id,
                'target_folder' => 'Content',
            ]);
            //  Process to save upload file
            $upload = $this->upload->reset()->model();
            $upload->disk = 'local';
            $upload->type = 'mp3';
            $upload->mime_type = 'audio/mpeg';
            $upload->path = $data['path'];
            $upload->name = $data['name'];
            $upload->target_type = get_class($content);
            $upload->target_id = $content->id;
            $upload->target_folder = 'Content';
            $upload->user_id = $content->user_id;
            $upload->category = 'audio';
            $upload->save();

            //  Remove cache file
            cache()->forget(make_key(['page' => 'scan', 'type' => 'place', 'uid' => $this->uid]));
        }
    }
}
