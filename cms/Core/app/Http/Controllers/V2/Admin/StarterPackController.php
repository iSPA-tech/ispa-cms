<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Cms\Core\Services\StarterPacks\StarterPackManifest;
use Cms\Core\Services\StarterPacks\StarterPackPlan;
use Cms\Core\Services\StarterPacks\StarterPackPlanner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin API for starter packs: list, preview (plan) and application history, and apply.
 *
 * Permissions: `starter_pack.view` reads, `starter_pack.apply` also reads
 * (whoever may apply must be able to preview first) and applies. The permissions are seeded by the Core permission
 * seeder and granted to Webmaster only. Order of checks: missing permission → 403, unknown pack key → 404.
 *
 * Packs are addressed by key (`[a-z0-9-]+`, the pack folder name); the ledger rows carry their own `uuid` and never
 * expose numeric ids. Only apply() writes: it queues the same {@see StarterPackApplier} path as
 * `cms:starter-pack-apply` (backup gate, module installs and configuration run inside the background task).
 */
class StarterPackController extends Controller
{
    /** Ability that allows reading starter packs. */
    public const VIEW_ABILITY = 'starter_pack.view';

    /** Ability that allows applying a starter pack (also allows reading). */
    public const APPLY_ABILITY = 'starter_pack.apply';

    /** Most ledger rows returned by the history endpoint. */
    private const HISTORY_LIMIT = 50;

    /**
     * Create the controller.
     *
     * @param StarterPackLoader $loader Pack loader.
     * @param StarterPackPlanner $planner Plan builder.
     * @param StarterPackApplier $applier Apply engine (queues the background task).
     */
    public function __construct(
        private readonly StarterPackLoader $loader,
        private readonly StarterPackPlanner $planner,
        private readonly StarterPackApplier $applier,
    ) {}

