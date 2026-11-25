<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Mail;

use BackedEnum;
use Illuminate\Support\Arr;
use Stringable;
use UnitEnum;

/**
 * Fills the placeholders of a stored, admin-edited template (for example `email_templates.content`) without compiling it.
 *
 * Stored templates must never reach Blade: anyone allowed to edit one could otherwise run PHP on the server
 * (`@php`, `{{ system(...) }}`, `{!! !!}`, `@include`, ...). This renderer only substitutes named values:
 *
 * - `{{ key }}`, `{{ $key }}`, `{!! key !!}` and `{!! $key !!}` print the value of `key` (dotted keys such as
 *   `contact.name` resolve with Arr::get), HTML-escaped with e(). A key listed in `$trustedHtmlKeys` prints raw: pass
 *   only values the server builds itself (and escapes inside), such as the order items table rows.
 * - A placeholder whose key is not in `$data`, and any `{{ ... }}` / `{!! ... !!}` holding something other than a
 *   plain key (an expression, a function or method call, an operator), prints nothing. Nothing is ever evaluated.
 * - `@{{ ... }}` prints the literal `{{ ... }}` and `{{-- ... --}}` comments are dropped, as Blade did.
 * - Blade directives (`@php`, `@include`, `@if`, ...) and PHP tags are not interpreted: they stay inert text.
 *
 * Values: strings and numbers print as-is (before escaping), null and false print nothing, true prints `1`, backed
 * enums print their value, Stringable objects their string; arrays and other objects print nothing.
 *
 * Outer layouts stay Blade *files* (code, not stored data): render the filled body here, then hand it to a view such
 * as `mail.order.layout` or `mail.branded` as an already-escaped string.
 *
 * @package Cms\Core\Services\Mail
 * @author  iSPA Team
 * @since   2026.11 (2026-11-25)
 */
final class SafeTemplateRenderer
{
    /** `@{{ ... }}` (escaped braces), `{{-- ... --}}` (comment), `{!! ... !!}` and `{{ ... }}` (placeholders). */
    private const HTML_TOKEN = '/@(\{\{.*?\}\})|\{\{--.*?--\}\}|\{!!\s*(.*?)\s*!!\}|\{\{\s*(.*?)\s*\}\}/s';

    /** `{{ ... }}` and single-brace `{key}` placeholders of a plain-text line (mail subject). */
    private const TEXT_TOKEN = '/\{\{\s*(.*?)\s*\}\}|\{([A-Za-z0-9_.-]+)\}/s';

    /** A plain key, optionally written as a PHP variable: `name`, `$name`, `contact.name`. */
    private const KEY = '/^\$?([A-Za-z_][A-Za-z0-9_]*(?:[.-][A-Za-z0-9_]+)*)$/';

    /**
     * Fill an HTML template.
     *
     * @param  string                $template         Stored template source (admin-edited, untrusted).
     * @param  array<string, mixed>  $data             Placeholder values; every value is HTML-escaped unless trusted.
     * @param  list<string>          $trustedHtmlKeys  Keys whose values are server-built HTML and print unescaped.
     *
     * @return string The filled HTML.
     */
    public function render(string $template, array $data, array $trustedHtmlKeys = []): string
    {
        $trusted = array_fill_keys($trustedHtmlKeys, true);

        return (string) preg_replace_callback(self::HTML_TOKEN, function (array $m) use ($data, $trusted): string {
            if (($m[1] ?? '') !== '') {
                return $m[1];
            }

            $inner = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
            $key = $this->key($inner);
            if ($key === null) {
                return '';
            }

            $value = $this->stringValue($data, $key);

            return isset($trusted[$key]) ? $value : e($value);
        }, $template);
    }

    /**
     * Fill a plain-text template such as a mail subject: `{key}` and `{{ key }}` placeholders, values not escaped.
     * Unknown keys and anything that is not a plain key print nothing.
     *
     * @param  string                $template  Stored text (admin-edited, untrusted).
     * @param  array<string, mixed>  $data      Placeholder values.
     *
     * @return string The filled text.
     */
    public function renderText(string $template, array $data): string
    {
        return (string) preg_replace_callback(self::TEXT_TOKEN, function (array $m) use ($data): string {
            $key = $this->key(($m[2] ?? '') !== '' ? $m[2] : $m[1]);

            return $key === null ? '' : $this->stringValue($data, $key);
        }, $template);
    }

    private function key(string $inner): ?string
    {
        return preg_match(self::KEY, trim($inner), $match) === 1 ? $match[1] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = array_key_exists($key, $data) ? $data[$key] : Arr::get($data, $key);

        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            default => '',
        };
    }
}
