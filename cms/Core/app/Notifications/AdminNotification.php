<?php

declare(strict_types=1);

namespace Cms\Core\Notifications;

use Cms\Core\Facades\CMSLogger;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base in-app notification for admin users.
 *
 * Stored in the database channel only; the mail channel is added when the site setting
 * `notifications.mail` is on (default off). The payload must hold scalar values only and stay
 * within 1024 bytes of JSON; an invalid notification is dropped (no channels) and a warning is logged.
 */
class AdminNotification extends Notification
{
    public const int MAX_PAYLOAD_BYTES = 1024;

    /**
     * @param string $type Short notification type key, for example `backup.failed`.
     * @param array<string, mixed> $payload Flat map of scalar values.
     */
    public function __construct(public readonly string $type, public readonly array $payload = [])
    {
    }

    /**
     * Whether the payload is flat (scalars and null only) and within the size limit.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        foreach ($this->payload as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }

        $json = json_encode($this->payload);

        return $json !== false && strlen($json) <= self::MAX_PAYLOAD_BYTES;
    }

    /**
     * Delivery channels: database, plus mail when the site setting is on. An invalid payload is dropped.
     *
     * @param mixed $notifiable Notification recipient.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        if (!$this->isValid()) {
            CMSLogger::warning([
                'message' => 'Admin notification dropped: payload must be flat scalars within ' . self::MAX_PAYLOAD_BYTES . ' bytes',
                'type' => $this->type,
            ]);

            return [];
        }

        $channels = ['database'];

        if (iSetting('notifications.mail', false)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Data stored in the notifications table.
     *
     * @param mixed $notifiable Notification recipient.
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function toArray(mixed $notifiable): array
    {
        return ['type' => $this->type, 'payload' => $this->payload];
    }

    /**
     * Minimal mail version: the subject is the type and the body carries no payload values.
     *
     * @param mixed $notifiable Notification recipient.
     * @return MailMessage
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->type)
            ->line('You have a new notification in the admin panel.');
    }
}
