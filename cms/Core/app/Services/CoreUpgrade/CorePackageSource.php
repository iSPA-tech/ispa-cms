<?php

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gets the signed core package from the platform hub: a download ticket (kind core, no licence), then the capped,
 * signed download of the zip. The signature check and the zip hash check stay with the caller (PackageVerifier,
 * ModuleInstaller::checkZip). Tests replace this class with one that hands out a local package.
 */
class CorePackageSource
{
    private const CHUNK = 65536;

    /** Largest core zip accepted, whatever the manifest says. */
    public const MAX_ZIP_BYTES = 600 * 1024 * 1024;

    /**
     * @return array{signature_json: string, download: callable(ReleaseManifest, string): void}
     *         The signed manifest document, and a callable that downloads the zip to a target path once the
     *         manifest has been verified (its size is the cap).
     *
     * @throws PackageVerificationException
     */
    public function ticket(string $version): array
    {
        $answer = (new PlatformHubClient())->post(ModuleInstaller::TICKET_PATH, [
            'name' => ReleaseManifest::CORE_NAME, 'version' => $version, 'kind' => 'core',
        ]);
        $url = $answer['download_url'] ?? null;
        $sig = $answer['signature_json'] ?? null;
        if (! is_string($url) || $url === '' || ! is_string($sig) || $sig === '') {
            throw new PackageVerificationException('CORE_TICKET', 'download ticket has an unexpected shape');
        }

        return [
            'signature_json' => $sig,
            'download' => fn (ReleaseManifest $manifest, string $target) => $this->download($url, $target, min($manifest->zipSize, self::MAX_ZIP_BYTES)),
        ];
    }

    private function download(string $url, string $target, int $maxBytes): void
    {
        $hub = parse_url(rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/'));
        $got = parse_url($url);
        if (! is_array($hub) || ! is_array($got) || isset($got['user']) || isset($got['pass']) || isset($got['fragment'])
            || strtolower((string) ($got['scheme'] ?? '')) !== strtolower((string) ($hub['scheme'] ?? ''))
            || strtolower((string) ($got['host'] ?? '')) !== strtolower((string) ($hub['host'] ?? ''))
            || ($got['port'] ?? null) !== ($hub['port'] ?? null)) {
            throw new PackageVerificationException('CORE_DOWNLOAD_HOST', 'download URL is not on the platform hub');
        }
        $path = PlatformSignature::normalizePath((string) ($got['path'] ?? '/'));
        parse_str((string) ($got['query'] ?? ''), $params);
        $query = PlatformSignature::canonicalQuery($params);
        $signedUrl = $got['scheme'].'://'.$got['host'].(isset($got['port']) ? ':'.$got['port'] : '').$path.($query !== '' ? '?'.$query : '');

        $credentials = app(HubCredentials::class);
        $timestamp = Carbon::now()->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $signature = PlatformSignature::request($credentials->secret(), 'GET', $path, $query, $timestamp, $nonce, '');

        try {
            $response = Http::withoutRedirecting()->connectTimeout(5)->timeout(900)->withOptions(['stream' => true])
                ->withHeaders([
                    PlatformSignature::HEADER_DOMAIN => $credentials->domain(),
                    PlatformSignature::HEADER_TIMESTAMP => (string) $timestamp,
                    PlatformSignature::HEADER_NONCE => $nonce,
                    PlatformSignature::HEADER_SIGNATURE => $signature,
                ])->get($signedUrl);
        } catch (ConnectionException) {
            throw new PackageVerificationException('CORE_DOWNLOAD_FAILED', 'package download could not connect');
        }
        if ($response->status() !== 200) {
            throw new PackageVerificationException('CORE_DOWNLOAD_FAILED', 'package download answered HTTP '.$response->status());
        }

        $out = @fopen($target, 'xb');
        if ($out === false) {
            throw new PackageVerificationException('CORE_STAGING', 'cannot create the package file');
        }
        @chmod($target, 0600);
        $body = $response->toPsrResponse()->getBody();
        $written = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new PackageVerificationException('CORE_DOWNLOAD_TOO_LARGE', 'package is larger than the signed manifest says');
                }
                if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new PackageVerificationException('CORE_STAGING', 'cannot write the package file');
                }
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($target);
            throw $e instanceof PackageVerificationException ? $e : new PackageVerificationException('CORE_DOWNLOAD_FAILED', 'package download was interrupted');
        } finally {
            $body->close();
        }
        fclose($out);
    }
}
