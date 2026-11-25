<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Credentials;

use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationHosts;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Test" button of the credential screen: one harmless read call per type against the type's fixed host
 * (Telegram getMe, Facebook page lookup, OpenAI model list, SMTP connect). HTTP auth credentials have no fixed host,
 * so they are reported as not testable. Results never contain a payload value.
 *
 * @package Cms\Core\Services\Workflow\Credentials
 * @since   2026-11-24
 */
class WorkflowCredentialTester
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const NOT_TESTABLE = 'not_testable';

    public function __construct(private readonly SmtpMailerFactory $smtp)
    {
    }

    /**
     * @return array{status: string, message: string|null}
     */
    public function test(WorkflowCredential $credential): array
    {
        $payload = is_array($credential->payload) ? $credential->payload : [];

        try {
            $result = match ($credential->type) {
                'telegram_bot' => $this->http()->get(IntegrationHosts::TELEGRAM . '/bot' . ($payload['token'] ?? '') . '/getMe'),
                'facebook_page' => $this->http()->get(
                    IntegrationHosts::FACEBOOK . '/' . IntegrationHosts::facebookVersion() . '/' . rawurlencode((string) ($payload['page_id'] ?? '')),
                    ['fields' => 'id,name', 'access_token' => (string) ($payload['access_token'] ?? '')],
                ),
                'tts' => $this->http()->withToken((string) ($payload['api_key'] ?? ''))->get(IntegrationHosts::OPENAI . '/v1/models'),
                'smtp' => $this->checkSmtp($payload),
                default => null,
            };
        } catch (Throwable $e) {
            return $this->record($credential, self::FAILED, WorkflowCredentialStore::redact(mb_substr($e->getMessage(), 0, 300), [$payload]));
        }

        if ($result === null) {
            return ['status' => self::NOT_TESTABLE, 'message' => null];
        }
        if ($result === true) {
            return $this->record($credential, self::OK, null);
        }
        if ($result->successful()) {
            return $this->record($credential, self::OK, null);
        }

        $message = (string) ($result->json('description') ?? $result->json('error.message') ?? ('HTTP ' . $result->status()));

        return $this->record($credential, self::FAILED, WorkflowCredentialStore::redact(mb_substr($message, 0, 300), [$payload]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function checkSmtp(array $payload): bool
    {
        $this->smtp->check($payload);

        return true;
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(IntegrationHosts::connectTimeout())->timeout(IntegrationHosts::timeout())->acceptJson()
            ->withoutRedirecting();
    }

    /**
     * @return array{status: string, message: string|null}
     */
    private function record(WorkflowCredential $credential, string $status, ?string $message): array
    {
        WorkflowCredential::query()->whereKey($credential->getKey())->update([
            'last_tested_at' => now(),
            'last_test_ok' => $status === self::OK,
        ]);

        return ['status' => $status, 'message' => $message];
    }
}
