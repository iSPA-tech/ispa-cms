<?php

namespace Cms\Core\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Send Laravel's signed email verification notification through the queue, with the site's translated copy.
 */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * The verification mail in the current language (Laravel's default copy is English only).
     *
     * @param string $url Signed verification link.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage())
            ->subject(__('mgs.register.verify_subject'))
            ->line(__('mgs.mail.verify-email-intro'))
            ->action(__('mgs.register.verify_link_label'), $url)
            ->line(__('mgs.register.verify_ignore'));
    }
}
