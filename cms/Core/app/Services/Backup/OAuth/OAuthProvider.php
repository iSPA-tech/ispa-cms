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
 * An OAuth 2.0 authorization server used to connect a cloud backup destination (authorization code + PKCE S256).
 *
 * A provider only knows its endpoints and its narrow scope; client credentials come from the destination row.
 */
interface OAuthProvider
{
    /**
     * Backup destination driver key this provider serves (for example `gdrive` or `onedrive`).
     */
    public function driver(): string;

    /**
     * The exact space-separated scope requested; nothing broader is ever asked for.
     */
    public function scope(): string;

    /**
     * Build the browser authorization URL.
     *
     * @param string $clientId OAuth client id of the destination
     * @param string $redirectUri Absolute callback URL registered with the provider
     * @param string $state Single-use state value that authenticates the callback
     * @param string $codeChallenge base64url SHA-256 of the PKCE code verifier
     */
    public function authorizationUrl(string $clientId, string $redirectUri, string $state, string $codeChallenge): string;

    /**
     * Token endpoint used for the code exchange and for refreshes.
     */
    public function tokenUrl(): string;

    /**
     * Extra form fields sent with a refresh request (for example the scope for Microsoft).
     *
     * @return array<string, string>
     */
    public function refreshParams(): array;
}
