<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Support\Mail\MailBrand;
use Illuminate\Mail\Events\MessageSending;

/**
 * Gives every mail the site sends through Laravel Mail the `[Brand] - <subject>` subject, in one place, so no module can
 * forget it. Mail that already starts with `[Brand]` is left as it is.
 */
class BrandMailSubject
{
    public function handle(MessageSending $event): void
    {
        $subject = (string) $event->message->getSubject();
        $branded = MailBrand::subject($subject);
        if ($branded !== $subject) {
            $event->message->subject($branded);
        }
    }
}
