<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use App\Enums\UserStatusEnum;
use App\Models\Contact;
use Cms\Core\Models\Email;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-side helper for the Core-owned admin contact screens.
 *
 * Contact management is currently exposed by the app-level admin controller and must not depend on optional
 * project-specific modules. This service keeps list, dashboard, status, and inbox enrichment logic local to the
 * Core contact contract so deployments with only Core/Content modules can still boot contact admin routes.
 */
class ContactAdminReadService
{
    public const BLOCKED_STATUS = UserStatusEnum::USER_BANNED->value;

    public const DASHBOARD_CACHE_VERSION_KEY = 'contact.dashboard.version';

    /**
     * Apply shared admin contact filters to the supplied query.
     *
     * @param Builder $query Contact query being prepared for list, export, or dashboard metrics.
     * @param array<string, mixed> $filters Request filters from the admin UI.
     *
     * @return Builder Filtered query instance.
     */
    public function applyFilters(Builder $query, array $filters = []): Builder
    {
        $keywords = trim((string)($filters['keywords'] ?? $filters['search'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $type = trim((string)($filters['type'] ?? ''));
        $source = trim((string)($filters['source'] ?? ''));
        $sourcePrefix = trim((string)($filters['source_prefix'] ?? $filters['sourcePrefix'] ?? ''));

        return $query
            ->when($keywords !== '', function (Builder $contactQuery) use ($keywords): void {
                $contactQuery->where(function (Builder $keywordQuery) use ($keywords): void {
                    $keywordQuery->where('name', 'like', "%{$keywords}%")
                        ->orWhere('email', 'like', "%{$keywords}%")
                        ->orWhere('mobile', 'like', "%{$keywords}%")
                        ->orWhere('organization', 'like', "%{$keywords}%");
                });
            })
            ->when($status === 'deleted', fn(Builder $contactQuery): Builder => $contactQuery->onlyTrashed())
            ->when($status === 'all', fn(Builder $contactQuery): Builder => $contactQuery->withTrashed())
            ->when(
                $status !== '' && !in_array($status, ['deleted', 'all'], true),
                fn(Builder $contactQuery): Builder => $contactQuery->where('status', $status)
            )
            ->when(
                $type !== '' && $type !== 'all',
                fn(Builder $contactQuery): Builder => $contactQuery->where('type', $type)
            )
            ->when($source !== '', fn(Builder $contactQuery): Builder => $contactQuery->where('source', $source))
            ->when(
                $sourcePrefix !== '',
                fn(Builder $contactQuery): Builder => $contactQuery->where('source', 'like', "{$sourcePrefix}%")
            );
    }

    /**
     * Fetch the latest inbox email for each contact ID.
     *
     * @param Collection<int, int> $contactIds Contact primary keys from the current page.
     *
     * @return Collection<int, Email> Latest inbox emails keyed by contact ID.
     */
    public function getLatestInboxByContactIds(Collection $contactIds): Collection
    {
        if ($contactIds->isEmpty()) {
            return collect();
        }

        return Email::query()
            ->whereIn('contact_id', $contactIds->all())
            ->where('type', 'inbox')
            ->orderByDesc('id')
            ->get()
            ->groupBy('contact_id')
            ->map(static fn(Collection $items): ?Email => $items->first());
    }

    /**
     * Build a lookup of contacts that already have outbound replies.
     *
     * @param Collection<int, int> $contactIds Contact primary keys from the current page.
     *
     * @return array<int, int> Contact IDs keyed for fast replied-state checks.
     */
    public function getRepliedLookup(Collection $contactIds): array
    {
        if ($contactIds->isEmpty()) {
            return [];
        }

        return Email::query()
            ->whereIn('contact_id', $contactIds->all())
            ->where(static function (Builder $query): void {
                $query->where('type', 'outbound')
                    ->orWhereNotNull('reply_id');
            })
            ->distinct()
            ->pluck('contact_id')
            ->flip()
            ->all();
    }

    /**
     * Restrict operational dashboard metrics to contacts that remain actionable.
     */
    public function excludeBlockedContacts(Builder $query): Builder
    {
        return $query->where(static function (Builder $contactQuery): void {
            $contactQuery->whereNull('status')
                ->orWhere('status', '!=', self::BLOCKED_STATUS);
        });
    }

    /**
     * Resolve the admin-facing contact workflow status.
     *
     * @param Contact $contact Contact being rendered.
     * @param array<int, int|bool> $repliedLookup Lookup from getRepliedLookup().
     *
     * @return string Admin status label key.
     */
    public function resolveContactStatus(Contact $contact, array $repliedLookup = []): string
    {
        if ($contact->status === self::BLOCKED_STATUS) {
            return 'blocked';
        }

        if (isset($repliedLookup[$contact->id])) {
            return 'replied';
        }

        if ($contact->user_id) {
            return 'assigned';
        }

        return 'new';
    }

    /**
     * Format a source string for display without assuming a project-specific source owner.
     *
     * @param string|null $source Raw contact source value.
     *
     * @return string|null Display label for the admin UI.
     */
    public function formatSourceLabel(?string $source): ?string
    {
        return $source ?: null;
    }

    /**
     * Convert a contact and optional inbox email into the admin API record shape.
     *
     * @param Contact $contact Contact model selected by the admin query.
     * @param Email|null $email Latest inbox email for this contact.
     * @param array<int, int|bool> $repliedLookup Lookup from getRepliedLookup().
     *
     * @return array<string, mixed> Contact payload consumed by admin contact pages.
     */
    public function mapContactRecord(Contact $contact, ?Email $email = null, array $repliedLookup = []): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'email' => $contact->email,
            'mobile' => $contact->mobile,
            'address' => $contact->address,
            'organization' => $contact->organization,
            'status' => $this->resolveContactStatus($contact, $repliedLookup),
            'raw_status' => $contact->status,
            'source' => $contact->source,
            'source_label' => $this->formatSourceLabel($contact->source),
            'type' => $contact->type,
            'user_id' => $contact->user_id,
            'is_subscribe' => $contact->is_subscribe,
            'page_url' => $contact->url,
            'subject' => $email?->subject,
            'message_excerpt' => str((string)($email?->content ?? ''))
                ->squish()
                ->limit(180)
                ->toString(),
            'showroom_context' => null,
            'is_showroom_inquiry' => false,
            'received_at' => optional($email?->created_at ?: $contact->created_at)?->toDateTimeString(),
            'created_at' => optional($contact->created_at)?->toDateTimeString(),
            'updated_at' => optional($contact->updated_at)?->toDateTimeString(),
        ];
    }
}
