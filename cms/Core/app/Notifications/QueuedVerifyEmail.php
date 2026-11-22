<?php

namespace Cms\Core\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Send Laravel's signed email verification notification through the queue.
 */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
