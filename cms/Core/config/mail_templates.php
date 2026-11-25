<?php

/*
 * Managed e-mail templates (`email_templates`), merged as `core.mail_templates`.
 *
 * - `layouts`: the Blade mail layout views an `email_templates.blade_layout` may name (MailLayoutAllowlist). Checked when
 *   a template is saved and again when a mail is rendered: a stored name outside the list is not rendered (the body
 *   goes out without that frame and a warning is logged). The order and Journal layouts are the ones existing rows use;
 *   `mail.branded` is the shared `<x-email.layout>` frame of workflow mails.
 * - `workflow.recipient_hours` / `workflow.recipient_max`: abuse guard of the workflow `email_send` node in template mode
 *   for a "trigger's own subject" recipient (an address a visitor typed: contact auto-reply, sign-up welcome). At most
 *   `recipient_max` mails per address and template within `recipient_hours`; further runs record
 *   `skipped: rate_limited`. 0 hours turns the guard off.
 * - `workflow.defaults`: the default rows of group `workflow` that a sample template needs, by `key_name`
 *   (WorkflowEmailTemplates::ensureDefaults). Created once when a template using the key is installed (code installer,
 *   hub install or in-place update); never overwritten afterwards, and not recreated once the key has any row (also a
 *   deleted one). `translations` by language code; a site without those languages gets one row without a language.
 *   Modules add their own entry from their provider's register():
 *   config()->set('core.mail_templates.workflow.defaults.<key>', [...]).
 */

return [
    'layouts' => [
        'mail.order.layout',
        'journal::mail.layout',
        'mail.branded',
    ],

    'workflow' => [
        'recipient_hours' => 24,
        'recipient_max' => 1,

        'defaults' => [
            // Contact auto-reply ("đã nhận tin"): template "Liên hệ mới" (cms:workflow-install-contact), to the sender.
            'contact_auto_reply' => [
                'mail_class' => 'system',
                'variables' => [
                    'display_name' => 'Name the sender typed (unchecked)',
                    'site_name' => 'Website name',
                    'site_url' => 'Website address',
                ],
                'sample_data' => ['display_name' => 'Lan Nguyễn', 'site_name' => 'iSPA', 'site_url' => 'https://example.com'],
                'translations' => [
                    'vi' => [
                        'name' => '{{ site_name }} đã nhận được tin nhắn của bạn',
                        'content' => '<p>Chào {{ display_name }},</p>'
                            . '<p>{{ site_name }} đã nhận được tin nhắn bạn gửi qua trang web. Chúng tôi sẽ đọc và trả lời bạn sớm.</p>'
                            . '<p>Đây là thư tự động, bạn không cần trả lời thư này.</p>',
                    ],
                    'en' => [
                        'name' => '{{ site_name }} has received your message',
                        'content' => '<p>Hello {{ display_name }},</p>'
                            . '<p>{{ site_name }} has received the message you sent through the website. We will read it and reply soon.</p>'
                            . '<p>This is an automatic message; there is no need to reply.</p>',
                    ],
                ],
            ],
        ],
    ],
];
