<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `telegram_send`: a text message, or a photo with a caption, to a Telegram chat or channel through a bot.
 *
 * Config: `credential` ($bind:<telegram_bot>), `chat` (id or @channel), `text` (template), `media` (photo URL,
 * optional), `parse_mode` (HTML|MarkdownV2|none, default none), `silent` (bool), `storeAs`.
 * Result: {sent, message_id, chat}.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
class TelegramSendRunner extends IntegrationRunner
{
    private const TEXT_LIMIT = 4096;

    private const CAPTION_LIMIT = 1024;

    protected function type(): string
    {
        return 'telegram_send';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $bot = $this->bind($config['credential'] ?? null, 'telegram_bot');
        $chat = $this->renderText($config['chat'] ?? '', $context);
        $text = $this->renderText($config['text'] ?? '', $context);
        $media = $this->renderText($config['media'] ?? '', $context);

        if (!preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/', $chat)) {
            throw new IntegrationFailure('chat must be a chat id or an @channel name.');
        }
        if ($text === '' && $media === '') {
            throw new IntegrationFailure('nothing to send: text and media are empty.');
        }
        if ($media !== '' && !preg_match('#^https://#i', $media)) {
            throw new IntegrationFailure('media must be an https URL.');
        }

        $params = ['chat_id' => $chat, 'disable_notification' => filter_var($config['silent'] ?? false, FILTER_VALIDATE_BOOL)];
        $parseMode = (string) ($config['parse_mode'] ?? '');
        if (in_array($parseMode, ['HTML', 'MarkdownV2'], true)) {
            $params['parse_mode'] = $parseMode;
        }

        if ($media !== '') {
            $method = 'sendPhoto';
            $params['photo'] = $media;
            $params['caption'] = mb_substr($text, 0, self::CAPTION_LIMIT);
        } else {
            $method = 'sendMessage';
            $params['text'] = mb_substr($text, 0, self::TEXT_LIMIT);
        }

        $response = $this->http()->asJson()->post(IntegrationHosts::TELEGRAM . '/bot' . ($bot['token'] ?? '') . '/' . $method, $params);
        $this->ensureOk($response, 'Telegram ' . $method);

        $result = [
            'sent' => true,
            'message_id' => $response->json('result.message_id'),
            'chat' => $chat,
        ];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'telegram_send',
            'method' => $method,
            'chat' => $chat,
            'text_length' => mb_strlen($text),
        ]]);
    }
}
