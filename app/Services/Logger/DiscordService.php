<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services\Logger;

use Throwable;
use JsonException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Contracts\LoggerServiceInterface;
use Cms\Core\Services\Security\LogRedactor;

// For file storage in production

class DiscordService implements LoggerServiceInterface
{
    protected static string $botToken = '';
protected static string $channelId = '';
        protected static string $iconUrl = ''; // The default channel ID to send messages to
protected static string $apiUrl = 'https://discord.com/api/v10/';
        /** @var bool */
    public bool $isDiscovery = true; // Discord API base URL

    /**
     * Initializes the service with Discord bot token and default channel ID.
     */
    public static function init(): void
    {
        self::$botToken = config('services.discord.bot_token');
        self::$channelId = config('services.discord.default_channel_id'); // e.g., channel ID for system alerts
        self::$iconUrl = (string) config('services.discord.icon_url', ''); // embed icon; empty = none

        if (empty(self::$botToken) || empty(self::$channelId)) {
            Log::error('DiscordService: Bot Token or Default Channel ID not set in configuration.');
            return;
        }
    }

    /**
     * Sends an error notification to Discord.
     */
    public static function error(array $data = []): void
    {
        $data['title'] = self::setTitle(self::getIconByType('error') . ' **Error Alert!**');

        self::send($data, 'error');
    }

