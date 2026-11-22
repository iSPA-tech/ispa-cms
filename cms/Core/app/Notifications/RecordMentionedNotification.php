<?php

declare(strict_types=1);

namespace Cms\Core\Notifications;

/**
 * In-app notice that the recipient was @mentioned in a note on a record.
 *
 * The payload names the record and the note only (`record_type`, `record_uuid`, `note_uuid`); it never carries the
 * note text, so the notification store holds nothing the recipient could not re-read through the guarded notes API.
 */
class RecordMentionedNotification extends AdminNotification
{
    /**
     * Create the notification.
     *
     * @param string $recordType Record type key, for example `helpdesk.tickets`.
     * @param string $recordUuid UUID of the record the note is attached to.
     * @param string $noteUuid UUID of the note holding the mention.
     */
    public function __construct(string $recordType, string $recordUuid, string $noteUuid)
    {
        parent::__construct($recordType . '.mentioned', [
            'record_type' => $recordType,
            'record_uuid' => $recordUuid,
            'note_uuid' => $noteUuid,
        ]);
    }
}
