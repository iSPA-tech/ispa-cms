<?php

namespace Cms\Content\Records;

use Cms\Content\Models\Content;
use Cms\Core\Records\RecordRegistry;
use Cms\Core\Records\RecordType;

/**
 * Registers the Content record type (`content.content`) in Core's record registry so its audit history is served by
 * `api.v2.records.history`.
 *
 * Reading needs `content.view`. Only workflow fields may show old/new values; titles, bodies and other free text are
 * never listed.
 */
final class ContentRecordTypes
{
    /** Ability that lets an editor read the history. */
    public const VIEW_ABILITY = 'content.view';

    /** Ability that manages the records. */
    public const MANAGE_ABILITY = 'content.update';

    /** Translation key prefix of the action labels. */
    private const LABEL_PREFIX = 'text.content.history.';

    /**
     * Register the type. Idempotent: a key already registered is left as it is.
     */
    public static function register(RecordRegistry $registry): void
    {
        if ($registry->get('content.content') !== null) {
            return;
        }

        $registry->register(new RecordType(
            'content.content',
            Content::class,
            self::VIEW_ABILITY,
            self::MANAGE_ABILITY,
            null,
            ['status', 'is_confirmed', 'language_id'],
            self::actionLabels(),
        ));
    }

    /**
     * Stored audit action (`action.<event>`) to translation key, for every event {@see \Cms\Content\Observers\ContentObserver} records.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        $labels = [];
        foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $event) {
            $labels["action.{$event}"] = self::LABEL_PREFIX . $event;
        }

        return $labels;
    }
}
