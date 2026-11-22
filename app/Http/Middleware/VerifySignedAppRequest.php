<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\SignedApp\SignedAppClientProvider;
use App\Contracts\SignedApp\SignedAppCredential;
use App\Contracts\SignedApp\SignedAppPrincipal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifySignedAppRequest
{
    private const CLOCK_SKEW_SECONDS = 300;
    private const NONCE_TTL_SECONDS = 600;
    private const MAX_APP_ID_BYTES = 128;
    private const MAX_PLATFORM_BYTES = 32;
    private const MAX_TIMESTAMP_BYTES = 20;
    private const MAX_NONCE_BYTES = 128;

    private ?string $rejectionCode = null;

    public function __construct(private readonly SignedAppClientProvider $clients)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $headers = $this->validatedHeaders($request);

        if ($headers === null) {
            return $this->reject();
        }

        try {
            $credential = $this->clients->findActive($headers['app_id']);

            if (
                $credential === null
                || $credential->appId !== $headers['app_id']
                || $this->hasAllowedPlatform($headers['platform'], $credential) === false
            ) {
                return $this->reject();
            }

            $secret = $this->clients->decryptSecret($credential);
        } catch (Throwable) {
            return $this->reject();
        }

        $payload = implode("\n", [
            strtoupper($request->method()),
            $request->getPathInfo(),
            hash('sha256', $this->canonicalBody($request)),
            $headers['timestamp'],
            $headers['nonce'],
        ]);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (hash_equals($expected, $headers['signature']) === false) {
            return $this->reject();
        }

        if ($this->rememberNonce($credential->appId, $headers['nonce']) === false) {
            return $this->reject();
        }

        $principal = new SignedAppPrincipal(
            clientKey: $credential->clientKey,
            appId: $credential->appId,
            platform: $headers['platform'],
            audience: $credential->audience,
        );
        $request->attributes->set('signed_app_principal', $principal);

        try {
            $this->clients->accepted($request, $credential, $principal);
        } catch (Throwable) {
            $request->attributes->remove('signed_app_principal');

            return $this->reject();
        }

        return $next($request);
    }

    /**
     * @return array{app_id: string, platform: string, timestamp: string, nonce: string, signature: string}|null
     */
    private function validatedHeaders(Request $request): ?array
    {
        $prefix = $this->headerPrefix();
        $appId = trim((string) $request->headers->get($prefix . '-Id', ''));
        $platform = strtolower(trim((string) $request->headers->get($prefix . '-Platform', '')));
        $timestamp = trim((string) $request->headers->get($prefix . '-Timestamp', ''));
        $nonce = trim((string) $request->headers->get($prefix . '-Nonce', ''));
        $signature = trim((string) $request->headers->get($prefix . '-Signature', ''));

        if (
            $appId === ''
            || strlen($appId) > self::MAX_APP_ID_BYTES
            || strlen($platform) > self::MAX_PLATFORM_BYTES
            || $timestamp === ''
            || strlen($timestamp) > self::MAX_TIMESTAMP_BYTES
            || ctype_digit($timestamp) === false
            || $nonce === ''
            || strlen($nonce) > self::MAX_NONCE_BYTES
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
        ) {
            return null;
        }

        if (abs(now()->timestamp - (int) $timestamp) > self::CLOCK_SKEW_SECONDS) {
            $this->rejectionCode = 'timestamp_skew';

            return null;
        }

        return [
            'app_id' => $appId,
            'platform' => $platform,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
        ];
    }

    private function hasAllowedPlatform(string $platform, SignedAppCredential $credential): bool
    {
        $allowed = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? strtolower(trim($value)) : '',
            $credential->allowedPlatforms,
        )));

        return $allowed === [] || ($platform !== '' && in_array($platform, $allowed, true));
    }

    private function canonicalBody(Request $request): string
    {
        return in_array(strtoupper($request->method()), ['GET', 'HEAD'], true)
            ? ''
            : (string) $request->getContent();
    }

    private function rememberNonce(string $appId, string $nonce): bool
    {
        $key = sprintf('signed-app:nonce:%s:%s', hash('sha256', $appId), hash('sha256', $nonce));

        return Cache::add($key, true, self::NONCE_TTL_SECONDS);
    }

    /**
     * Prefix of the five request headers (`<prefix>-Id`, `-Platform`, `-Timestamp`, `-Nonce`, `-Signature`). A module
     * whose apps already send another prefix overrides this in its own subclass.
     */
    protected function headerPrefix(): string
    {
        return 'X-App';
    }

    protected function rejectionMessage(): string
    {
        return 'Signed app verification failed.';
    }

    private function reject(): JsonResponse
    {
        $payload = [
            'status' => 'error',
            'message' => $this->rejectionMessage(),
        ];

        if ($this->rejectionCode !== null) {
            $payload['code'] = $this->rejectionCode;
            $payload['server_time'] = now()->timestamp;
        }

        return response()->json($payload, 401);
    }
}
