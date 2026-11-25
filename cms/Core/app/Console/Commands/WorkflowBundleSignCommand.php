<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\ReleaseKeyFile;
use Cms\Core\Services\Workflow\Hub\WorkflowBundle;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Signs a workflow template bundle built by `cms:workflow-bundle-build` with the offline release key and writes
 * `<bundle>.sig.json` next to it: the same envelope ({@see PackageSigner}) and key file rules ({@see ReleaseKeyFile})
 * as widget bundles and module packages. Key material is never printed.
 */
class WorkflowBundleSignCommand extends Command
{
    protected $signature = 'cms:workflow-bundle-sign {bundle : Path to <key>-<version>.workflow-bundle.json} {--key-file= : Path to release-signing.key (mode 0600, outside the repository)}';

    protected $description = 'Sign a workflow template bundle with the offline release key.';

    public function handle(PackageSigner $signer): int
    {
        try {
            $keyPath = ReleaseKeyFile::resolve((string) $this->option('key-file'));
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        $bundlePath = realpath((string) $this->argument('bundle'));
        if ($bundlePath === false || !is_file($bundlePath) || !is_readable($bundlePath)) {
            return $this->refuse('Bundle not found: ' . $this->argument('bundle'));
        }
        $bytes = (string) file_get_contents($bundlePath);
        try {
            $bundle = WorkflowBundle::fromJson($bytes);
        } catch (Throwable $e) {
            return $this->refuse('Bundle is not valid: ' . $e->getMessage());
        }
        if ($bundle->toCanonicalJson() !== $bytes || !hash_equals($bundle->computeContentSha256(), $bundle->contentSha256)) {
            return $this->refuse('Bundle is not canonical or its content hash does not match; rebuild it with cms:workflow-bundle-build.');
        }

        $sigPath = $bundlePath . '.sig.json';
        if (file_exists($sigPath) || is_link($sigPath)) {
            return $this->refuse('Signature file already exists, refusing to overwrite: ' . $sigPath);
        }

        try {
            $secret = ReleaseKeyFile::readSecret($keyPath);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }
        try {
            $keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey_from_secretkey($secret));
            $document = $signer->envelope($bytes, $secret);
        } finally {
            sodium_memzero($secret);
        }

        $handle = @fopen($sigPath, 'x');
        if ($handle === false) {
            return $this->refuse('Cannot create ' . $sigPath);
        }
        $written = fwrite($handle, $document);
        fclose($handle);
        if ($written !== strlen($document)) {
            @unlink($sigPath);

            return $this->refuse('Writing ' . $sigPath . ' failed; nothing kept.');
        }

        $this->info('Signed ' . $bundle->key . ' version ' . WorkflowBundle::versionLabel($bundle->version) . " with key {$keyId}: {$sigPath}");
        $this->line('Next, on the hub: php artisan cms:platform-workflow-publish <copied path of ' . basename($sigPath) . '>');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
