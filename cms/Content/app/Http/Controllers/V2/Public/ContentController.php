<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Public;

use Exception;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Cms\Content\Http\Requests\Public\Content\ConvertContentRequest;
use Cms\Content\Http\Requests\Public\Content\TranslateContentRequest;

/**
 * Public API controller for content translation and text-to-speech conversion.
 *
 * Provides endpoints for translating content between supported languages and
 * converting text content to audio files.
 *
 * @package Cms\Content\Http\Controllers\V2\Public
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ContentController extends Controller
{
    public const ALLOWED_TARGET = ['en', 'ja', 'ko', 'zh-TW', 'fr', 'ru', 'vi'];

    /**
     * Translate content from Vietnamese to target
     *
     * @param string $target
     * @param TranslateContentRequest $request
     *
     * @return mixed
     * @author vuongnm
     * @since 7.160.1175 - 2023-10-16, 20:38 ICT
     */
    public function translate(string $target, TranslateContentRequest $request): mixed
    {
        if (!in_array($target, self::ALLOWED_TARGET)) {
            return ires()->error()->message('Target language is not supported.');
        }

        //  Process to translate content
        try {
//            $content = $this->client->translate([$request->input('content')], $target);
        } catch (Exception $e) {
            return ires()->error()->message($e->getMessage());
        }


        return ires()->data([
            'target' => $target,
//            'content' => $content,
        ]);
    }

    /**
     * Convert text to audio
     *
     * @param  string $target
     * @param  ConvertContentRequest $request
     * @return BinaryFileResponse
     * @author vuongnm
     * @since 7.160.1175 - 2023-10-17, 15:50 ICT
     */
    public function textToSpeech(string $target, ConvertContentRequest $request): BinaryFileResponse
    {
        if (!in_array($target, array_merge(self::ALLOWED_TARGET, ['vi']), true)) {
            return ires()->error()->message('Target language is not supported.');
        }

        try {
//            $audio = $this->client->textToSpeech($request->input('content'), $target);
        } catch (Exception $e) {
            Log::debug($e->getMessage(), $e->getTrace());
            return ires()->error()->message($e->getMessage());
        }

//        return ires()->download(Storage::disk('public')->path($audio['path'] . '/' . $audio['name']), $audio['name']);
    }
}
