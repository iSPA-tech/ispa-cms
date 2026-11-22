<?php

declare(strict_types=1);

namespace Cms\Widget\Audit;

use Cms\Core\Events\AuditLogEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Durable audit writer for high-stakes Site Layout actions (plan Chunk 2
 * Task 14; docs/architecture/adr-widget-audit-durability.md, Accepted).
 *
 * Reuses Cms\Core\Models\AuditLog -- no parallel audit table (ADR decision
 * 1) -- through the existing synchronous AuditLogEvent -> AuditLogHandler
 * chain: cms/Core/app/Listeners/AuditLogHandler.php is a plain,
 * non-ShouldQueue listener registered against AuditLogEvent in
 * cms/Core/app/Providers/EventServiceProvider.php, so dispatching the event
 * from inside an open DB::transaction() writes the AuditLog row in that same
 * transaction; a rollback removes it (ADR decision 3, G-007's executable
 * proof owner is WidgetAuditWriterTest).
 *
 * DISPATCH IS EXPLICIT (ADR decision 4). Cms\Core\Observers\AbstractObserver::audit()'s
 * body is commented out, so no Widget model automatically produces an audit
 * row -- every high-stakes Widget service (publish/rollback/approval/
 * revocation/trust-change; Tasks 20/21/etc.) must call writeDurable() itself,
 * from inside its own transaction. This class never opens or manages a
 * transaction of its own.
 *
 * Excluded fields (ADR decision 6; never written to the audit payload): full
 * HTML, Input Values, provider payloads, tokens, credentials, raw preview
 * overlays. Checked recursively, case-insensitively, at every key in the
 * $context payload -- see self::BANNED_KEYS.
 *
 * Required fields (ADR decision 5): actor id and project; action and target
 * identity; source/destination release; correlation id; note/result. All of
 * these, plus caller-supplied safe metadata, are written into AuditLog's
 * free-form `changed` JSON column (see writeDurable()'s docblock for why:
 * AuditLogEvent/AuditLogHandler, both Core-owned and not modified by this
 * module, hardcode the real `options` DB column from icon/color/method only).
 */
final class WidgetAuditWriter
{
    /**
     * Key names never permitted anywhere in the audit context payload
     * (matched case-insensitively, at any nesting depth). Combines
     * adr-widget-audit-durability.md decision 6 with the frozen idempotency
     * `banned_retained_fields` list
     * (cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json).
     *
     * @var list<string>
     */
    private const BANNED_KEYS = [
        'values',
        'value',
        'html',
        'full_html',
        'raw_html',
        'input_values',
        'provider_payload',
        'preview_overlay',
        'raw_preview_overlay',
        'response_body',
        'raw_request',
        'token',
        'tokens',
        'api_key',
        'authorization',
        'credential',
        'credentials',
        'password',
        'passwords',
        'secret',
        'secrets',
    ];

    /**
     * Maximum length of the AuditLogEvent action string once prefixed with
     * "action." by AuditLogHandler, matching audit_logs.action's char(50)
     * column (cms/Core/database/migrations/2022_02_09_103828_create_audit_logs_table.php).
     */
    private const MAX_PREFIXED_ACTION_LENGTH = 50;

