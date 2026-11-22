<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services\Logger;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Contracts\LoggerServiceInterface;
use Cms\Core\Services\Security\LogRedactor;

class TelegramService implements LoggerServiceInterface
{
    /** Cache key prefix for alert throttling and the Telegram rate-limit pause. */
    protected const THROTTLE_PREFIX = 'telegram-alerts:';

    protected static string $token = '';
    protected static string $chatId = '';
    protected static string $apiUrl = '';
    /** @var bool */
    public bool $isDiscovery = true;

    public static function init(): void
    {
        self::$token = (string) config('services.telegram.token');
        self::$chatId = (string) config('services.telegram.chat_id');
        self::$apiUrl = "https://api.telegram.org/bot" . self::$token . "/";
    }

    /**
     * Telegram is optional: a public install ships with an empty token and chat id.
     * That is a normal state, so callers skip silently instead of logging an error.
     *
     * @return bool
     */
    protected static function isConfigured(): bool
    {
        return self::$token !== '' && self::$chatId !== '';
    }

    public static function error(array $data = []): void
    {
        $data['title'] = self::setTitle(self::getIconByType('error') . ' *Cảnh báo lỗi hệ thống!*');
        self::sendThrottled('error', $data);
    }

    protected static function setTitle(string $title = ''): string
    {
        $env = strtoupper(config('app.env', env('APP_ENV', 'debug')));
        $appName = config('app.name', 'iSPA CMS');
        return "*$appName* ($env)\n\n" . ($title ?: 'Thông báo hệ thống');
    }

    protected static function getIconByType(string $type): string
    {
        return match ($type) {
            'error' => '🚨',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            default => '🔔',
        };
    }

    public static function send(array $data = []): bool
    {
        try {
            if (!self::$token || !self::$chatId) {
                self::init();
            }

            if (! self::isConfigured()) {
                return false;
            }

            if (self::pausedByRateLimit()) {
                return false;
            }

            $dropped = self::pullCount(self::THROTTLE_PREFIX . 'dropped');
            if ($dropped > 0) {
                $data['dropped'] = number_format($dropped) . ' earlier alerts were not sent while Telegram rate limited this app';
            }

            // Every message leaves the system: mask personal data and secrets whatever the caller sent
            $redactor = app(LogRedactor::class);
            $data = $redactor->redact($data);

            $message = $data['message'] ?? '';
            $exception = $data['exception'] ?? null;
            $attachTrace = config('app.env') === 'production';
            $traceFileUrl = null;

            $fields = '';
            if ($exception instanceof Throwable) {
                // File info in markdown: `File:` _file.php:line_
                $fields .= "\n\n`File:` _" . self::escapeMarkdownV2("{$exception->getFile()}:{$exception->getLine()}") . "_";

                if ($attachTrace) {
                    $traceContent = "Exception: {$exception->getMessage()}\n";
                    $traceContent .= "{$exception->getFile()}:{$exception->getLine()}\nTrace:\n";
                    $traceContent .= $exception->getTraceAsString();
                    $traceContent = $redactor->redactString($traceContent);

                    $fileName = 'trace_' . now()->format('Ymd_His') . '.log';
                    $filePath = "logs/telegram_errors/{$fileName}";

                    File::ensureDirectoryExists(storage_path('app/public/logs/telegram_errors'));
                    Storage::disk('public')->put($filePath, $traceContent);

                    $traceFileUrl = url("storage/{$filePath}");
                }
            }

            $messageTitle = $data['title'] ?? self::setTitle();

            // Dữ liệu bổ sung
            unset($data['message'], $data['title'], $data['exception']);
            if (!empty($data)) {
                $fields .= "\n\n*Thông tin bổ sung*";
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        $pretty = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                        // Join array value as markdown inline code with key
                        $fields .= "\n_" . self::escapeMarkdownV2($key) . ":_ ```\n" . self::escapeCodeBlock($pretty) . "```\n";
                    } else {
                        $fields .= "\n_" . self::escapeMarkdownV2($key) . ":_ ```\n" . self::escapeCodeBlock((string)$value) . "```\n";
                    }
                }
            }

