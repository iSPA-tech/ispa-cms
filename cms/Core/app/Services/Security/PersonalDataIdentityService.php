<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use Normalizer;

final class PersonalDataIdentityService
{
    public function normalizeEmail(?string $email): ?string
    {
        $value = $this->normalizeText($email);

        return $value !== '' ? $value : null;
    }

    public function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '84') && strlen($digits) > 2) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits !== '' ? $digits : null;
    }

    public function normalizeText(?string $text): string
    {
        if (! $text) {
            return '';
        }

        $value = mb_strtolower(trim($text));
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value, " \t\n\r\0\x0B,.;");
    }

    public function normalizeHex64(?string $hex): string
    {
        $value = strtolower(trim((string) $hex));

        return preg_match('/^[0-9a-f]{64}$/', $value) ? $value : '';
    }

    public function contactHash(?string $value, string $type = 'contact'): ?string
    {
        $normalized = $type === 'phone'
            ? $this->normalizePhone($value)
            : $this->normalizeEmail($value);

        if ($normalized === null) {
            return null;
        }

        return hash_hmac('sha256', $type . ':' . $normalized, $this->hashKey());
    }

    public function contactHint(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) <= 4) {
            return '***';
        }

        return mb_substr($normalized, 0, 2) . '***' . mb_substr($normalized, -2);
    }

    public function customerFingerprint(array $attributes): string
    {
        $emailHash = $this->normalizeHex64($attributes['email_hash'] ?? '');
        $phoneHash = $this->normalizeHex64($attributes['phone_hash'] ?? '');
        $taxHash = $this->normalizeHex64($attributes['tax_code_hash'] ?? '');
        $addressFingerprint = $this->normalizeHex64($attributes['address_fingerprint'] ?? '');
        $userId = isset($attributes['user_id']) ? (string) $attributes['user_id'] : '';
        $version = 'v1';
        $keyset = implode(',', array_filter([
            $emailHash ? 'e' : null,
            $phoneHash ? 'p' : null,
            $taxHash ? 't' : null,
            $addressFingerprint ? 'a' : null,
            $userId ? 'u' : null,
        ]));

        if (! $emailHash && ! $phoneHash && ! $taxHash && ! $addressFingerprint && ! $userId) {
            $fallback = $attributes['profile_code'] ?? $attributes['uuid'] ?? '';

            return $fallback !== '' ? hash('sha256', $version . '|fallback:' . $fallback) : '';
        }

        return hash('sha256', implode('|', [
            $version,
            'kset:' . $keyset,
            'u:' . $userId,
            'e:' . $emailHash,
            'p:' . $phoneHash,
            't:' . $taxHash,
            'a:' . $addressFingerprint,
        ]));
    }

    public function addressFingerprint(array $attributes): string
    {
        $version = 'v1';
        $name = $this->normalizeText($attributes['name'] ?? '');
        $phone = $this->normalizeText($attributes['phone'] ?? '');
        $street1 = $this->normalizeStreet($attributes['address_line1'] ?? '');
        $street2 = $this->normalizeStreet($attributes['address_line2'] ?? '');
        $city = $this->normalizePlace($attributes['city'] ?? '');
        $state = $this->normalizePlace($attributes['state'] ?? '');
        $postal = $this->normalizePostal($attributes['postal_code'] ?? '');
        $country = $this->normalizeCountry($attributes['country'] ?? '');

        if ($street1 === '' && $city === '' && $state === '' && $postal === '' && $country === '') {
            return '';
        }

        $keyset = implode(',', array_filter([
            $name ? 'n' : null,
            $phone ? 'p' : null,
            $street1 ? 's' : null,
            $street2 ? 'x' : null,
            $city ? 'c' : null,
            $state ? 't' : null,
            $postal ? 'p' : null,
            $country ? 'n' : null,
        ]));

        return hash('sha256', implode('|', [
            $version,
            'kset:' . $keyset,
            'n:' . $name,
            'p:' . $phone,
            's:' . $street1,
            'x:' . $street2,
            'c:' . $city,
            't:' . $state,
            'p:' . $postal,
            'n:' . $country,
        ]));
    }

    private function normalizeStreet(?string $text): string
    {
        $value = $this->normalizeText($text);
        $value = $this->stripAccents($value);
        $value = preg_replace('/[^\p{L}\p{N}\s\/\-#]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/\b(tp|t\.p\.)\b/u', 'thanh pho', $value) ?? '';
        $value = preg_replace('/\b(q|quận|quan)\b/u', 'quan', $value) ?? '';
        $value = preg_replace('/\b(p|phường|phuong)\b/u', 'phuong', $value) ?? '';

        return preg_replace('/\b(h|huyện|huyen)\b/u', 'huyen', $value) ?? '';
    }

    private function normalizePlace(?string $text): string
    {
        $value = $this->normalizeText($text);
        $value = $this->stripAccents($value);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/\b(thanh pho|tp|t\.p\.)\b/u', 'tp', $value) ?? '';
        $value = preg_replace('/\b(quan|q)\b/u', 'q', $value) ?? '';
        $value = preg_replace('/\b(phuong|p)\b/u', 'p', $value) ?? '';
        $value = preg_replace('/\b(huyen|h)\b/u', 'h', $value) ?? '';

        return $value;
    }

    private function normalizePostal(?string $text): string
    {
        $value = preg_replace('/\W+/', '', (string) $text) ?? '';

        return strtolower($value);
    }

    private function normalizeCountry(?string $text): string
    {
        $value = strtolower(trim($this->stripAccents((string) $text)));
        if ($value === '') {
            return '';
        }

        $map = [
            'viet nam' => 'VN',
            'vietnam' => 'VN',
            'việt nam' => 'VN',
            'united states' => 'US',
            'usa' => 'US',
            'us' => 'US',
            'united kingdom' => 'GB',
            'uk' => 'GB',
            'japan' => 'JP',
            'nhat ban' => 'JP',
        ];

        return $map[$value] ?? strtoupper($value);
    }

    private function stripAccents(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_D) ?: $value;

            $stripped = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $value;

            return strtr($stripped, ['đ' => 'd', 'Đ' => 'D']);
        }

        return strtr($value, ['đ' => 'd', 'Đ' => 'D']);
    }

    private function hashKey(): string
    {
        $key = (string) config('app.key', '');

        return $key !== '' ? $key : 'ispa-core-personal-data';
    }
}
