<?php

declare(strict_types=1);

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Models\Agents\AgentApproval;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Feeds the "my tasks" list and the dashboard strip with agent approvals that wait for a decision. Visibility follows the
 * approvals list API: whoever may view agentic resources sees every pending approval. Record data follows it too: the
 * artifact payload summary is shown only to users who may approve (the API hides the payload from everyone else), so
 * view-only users see the artifact type or the approval id instead.
 */
final class AgentApprovalAttentionProvider
{
    public const KEY = 'agentic_approvals_pending';

    private const ITEM_LIMIT = 10;

    /**
     * Register the provider with the shared attention registry.
     */
    public static function register(AttentionRegistry $registry): void
    {
        $provider = new self();
        $registry->register(
            self::KEY,
            static fn (Authenticatable $user): bool => $user instanceof User && app(AgenticAuthorization::class)->canView($user),
            static fn (Authenticatable $user): int => AgentApproval::query()->where('status', 'pending')->count(),
            static fn (Authenticatable $user): array => $provider->items($user),
            ['route' => 'setting-agentic-approvals', 'query' => ['status' => 'pending']]
        );
    }

    /**
     * Up to ten oldest pending approvals. The title uses the artifact payload summary only when the user may approve;
     * otherwise it falls back to the artifact type or the approval id, so no payload data reaches view-only users.
     *
     * @param Authenticatable $user The user the list is built for.
     * @return array<int, array<string, mixed>>
     */
    public function items(Authenticatable $user): array
    {
        $canSeePayload = $user instanceof User && app(AgenticAuthorization::class)->canApprove($user);

        return AgentApproval::query()
            ->with('artifact')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::ITEM_LIMIT)
            ->get()
            ->map(static function (AgentApproval $approval) use ($canSeePayload): array {
                $summary = $canSeePayload ? data_get($approval->artifact?->payload, 'summary') : null;

                $hasSummary = is_string($summary) && $summary !== '';
                $type = (string) ($approval->artifact?->artifact_type ?? '');
                $item = [
                    'title' => $hasSummary ? $summary : '',
                    'route' => 'setting-agentic-approval-detail',
                    'params' => ['id' => $approval->id],
                    'at' => $approval->created_at?->toIso8601String(),
                    'at_label' => 'text.my_tasks.at.created',
                ];
                if (! $hasSummary) {
                    // Never a raw code: the page translates the type key and falls back to the generic one.
                    if (preg_match('/^[a-z0-9_]+$/', $type) === 1) {
                        $item['title_key'] = 'text.agentic.artifact.' . $type;
                    }
                    $item['title_fallback_key'] = 'text.agentic.artifact.unknown';
                }

                return $item;
            })
            ->all();
    }
}
