<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

use Illuminate\Mail\Mailable;

/**
 * Mail sent by the `email_send` workflow node: the workflow's subject and plain-text body inside the shared branded
 * frame (`mail.branded`). The body is escaped; each blank-line-separated block becomes a paragraph.
 *
 * fromTemplate(): a managed e-mail template already filled by SafeTemplateRenderer (its text written by an admin, every filled
 * value escaped) goes into the same frame as it is (template mode of `email_send`, policy B 2026-11-25).
 *
 * @package Cms\Core\Mail
 * @since   2026-11-24
 */
final class WorkflowMessageMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly ?string $replyAddress = null,
        public readonly ?string $filledHtml = null,
    ) {
    }

    /** A filled managed template (HTML) in the branded frame. */
    public static function fromTemplate(string $subject, string $html, ?string $replyAddress = null): self
    {
        return new self($subject, '', $replyAddress, $html);
    }

    public function build(): static
    {
        $html = $this->filledHtml ?? '';
        if ($this->filledHtml === null) {
            $paragraphs = preg_split("/\R{2,}/", trim($this->text)) ?: [];
            foreach ($paragraphs as $paragraph) {
                $html .= '<p style="margin:0 0 12px;">' . nl2br(e($paragraph)) . '</p>';
            }
        }

        if ($this->replyAddress !== null) {
            $this->replyTo($this->replyAddress);
        }

        return $this->subject($this->mailSubject)->view('mail.branded', [
            'title' => $this->mailSubject,
            'body' => $html,
        ]);
    }
}
