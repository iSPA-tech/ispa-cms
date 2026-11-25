<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialException;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared base of the messaging nodes (telegram_send, email_send, facebook_page_post, text_to_speech).
 *
 * Redaction rule: every payload a runner resolves through `$bind:` is remembered, and the result data, logs and error
 * message of the step pass through WorkflowCredentialStore::redact() with those payloads, so no secret value (token,
 * password, API key, also URL-encoded) can reach `workflow_instance_steps`, `vars` or logs. Logs carry sizes, not
 * bodies. Outbound calls use Laravel HTTP with connect/total timeouts, no redirects, and the fixed host of the
 * integration (IntegrationHosts).
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
abstract class IntegrationRunner implements StepRunner
{
    /** @var list<array<string, mixed>> Payloads resolved during the current run() call. */
    private array $resolved = [];

    /** Run and step of the current run() call, for the credential use record (B6). */
    private int $instanceId = 0;

    private string $stepCode = '';

    public function __construct(
        protected TemplateRenderer $renderer = new TemplateRenderer(),
        protected ?WorkflowCredentialStore $credentials = null,
    ) {
        $this->credentials ??= app(WorkflowCredentialStore::class);
    }

    /** Step type, used in messages and logs. */
    abstract protected function type(): string;

    /**
     * Do the work. Throw WorkflowCredentialException or IntegrationFailure for a clean failure.
     */
    abstract protected function execute(array $config, WorkflowContext $context): StepRunResult;

    final public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $this->resolved = [];
        $instanceId = $context->getVarPath('__runtime.instance_id');
        $this->instanceId = is_numeric($instanceId) ? (int) $instanceId : 0;
        $this->stepCode = (string) ($step->stepCode ?? '');

        try {
            $result = $this->execute($step->stepConfig ?? [], $context);
        } catch (WorkflowCredentialException|IntegrationFailure $e) {
            $result = StepRunResult::failed($this->type() . ': ' . $e->getMessage());
        } catch (Throwable $e) {
            $result = StepRunResult::failed($this->type() . ' failed: ' . mb_substr($e->getMessage(), 0, 500));
        }

        return $this->redacted($result);
    }

    /**
     * Resolve a `$bind:<name>` config value and remember the payload for redaction.
     *
     * @return array<string, mixed>
     */
    protected function bind(mixed $configValue, string $type): array
    {
        $payload = $this->credentials->resolveBinding($configValue, $type);
        $this->resolved[] = $payload;
        $this->credentials->recordUse($this->instanceId, $this->stepCode, (string) WorkflowCredentialStore::bindingName($configValue));

        return $payload;
    }

    protected function render(mixed $value, WorkflowContext $context): mixed
    {
        return $this->renderer->renderMixed($value, $context);
    }

    protected function renderText(mixed $value, WorkflowContext $context): string
    {
        $rendered = $this->render($value, $context);
        if (is_array($rendered)) {
            return (string) json_encode($rendered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim((string) ($rendered ?? ''));
    }

    /**
     * Store a (redacted) result under `storeAs` when it names a `vars.` path.
     *
     * @param array<string, mixed> $config
     */
    protected function store(array $config, WorkflowContext $context, mixed $value): void
    {
        $storeAs = $config['storeAs'] ?? null;
        if (is_string($storeAs) && str_starts_with($storeAs, 'vars.') && strlen($storeAs) > 5) {
            $context->setVarPath(substr($storeAs, 5), WorkflowCredentialStore::redact($value, $this->resolved));
        }
    }

    protected function http(): PendingRequest
    {
        return Http::connectTimeout(IntegrationHosts::connectTimeout())
            ->timeout(IntegrationHosts::timeout())
            ->acceptJson()
            ->withoutRedirecting();
    }

    /**
     * Turn a non-2xx answer into an IntegrationFailure with the provider's own error text.
     *
     * @throws IntegrationFailure
     */
    protected function ensureOk(Response $response, string $what): void
    {
        if ($response->successful() && $response->json('ok') !== false) {
            return;
        }

        $detail = $response->json('description') ?? $response->json('error.message') ?? null;
        throw new IntegrationFailure(trim($what . ' failed (HTTP ' . $response->status() . ')' . (is_string($detail) ? ': ' . mb_substr($detail, 0, 300) : '')));
    }

    private function redacted(StepRunResult $result): StepRunResult
    {
        if ($this->resolved === []) {
            return $result;
        }

        $result->resultData = WorkflowCredentialStore::redact($result->resultData, $this->resolved);
        $result->logs = WorkflowCredentialStore::redact($result->logs, $this->resolved);
        if (is_array($result->error)) {
            $result->error = WorkflowCredentialStore::redact($result->error, $this->resolved);
        }
        $this->resolved = [];

        return $result;
    }
}
