<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\ReleaseKeyFile;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetPublishLedger;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Signs a widget bundle built by `cms:widget-bundle-build` with the offline release key and writes
 * `<bundle>.sig.json` next to it (the same envelope as module packages). The key file follows the
 * `cms:module-package-sign` rules (mode 0600, outside the repository); key material is never printed.
 *
 * The signed version is recorded as pending in the local {@see WidgetPublishLedger}; it counts as published only after
 * `cms:widget-bundle-mark-published`.
 */
class WidgetBundleSignCommand extends Command
{
    protected $signature = 'cms:widget-bundle-sign {bundle : Path to <widget_key>-<version>.bundle.json} {--key-file= : Path to release-signing.key (mode 0600, outside the repository)} {--key= : Alias of --key-file}';

    protected $description = 'Sign a widget bundle with the offline release key.';

    public function handle(PackageSigner $signer, WidgetPublishLedger $ledger): int
    {
        try {
            $keyPath = ReleaseKeyFile::resolve((string) ($this->option('key-file') ?: $this->option('key')));
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        $bundlePath = realpath((string) $this->argument('bundle'));
        if ($bundlePath === false || !is_file($bundlePath) || !is_readable($bundlePath)) {
            return $this->refuse('Bundle not found: ' . $this->argument('bundle'));
        }
        $bytes = (string) file_get_contents($bundlePath);
        try {
            $bundle = WidgetBundle::fromCanonicalJson($bytes);
        } catch (Throwable $e) {
            return $this->refuse('Bundle is not valid: ' . $e->getMessage());
        }
        if ($bundle->toCanonicalJson() !== $bytes) {
            return $this->refuse('Bundle is not in canonical form; rebuild it with cms:widget-bundle-build.');
        }
        if (!hash_equals($bundle->computeContentSha256(), $bundle->contentSha256)) {
            return $this->refuse('Bundle content hash does not match; rebuild it with cms:widget-bundle-build.');
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

        $this->info("Signed {$bundle->widgetKey} version {$bundle->version} with key {$keyId}: {$sigPath}");
        try {
            $ledger->recordSigned($bundle);
        } catch (Throwable $e) {
            $this->warn('The signature is written, but the local publish record was not: ' . $e->getMessage());
        }
        $this->line('Next: publish it on the hub (cms:platform-widget-publish), then run here: php artisan cms:widget-bundle-mark-published ' . $sigPath);

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
