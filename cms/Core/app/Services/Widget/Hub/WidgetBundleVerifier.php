<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use InvalidArgumentException;

/**
 * Verifies a signed widget bundle (sig.json `{key_id, signature, manifest}`, manifest = canonical bundle bytes), then
 * checks the payload is canonical, its content hash and every asset's sha256. Reads and writes nothing else; any
 * failure is {@see WidgetBundleUntrusted}.
 *
 * Trust: only the iSPA owner release keys may sign widget bundles, because their Blade runs on every site. The default
 * verifier trusts the keys of core.module_packages.trusted_keys whose id is listed in widget_bundle_key_ids, minus
 * revoked_key_ids, and a key listed in countersign_key_ids (the hub's own key) is refused on every path, an injected
 * verifier included. Used by the site installer, the hub publish check and cms:widget-bundle-mark-published.
 */
final class WidgetBundleVerifier
{
    private readonly PackageVerifier $verifier;

    public function __construct(?PackageVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? new PackageVerifier(self::releaseKeys());
    }

    /**
     * Trusted keys allowed to sign widget bundles: listed in widget_bundle_key_ids and never a countersign key.
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
    private static function countersignKeyIds(): array
    {
        return array_map('strval', (array) config('core.module_packages.countersign_key_ids', []));
    }

    /**
     * @throws WidgetBundleUntrusted
     */
    public function verify(string $sigJson): WidgetBundle
    {
        try {
            ['key_id' => $keyId, 'bytes' => $bytes] = $this->verifier->verifyEnvelope($sigJson);
        } catch (PackageVerificationException $e) {
            throw new WidgetBundleUntrusted($e->errorCode, $e);
        }
        if (in_array($keyId, self::countersignKeyIds(), true)) {
            throw new WidgetBundleUntrusted('KEY_NOT_RELEASE: signing key ' . $keyId . ' is a hub countersign key');
        }

        try {
            $bundle = WidgetBundle::fromCanonicalJson($bytes);
        } catch (InvalidArgumentException $e) {
            throw new WidgetBundleUntrusted('malformed bundle: ' . $e->getMessage(), $e);
        }

        if ($bundle->toCanonicalJson() !== $bytes) {
            throw new WidgetBundleUntrusted('bundle is not in canonical form');
        }
        if (!hash_equals($bundle->computeContentSha256(), $bundle->contentSha256)) {
            throw new WidgetBundleUntrusted('content hash mismatch');
        }
        foreach ($bundle->assets as $asset) {
            $raw = base64_decode($asset['base64'], true);
            if ($raw === false || !hash_equals($asset['sha256'], hash('sha256', $raw))) {
                throw new WidgetBundleUntrusted('asset ' . $asset['path'] . ' does not match its sha256');
            }
        }

        return $bundle;
    }
}