    /**
     * Dispatch a durable audit row for a high-stakes Site Layout action.
     *
     * MUST be called from inside the caller's own DB::transaction() closure
     * for actions the ADR lists as requiring same-transaction durability
     * (publish, rollback, eligibility approval/revocation, trust changes,
     * Safe Import, reference-blocked destructive attempts, protected
     * artifact retry). This method does not open or manage a transaction --
     * that is the calling service's responsibility.
     *
     * @param string $action Dot-form action name, e.g. "widget_layout.publish".
     * @param array{
     *     actor_id?: int,
     *     project?: string,
     *     target?: Model,
     *     target_type?: string,
     *     target_id?: int,
     *     source_release?: int|string|null,
     *     destination_release?: int|string|null,
     *     note?: string|null,
     *     result?: string|null,
     *     correlation_id?: string|null,
     *     idempotency_key_hash?: string|null,
     *     metadata?: array<array-key, mixed>,
     *     icon?: string|null,
     *     color?: string|null,
     * } $context
     *
     * @return string The correlation id used for this audit row (caller-supplied or freshly minted).
     */
    public function writeDurable(string $action, array $context): string
    {
        $action = trim($action);
        if ($action === '') {
            throw new InvalidArgumentException('WidgetAuditWriter::writeDurable() requires a non-empty action.');
        }

        if (strlen('action.' . $action) > self::MAX_PREFIXED_ACTION_LENGTH) {
            throw new InvalidArgumentException(
                "WidgetAuditWriter::writeDurable() action '{$action}' exceeds the audit_logs.action column length."
            );
        }

        $this->assertNoBannedKeys($context);

        $actorId = $context['actor_id'] ?? null;
        if (! is_int($actorId) || $actorId <= 0) {
            throw new InvalidArgumentException('WidgetAuditWriter::writeDurable() requires a positive integer actor_id.');
        }

        $project = $context['project'] ?? null;
        if (! is_string($project) || trim($project) === '') {
            throw new InvalidArgumentException('WidgetAuditWriter::writeDurable() requires a non-empty project.');
        }

        [$targetType, $targetId] = $this->resolveTarget($context);

        $correlationId = $context['correlation_id'] ?? null;
        if (! is_string($correlationId) || trim($correlationId) === '') {
            $correlationId = (string) Str::uuid();
        }

        $metadata = $context['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('WidgetAuditWriter::writeDurable() metadata must be an array.');
        }

        $changed = [
            'actor_id' => $actorId,
            'project' => $project,
            'action' => $action,
            'source_release' => $context['source_release'] ?? null,
            'destination_release' => $context['destination_release'] ?? null,
            'note' => $context['note'] ?? null,
            'result' => $context['result'] ?? null,
            'correlation_id' => $correlationId,
            // ADR decision 5 lists "correlation ID and idempotency-key hash"
            // together as first-class frozen payload fields -- a dedicated
            // top-level slot so Task 20/21 callers never smuggle the hash
            // through metadata.
            'idempotency_key_hash' => $context['idempotency_key_hash'] ?? null,
            // "Store ... safe metadata in options" (plan Chunk 2 Task 14
            // checklist): nested under an explicit "options" key inside
            // AuditLog's free-form `changed` JSON column. AuditLogEvent has
            // no constructor parameter for the real `options` DB column --
            // AuditLogHandler::handle() (Core-owned, not modified by this
            // module) always builds that column from icon/color/method only.
            // This is a disclosed, deliberate choice that keeps
            // WidgetAuditWriter entirely inside the existing, unmodified
            // Core event/listener chain rather than forking it.
            'options' => $metadata,
        ];

        event(new AuditLogEvent(
            $action,
            $changed,
            $targetType,
            $targetId,
            $context['icon'] ?? null,
            $context['color'] ?? null,
            $actorId,
        ));

        return $correlationId;
    }

    /**
     * Resolve target identity from either an explicit target_type/target_id
     * pair or a convenience Eloquent Model instance under 'target'.
     *
     * @param array<string, mixed> $context
     * @return array{0: string, 1: int}
     */
    private function resolveTarget(array $context): array
    {
        $target = $context['target'] ?? null;
        if ($target instanceof Model) {
            $key = $target->getKey();
            if (is_int($key) || (is_string($key) && ctype_digit($key))) {
                return [get_class($target), (int) $key];
            }

            // Dedicated branch: a Model WAS passed, so falling through to the
            // generic "requires target identity" error below would be
            // misleading. This happens for an unsaved model (null key) or a
            // model whose primary key is not an integer.
            throw new InvalidArgumentException(
                'WidgetAuditWriter::writeDurable() received a target Model with a non-integer primary key '
                . '(unsaved model, or non-numeric key): pass explicit target_type + integer target_id instead.'
            );
        }

        $targetType = $context['target_type'] ?? null;
        $targetId = $context['target_id'] ?? null;

        if (is_string($targetType) && trim($targetType) !== '' && is_int($targetId) && $targetId > 0) {
            return [$targetType, $targetId];
        }

        throw new InvalidArgumentException(
            'WidgetAuditWriter::writeDurable() requires target identity: pass target_type + target_id, or a target Model instance.'
        );
    }

    /**
     * Recursively reject any banned key anywhere in the context payload.
     *
     * @param array<array-key, mixed> $data
     */
    private function assertNoBannedKeys(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_string($key) && in_array(strtolower($key), self::BANNED_KEYS, true)) {
                throw new InvalidArgumentException(
                    "WidgetAuditWriter::writeDurable() rejected banned audit field '{$currentPath}' "
                    . '(values/HTML/tokens/credentials are never audited).'
                );
            }

            if (is_array($value)) {
                $this->assertNoBannedKeys($value, $currentPath);
            }
        }
    }
}
