<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Service for handling sensitive data visibility and masking.
 *
 * This service provides utilities to:
 * - Detect if a request asks to reveal sensitive data via query parameter
 * - Check authorization to view sensitive data
 * - Mask common sensitive fields (email, phone, address, date of birth)
 *
 * Usage Example (InstructorController):
 * ```php
 * use Cms\Core\Services\Security\SensitiveDataService;
 *
 * public function show(Request $request, Instructor $instructor, SensitiveDataService $sensitive)
 * {
 *     $data = $instructor->toArray();
 *
 *     if (!$sensitive->canShowSensitive($request, 'education.instructors.view_sensitive')) {
 *         $data['email'] = $sensitive->maskEmail($data['email']);
 *         $data['phone'] = $sensitive->maskPhone($data['phone']);
 *     }
 *
 *     return response()->json($data);
 * }
 * ```
 *
 * Usage Example (CustomerController):
 * ```php
 * use Cms\Core\Services\Security\SensitiveDataService;
 *
 * public function index(Request $request, SensitiveDataService $sensitive)
 * {
 *     $customers = Customer::paginate();
 *     $showSensitive = $sensitive->canShowSensitive($request, 'crm.customers.view_sensitive');
 *
 *     return CustomerResource::collection($customers)->additional([
 *         'meta' => ['show_sensitive' => $showSensitive],
 *     ]);
 * }
 *
 * // In CustomerResource:
 * public function toArray($request): array
 * {
 *     $sensitive = app(SensitiveDataService::class);
 *     $showSensitive = $this->additional['meta']['show_sensitive'] ?? false;
 *
 *     return [
 *         'id' => $this->id,
 *         'email' => $showSensitive ? $this->email : $sensitive->maskEmail($this->email),
 *         'phone' => $showSensitive ? $this->phone : $sensitive->maskPhone($this->phone),
 *         'address' => $showSensitive ? $this->address : $sensitive->maskAddress($this->address),
 *         'dob' => $showSensitive ? $this->dob : $sensitive->maskDob($this->dob),
 *     ];
 * }
 * ```
 * @package Cms\Core\Services\Security
 */
class SensitiveDataService
{
    /**
     * Query parameter name for requesting sensitive data visibility.
     */
    public const string PARAM_SHOW_SENSITIVE = 'show_sensitive';

    /**
     * Legacy typo fallback parameter name.
     */
    public const string PARAM_SHOW_SENSITIVE_LEGACY = 'show_senstive';

    /**
     * Default ability name for viewing sensitive data.
     */
    public const string DEFAULT_ABILITY = 'viewSensitive';

    /**
     * Check if the request can show sensitive data.
     *
     * Returns true only if:
     * 1. The request asks to show sensitive data (wantsShowSensitive)
     * 2. The current user has the required ability (Gate check)
     *
     * @param Request $request The current request
     * @param string|null $ability The ability to check (default: 'viewSensitive')
     * @param mixed $subject Optional subject for policy check
     * @return bool
     */
    public function canShowSensitive(Request $request, ?string $ability = null, mixed $subject = null): bool
    {
        if (!$this->wantsShowSensitive($request)) {
            return false;
        }

        $ability = $ability ?? self::DEFAULT_ABILITY;

        if ($subject !== null) {
            return Gate::allows($ability, $subject);
        }

        return Gate::allows($ability);
    }

    /**
     * Check if the request asks to show sensitive data.
     *
     * Reads the query parameter `show_sensitive` (or legacy `show_senstive`).
     * Truthy values: "1", "true", 1, true
     * @param  Request $request
     * @return bool
     */
    public function wantsShowSensitive(Request $request): bool
    {
        $value = $request->query(self::PARAM_SHOW_SENSITIVE);

        // Fallback to legacy typo parameter
        if ($value === null) {
            $value = $request->query(self::PARAM_SHOW_SENSITIVE_LEGACY);
        }

        if ($value === null) {
            return false;
        }

        return $this->isTruthy($value);
    }

