<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\Tracking\ApiLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Repositories\Interfaces\ApiLogInterface;
use Cms\Core\Services\Security\LogRedactor;
use Illuminate\Foundation\Http\Events\RequestHandled;

class ApiLogHandler
{
    protected ApiLogInterface $apiLog;

    protected LogRedactor $redactor;

    /**
     * Create the event listener.
     *
     * @param ApiLogInterface $apiLog   API log repository.
     * @param LogRedactor     $redactor Masks personal data and secrets in the stored copy.
     *
     * @return void
     */
    public function __construct(ApiLogInterface $apiLog, LogRedactor $redactor)
    {
        $this->apiLog = $apiLog;
        $this->redactor = $redactor;
    }

    /**
     * Handle the event.
     *
     * @param RequestHandled $event
     *
     * @return void
     */
    public function handle(RequestHandled $event): void
    {
        if (!$event->request->attributes->get('enable_log', false)) {
            return;
        }

        $startTime = defined('LARAVEL_START') ? LARAVEL_START : $event->request->server('REQUEST_TIME_FLOAT');
        $duration = $startTime ? floor((microtime(true) - $startTime) * 1000) : null;

        if (!method_exists($event->response, 'getData')) {
            return;
        }

        DB::beginTransaction();

        try {
            //  Generate requestId
            // Prefer the shared Context id (AssignRequestId) so API logs and AI call logs line up; the
            // api_logs.request_id column is a 36-char uuid column, so longer safe tokens fall back.
            $contextId = Context::get('request_id');
            $preferred = is_string($contextId) && $contextId !== '' && strlen($contextId) <= 36
                ? $contextId
                : $event->request->attributes->get('ISPA-REQUEST-ID', Str::uuid());
            $requestId = (new ApiLog())->gen_uuid((string) $preferred);
            Context::add('request_id', $requestId);
            //  Set header response
            $event->response->headers->set('ISPA-REQUEST-ID', $requestId);
            //  Start to set content
            $originalContent = $event->response->getOriginalContent();
            if (!$originalContent) {
                $originalContent = $event->response->getData(true);
            }
            if (is_array($originalContent)) {
                $originalContent['request_id'] = $requestId;
            }

            //  Store log (redacted copy only; the response sent to the client is unchanged)
            $this->apiLog->reset()->create([
                'request_id' => $requestId,
                'type' => $this->getType($event->request),
                'method' => $event->request->getMethod(),
                'ip_address' => $event->request->getClientIp(),
                'user_agent' => $event->request->userAgent(),
                'url' => $this->redactor->redactUrl($event->request->fullUrl()),
                'domain' => $event->request->headers->get('origin'),
                'data_request' => $this->redactor->redact($event->request->all()),
                'data_response' => $this->redactor->redact($originalContent),
                'user_id' => ($event->request->user()->id ?? null),
                'time_start' => $startTime,
                'duration' => $duration,
            ]);
            //  Overwrite response content
            $event->response->setContent(json_encode($originalContent, JSON_THROW_ON_ERROR));
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();
            return;
        }

        DB::commit();
    }

    /**
     * Get type of request
     *
     * @param Request $request
     *
     * @return string
     * @author malayvuong
     * @since 6.19.6 - 2022-08-04, 01:56 ICT
     */
    protected function getType(Request $request): string
    {
        if (Str::contains($request->fullUrl(), $request->headers->get('origin'))) {
            return 'internal';
        }

        if (Str::contains($request->fullUrl(), ['localhost', '127.0.0.1'])) {
            return 'local';
        }

        if ($request->attributes->get('request_type')) {
            return $request->attributes->get('request_type');
        }

        return 'incoming';
    }
}
