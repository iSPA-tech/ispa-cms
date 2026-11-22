<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\SlackWebhookHandler;

class SlackLoggingFormatter
{
    /**
     * Customize the given logger instance for slack webhook.
     *
     * @param Logger $logger
     *
     * @return void
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof SlackWebhookHandler) {
                $formatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message% %context% %extra%');

                $handler->setFormatter($formatter);

                $handler->pushProcessor(function ($record) {
                    $record['extra']['Request ID'] = request()->attributes->get('ISPA-REQUEST-ID', 'No Request ID');
                    $record['extra']['Request Url'] = request()->fullUrl();
                    $record['extra']['Request Method'] = request()->method();
                    $record['extra']['Request Body'] = request()->input();
                    $record['extra']['Request Headers'] = request()->headers->all();

                    if (isset($record['context']['exception']) && !empty($record['context']['exception'])) {
                        $record['extra']['Exception Code'] = $record['context']['exception']->getCode();
                        $record['extra']['Exception Message'] = $record['context']['exception']->getMessage();
                        $record['extra']['Exception Line'] = $record['context']['exception']->getLine();
                        $record['extra']['Exception File'] = $record['context']['exception']->getFile();
                        unset($record['context']['exception']);
                    }

                    return $record;
                });
            }
        }
    }
}
