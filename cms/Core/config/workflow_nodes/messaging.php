<?php

/*
 * Messaging node set (lane N5b): telegram_send, email_send, facebook_page_post, text_to_speech.
 * Merged as `core.workflow_nodes.messaging`; NodeRegistry::runnerMap()/definitionRows() pick up `step_runners` and
 * `definitions`. Credentials are `$bind:<name>` values resolved from the workflow credential store at run time.
 */

use Cms\Core\Workflows\Runtime\Runners\Integration\EmailSendRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\FacebookPagePostRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\TelegramSendRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\TextToSpeechRunner;

$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr' => true];
$str = ['type' => ['string', 'null']];
$bool = ['type' => ['boolean', 'string', 'integer', 'null']];
$recipients = ['type' => ['string', 'array', 'null'], 'x-expr' => true];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
// A credential field is also a setup question (x-setup credential) the site answers after installing a sample.
$secret = static fn (string $kind): array => ['type' => ['string', 'null'], 'x-secret' => $kind, 'x-setup' => 'credential'];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];

// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);
// Known values of a result field (NodeDefinition::$result `values`): value => English label.
$values = static fn (array $labels): array => ['values' => array_map(
    static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
    array_keys($labels),
    array_values($labels),
)];

return [
    'step_runners' => [
        'telegram_send' => TelegramSendRunner::class,
        'email_send' => EmailSendRunner::class,
        'facebook_page_post' => FacebookPagePostRunner::class,
        'text_to_speech' => TextToSpeechRunner::class,
    ],

    'definitions' => [
        'telegram_send' => $label('telegram_send') + [
            'result' => [
                $part('sent', 'boolean', 'Sent'),
                $part('message_id', 'number', 'Message number'),
                $part('chat', 'string', 'Chat'),
            ],
            'group' => 'integration', 'icon' => 'send',
            'safety' => ['external_call'], 'approval_default' => 'on',
            'bindings' => ['telegram_bot'],
            'config_schema' => $object([
                'credential' => $secret('telegram_bot'), 'chat' => $expr + ['x-setup' => 'notify_channel'], 'text' => $expr, 'media' => $expr,
                'parse_mode' => ['type' => ['string', 'null'], 'enum' => ['', 'HTML', 'MarkdownV2', null]],
                'silent' => $bool, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['credential' => '', 'chat' => '', 'text' => '', 'storeAs' => 'vars.telegram'],
        ],
        // Version 2: `mode` `template` sends a managed e-mail template (Core
        // `email_templates`, group `workflow`, picked by `template_key`) filled with `variables` (name => expression;
        // every value escaped, untrusted) to `recipient` `trigger_subject` (the trigger's own account or sender,
        // resolved on the server) or `staff` (holders of `recipient_permission`). A `business` template sends only while
        // `business_enabled` is on; a `system` one always. `mode` `text` (default, version 1 shape) is unchanged:
        // typed subject/body to `to`. A version 1 config upgrades unchanged; templates stamped with version 2 make an
        // older site refuse them (`node_version_unsupported`). Policy B limits: EmailSendPolicy.
        'email_send' => $label('email_send') + [
            'version' => 2,
            'result' => [
                $part('sent', 'boolean', 'Sent'),
                $part('recipients', 'number', 'Number of recipients'),
                $part('skipped', 'boolean', 'Skipped'),
                $part('skip_reason', 'string', 'Why it was skipped') + $values([
                    'business_mail_off' => 'Business mail is off',
                    'rate_limited' => 'Already sent to this address recently',
                    'spam' => 'Spam or blocked sender',
                    'no_recipient' => 'Nobody to send to',
                ]),
                $part('mail_class', 'string', 'Mail class') + $values(['system' => 'System mail', 'business' => 'Business mail']),
            ],
            'group' => 'integration', 'icon' => 'email',
            'safety' => ['external_call'], 'approval_default' => 'on',
            'bindings' => ['smtp'],
            'config_schema' => $object([
                'credential' => $secret('smtp') + ['x-setup-when' => ['mode' => ['text']]],
                'mode' => ['type' => ['string', 'null'], 'enum' => ['text', 'template', null]],
                'to' => $recipients + ['x-setup' => 'notify_channel', 'x-setup-when' => ['recipient' => ['address']]],
                'subject' => $expr, 'body' => $expr, 'reply_to' => $expr,
                'template_key' => $str,
                'language' => $expr,
                'variables' => ['type' => ['object', 'array', 'null']],
                'recipient' => ['type' => ['string', 'null'], 'enum' => ['address', 'trigger_subject', 'staff', null]],
                'recipient_permission' => $str + ['x-setup' => 'notify_channel', 'x-setup-when' => ['recipient' => ['staff']]],
                'business_enabled' => $bool,
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['mode' => 'text', 'recipient' => 'address', 'to' => '', 'subject' => '', 'body' => '', 'storeAs' => 'vars.email'],
        ],
        'facebook_page_post' => $label('facebook_page_post') + [
            'result' => [
                $part('posted', 'boolean', 'Posted'),
                $part('reused', 'boolean', 'Reused an earlier result'),
                $part('post_id', 'string', 'Post number'),
                $part('url', 'string', 'Link'),
            ],
            'group' => 'integration', 'icon' => 'facebook',
            'safety' => ['external_call', 'publish'], 'approval_default' => 'required',
            'bindings' => ['facebook_page'],
            'config_schema' => $object([
                'credential' => $secret('facebook_page'),
                'kind' => ['type' => ['string', 'null'], 'enum' => ['text', 'link', 'photo', null]],
                'message' => $expr, 'link' => $expr, 'media' => $expr, 'receipt_key' => $expr, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['credential' => '', 'kind' => 'text', 'message' => '', 'receipt_key' => '', 'storeAs' => 'vars.facebook_post'],
        ],
        'text_to_speech' => $label('text_to_speech') + [
            'result' => [
                $part('path', 'string', 'File path'),
                $part('url', 'string', 'Link'),
                $part('disk', 'string', 'Storage'),
                $part('bytes', 'number', 'Size (bytes)'),
                $part('format', 'string', 'Format'),
                $part('characters', 'number', 'Characters'),
            ],
            'group' => 'integration', 'icon' => 'volume-high',
            'safety' => ['ai_spend'], 'approval_default' => 'off',
            'bindings' => ['tts'],
            'config_schema' => $object([
                'credential' => $secret('tts'), 'text' => $expr, 'voice' => $str, 'model' => $str,
                'format' => ['type' => ['string', 'null'], 'enum' => ['mp3', 'opus', 'aac', 'wav', null]],
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['credential' => '', 'text' => '', 'voice' => 'alloy', 'format' => 'mp3', 'storeAs' => 'vars.speech'],
        ],
    ],

    'settings' => [
        'timeout_seconds' => 20,
        'connect_timeout_seconds' => 5,
        'facebook_graph_version' => 'v26.0',
        'email_max_recipients' => 20,
        'tts' => [
            'disk' => 'public',
            'max_characters' => 4096,
            'default_model' => 'gpt-4o-mini-tts',
            // USD per million input characters (gpt-4o-mini-tts is billed by tokens; 15 is an estimate).
            'models' => ['gpt-4o-mini-tts' => 15, 'tts-1' => 15, 'tts-1-hd' => 30],
        ],
    ],
];
