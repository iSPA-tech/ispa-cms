<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use RuntimeException;

/**
 * Retention-health enforcement layer (plan Chunk 1 Task 11 title: "versioned
 * Data Provider Registry and retention health";
 * docs/architecture/adr-widget-provider-lifecycle.md decisions 4 and 6).
 *
 * Single transition owner for unregister: the usage check runs BEFORE
 * {@see WidgetDataProviderRegistry::forget()} is ever called, never after,
 * so a referenced provider version is never removed even transiently. This
 * mirrors App\Support\Modules\ManagedModuleDisabler's "guard check, then
 * mutate, inside one owner" shape from Task 7's module-disable transition.
 *
 * {@see self::health()} is diagnostic only in this slice -- S-012 is
 * explicitly rejected by the ADR ("runtime health is enough when code
 * registrations disappear"): the real pre-deploy gate is
 * `cms:widget-registry-preflight` (Task 15), which this class does not
 * implement.
 */
final class ProviderUsageGuard
{
    public function __construct(
        private readonly WidgetDataProviderRegistry $registry,
        private readonly ProviderUsageReader $usageReader,
    ) {
    }

    /**
     * @throws WidgetProviderInUseException when any retained Draft/Release
     *         still references this exact key/version (D-015). No removal
     *         happens in that case -- the registry is left untouched.
     */
    public function unregister(string $key, int $version): void
    {
        if ($this->usageReader->isReferenced($key, $version)) {
            throw new WidgetProviderInUseException($key, $version);
        }

        $this->registry->forget($key, $version);
    }

    /**
     * Cross-references every currently-referenced {key, version} against
     * the live registry: `missing` is referenced but never registered (or
     * already unregistered) -- a real drift signal; `deprecated` is
     * referenced, still registered, but declared `deprecated: true`.
     * Neither list blocks anything by itself (diagnostic only).
     *
     * @return array{missing: list<array{key: string, version: int}>, deprecated: list<array{key: string, version: int}>}
     */
    public function health(): array
    {
        $missing = [];
        $deprecated = [];

        foreach ($this->usageReader->referencedProviderVersions() as $usage) {
            $key = $usage['key'];
            $version = $usage['version'];

            if (!$this->registry->isRegistered($key, $version)) {
                $missing[] = $usage;
                continue;
            }

            if ($this->registry->declarationFor($key, $version)?->deprecated === true) {
                $deprecated[] = $usage;
            }
        }

        return ['missing' => $missing, 'deprecated' => $deprecated];
    }
}

/**
 * Co-located with ProviderUsageGuard because Task 11's allowed_paths has no
 * separate exception-file slot -- the same co-location precedent as
 * Cms\Widget\Rendering\Eligibility\WidgetEligibilityDecision inside
 * WidgetSiteLayoutEligibility.php (Task 9c). `ERROR_CODE` is ready to become
 * a future `409` conflict code once Task 15/20's HTTP layer maps it,
 * mirroring the `WIDGET_MODULE_IN_USE` precedent from Task 7 -- this class
 * does no HTTP mapping itself.
 */
final class WidgetProviderInUseException extends RuntimeException
{
    public const ERROR_CODE = 'WIDGET_PROVIDER_IN_USE';

    public function __construct(
        public readonly string $providerKey,
        public readonly int $providerVersion,
    ) {
        parent::__construct(
            "Provider '{$providerKey}' version {$providerVersion} cannot be unregistered while referenced."
        );
    }
}