    /**
     * Packs with localized name and description, version, module names and the applied state from the ledger.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $this->canView($request->user())) {
            return $this->forbidden();
        }

        try {
            $packs = $this->loader->all();
        } catch (StarterPackException) {
            return response()->json(['message' => 'The starter packs could not be read.'], 500);
        }

        $locale = $this->locale();
        $latest = StarterPackApplication::query()
            ->succeeded()
            ->whereIn('pack_key', array_keys($packs))
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get()
            ->keyBy('pack_key');

        $items = [];
        foreach ($packs as $key => $pack) {
            /** @var StarterPackApplication|null $applied */
            $applied = $latest->get($key);
            $items[] = [
                'key' => $key,
                'version' => $pack->version,
                'name' => $this->localized($pack->name, $locale),
                'description' => $this->localized($pack->description, $locale),
                'modules' => array_keys($pack->modules),
                'applied' => $applied !== null,
                'applied_version' => $applied?->pack_version,
                'applied_at' => ($applied?->finished_at ?? $applied?->started_at)?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $items]);
    }

    /**
     * The plan for one pack on this site now, blockers included.
     */
    public function show(Request $request, string $key): JsonResponse
    {
        if (! $this->canView($request->user())) {
            return $this->forbidden();
        }

        $pack = $this->find($key);
        if ($pack === null) {
            return $this->notFound();
        }

        $locale = $this->locale();
        $user = $request->user();

        return response()->json(['data' => [
            'key' => $pack->key,
            'version' => $pack->version,
            'name' => $this->localized($pack->name, $locale),
            'description' => $this->localized($pack->description, $locale),
            'can_apply' => Gate::forUser($user)->allows(self::APPLY_ABILITY),
            'plan' => $this->planner->plan($pack, (int) $user->getAuthIdentifier())->toArray(),
        ]]);
    }

    /**
     * Ledger rows of one pack, newest first.
     */
    public function history(Request $request, string $key): JsonResponse
    {
        if (! $this->canView($request->user())) {
            return $this->forbidden();
        }

        $pack = $this->find($key);
        if ($pack === null) {
            return $this->notFound();
        }

        $rows = StarterPackApplication::query()
            ->where('pack_key', $pack->key)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $names = User::query()
            ->whereIn('id', $rows->pluck('applied_by')->filter()->unique()->all())
            ->pluck('name', 'id');

        return response()->json(['data' => $rows->map(static fn (StarterPackApplication $row): array => [
            'uuid' => $row->uuid,
            'pack_key' => $row->pack_key,
            'pack_version' => $row->pack_version,
            'status' => $row->status,
            'steps' => $row->steps ?? [],
            'applied_by_name' => $row->applied_by !== null ? ($names[$row->applied_by] ?? null) : null,
            'background_task_uuid' => $row->background_task_uuid,
            'started_at' => $row->started_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
        ])->all()]);
    }

    /**
     * Queue the apply of one pack and return the background task uuid (202).
     *
     * Body: `version` (required, the pack version the user reviewed), `confirm_irreversible` (bool, needed when a
     * module to install has database changes that cannot be undone), `confirm_third_party` (bool, needed when a module to
     * install is published by a partner; answer 422 `third_party` lists them), `content_status` (`draft` default or
     * `published`). Answers: 403 without `starter_pack.apply` or, for a pack with a configuration, for a user who is
     * not a Webmaster; 404 unknown pack; 422 invalid body, a blocked plan (`blockers`) or unconfirmed irreversible
     * modules (`irreversible`); 409 when the reviewed version is stale or another pack is being applied. Nothing is
     * recorded or dispatched before every check passed. The engine checks everything again in its preflight step.
     */
    public function apply(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows(self::APPLY_ABILITY)) {
            return $this->forbidden();
        }

        $pack = $this->find($key);
        if ($pack === null) {
            return $this->notFound();
        }

        $input = $request->validate([
            'version' => ['required', 'string', 'max:32'],
            'confirm_irreversible' => ['sometimes', 'boolean'],
            'confirm_third_party' => ['sometimes', 'boolean'],
            'content_status' => ['sometimes', 'string', 'in:draft,published'],
        ]);

        if ($input['version'] !== $pack->version) {
            return response()->json([
                'message' => 'The starter pack changed after you reviewed it; review the plan again.',
                'pack_version' => $pack->version,
            ], 409);
        }

        if ($pack->configPath() !== null && ! ($user instanceof User && $user->isWebmaster())) {
            return response()->json(['message' => 'Only a Webmaster can apply the configuration of this pack.'], 403);
        }

        $userId = (int) $user->getAuthIdentifier();
        $plan = $this->planner->plan($pack, $userId);
        if (! $plan->canApply()) {
            return response()->json([
                'message' => 'The starter pack cannot be applied.',
                'blockers' => $plan->blockers,
            ], 422);
        }

        $confirmed = (bool) ($input['confirm_irreversible'] ?? false);
        $irreversible = $this->irreversible($plan);
        if ($irreversible !== [] && ! $confirmed) {
            return response()->json([
                'message' => 'Some modules have database changes that cannot be undone; confirm them to apply the pack.',
                'irreversible' => $irreversible,
            ], 422);
        }

        // Modules published by a partner need the same recorded confirmation as a single install.
        $thirdParty = $this->thirdParty($plan);
        $confirmedThirdParty = (bool) ($input['confirm_third_party'] ?? false);
        if ($thirdParty !== [] && ! $confirmedThirdParty) {
            return response()->json([
                'message' => 'Some modules are published by a partner, not by iSPA, and run with full rights on this site; confirm that you understand this to apply the pack.',
                'third_party' => $thirdParty,
            ], 422);
        }

        $options = [StarterPackApplier::OPTION_CONTENT_STATUS => $input['content_status'] ?? 'draft'];
        if ($confirmed) {
            $options[StarterPackApplier::OPTION_CONFIRM_IRREVERSIBLE] = true;
        }
        if ($thirdParty !== []) {
            $options[StarterPackApplier::OPTION_CONFIRM_THIRD_PARTY] = true;
        }

        try {
            $application = $this->applier->queue($pack, $userId, $options);
        } catch (StarterPackException|LockTimeoutException) {
            return response()->json(['message' => 'Another starter pack is being applied on this site; wait until it has finished.'], 409);
        }

        event(new AuditLogEvent(
            'starter_pack.apply_requested',
            [
                'pack_key' => $application->pack_key,
                'pack_version' => $application->pack_version,
                'application_uuid' => (string) $application->uuid,
            ],
            'starter_pack_application',
            (int) $application->id,
            null,
            null,
            $userId,
        ));

        return response()->json(['data' => [
            'task_uuid' => $application->background_task_uuid,
            'application_uuid' => $application->uuid,
        ]], 202);
    }

    /**
     * Modules the plan would install that a partner publishes and this site never confirmed.
     *
     * @return list<array{module: string, publisher: ?array<string, mixed>, impact: ?array<string, mixed>}>
     */
    private function thirdParty(StarterPackPlan $plan): array
    {
        $items = [];
        foreach ($plan->modules as $module) {
            if ($module['state'] === StarterPackPlan::STATE_TO_INSTALL && is_array($module['third_party'] ?? null)) {
                $items[] = ['module' => $module['name'], 'publisher' => $module['third_party']['publisher'] ?? null, 'impact' => $module['third_party']['impact'] ?? null];
            }
        }

        return $items;
    }

    /**
     * Modules the plan would install whose database changes cannot be undone.
     *
     * @return list<array{module: string, changes: list<string>}>
     */
    private function irreversible(StarterPackPlan $plan): array
    {
        $items = [];
        foreach ($plan->modules as $module) {
            if ($module['state'] === StarterPackPlan::STATE_TO_INSTALL && $module['irreversible'] !== []) {
                $items[] = ['module' => $module['name'], 'changes' => array_values($module['irreversible'])];
            }
        }

        return $items;
    }

    /**
     * Load one pack by key, or null when it does not exist (or cannot be read).
     */
    private function find(string $key): ?StarterPackManifest
    {
        if (! preg_match('/^[a-z0-9-]+$/', $key)) {
            return null;
        }

        try {
            return $this->loader->all()[$key] ?? null;
        } catch (StarterPackException) {
            return null;
        }
    }

    /**
     * Whether the user may read starter packs.
     */
    private function canView(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->any([self::VIEW_ABILITY, self::APPLY_ABILITY]);
    }

    /**
     * Request locale limited to the pack languages.
     */
    private function locale(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, ['vi', 'en'], true) ? $locale : 'en';
    }

    /**
     * Text in the locale, falling back to English.
     *
     * @param array{vi: string, en: string} $text
     */
    private function localized(array $text, string $locale): string
    {
        return $text[$locale] ?? $text['en'] ?? '';
    }

    /** 403 body. */
    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    /** 404 body. */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Starter pack not found.'], 404);
    }
}