    /**
     * Check if a value is truthy.
     */
    protected function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Mask an email address.
     *
     * Examples:
     * - "john.doe@example.com" -> "jo***@***.com"
     * - "a@b.com" -> "a***@***.com"
     * - null -> null
     * - "" -> ""
     * @param  string|null $email
     * @return string|null
     */
    public function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return $email;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***.***';
        }

        [$local, $domain] = $parts;

        // Mask local part: keep first 2 chars
        $maskedLocal = $this->maskString($local, 2);

        // Mask domain: keep only TLD
        $domainParts = explode('.', $domain);
        $tld = array_pop($domainParts);
        $maskedDomain = '***.' . $tld;

        return $maskedLocal . '@' . $maskedDomain;
    }

    /**
     * Mask a string keeping first $keep characters.
     */
    protected function maskString(string $value, int $keep): string
    {
        $length = mb_strlen($value);

        if ($length <= $keep) {
            return $value;
        }

        return mb_substr($value, 0, $keep) . '***';
    }

    /**
     * Mask a phone number.
     *
     * Keeps last 4 digits visible.
     *
     * Examples:
     * - "+1234567890" -> "*******7890"
     * - "0912345678" -> "******5678"
     * - null -> null
     * - "" -> ""
     * @param  string|null $phone
     * @return string|null
     */
    public function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        $digitsOnly = preg_replace('/\D/', '', $phone);
        $length = strlen($digitsOnly);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visible = substr($digitsOnly, -4);
        $masked = str_repeat('*', $length - 4);

        return $masked . $visible;
    }

    /**
     * Mask an address string.
     *
     * Keeps the first $keep characters visible.
     *
     * Examples:
     * - "123 Main Street, City" (keep=10) -> "123 Main S***"
     * - "Short" (keep=10) -> "Short" (no masking if shorter than keep)
     * - null -> null
     * - "" -> ""
     * @param  string|null $address
     * @param  int $keep
     * @return string|null
     */
    public function maskAddress(?string $address, int $keep = 10): ?string
    {
        if ($address === null || $address === '') {
            return $address;
        }

        $length = mb_strlen($address);

        if ($length <= $keep) {
            return $address;
        }

        return mb_substr($address, 0, $keep) . '***';
    }

    /**
     * Mask a date of birth.
     *
     * Returns year only for privacy, or "****" if null/empty.
     *
     * Examples:
     * - "1990-05-15" -> "1990"
     * - Carbon("2001-12-25") -> "2001"
     * - null -> "****"
     * - "" -> "****"
     *
     * @param mixed $dob Date of birth (string, Carbon, DateTime, or null)
     * @return string
     */
    public function maskDob(mixed $dob): string
    {
        if ($dob === null || $dob === '') {
            return '****';
        }

        // Handle Carbon/DateTime objects
        if (is_object($dob) && method_exists($dob, 'format')) {
            return $dob->format('Y');
        }

        // Handle string dates
        if (is_string($dob)) {
            // Try to extract year from YYYY-MM-DD or similar formats
            if (preg_match('/^(\d{4})/', $dob, $matches)) {
                return $matches[1];
            }

            // Try to parse with strtotime
            $timestamp = strtotime($dob);
            if ($timestamp !== false) {
                return date('Y', $timestamp);
            }
        }

        return '****';
    }

    /**
     * Mask fields based on a configuration array.
     *
     * Convenience method for applying different masks to different field types.
     *
     * Example:
     * ```php
     * $data = $sensitive->applyConfig($userData, [
     *     'email' => ['email', 'secondary_email'],
     *     'phone' => ['phone', 'mobile', 'work_phone'],
     *     'address' => ['address', 'billing_address'],
     *     'dob' => ['date_of_birth', 'birth_date'],
     * ]);
     * ```
     *
     * @param array<string, mixed> $data The data array to process
     * @param array<string, array<string>> $config Mapping of mask type to field names
     *
     * @return array<string, mixed> The data array with masked fields
     */
    public function applyConfig(array $data, array $config): array
    {
        $maskers = [
            'email' => [$this, 'maskEmail'],
            'phone' => [$this, 'maskPhone'],
            'address' => [$this, 'maskAddress'],
            'dob' => [$this, 'maskDob'],
        ];

        foreach ($config as $type => $fields) {
            if (isset($maskers[$type])) {
                $data = $this->apply($data, $fields, $maskers[$type]);
            }
        }

        return $data;
    }

    /**
     * Apply masking to multiple fields in an array.
     *
     * Convenience method for bulk masking operations.
     *
     * Example:
     * ```php
     * $data = $sensitive->apply($userData, ['email', 'secondary_email'], [$sensitive, 'maskEmail']);
     * $data = $sensitive->apply($data, ['phone', 'mobile'], [$sensitive, 'maskPhone']);
     * ```
     *
     * @param array<string, mixed> $data The data array to process
     * @param array<string> $fields The field keys to mask
     * @param callable $masker The masking function to apply
     *
     * @return array<string, mixed> The data array with masked fields
     */
    public function apply(array $data, array $fields, callable $masker): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $masker($data[$field]);
            }
        }

        return $data;
    }
}
