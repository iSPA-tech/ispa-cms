<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseBundleSignature;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use InvalidArgumentException;
use JsonException;

/**
 * Verifies a signed workflow template bundle (sig.json `{key_id, signature, manifest}`, manifest = canonical bundle
 * bytes) with the trust rule of every owner-signed hub bundle ({@see ReleaseBundleSignature}), then checks the payload:
 * canonical form, content hash, the package checksum, a package without bindings, and `requires` / the summary's safety
 * classes, hosts and credential kinds equal to the package manifest (registry-free, so a site that lacks a module still
 * reads the bundle and reports the missing module). Any failure is {@see WorkflowHubException::UNTRUSTED}.
 *
 * Used by the hub publish command and by the site before showing a template's detail or installing it. The package's
 * own checks against this site (node catalog, permissions, manifest re-derivation) stay with WorkflowPackageInspector.
 */
final class WorkflowBundleVerifier
{
    private readonly ReleaseBundleSignature $signature;

    public function __construct(?PackageVerifier $verifier = null)
    {
        $this->signature = new ReleaseBundleSignature($verifier);
    }

    /**
     * @return array{bundle: WorkflowBundle, key_id: string}
     *
     * @throws WorkflowHubException
     */
    public function verify(string $sigJson): array
    {
        try {
            ['key_id' => $keyId, 'bytes' => $bytes] = $this->signature->open($sigJson);
        } catch (PackageVerificationException $e) {
            throw WorkflowHubException::untrusted($e->errorCode === ReleaseBundleSignature::KEY_NOT_RELEASE ? $e->getMessage() : $e->errorCode, $e);
        }

        try {
            $bundle = WorkflowBundle::fromJson($bytes);
        } catch (InvalidArgumentException $e) {
            throw WorkflowHubException::untrusted('malformed bundle: ' . $e->getMessage(), $e);
        }
        if ($bundle->toCanonicalJson() !== $bytes) {
            throw WorkflowHubException::untrusted('bundle is not in canonical form');
        }
        if (!hash_equals($bundle->computeContentSha256(), $bundle->contentSha256)) {
            throw WorkflowHubException::untrusted('content hash mismatch');
        }

        $package = $bundle->package;
        try {
            $checksumOk = ($package['checksum'] ?? null) === WorkflowPackageFormat::checksum($package);
        } catch (JsonException) {
            $checksumOk = false;
        }
        if (!$checksumOk) {
            throw WorkflowHubException::untrusted('package checksum mismatch');
        }
        $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
        if ((array) ($manifest['bindings'] ?? []) !== []) {
            throw WorkflowHubException::untrusted('a hub template must not carry site bindings');
        }
        $nodeTypes = (array) ($manifest['node_types'] ?? []);
        ksort($nodeTypes);
        if ($bundle->requires !== ['modules' => array_values((array) ($manifest['modules'] ?? [])), 'node_types' => $nodeTypes]) {
            throw WorkflowHubException::untrusted('requires differs from the package manifest');
        }
        foreach (['safety_classes', 'outbound_hosts', 'credential_kinds'] as $field) {
            if ($bundle->summary[$field] !== array_values((array) ($manifest[$field] ?? []))) {
                throw WorkflowHubException::untrusted("summary {$field} differs from the package manifest");
            }
        }

        return ['bundle' => $bundle, 'key_id' => $keyId];
    }
}
