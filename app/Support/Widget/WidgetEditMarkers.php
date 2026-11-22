<?php

declare(strict_types=1);

namespace App\Support\Widget;

/**
 * Marks widget field values in rendered HTML so the admin preview can edit
 * them in place.
 *
 * Text and textarea values are wrapped in invisible sentinel tokens before the
 * template renders. After rendering, finalize() turns every token pair that
 * landed in a text position into an <ispa-field> element and strips the tokens
 * everywhere else (attributes, script, style, title, textarea, comments).
 */
final class WidgetEditMarkers
{
    public const SENTINEL = "\u{2063}";

    private const RAW_TEXT_TAGS = ['script', 'style', 'title', 'textarea'];

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    public static function wrapValues(array $values, array $types): array
    {
        foreach ($values as $name => $value) {
            $type = $types[$name] ?? null;
            if (($type !== 'text' && $type !== 'textarea') || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            $clean = str_replace(self::SENTINEL, '', (string) ($value ?? ''));
            $values[$name] = self::SENTINEL . 'F:' . $name . self::SENTINEL . $clean . self::SENTINEL . '/F' . self::SENTINEL;
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $types
     * @param  array<string, array<string, mixed>>  $repeaters  WidgetRepeater::expand() repeaters, listed in the manifest
     */
    public static function finalize(string $html, array $types, array $repeaters = []): string
    {
        $s = self::SENTINEL;
        $endToken = $s . '/F' . $s;
        $len = strlen($html);
        $out = '';
        $textFields = [];
        $i = 0;
        $state = 'text'; // text | tag | raw
        $quote = '';
        $rawName = '';
        $pendingRaw = '';

        while ($i < $len) {
            if (str_starts_with(substr($html, $i, 3), $s)) {
                if (str_starts_with(substr($html, $i, strlen($endToken)), $endToken)) {
                    $i += strlen($endToken);

                    continue;
                }
                if (substr($html, $i + 3, 2) === 'F:') {
                    $nameEnd = strpos($html, $s, $i + 5);
                    if ($nameEnd !== false) {
                        $name = substr($html, $i + 5, $nameEnd - $i - 5);
                        $tokenLen = $nameEnd + 3 - $i;
                        if ($state === 'text') {
                            $endPos = strpos($html, $endToken, $nameEnd + 3);
                            if ($endPos !== false) {
                                $value = substr($html, $nameEnd + 3, $endPos - $nameEnd - 3);
                                $type = $types[$name] ?? 'text';
                                $out .= '<ispa-field data-field="' . e($name) . '" data-type="' . e($type) . '">'
                                    . $value . '</ispa-field>';
                                $textFields[$name] = true;
                                $i = $endPos + strlen($endToken);

                                continue;
                            }
                        }
                        $i += $tokenLen;

                        continue;
                    }
                }
                $i += 3; // stray sentinel

                continue;
            }

            $c = $html[$i];

            if ($state === 'tag') {
                $out .= $c;
                if ($quote !== '') {
                    if ($c === $quote) {
                        $quote = '';
                    }
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '>') {
                    $state = $pendingRaw !== '' ? 'raw' : 'text';
                    $rawName = $pendingRaw;
                    $pendingRaw = '';
                }
                $i++;

                continue;
            }

            if ($state === 'raw') {
                if ($c === '<' && preg_match('/\G<\/' . $rawName . '(?=[\s>\/])/i', $html, $m, 0, $i) === 1) {
                    $state = 'tag';
                    $pendingRaw = '';
                    $out .= '</';
                    $i += 2;

                    continue;
                }
                $out .= $c;
                $i++;

                continue;
            }

            // text state
            if ($c === '<') {
                if (substr($html, $i, 4) === '<!--') {
                    $end = strpos($html, '-->', $i + 4);
                    $stop = $end === false ? $len : $end + 3;
                    $out .= self::stripTokens(substr($html, $i, $stop - $i));
                    $i = $stop;

                    continue;
                }
                if (preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/', $html, $m, 0, $i) === 1) {
                    $tag = strtolower($m[2]);
                    $pendingRaw = ($m[1] === '' && in_array($tag, self::RAW_TEXT_TAGS, true)) ? $tag : '';
                    $state = 'tag';
                    $quote = '';
                    $out .= $m[0];
                    $i += strlen($m[0]);

                    continue;
                }
            }
            $out .= $c;
            $i++;
        }

        $out .= self::manifest($out, $types, array_keys($textFields), $repeaters);

        return $out;
    }

    public static function markImage(string $imgHtml, string $name): string
    {
        return (string) preg_replace(
            '/^<img\b/i',
            '<img data-field="' . e($name) . '" data-type="image"',
            $imgHtml,
            1,
        );
    }

    public static function emptyImage(string $name, string $class): string
    {
        return '<ispa-field data-field="' . e($name) . '" data-type="image" data-empty="1" class="' . e($class) . '"></ispa-field>';
    }

    private static function stripTokens(string $chunk): string
    {
        return str_replace(self::SENTINEL, '', (string) preg_replace(
            '/' . self::SENTINEL . '(?:F:[^' . self::SENTINEL . ']*|\/F)' . self::SENTINEL . '/u',
            '',
            $chunk,
        ));
    }

    /**
     * @param  array<string, string>  $types
     * @param  list<string|int>  $textFields
     * @param  array<string, array<string, mixed>>  $repeaters
     */
    private static function manifest(string $html, array $types, array $textFields, array $repeaters): string
    {
        $on = array_map('strval', $textFields);

        if (preg_match_all('/data-field="([^"]*)" data-type="image"/', $html, $m) > 0) {
            foreach ($m[1] as $raw) {
                $name = html_entity_decode($raw, ENT_QUOTES);
                if (! in_array($name, $on, true)) {
                    $on[] = $name;
                }
            }
        }

        //  A repeater the template renders is edited on the preview through its items
        foreach (array_keys($repeaters) as $name) {
            if (! in_array((string) $name, $on, true)) {
                $on[] = (string) $name;
            }
        }

        $off = [];
        foreach (array_keys($types) as $name) {
            if (! in_array((string) $name, $on, true)) {
                $off[] = (string) $name;
            }
        }

        //  Item fields are flat `NAME.ID.FIELD` keys: their identity travels in data-repeat/data-item-id, so neither
        //  list names them (the admin would show them under "Cài đặt khác"); the repeater NAME stays on_preview
        if ($repeaters !== []) {
            $isItemField = static fn (string $name): bool => str_contains($name, '.')
                && array_key_exists(strstr($name, '.', true), $repeaters);
            $on = array_values(array_filter($on, static fn (string $name): bool => ! $isItemField($name)));
            $off = array_values(array_filter($off, static fn (string $name): bool => ! $isItemField($name)));
        }

        $manifest = ['on_preview' => $on, 'off_preview' => $off];
        if ($repeaters !== []) {
            foreach ($repeaters as $name => $repeater) {
                $repeaters[$name]['item_label'] = ($repeater['item_label'] ?? null) ?: 'mục';
            }
            $manifest['repeaters'] = $repeaters;
        }

        $json = json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return '<script type="application/json" id="ispa-edit-fields">' . $json . '</script>';
    }
}
