<?php

declare(strict_types=1);

namespace Cms\Skeleton\Mail;

use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SkeletonThingSavedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly SkeletonThing $skeletonThing)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('skeleton::text.skeleton.mail.saved_subject', ['name' => $this->skeletonThing->name]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'skeleton::mail.thing-saved',
            with: ['skeletonThing' => $this->skeletonThing, 'adminUrl' => url('iPanel/skeleton/things')],
        );
    }
}
