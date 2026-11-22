<?php

declare(strict_types=1);

namespace Cms\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Notice that the account signed in from a device or network not seen in its recent tokens.
 *
 * Queued, so the sign-in answer never waits for mail. The payload holds the short device label and the time only: no
 * address, no token, no network label. Mail wording uses translation keys `mgs.new_device_notice_*`, with an English
 * default while a site has no row for a key.
 */
class NewDeviceSignInNotification extends AdminNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param string|null $device Short device label (for example "Chrome on OS X"); null when unknown.
     * @param string $at Sign-in time, ISO 8601.
     */
    public function __construct(?string $device, string $at)
    {
        parent::__construct('security.new_device', ['device' => $device, 'at' => $at]);
    }

    /**
     * Mail body: device label and time, never an address or token.
     *
     * @param mixed $notifiable Notification recipient.
     * @return MailMessage
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $device = (string) ($this->payload['device'] ?? '');
        $at = (string) ($this->payload['at'] ?? '');

        return (new MailMessage())
            ->subject(self::line('mgs.new_device_notice_subject', 'New sign-in to your account'))
            ->line(self::line('mgs.new_device_notice_intro', 'Your account was just signed in from a device we have not seen recently.'))
            ->line(self::line('mgs.new_device_notice_device', 'Device: :device', ['device' => $device !== '' ? $device : '-']))
            ->line(self::line('mgs.new_device_notice_time', 'Time: :time', ['time' => self::localTime($at)]))
            ->line(self::line('mgs.new_device_notice_outro', 'If this was not you, sign out of all devices and change your password.'));
    }

    /**
     * Sign-in time in the site timezone and the current locale (for example "11/11/2026 17:00 (UTC+07:00)").
     * Falls back to the stored text when it cannot be read as a date.
     *
     * @param string $at Sign-in time, ISO 8601.
     * @return string
     */
    private static function localTime(string $at): string
    {
        try {
            $time = Carbon::parse($at)->timezone((string) config('app.timezone', 'UTC'))->locale(app()->getLocale());

            return $time->isoFormat('L LT') . ' (UTC' . $time->format('P') . ')';
        } catch (Throwable) {
            return $at;
        }
    }

    /**
     * Translated line, or the English default when the key has no row yet.
     *
     * @param string $key Translation key.
     * @param string $default English default with :placeholders.
     * @param array<string, string> $replace Placeholder values.
     * @return string
     */
    private static function line(string $key, string $default, array $replace = []): string
    {
        if (Lang::has($key)) {
            return (string) __($key, $replace);
        }
        foreach ($replace as $name => $value) {
            $default = str_replace(':' . $name, $value, $default);
        }

        return $default;
    }
}
