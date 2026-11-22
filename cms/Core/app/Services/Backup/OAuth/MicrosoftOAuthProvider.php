<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\OAuth;

/**
 * Microsoft identity platform (v2, `common` tenant) for the OneDrive backup destination.
 *
 * Asks only for the app's own OneDrive folder (`Files.ReadWrite.AppFolder`) plus `offline_access` for a refresh token.
 */
class MicrosoftOAuthProvider implements OAuthProvider
{
    public const AUTHORIZE_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    public const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    public const SCOPE = 'Files.ReadWrite.AppFolder offline_access';

    /**
     * {@inheritDoc}
     */
    public function driver(): string
    {
        return 'onedrive';
    }

    /**
     * {@inheritDoc}
     */
    public function scope(): string
    {
        return self::SCOPE;
    }

    /**
     * {@inheritDoc}
     */
    public function authorizationUrl(string $clientId, string $redirectUri, string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'response_mode' => 'query',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * {@inheritDoc}
     */
    public function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    /**
     * {@inheritDoc}
     */
    public function refreshParams(): array
    {
        return ['scope' => self::SCOPE];
    }
}
