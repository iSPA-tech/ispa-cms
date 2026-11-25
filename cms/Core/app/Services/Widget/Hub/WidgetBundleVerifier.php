<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseBundleSignature;
use InvalidArgumentException;

/**
 * Verifies a signed widget bundle (sig.json `{key_id, signature, manifest}`, manifest = canonical bundle bytes), then
 * checks the payload is canonical, its content hash and every asset's sha256. Reads and writes nothing else; any
 * failure is {@see WidgetBundleUntrusted}.
 *
 * Trust: only the iSPA owner release keys may sign widget bundles, because their Blade runs on every site; the rule is
 * {@see ReleaseBundleSignature}, shared with workflow template bundles. Used by the site installer, the hub publish check and cms:widget-bundle-mark-published.
 */
final class WidgetBundleVerifier
{
    private readonly ReleaseBundleSignature $signature;

    public function __construct(?PackageVerifier $verifier = null)
    {
        $this->signature = new ReleaseBundleSignature($verifier);
    }

    /**
     * Trusted keys allowed to sign widget bundles ({@see ReleaseBundleSignature::releaseKeys()}).
     *
     * @return array<string, string> key id => base64 public key
     */
    public static function releaseKeys(): array
    {
        return ReleaseBundleSignature::releaseKeys();
    }

    /**
     * @throws WidgetBundleUntrusted
     */
    public function verify(string $sigJson): WidgetBundle
    {
        try {
            ['bytes' => $bytes] = $this->signature->open($sigJson);
        } catch (PackageVerificationException $e) {
            throw new WidgetBundleUntrusted($e->errorCode === ReleaseBundleSignature::KEY_NOT_RELEASE ? $e->getMessage() : $e->errorCode, $e);
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
