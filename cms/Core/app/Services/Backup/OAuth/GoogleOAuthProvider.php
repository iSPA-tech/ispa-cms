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
 * Google OAuth for the Google Drive backup destination.
 *
 * Asks only for `drive.file` (files the app created), offline access and a forced consent screen so Google
 * always returns a refresh token.
 */
class GoogleOAuthProvider implements OAuthProvider
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /**
     * {@inheritDoc}
     */
    public function driver(): string
    {
        return 'gdrive';
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
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
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
        return [];
    }
}
