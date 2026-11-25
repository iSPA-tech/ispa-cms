<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * HMAC seal of a published workflow version.
 *
 * seal = HMAC-SHA256(key, "<project_id>|<number>|<content_hash>") with
 * key = hash_hkdf('sha256', <decoded APP_KEY>, 32, 'workflow-version-seal'). The key never lives in the database, so
 * a direct database write can change a version's graph but cannot produce a matching seal. An APP_KEY rotation makes
 * every seal fail: run `cms:workflow-reseal --force` right after rotating (see WorkflowResealCommand).
 *
 * Versions that are not live (`pending_review`, `cooling_off`, and the `cancelled` / `rejected` ones they become) are
 * sealed under another HKDF domain (`workflow-version-pending-seal`): their seal proves their content was not changed
 * while they waited, but it is never a run seal, so verify() refuses them. The publish service re-seals a version with
 * the run domain only when it goes live.
 *
 * The project's live pointer is sealed too (review fix 15, 2026-11-25): `workflow_projects.published_version_seal` =
 * HMAC-SHA256(hkdf(APP_KEY, 'workflow-live-pointer-seal'), "<project_id>|<published_version_id>"), written by the
 * publish service when a version goes live and re-sealed by `cms:workflow-reseal`; RunGraph::published() refuses a
 * pointer whose seal does not match.
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
final class WorkflowVersionSeal
{
    public const HKDF_INFO = 'workflow-version-seal';

    /** Domain of versions waiting for change control (never runnable). */
    public const PENDING_HKDF_INFO = 'workflow-version-pending-seal';

    /** Domain of the project's live pointer seal (`workflow_projects.published_version_seal`). */
    public const POINTER_HKDF_INFO = 'workflow-live-pointer-seal';

    /** Statuses sealed with the run domain. */
    public const LIVE_STATUSES = ['published', 'superseded'];

    /**
     * Seal value for a version's identity and content hash, in the domain of its status (run seal for live statuses).
     */
    public static function seal(WorkflowVersion $version): string
    {
        return self::sign((int) $version->project_id, (int) $version->number, (string) $version->content_hash, null, self::domainOf($version));
    }

    /**
     * Whether the stored graph matches its content hash and the stored seal is a RUN seal (both compared in constant
     * time). A pending version's seal never passes.
     */
    public static function verify(WorkflowVersion $version): bool
    {
        return self::verifyIn($version, self::HKDF_INFO);
    }

    /**
     * Whether a waiting version's content and pending-domain seal are intact (checked before it goes live).
     */
    public static function verifyPending(WorkflowVersion $version): bool
    {
        return self::verifyIn($version, self::PENDING_HKDF_INFO);
    }

    public static function domainOf(WorkflowVersion $version): string
    {
        return in_array((string) $version->status, self::LIVE_STATUSES, true) ? self::HKDF_INFO : self::PENDING_HKDF_INFO;
    }

    private static function verifyIn(WorkflowVersion $version, string $domain): bool
    {
        $raw = $version->getRawOriginal('graph') ?? $version->getAttributes()['graph'] ?? null;
        $storedHash = (string) $version->content_hash;
        $storedSeal = (string) $version->seal;
        if (!is_string($raw) || $storedHash === '' || $storedSeal === '') {
            return false;
        }

        $hashOk = hash_equals($storedHash, WorkflowVersionSnapshot::hashText($raw));

        try {
            $sealOk = hash_equals(self::sign((int) $version->project_id, (int) $version->number, $storedHash, null, $domain), $storedSeal);
        } catch (RuntimeException) {
            $sealOk = false;
        }

        return $hashOk && $sealOk;
    }

    /**
     * Pure seal function (also used by the migration backfill).
     */
    public static function sign(int $projectId, int $number, string $contentHash, ?string $appKey = null, string $domain = self::HKDF_INFO): string
    {
        return hash_hmac('sha256', $projectId . '|' . $number . '|' . $contentHash, self::key($appKey ?? (string) config('app.key'), $domain));
    }

    /**
     * Seal of a project's live pointer: HMAC-SHA256 over "<project_id>|<published_version_id>" under its own HKDF domain
     * of APP_KEY (review fix 15). A database write that re-points the project at another version (a silent rollback)
     * cannot produce a matching value.
     */
    public static function signPointer(int $projectId, int $versionId, ?string $appKey = null): string
    {
        return hash_hmac('sha256', $projectId . '|' . $versionId, self::key($appKey ?? (string) config('app.key'), self::POINTER_HKDF_INFO));
    }

    /**
     * Whether the project's stored pointer seal matches its `published_version_id` (constant-time compare). A project
     * without a pointer or without a seal never passes.
     */
    public static function verifyPointer(WorkflowProject $project, ?string $appKey = null): bool
    {
        $versionId = (int) ($project->published_version_id ?? 0);
        $stored = (string) ($project->getAttribute('published_version_seal') ?? '');
        if ($versionId <= 0 || $stored === '') {
            return false;
        }

        try {
            return hash_equals(self::signPointer((int) $project->getKey(), $versionId, $appKey), $stored);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Whether the database schema has the pointer seal column (a site before the 2026-11-25 migration has none; the
     * check is skipped there until the migration adds and backfills it). Asked once per process (`once()` is flushed
     * between tests); queue workers restart at deploy.
     */
    public static function pointerSealed(): bool
    {
        return once(static fn (): bool => Schema::hasColumn((new WorkflowProject())->getTable(), 'published_version_seal'));
    }

    /**
     * Whether $version's seal was made with $appKey (an APP_KEY string as in .env, `base64:` prefix allowed), in the
     * domain of its status (the reseal command keeps pending versions pending).
     */
    public static function sealedWith(WorkflowVersion $version, string $appKey): bool
    {
        try {
            return hash_equals(
                self::sign((int) $version->project_id, (int) $version->number, (string) $version->content_hash, $appKey, self::domainOf($version)),
                (string) $version->seal
            );
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @throws RuntimeException When the key is empty (never seal with an empty key).
     */
    private static function key(string $appKey, string $domain): string
    {
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is not set; workflow versions can not be sealed or verified.');
        }

        return hash_hkdf('sha256', $appKey, 32, $domain);
    }
}
