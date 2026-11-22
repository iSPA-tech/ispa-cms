<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use Throwable;
use Stringable;
use JsonSerializable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Removes personal data and secrets from log payloads before they are stored or sent out.
 *
 * Used by the CMSLogger sinks (Telegram, Discord) and the API request log. Values under
 * sensitive keys are masked recursively, and emails, phone numbers, card-like digit runs and
 * Bearer/Basic credentials are masked inside every string. Array structure and non-sensitive
 * fields stay readable. Email, phone and date-of-birth masks reuse {@see SensitiveDataService}.
 *
 * Text that leaves the site (the module diagnostic bundle,) is additionally passed through
 * {@see self::redactKnownValues()}, {@see self::redactKeyValuePairs()} and {@see self::redactIps()}; those are
 * opt-in so the existing sinks keep their current output (for example the IP addresses operators rely on).
 *
 * Usage:
 * ```php
 * $safe = app(LogRedactor::class)->redact(['message' => 'Paid', 'customer' => $customer->toArray()]);
 * ```
 *
 * @package Cms\Core\Services\Security
 * @author  iSPA Team
 * @since   8.0.0 (2026-10-21)
 */
class LogRedactor
{
    /**
     * Replacement for fully masked values.
     */
    public const string MASK = '[REDACTED]';

