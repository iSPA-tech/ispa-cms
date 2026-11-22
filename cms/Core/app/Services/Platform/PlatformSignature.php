<?php

namespace Cms\Core\Services\Platform;

/**
 * Pure HMAC-SHA256 signing scheme for site-to-hub platform requests and the
 * hub's signed answers. The shared domain secret only keys the HMAC and is
 * never part of the signed or transmitted material.
 */
final class PlatformSignature
{
    public const VERSION = 'v1';

    public const HEADER_DOMAIN = 'X-Ispa-Domain';

    public const HEADER_TIMESTAMP = 'X-Ispa-Timestamp';

    public const HEADER_NONCE = 'X-Ispa-Nonce';

    public const HEADER_SIGNATURE = 'X-Ispa-Signature';

    public const HEADER_RESPONSE_SIGNATURE = 'X-Ispa-Response-Signature';

    /** Path prefix the hub calls back on a connecting site; the nonce follows it. */
    public const CALLBACK_PATH = '/.well-known/ispa-hub/';

    /** Shape of a callback nonce; a site answers no other value. */
    public const CALLBACK_NONCE_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Sign an outgoing request.
     *
     * @param  string  $secret  Shared domain secret (HMAC key).
     * @param  string  $method  HTTP method; uppercased before signing.
     * @param  string  $path  Request path; a leading "/" is enforced.
     * @param  string  $sortedQuery  Query string produced by canonicalQuery().
     * @param  int  $timestamp  Unix timestamp sent with the request.
     * @param  string  $nonce  Single-use random value sent with the request.
     * @param  string  $body  Exact raw request body ("" when none).
     * @return string Signature in the form "v1=<hex>".
     */
    public static function request(
        #[\SensitiveParameter] string $secret,
        string $method,
        string $path,
        string $sortedQuery,
        int $timestamp,
        string $nonce,
        string $body,
    ): string {
        $canonical = implode("\n", [
            self::VERSION,
            strtoupper($method),
            self::normalizePath($path),
            $sortedQuery,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return self::VERSION.'='.hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Sign a hub response so the site can trust the answer.
     *
     * @param  string  $secret  Shared domain secret (HMAC key).
     * @param  int  $status  HTTP status code of the response.
     * @param  string  $requestNonce  Nonce of the request being answered.
     * @param  string  $body  Exact raw response body.
     * @return string Signature in the form "v1=<hex>".
     */
    public static function response(
        #[\SensitiveParameter] string $secret,
        int $status,
        string $requestNonce,
        string $body,
    ): string {
        $canonical = implode("\n", [
            self::VERSION,
            'response',
            (string) $status,
            $requestNonce,
            hash('sha256', $body),
        ]);

        return self::VERSION.'='.hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Answer of the hub's domain callback `GET https://<domain>/.well-known/ispa-hub/<nonce>`.
     *
     * The site serves this value as the plain response body. The canonical text starts with "v1\ncallback", which can
     * never equal a request canonical (its method is uppercased) or a response canonical ("response"), so a site that
     * answers callbacks for any nonce is no signing oracle for platform requests or answers. Sites must still accept
     * only nonces matching CALLBACK_NONCE_PATTERN.
     *
     * @param  string  $secret  Shared domain secret (HMAC key).
     * @param  string  $nonce  Nonce from the callback URL (64 lowercase hex characters).
     * @return string Proof in the form "v1=<hex>".
     */
    public static function callback(#[\SensitiveParameter] string $secret, string $nonce): string
    {
        return self::VERSION.'='.hash_hmac('sha256', implode("\n", [self::VERSION, 'callback', $nonce]), $secret);
    }

    /**
     * Build the canonical query string: keys sorted recursively, RFC 3986 encoding.
     * Null values are dropped, matching http_build_query().
     *
     * @param  array<array-key, mixed>  $query
     */
    public static function canonicalQuery(array $query): string
    {
        return http_build_query(self::sortRecursive($query), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Normalize a path so it always starts with exactly one "/".
     */
    public static function normalizePath(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    /**
     * Sort array keys recursively.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortRecursive(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }
}