    /**
     * Sets the title for the Discord message embed.
     */
    public static function setTitle(string $title = ''): string
    {
        if (empty($title)) {
            $title = 'System Notification!';
        }

        // Discord embeds use Markdown, so bold with ** and italic with *
        return $title;
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

    /**
     * Sends a message to Discord using a bot token.
     */
    public static function send(array $data = [], string $type = 'info'): bool
    {
        try {
            if (!self::$botToken || !self::$channelId) {
                self::init(); // Ensure token and channel ID are set
                if (!self::$botToken || !self::$channelId) { // Check again after init
                    return false; // Still missing, cannot proceed
                }
            }

            // Every message leaves the system: mask personal data and secrets whatever the caller sent
            $redactor = app(LogRedactor::class);
            $data = $redactor->redact($data);

            $fields = collect();
            $env = str(config('app.env', env('APP_ENV', 'DEBUG')))->upper();

            // Determine the target channel ID
            $channelId = $data['channel_id'] ?? self::$channelId;
            // Discord threads are specific to messages. If you mean sending to a specific thread,
            // you'd need the thread ID and potentially send a message to the thread's channel.
            // For simplicity, we'll focus on channel messages here.
            // Discord does not have a direct 'message_thread_id' for initial messages like Telegram.

            $messageContent = $data['message'];
            $title = $data['title'] ?? self::setTitle(); // Fallback if no title is set

            // Format base message content for embed description
            $description = $messageContent ? "__**Message:**__\n```\n$messageContent\n```" : null;

            // Handle exception details
            $exception = $data['exception'] ?? null;
            $attachTrace = config('app.env') === 'production';

            if ($exception instanceof Throwable) {
                $fields->push([
                    'name' => 'File & Line:',
                    'value' => '```' . htmlspecialchars($exception->getFile() . ':' . $exception->getLine()) . '```',
                ]);

                if ($attachTrace) {
                    $traceContent = "Exception: " . $exception->getMessage() . "\n";
                    $traceContent .= $exception->getFile() . ":" . $exception->getLine() . "\n";
                    $traceContent .= "Trace:\n" . $exception->getTraceAsString();
                    $traceContent = $redactor->redactString($traceContent);

                    $fileName = 'trace_' . now()->format('Ymd_His') . '.log';
                    $filePath = "logs/discord_errors/$fileName";

                    // Ensure directory exists
                    File::ensureDirectoryExists(storage_path('app/public/logs/discord_errors'));

                    // Save the file to public disk for web access if in production
                    if (config('app.env') === 'production') {
                        Storage::disk('public')->put($filePath, $traceContent);
                        $traceFileUrl = url("storage/$filePath");

                        $fields->push([
                            'name' => 'Trace Log',
                            'value' => "> 📄 [Click to view Trace Log]($traceFileUrl)",
                        ]);
                    } else {
                        // For non-production, save locally without making it publicly accessible via URL
                        File::put(storage_path("app/public/$filePath"), $traceContent);
                        // In development, you might just want to view the file locally,
                        // or provide a placeholder URL if public access is not set up.
                    }
                }
            }

            // Remove all previous keys used from the data array for "additional info"
            unset($data['message'], $data['title'], $data['exception'], $data['isAttach'], $data['file'], $data['line'], $data['channel_id']);
            // Add a break line before additional data
            // Prepare Discord embed fields for additional data
            if (!empty($data)) {
                //  In every 2 additional data, we will create a new field breaking the line
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        try {
                            // REMOVE htmlspecialchars here for JSON
                            $fieldValue = "```json\n" . self::subString(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 1010) . "\n```";
                        } catch (JsonException $e) {
                            $fieldValue = "> " . self::subString(print_r($value, true), 1022); // Fallback if JSON encoding fails
                        }
                    } else {
                        // Apply htmlspecialchars for non-JSON string values if needed,
                        // Replace all "\n" with "> " for blockquote style in every line
                        $fieldValue = "> " . self::subString(str_replace("\n", "\n> ", htmlspecialchars((string)$value)), 1022);
                    }

                    $fields->push([
                        'name' => htmlspecialchars((string)$key),
                        'value' => $fieldValue,
                    ]);
                }
            }

            $fields->push([
                'name' => 'Request URL',
                'value' => '```' . htmlspecialchars($redactor->redactUrl(request()->fullUrl())) . '```',
            ]);

            // Construct the Discord embed
            $embed = [
                'title' => self::subString($title, 256),
                'description' => self::subString($description, 4096),
                'color' => self::getColorCode($type),
                'timestamp' => now()->toISOString(),
                // Discord rejects an empty icon_url, so the key is left out when no icon is configured.
                'footer' => array_filter([
                    'text' => self::subString(
                        'iSPA CMS Notification • ' . request()->ip(),
                        1011
                    ),
                    'icon_url' => self::$iconUrl,
                ]),
                'fields' => $fields->toArray(),
                'author' => array_filter([
                    'name' => config('app.name', 'iSPA CMS') . " ($env)",
                    'icon_url' => self::$iconUrl,
                ]),
            ];

            $payload = [
                'embeds' => [$embed],
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bot ' . self::$botToken,
                'Content-Type' => 'application/json',
            ])->post(self::$apiUrl . "channels/$channelId/messages", $payload);

            if ($response->failed()) {
                Log::error('DiscordService: Failed to send log', ['response' => $response->body(), 'status' => $response->status()]);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('DiscordService: Failed to send log via bot.', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return false;
        }
    }

    protected static function subString(string $string, int $length = 1000): string
    {
        if (strlen($string) < $length) {
            return $string;
        }

        return substr($string, 0, $length - 3) . '...';
    }

    protected static function getColorCode(string $type): int
    {
        return match ($type) {
            'error' => 15682705, // Red-rose color for errors (DEC for #E74C3C)
            'warning' => 16091659, // Yellow color for warnings (DEC for #FFFF00)
            'info' => 693972, // Cyan color for info (DEC for #1E90FF)
            default => 7042176, // gray color for default (DEC for #00FF00)
        };
    }

    /**
     * Sends a warning notification to Discord.
     */
    public static function warning(array $data = []): void
    {
        $data['title'] = self::setTitle(self::getIconByType('warning') . ' **Warning!**');
        self::send($data, 'warning');
    }

    /**
     * Sends an informational notification to Discord.
     */
    public static function info(array $data = []): void
    {
        $data['title'] = self::setTitle(self::getIconByType('info') . ' **Information!**');
        self::send($data);
    }
}
