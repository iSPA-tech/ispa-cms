<?php

declare(strict_types=1);

namespace Cms\Core\Services\Mail;

use Illuminate\Support\Facades\Log;

/**
 * The mail layout views an `email_templates.blade_layout` may name (`core.mail_templates.layouts`).
 *
 * `blade_layout` is an admin-set view name passed to view(): without a list any view file of the site could be chosen.
 * Saving a template checks the name (allows()); rendering a stored template asks wrap(), which renders only a listed
 * layout and otherwise returns the filled body without a frame and logs a warning (a row saved before the list keeps
 * sending; it just loses an unknown frame).
 *
 * @package Cms\Core\Services\Mail
 * @since   2026-11-25
 */
final class MailLayoutAllowlist
{
    /**
     * @return list<string>
     */
    public static function layouts(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', (array) config('core.mail_templates.layouts', [])),
            static fn (string $v): bool => $v !== '',
        )));
    }

    /** Whether a stored or submitted layout name may be used (empty = no layout, always allowed). */
    public static function allows(?string $layout): bool
    {
        $layout = trim((string) $layout);

        return $layout === '' || in_array($layout, self::layouts(), true);
    }

    /**
     * The filled body inside the template's layout when the layout is listed; the body alone otherwise.
     *
     * @param string               $body   Filled template body (already escaped by SafeTemplateRenderer).
     * @param array<string, mixed> $data   Placeholder data the layout may read.
     */
    public static function wrap(?string $layout, string $body, array $data = []): string
    {
        $layout = trim((string) $layout);
        if ($layout === '') {
            return $body;
        }

        if (!self::allows($layout)) {
            Log::warning('Mail layout not on core.mail_templates.layouts; sent without it', ['layout' => mb_substr($layout, 0, 100)]);

            return $body;
        }

        return view($layout, ['content' => $body, 'data' => $data])->render();
    }
}
