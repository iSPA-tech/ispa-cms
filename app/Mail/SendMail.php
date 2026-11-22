<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendMail extends Mailable implements HasLocalePreference
{
    use Queueable, SerializesModels;

    public $subject;

    public string $blade;

    public array $mail;

    /**
     * Create a new message instance.
     *
     * @param  array  $mail
     * @param  string  $subject
     * @param  string  $blade
     */
    public function __construct(array $mail, string $subject, string $blade)
    {
        $this->mail = $mail;
        $this->subject = $subject;
        $this->blade = $blade;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        return $this->view($this->blade)->subject($this->subject)->with($this->mail);
    }

    /**
     * @return string
     *
     **@author malayvuong
     */
    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
