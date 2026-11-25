<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

/**
 * The one trust rule of hub bundles signed offline by the iSPA owner (widget bundles, workflow template bundles).
 *
 * The sig.json envelope `{key_id, signature, manifest}` is the module package envelope ({@see PackageSigner} signs it,
 * {@see PackageVerifier::verifyEnvelope()} checks it). Trusted keys are those of core.module_packages.trusted_keys
 * whose id is listed in widget_bundle_key_ids, minus revoked_key_ids; a key listed in countersign_key_ids (the hub's
 * own key) is refused on every path, an injected verifier included, because bundle content runs on every site.
 */
final class ReleaseBundleSignature
{
    /** Error code of a bundle signed with a hub countersign key. */
    public const KEY_NOT_RELEASE = 'KEY_NOT_RELEASE';

    private readonly PackageVerifier $verifier;

    public function __construct(?PackageVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? new PackageVerifier(self::releaseKeys());
    }

    /**
     * Trusted keys allowed to sign hub bundles: listed in widget_bundle_key_ids and never a countersign key.
     *
     * @return array<string, string> key id => base64 public key
     */
    public static function releaseKeys(): array
    {
        $allowed = array_map('strval', (array) config('core.module_packages.widget_bundle_key_ids', []));
        $countersign = self::countersignKeyIds();

        return array_filter(
            (array) config('core.module_packages.trusted_keys', []),
            static fn (string|int $keyId): bool => in_array((string) $keyId, $allowed, true) && !in_array((string) $keyId, $countersign, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function countersignKeyIds(): array
    {
        return array_map('strval', (array) config('core.module_packages.countersign_key_ids', []));
    }

    /**
     * Verify the envelope and return the signing key id and the signed bytes, unparsed.
     *
     * @return array{key_id: string, bytes: string}
     *
     * @throws PackageVerificationException with the code of the failing step ({@see self::KEY_NOT_RELEASE} for a hub key)
     */
    public function open(string $sigJson): array
    {
        $opened = $this->verifier->verifyEnvelope($sigJson);
        if (in_array($opened['key_id'], self::countersignKeyIds(), true)) {
            throw new PackageVerificationException(self::KEY_NOT_RELEASE, 'signing key ' . $opened['key_id'] . ' is a hub countersign key');
        }

        return $opened;
    }
}