    /**
     * Key fragments (matched inside the key with separators removed) that always hold secrets or card data.
     */
    protected const array SECRET_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'apikey', 'accesskey', 'privatekey', 'authorization',
        'cookie', 'cardnumber', 'accountnumber', 'signature',
    ];

    /**
     * Whole key words that always hold secrets ("prompt_tokens" stays readable, "access_token" does not).
     */
    protected const array SECRET_KEY_WORDS = ['token', 'pwd', 'otp', 'ssn', 'iban', 'cvc', 'cvv'];

    /**
     * Keys (separators removed) that hold a postal address part.
     */
    protected const array ADDRESS_KEYS = ['line1', 'line2', 'street', 'streetaddress', 'postalcode', 'zipcode', 'zip'];

    /**
     * Keys (separators removed) that always hold a person's name.
     */
    protected const array NAME_KEYS = [
        'fullname', 'firstname', 'lastname', 'middlename', 'givenname', 'familyname', 'surname',
        'customername', 'billingname', 'shippingname', 'recipientname', 'contactname', 'cardholdername',
    ];

    /**
     * Key words of objects that describe a person; a plain "name" key inside them is masked.
     */
    protected const array PERSON_CONTEXT_WORDS = [
        'customer', 'billing', 'shipping', 'recipient', 'cardholder', 'holder', 'owner', 'buyer',
        'payer', 'contact', 'user', 'member',
    ];

    /**
     * Keys (separators removed) that hold a date of birth.
     */
    protected const array DOB_KEYS = ['dob', 'dateofbirth', 'birthdate', 'birthday'];

    /**
     * Key words that hold a phone number.
     */
    protected const array PHONE_KEY_WORDS = ['phone', 'mobile', 'tel', 'telephone', 'msisdn', 'phonenumber'];

    /**
     * Email addresses inside free text (a dotted domain is required, so "Controller@method" stays).
     */
    protected const string EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/';

    /**
     * Runs of 13-19 digits, optionally grouped by spaces or dashes; masked only when they pass the Luhn check.
     */
    protected const string CARD_PATTERN = '/(?<![\w.-])\d(?:[ -]?\d){12,18}(?!\w)/';

    /**
     * Phone numbers inside free text: "+" followed by 8-15 digits, or a local number of 10-11 digits starting with 0.
     */
    protected const string PHONE_PATTERN = '/(?<![\w.+-])(?:\+(?:\d[ .()-]{0,2}){7,14}\d|0\d(?:[ .-]?\d){8,9})(?!\w|[.-]\d)/';

    /**
     * HTTP credentials inside free text: "Bearer <token>" or "Basic <base64>" (the scheme word is kept). The token
     * must hold a digit, so prose such as "Basic authentication" stays readable.
     */
    protected const string CREDENTIAL_PATTERN = '/\b(Bearer|Basic)\s+(?=[A-Za-z._~+\/=|-]*\d)[A-Za-z0-9._~+\/=|-]{8,}/';

    /**
     * Dotted-quad IPv4 addresses (not part of a longer dotted number such as a four-part version).
     */
    protected const string IPV4_PATTERN = '/(?<![\w.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?!\w|\.\d)/';

    /**
     * IPv6 candidates (hex groups with at least two colons, optional zone); each one is confirmed with filter_var,
     * so clock times ("10:15:30") and method calls ("Foo::bar") stay readable.
     */
    protected const string IPV6_CANDIDATE_PATTERN = '/(?<![\w:.%])(?=[0-9A-Fa-f]*:[0-9A-Fa-f]*:)[0-9A-Fa-f:]{2,39}(?:%[\w.-]+)?(?![\w:])/';

    /**
     * "key=value", "key: value" and JSON "key":"value" pairs inside free text (query strings, log context); the value
     * is masked when the key names a secret ({@see self::isSecretKey()}).
     */
    protected const string KEY_VALUE_PATTERN = '/(["\']?)\b([A-Za-z][A-Za-z0-9_.-]{1,60})\1(\s*[=:]\s*)("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s&?#,;}\])"\']+)/';

    /**
     * Replacement for IP addresses.
     */
    public const string IP_MASK = '[IP]';

    /**
     * Create the redactor.
     *
     * @param SensitiveDataService $masks Field maskers shared with API response masking.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-10-21)
     */
    public function __construct(protected SensitiveDataService $masks = new SensitiveDataService())
    {
    }

    /**
     * Redact any log value: arrays recursively, strings by pattern, arrayable objects after conversion.
     *
     * Throwables and other objects are returned untouched so sinks can still read them.
     *
     * @param mixed $value Log payload.
     *
     * @return mixed The redacted payload with the same structure.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-10-21)
     */
    public function redact(mixed $value): mixed
    {
        return $this->redactValue($value, false);
    }

    /**
     * Mask emails, card-like numbers and phone numbers inside a string.
     *
     * Fails closed: if a pattern cannot be applied, the whole string is replaced by {@see self::MASK}.
     *
     * @param string $value Free text.
     *
     * @return string The masked text.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-10-21)
     */
    public function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $value = preg_replace(self::CREDENTIAL_PATTERN, '$1 ' . self::MASK, $value);
        $value = $value === null ? null : preg_replace_callback(self::EMAIL_PATTERN, fn (array $m): string => (string)$this->masks->maskEmail($m[0]), $value);
        $value = $value === null ? null : preg_replace_callback(self::CARD_PATTERN, fn (array $m): string => $this->maskCard($m[0]), $value);
        $value = $value === null ? null : preg_replace_callback(self::PHONE_PATTERN, fn (array $m): string => (string)$this->masks->maskPhone($m[0]), $value);

        return $value ?? self::MASK;
    }

    /**
     * Mask IPv4 and IPv6 addresses inside a string with {@see self::IP_MASK}.
     *
     * Opt-in (not part of {@see self::redactString()}): used for text that leaves the site. Fails closed: if a
     * pattern cannot be applied, the whole string is replaced by {@see self::MASK}.
     *
     * @param string $value Free text.
     *
     * @return string The text with IP addresses masked.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function redactIps(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $value = preg_replace_callback(
            self::IPV6_CANDIDATE_PATTERN,
            static function (array $m): string {
                $address = explode('%', $m[0], 2)[0];

                return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? self::IP_MASK : $m[0];
            },
            $value,
        );
        $value = $value === null ? null : preg_replace(self::IPV4_PATTERN, self::IP_MASK, $value);

        return $value ?? self::MASK;
    }

    /**
     * Mask the value of every "key=value", "key: value" or JSON "key":"value" pair whose key names a secret
     * (password, token, secret, api key, authorization, ...), keeping the key and the quotes.
     *
     * Opt-in (not part of {@see self::redactString()}): used for text that leaves the site, where log context and
     * query strings arrive as flat text instead of arrays. Fails closed like {@see self::redactString()}.
     *
     * @param string $value Free text.
     *
     * @return string The text with secret pair values masked.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function redactKeyValuePairs(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $value = preg_replace_callback(self::KEY_VALUE_PATTERN, function (array $m): string {
            $words = $this->keyWords($m[2]);
            if (!$this->isSecretKey($words, implode('', $words)) || $m[4] === 'null') {
                return $m[0];
            }
            $quote = in_array($m[4][0], ['"', "'"], true) ? $m[4][0] : '';

            return $m[1] . $m[2] . $m[1] . $m[3] . $quote . self::MASK . $quote;
        }, $value);

        return $value ?? self::MASK;
    }

    /**
     * Mask every occurrence of the given known secret values (for example the site's `.env` values).
     *
     * Each value is also matched in its JSON-escaped and URL-encoded spellings, because log context is written as
     * JSON ("/" becomes "\/"). Longer values win over values they contain. Empty values are ignored; choosing a
     * sensible minimum length is the caller's job.
     *
     * @param string       $value  Free text.
     * @param list<string> $values Secret values to mask.
     *
     * @return string The text with every known value replaced by {@see self::MASK}.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function redactKnownValues(string $value, array $values): string
    {
        $map = [];
        foreach ($values as $known) {
            if (!is_string($known) || $known === '') {
                continue;
            }
            $json = json_encode($known, JSON_UNESCAPED_UNICODE);
            $variants = [$known, rawurlencode($known), is_string($json) ? substr($json, 1, -1) : $known];
            foreach ($variants as $variant) {
                if ($variant !== '') {
                    $map[$variant] = self::MASK;
                }
            }
        }

        // strtr() prefers the longest match at each position, so "secret-value" is masked whole before "secret".
        return $value === '' || $map === [] ? $value : strtr($value, $map);
    }

    /**
     * Redact a URL: query parameters by key and pattern, the rest of the URL by pattern.
     *
     * @param string $url Full URL including the query string.
     *
     * @return string The URL with sensitive query values masked (the query is re-encoded).
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-10-21)
     */
    public function redactUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return $this->redactString($url);
        }

        parse_str($query, $params);

        return $this->redactString((string)strstr($url, '?', true)) . '?' . http_build_query($this->redact($params));
    }

    /**
     * Redact one value; $personContext is true when the value sits directly in a person-like object.
     */
    protected function redactValue(mixed $value, bool $personContext): mixed
    {
        $value = $this->normalize($value);

        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_int($key)
                ? $this->redactValue($item, $personContext)
                : $this->redactField($key, $item, $personContext);
        }

        return $value;
    }

    /**
     * Redact the value of a named field according to its key.
     */
    protected function redactField(string $key, mixed $value, bool $personContext): mixed
    {
        $words = $this->keyWords($key);
        $compact = implode('', $words);

        if ($this->isSecretKey($words, $compact) || in_array('address', $words, true) || in_array($compact, self::ADDRESS_KEYS, true)) {
            return $this->maskAll($value);
        }

        $value = $this->normalize($value);

        if (is_string($value) || is_int($value)) {
            $masked = $this->maskPersonalScalar($words, $compact, (string)$value, $personContext);

            if ($masked !== null) {
                return $masked;
            }
        }

        return $this->redactValue($value, $this->isPersonContext($words));
    }

    /**
     * Mask a scalar under a personal-data key, or return null when no key rule applies.
     *
     * @param array<int, string> $words
     */
    protected function maskPersonalScalar(array $words, string $compact, string $value, bool $personContext): ?string
    {
        if ($value === '') {
            return null;
        }

        if (in_array($compact, self::NAME_KEYS, true) || ($personContext && in_array('name', $words, true))) {
            return self::MASK;
        }

        if (in_array($compact, self::DOB_KEYS, true)) {
            return $this->masks->maskDob($value);
        }

        if (in_array('email', $words, true) && str_contains($value, '@')) {
            return $this->masks->maskEmail($value);
        }

        $isPhoneKey = in_array($compact, self::PHONE_KEY_WORDS, true) || array_intersect($words, self::PHONE_KEY_WORDS) !== [];

        if ($isPhoneKey && preg_match('/^\+?[\d\s().-]+$/', $value) === 1 && strlen((string)preg_replace('/\D/', '', $value)) >= 7) {
            return $this->masks->maskPhone($value);
        }

        return null;
    }

    /**
     * Mask every leaf under a sensitive key, keeping nested keys, nulls and booleans.
     */
    protected function maskAll(mixed $value): mixed
    {
        $value = $this->normalize($value);

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->maskAll($item), $value);
        }

        if ($value === null || $value === '' || is_bool($value)) {
            return $value;
        }

        return self::MASK;
    }

    /**
     * Mask a card-like digit run when it passes the Luhn check, keeping the last four digits.
     */
    protected function maskCard(string $match): string
    {
        $digits = (string)preg_replace('/\D/', '', $match);

        if (!$this->passesLuhn($digits)) {
            return $match;
        }

        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    /**
     * Check a digit string with the Luhn checksum used by payment card numbers.
     */
    protected function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int)$digits[$i];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Convert arrayable, JSON-serializable and stringable objects (Stripe objects, models, Stringable) to plain values.
     */
    protected function normalize(mixed $value): mixed
    {
        if (!is_object($value) || $value instanceof Throwable) {
            return $value;
        }

        return match (true) {
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof Stringable => (string)$value,
            default => $value,
        };
    }

    /**
     * Check whether a key holds a secret.
     *
     * @param array<int, string> $words
     */
    protected function isSecretKey(array $words, string $compact): bool
    {
        if (array_intersect($words, self::SECRET_KEY_WORDS) !== []) {
            return true;
        }

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a key names a person-like object (singular or plural, e.g. "customer_details", "users").
     *
     * @param array<int, string> $words
     */
    protected function isPersonContext(array $words): bool
    {
        foreach ($words as $word) {
            if (in_array($word, self::PERSON_CONTEXT_WORDS, true) || in_array(rtrim($word, 's'), self::PERSON_CONTEXT_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a key into lowercase words ("billingDetails" and "billing-details" give ["billing", "details"]).
     *
     * @return array<int, string>
     */
    protected function keyWords(string $key): array
    {
        $snake = (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);

        return preg_split('/[^a-z0-9]+/', strtolower($snake), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
