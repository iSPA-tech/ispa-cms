<?php

namespace Cms\Core\Services\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hub-side check of a site's signed platform request: timestamp window,
 * HMAC signature, then single-use nonce. Failure reasons are logged without
 * the secret or the signature value.
 */
final class PlatformSignatureVerifier
{
    /** @var callable(string): ?string */
    private $secretResolver;

    /**
     * @param  callable(string): ?string  $secretResolver  Returns the domain secret for a registered domain, or null.
     * @param  int  $windowSeconds  Allowed clock skew in either direction.
     */
    public function __construct(callable $secretResolver, private readonly int $windowSeconds = 300)
    {
        $this->secretResolver = $secretResolver;
    }

    /**
     * Verify the request and return the authenticated domain, or null on any failure.
     */
    public function verify(Request $request): ?string
    {
        $domain = trim((string) $request->header(PlatformSignature::HEADER_DOMAIN, ''));
        $timestampHeader = (string) $request->header(PlatformSignature::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(PlatformSignature::HEADER_NONCE, '');
        $signature = (string) $request->header(PlatformSignature::HEADER_SIGNATURE, '');

        if ($domain === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestampHeader)) {
            return $this->reject('missing_headers', $domain);
        }
        if (strlen($nonce) > 128 || strlen($domain) > 255) {
            return $this->reject('malformed_headers', $domain);
        }

        $timestamp = (int) $timestampHeader;
        if (abs(Carbon::now()->getTimestamp() - $timestamp) > $this->windowSeconds) {
            return $this->reject('timestamp_outside_window', $domain);
        }

        $secret = ($this->secretResolver)($domain);
        if (! is_string($secret) || $secret === '') {
            return $this->reject('unknown_domain', $domain);
        }

        $expected = PlatformSignature::request(
            $secret,
            $request->getMethod(),
            $request->getBaseUrl().$request->getPathInfo(),
            PlatformSignature::canonicalQuery($request->query->all()),
            $timestamp,
            $nonce,
            (string) $request->getContent(),
        );
        if (! hash_equals($expected, $signature)) {
            return $this->reject('signature_mismatch', $domain);
        }

        // Only a verified request may consume its nonce, so forgeries cannot burn nonces.
        $key = 'platform:nonce:'.hash('sha256', $domain.'|'.$nonce);
        if (! Cache::add($key, 1, $this->windowSeconds * 2)) {
            return $this->reject('nonce_replayed', $domain);
        }

        return $domain;
    }

    /**
     * Attach X-Ispa-Response-Signature to a hub response answering the given request nonce.
     *
     * @param  Response  $response  Final response; its body must not change afterwards.
     * @param  string  $secret  Shared domain secret of the calling site.
     * @param  string  $requestNonce  X-Ispa-Nonce of the verified request.
     */
    public function signResponse(Response $response, #[\SensitiveParameter] string $secret, string $requestNonce): Response
    {
        $response->headers->set(
            PlatformSignature::HEADER_RESPONSE_SIGNATURE,
            PlatformSignature::response($secret, $response->getStatusCode(), $requestNonce, (string) $response->getContent()),
        );

        return $response;
    }

    /**
     * Log a rejection reason (never the secret or the signature) and return null.
     */
    private function reject(string $reason, string $domain): null
    {
        Log::warning('Platform request signature rejected', [
            'reason' => $reason,
            'domain' => mb_substr($domain, 0, 255),
        ]);

        return null;
    }
}
