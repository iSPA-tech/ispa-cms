<?php

declare(strict_types=1);

$appUrl = rtrim((string) env('APP_URL', ''), '/');
$defaultRelyingPartyId = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
$configuredOrigins = (string) (env('PASSKEYS_ALLOWED_ORIGINS') ?: $appUrl);
$userHandleSecret = (string) (env('PASSKEYS_USER_HANDLE_SECRET') ?: env('APP_KEY', ''));

return [
    'relying_party_id' => (string) env('PASSKEYS_RP_ID', $defaultRelyingPartyId),
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', $configuredOrigins)),
        static fn (string $origin): bool => $origin !== '',
    )),
    'user_handle_secret' => $userHandleSecret,
    'timeout' => (int) env('PASSKEYS_TIMEOUT', 60000),
    'challenge_ttl' => (int) env('PASSKEYS_CHALLENGE_TTL', 300),
    'guard' => 'web',
    'middleware' => [],
    'management_middleware' => [],
];