            $formattedMessage = self::escapeMarkdownV2($messageTitle)
                . "\n\n_Mô tả:_\n```\n" . self::escapeCodeBlock($message) . "```\n"
                . "\n\n_Thời gian:_ _" . self::escapeMarkdownV2(now()->toDateTimeString()) . "_"
                . $fields;

            $payload = [
                'chat_id' => self::$chatId,
                'text' => self::subString($formattedMessage),
                'parse_mode' => 'MarkdownV2',
            ];

            if ($traceFileUrl) {
                $payload['reply_markup'] = json_encode([
                    'inline_keyboard' => [
                        [['text' => '📄 Xem Trace Log', 'url' => $traceFileUrl]],
                    ],
                ], JSON_THROW_ON_ERROR);
            }

            $response = Http::post(self::$apiUrl . 'sendMessage', $payload);

            if ($response->status() === 429) {
                self::pauseForRateLimit((int) $response->json('parameters.retry_after', 0));

                return false;
            }

            if ($response->failed()) {
                Log::error('TelegramService: Gửi thông báo thất bại', ['response' => $response->body(), 'payload' => $payload]);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('TelegramService: Gửi thông báo thất bại', ['exception' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send an error or warning unless the same alert was already sent within the throttle window.
     *
     * In an incident one call site can fail on every request: on 2026-10-24 a full Redis made
     * "CMSSettingRuntime redis write failed" fire ~17,000 times in 7 hours, Telegram answered
     * 429 and every rejection was logged again. Now the first occurrence is sent and repeats
     * within `logging.alert_throttle.seconds` are only counted, then reported with the next
     * alert of the same signature (level, message, exception class and location).
     *
     * @param string $level Alert level, part of the signature.
     * @param array<string, mixed> $data Alert payload as given to error() / warning().
     * @return void
     */
    protected static function sendThrottled(string $level, array $data): void
    {
        if (self::pausedByRateLimit()) {
            return;
        }

        $repeats = self::admit($level, $data);
        if ($repeats === null) {
            return;
        }

        if ($repeats > 0) {
            $data['repeated'] = sprintf(
                '%s more times since the previous alert (the same alert is sent at most once per %d min)',
                number_format($repeats),
                max(1, intdiv(self::throttleSeconds(), 60)),
            );
        }

        self::send($data);
    }

    /**
     * Open the throttle window for this alert, or count it as a repeat.
     *
     * @param string $level Alert level.
     * @param array<string, mixed> $data Alert payload.
     * @return int|null Repeats suppressed since the last sent alert, or null to suppress this one.
     */
    protected static function admit(string $level, array $data): ?int
    {
        $seconds = self::throttleSeconds();
        if ($seconds <= 0) {
            return 0;
        }

        $message = $data['message'] ?? '';
        $exception = $data['exception'] ?? null;
        $key = self::THROTTLE_PREFIX . sha1(implode("\n", [
            $level,
            is_scalar($message) ? (string) $message : (string) json_encode($message),
            $exception instanceof Throwable ? $exception::class . '@' . $exception->getFile() . ':' . $exception->getLine() : '',
        ]));

        try {
            $store = self::throttleStore();
            if ($store->add($key, true, $seconds)) {
                return self::pullCount("{$key}:repeats");
            }

            self::count($store, "{$key}:repeats");

            return null;
        } catch (Throwable) {
            // A broken throttle store must not hide alerts.
            return 0;
        }
    }

    /**
     * True while Telegram asked us to wait (429); counts the alert as dropped.
     *
     * @return bool
     */
    protected static function pausedByRateLimit(): bool
    {
        try {
            $store = self::throttleStore();
            if (! $store->has(self::THROTTLE_PREFIX . 'paused')) {
                return false;
            }

            self::count($store, self::THROTTLE_PREFIX . 'dropped');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Stop sending for Telegram's retry_after and log that once, instead of logging every
     * rejected alert with its full payload as a new error.
     *
     * @param int $retryAfter Seconds Telegram asked us to wait.
     * @return void
     */
    protected static function pauseForRateLimit(int $retryAfter): void
    {
        $seconds = min(3600, max(5, $retryAfter));

        try {
            $store = self::throttleStore();
            $first = $store->add(self::THROTTLE_PREFIX . 'paused', true, $seconds);
            self::count($store, self::THROTTLE_PREFIX . 'dropped');
        } catch (Throwable) {
            $first = true;
        }

        if ($first) {
            Log::warning('TelegramService: Telegram rate limit (429), alerts paused', ['retry_after' => $seconds]);
        }
    }

    /**
     * Add one to a counter that lives for a day.
     *
     * @param Repository $store Throttle store.
     * @param string $key Counter key.
     * @return void
     */
    protected static function count(Repository $store, string $key): void
    {
        $store->add($key, 0, 86400);
        $store->increment($key);
    }

    /**
     * Read and reset a counter; 0 when missing or unreadable.
     *
     * @param string $key Counter key.
     * @return int
     */
    protected static function pullCount(string $key): int
    {
        try {
            return (int) self::throttleStore()->pull($key, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Seconds during which the same alert is sent only once (0 turns throttling off).
     *
     * @return int
     */
    protected static function throttleSeconds(): int
    {
        return (int) config('logging.alert_throttle.seconds', 600);
    }

    /**
     * Throttle state store. Defaults to the file store: Redis is what fails in an incident.
     *
     * @return Repository
     */
    protected static function throttleStore(): Repository
    {
        $store = config('logging.alert_throttle.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : 'file');
    }

    /**
     * Escape Telegram MarkdownV2 special characters.
     *
     * @param string $text
     *
     * @return string
     */
    protected static function escapeMarkdownV2(string $text): string
    {
        // Escape all special characters for MarkdownV2
        $specials = ['_', '[', ']', '(', ')', '~', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        return str_replace(
            $specials,
            array_map(fn($c) => '\\' . $c, $specials),
            $text
        );
    }

    /**
     * Escape string for MarkdownV2 code block.
     *
     * @param string $text
     *
     * @return string
     */
    protected static function escapeCodeBlock(string $text): string
    {
        return str_replace(['\\', '`'], ['\\\\', '\\`'], $text);
    }

    protected static function subString(string $string, int $length = 4000): string
    {
        // Telegram hard limit ~4096; we default to 4000 to be safe
        if (mb_strlen($string, 'UTF-8') <= $length) {
            return $string;
        }

        $slice = mb_substr($string, 0, $length, 'UTF-8');

        // If we cut in the middle of a triple backtick, trim back to the last full fence
        $lastFencePos = mb_strrpos($slice, '```', 0, 'UTF-8');
        if ($lastFencePos !== false) {
            // Count fences in the slice to see if we're inside a code block
            $fenceCountInSlice = substr_count($slice, '```');
            if ($fenceCountInSlice % 2 !== 0) {
                // We're inside an open code block → close it
                return $slice . '```';
            }
        }

        // Also try to avoid ending with a single backtick (inline code not closed)
        // Replace triple fences temporarily to avoid counting their backticks
        $tmp = str_replace('```', '', $slice);
        if ((substr_count($tmp, '`') % 2) !== 0) {
            $slice .= '`';
        }

        Log::debug('TelegramService: Message truncated to fit length limit', [$string]);

        return $slice;
    }

    public static function warning(array $data = []): void
    {
        $data['title'] = self::setTitle(self::getIconByType('warning') . ' *Cảnh báo!*');
        self::sendThrottled('warning', $data);
    }

    public static function info(array $data = []): void
    {
        $data['title'] = self::setTitle(self::getIconByType('info') . ' *Thông báo hệ thống*');
        self::send($data);
    }
}
