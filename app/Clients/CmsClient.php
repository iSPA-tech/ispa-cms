<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Clients;

use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\SiteStatusService;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Client for the hub-run AI services (translate, gptTranslate, textToSpeech) on the platform hub.
 *
 * the domain token comes from the site backend (SiteStatusService, which asks the hub with a signed
 * request and keeps the token server-side), and the domain and secret come from HubCredentials (`.env` first, then the
 * encrypted database row). The methods take no token argument (removed the ignored one). Without a token the
 * call is skipped and the method returns its empty value.
 */
class CmsClient
{
    /**
     * App Client constructor
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct()
    {
    }

    /**
     * ChatGPT translate
     *
     * @param array $body
     *
     * @return mixed
     * @since 7.160.1175 - 2024-09-20, 23:24 ICT
     * @author malayvuong
     */
    public function gptTranslate(array $body): mixed
    {
        $client = $this->setup();
        if ($client === null) {
            return null;
        }

        try {
            $responseData = $client->post($this->url('content/gpt-translate'), $body)->json();

            if (($responseData['status'] ?? null) === 'success') {
                $data = $responseData['data']['choices'][0]['message']['content'] ?? null;
            } else {
                $data = null;
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
            $data = null;
        }

        return $data;
    }

    /**
     * Request builder carrying the hub credentials and the server-held domain token, or null when the site has no
     * token (not connected to the hub). A non-2xx answer throws, so callers return their empty value as before.
     *
     * @return PendingRequest|null
     * @since  1.0.0
     * @author malayvuong
     */
    protected function setup(): ?PendingRequest
    {
        $token = app(SiteStatusService::class)->domainToken();
        if ($token === null) {
            Log::warning('Hub-run content service skipped: this site is not connected to the platform hub.');

            return null;
        }

        $credentials = app(HubCredentials::class);

        return Http::withoutRedirecting()
            ->timeout(60)
            ->withHeaders([
                'Accept' => '*/*',
                'domain-secret' => $credentials->secret(),
                'domain-token' => $token,
                'domain-name' => $credentials->domain(),
            ])
            ->asJson()
            ->throw();
    }

    /**
     * Absolute URL of a public hub API path.
     */
    protected function url(string $path): string
    {
        return rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/') . '/api/v2/public/' . $path;
    }

    /**
     * Call to translate content
     *
     * @param string $langPath
     * @param string $content
     *
     * @return mixed|null
     * @since 7.160.1175 - 2023-10-17, 23:19 ICT
     * @author vuongnm
     */
    public function translate(string $langPath, string $content): mixed
    {
        $client = $this->setup();
        if ($client === null) {
            return null;
        }

        try {
            $responseData = $client->post($this->url('content/translate/' . $langPath), [
                'content' => $content,
            ])->json();

            if (($responseData['status'] ?? null) === 'success' && isset($responseData['data']['content'])) {
                $data = $responseData['data']['content'][0] ?? null;
            } else {
                $data = null;
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
            $data = null;
        }

        return $data;
    }

    /**
     * Text to Speech
     *
     * @param string $langPath
     * @param string $content
     * @param string $filePath
     *
     * @return mixed
     * @author vuongnm
     * @since 7.160.1175 - 2023-10-18, 10:30 ICT
     */
    public function textToSpeech(string $langPath, string $content, string $filePath): mixed
    {
        $client = $this->setup();
        if ($client === null) {
            return [];
        }
        // The request has its own 60 s timeout (setup()). No set_time_limit() here: this runs inside long-lived queue
        // workers, and a process limit would cut the worker off 60 s later, mid-way through another job.
        try {
            $response = $client->post($this->url('content/text-to-speech/' . $langPath), [
                'content' => $content,
            ]);
            //  Binary return data is a mp3 file, save it into storage and return path to client
            Storage::disk((string) config('filesystems.default', 'public'))->put($filePath, $response->body());

            $data = [
                'path' => $filePath,
                'name' => basename($filePath),
            ];
        } catch (Exception $e) {
            Log::error($e->getMessage());
            $data = [];
        }

        return $data;
    }
}
