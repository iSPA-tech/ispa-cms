<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Str;

/**
 * Hashes and ids: `hash` (`algo` sha256|sha512|sha1|md5 of `inputExpr`), `hmac` (key from a `$bind:<name>` credential
 * only, never a literal key in the graph), `uuid`, `random` (`length` bytes as hex). No encryption or decryption.
 *
 * `hmac` takes its key from an `http_header` credential (WorkflowCredentialStore; the key is the credential's `value`):
 * a missing credential or one of another type fails the step, and the key never reaches the result or the logs.
 */
class CryptoRunner implements StepRunner
{
    use NodeHelpers;

    public const OPS = ['hash', 'hmac', 'uuid', 'random'];
    protected const ALGOS = ['sha256', 'sha512', 'sha1', 'md5'];
    /** Credential type that holds an hmac key (its `value` field); no separate key type. */
    public const KEY_CREDENTIAL_TYPE = 'http_header';

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $op = (string) ($config['op'] ?? '');
        $path = $this->storePath($config, 'vars.crypto');
        $algo = (string) ($config['algo'] ?? 'sha256');

        if (!in_array($op, self::OPS, true)) {
            return StepRunResult::failed('crypto: unknown op');
        }
        if ($path === null) {
            return StepRunResult::failed('crypto: storeAs must start with vars.');
        }
        if (in_array($op, ['hash', 'hmac'], true) && !in_array($algo, self::ALGOS, true)) {
            return StepRunResult::failed('crypto: algo must be one of ' . implode(', ', self::ALGOS));
        }

        try {
            $value = match ($op) {
                'uuid' => (string) Str::uuid(),
                'random' => bin2hex(random_bytes(max(1, min(256, (int) ($config['length'] ?? 16))))),
                'hash' => hash($algo, $this->input($config, $context)),
                'hmac' => hash_hmac($algo, $this->input($config, $context), $this->bindingKey($config, $context, (string) ($step->stepCode ?? ''))),
            };
        } catch (\Throwable $e) {
            return StepRunResult::failed('crypto: ' . $e->getMessage());
        }

        $context->setVarPath($path, $value);

        return StepRunResult::success(['op' => $op]);
    }

    protected function input(array $config, WorkflowContext $context): string
    {
        $input = $this->evalField($config, 'inputExpr', $context, '');
        if (is_array($input) || is_object($input)) {
            return (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $input;
    }

    protected function bindingKey(array $config, ?WorkflowContext $context = null, string $stepCode = ''): string
    {
        if (WorkflowCredentialStore::bindingName($config['key'] ?? null) === null) {
            throw new \InvalidArgumentException('hmac key must be a $bind:<name> credential');
        }
        $store = app(WorkflowCredentialStore::class);
        $secret = $store->resolveBinding($config['key'], self::KEY_CREDENTIAL_TYPE)['value'] ?? null;
        $instanceId = $context?->getVarPath('__runtime.instance_id');
        $store->recordUse(is_numeric($instanceId) ? (int) $instanceId : 0, $stepCode, (string) WorkflowCredentialStore::bindingName($config['key']));
        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException('the hmac credential has no value');
        }

        return $secret;
    }
}
