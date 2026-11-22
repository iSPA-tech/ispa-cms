<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Listeners;

use Exception;
use App\Clients\CmsClient;
use Illuminate\Bus\Queueable;
use App\Enums\ContentStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\TranslateContentRequest;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Cms\Content\Interfaces\ContentInterface;

class TranslateContentRequestHandler implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Create the event listener.
     *
     * @param ContentInterface $content
     * @param CmsClient $client
     */
    public function __construct(
        protected ContentInterface $content,
        protected CmsClient        $client
    )
    {
    }

    /**
     * Handle the event.
     *
     * @param TranslateContentRequest $event
     *
     * @return void
     * @throws GuzzleException
     */
    public function handle(TranslateContentRequest $event): void
    {
        DB::beginTransaction();
        try {
            //  Get content `from` and `to` need to translated
            $from = $this->content->reset()->find($event->fromContentId, ['*']);
            $to = $this->content->reset()->find($event->toContentId, ['*'], ['language:id,name,path,path_alias']);

            if ($from && $to) {
                $langPath = $to->language->path === 'cn' ? 'zh-TW' : $to->language->path;
                //  Update custom field status
                $custom = $to->custom;

                if ($event->columnNameToTranslate !== ['content']) {
                    //  Translate name vs description
                    $to->name = $this->client->translate($langPath, $from->name);
                    $to->description = str($this->client->translate($langPath, $from->description))->limit(500)->__toString();

                    $custom['name'] = 'done';
                    $custom['description'] = 'done';
                } else {
                    //  Translate content
                    $translated = [];

                    foreach ($from->content as $key => $ct) {
                        if ($ct['type'] === 'text') {
                            $translated[$key] = $this->client->translate($langPath, $ct['value']);
                        }
                    }

                    $newContent = [];
                    foreach ($from->content as $key => $ct) {
                        if ($ct['type'] === 'text') {
                            $newContent[$key] = [
                                'type' => 'text',
                                'value' => $translated[$key],
                            ];
                        } else {
                            $newContent[$key] = $ct;
                        }
                    }
                    $to->content = $newContent;

                    $custom['content'] = 'done';
                }
                $custom['translated_time'] = now();

                $to->custom = $custom;
                //  Update status to translated
                $to->status = ContentStatusEnum::CONTENT_TRANSLATED->value;
                //  Save content `to` translated
                $to->save();

                if (!empty($event->uid)) {
                    //  Remove cache file by target_type
                    cache()->forget(make_key([
                        'page' => 'scan',
                        'type' => (strpos($to->target_type, 'place', true) !== false) ? 'place' : 'item',
                        'uid' => $event->uid,
                    ]));
                }
            }
        } catch (Exception $e) {
            Log::error($e->getMessage(), [
                'from_content_id' => $event->fromContentId,
                'to_content_id' => $event->toContentId,
                'column_name_to_translate' => $event->columnNameToTranslate,
                'uid' => $event->uid,
                'trace' => $e->getTraceAsString(),
            ]);
            DB::rollBack();
        }
        DB::commit();
    }
}
