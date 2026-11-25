<?php

/*
 * Ready-made choices of the workflow designer ("pick, don't type"): `GET api/v2/admin/workflows/presets?kind=<kind>`
 * (Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog). Merged as `core.workflow_presets`.
 *
 * Kinds and item shapes:
 * - `jev_criteria`   items `{key, label, text, default}`: `text` is the line the JEV judge reads (one checkable fact
 *   per item); `default` = ticked when the set is picked.
 * - `score_rubric`   items `{key, label, text, weight}` (ai_score `rubric`: key, weight, description = text).
 * - `classify_labels` items `{key, label}` (ai_classify `labels`), or a `source` whose `resolver` lists the site's own
 *   records (a WorkflowPresetSource class).
 *
 * Text is translated: set `label`/`description` from `text.workflow.preset.<set>.title|desc`, item `label` from
 * `text.workflow.preset.<set>.<item>`, item `text` from `text.workflow.preset.<set>.<item>_text` (English below is the
 * fallback). A row may name its own `label_key` / `description_key` / `text_key`.
 *
 * Modules add sets from their own config file (e.g. `cms/<Module>/config/workflow_presets.php`, same `sets`
 * shape) and register it from their provider's `app()->booted()` with WorkflowPresetCatalog::registerModuleConfig().
 */

$criterion = static fn (string $key, string $label, string $text, bool $default = true): array => [
    'key' => $key, 'label' => $label, 'text' => $text, 'default' => $default,
];

return [
    'kinds' => ['jev_criteria', 'score_rubric', 'classify_labels'],

    /* Module config keys holding more sets: [module => config key]. Filled at boot by registerModuleConfig(). */
    'module_configs' => [],

    'sets' => [
        'news' => [
            'kind' => 'jev_criteria',
            'label' => 'Parish news',
            'description' => 'News of parish events and announcements.',
            'items' => [
                $criterion('source_facts', 'Facts from the source only', 'Every name, date, time, place and number in the article appears in the source material; the article adds no other details.'),
                $criterion('key_details', 'Main details included', 'The article states what happens, who organises it, the date and time, and the place, whenever the source gives them.'),
                $criterion('names_titles', 'Correct names and titles', 'Parish names, saint names, full names and titles (Bishop, Father, Brother, Sister...) are written exactly as in the source.'),
                $criterion('respectful_tone', 'Formal tone', 'The tone is formal and respectful: no clickbait, no advertising, no political comment.'),
                $criterion('no_private_info', 'No private information', 'The article gives no phone number, home address, health detail or other private information about parishioners unless the source published it openly.'),
            ],
        ],
        'reflection' => [
            'kind' => 'jev_criteria',
            'label' => 'Reflection on the Word of God',
            'description' => 'A reflection on the readings of a day.',
            'items' => [
                $criterion('scripture_reference', 'Correct Bible quotations', 'Every Bible quotation names its book, chapter and verse and matches the readings of the stated day.'),
                $criterion('faithful_teaching', 'Faithful to Church teaching', 'Nothing in the text contradicts the teaching of the Catholic Church; uncertain points are not stated as fact.'),
                $criterion('tied_to_reading', 'Stays with the reading', 'The main idea comes from the stated Scripture reading and does not drift to another topic.'),
                $criterion('practical_application', 'A way to live it', 'The text gives at least one concrete suggestion for living the Word in daily life.'),
                $criterion('no_invented_quotes', 'No invented quotations', 'The text attributes no words to the Pope, a saint or any author unless the source contains that quotation.'),
            ],
        ],
        'support_answer' => [
            'kind' => 'jev_criteria',
            'label' => 'Support answer',
            'description' => 'An answer to a question sent to the help desk.',
            'items' => [
                $criterion('answers_question', 'Answers the question', 'The answer addresses what the person actually asked, not a different question.'),
                $criterion('from_support_material', 'Based on the support material', 'Every instruction, price, deadline and policy in the answer appears in the support material provided.'),
                $criterion('clear_steps', 'Clear steps', 'When several steps are needed, the answer lists them in order, one action per step.'),
                $criterion('plain_polite', 'Polite and plain', 'The wording is polite and plain, with no technical terms unless they are needed.'),
                $criterion('no_promises', 'No unsupported promises', 'The answer promises no refund, compensation or handling time that the support material does not state.'),
                $criterion('no_secrets', 'Never asks for secrets', 'The answer never asks the person to send a password, a one-time code or a card number.'),
            ],
        ],
        'seo_description' => [
            'kind' => 'jev_criteria',
            'label' => 'Short description and SEO',
            'description' => 'The short description shown in lists and search results.',
            'items' => [
                $criterion('length', 'Right length', 'The description is 120 to 160 characters long.'),
                $criterion('matches_content', 'Matches the article', 'The description says only what the article contains and promises nothing the article lacks.'),
                $criterion('keyword', 'Main keyword', 'The description contains the main keyword of the article once, used naturally.'),
                $criterion('no_clickbait', 'No clickbait', 'The description has no all-caps words, no repeated exclamation marks and no clickbait.'),
                $criterion('full_diacritics', 'Full Vietnamese diacritics', 'When the description is in Vietnamese, every word has full and correct diacritics.'),
            ],
        ],
        'article_quality' => [
            'kind' => 'score_rubric',
            'label' => 'Article quality',
            'description' => 'Scores an article on accuracy, clarity, usefulness and style.',
            'items' => [
                ['key' => 'accuracy', 'label' => 'Accurate', 'text' => 'The information matches the source and nothing is invented.', 'weight' => 40],
                ['key' => 'clarity', 'label' => 'Clear', 'text' => 'Sentences are clear and easy to follow, and the ideas come in a sensible order.', 'weight' => 30],
                ['key' => 'usefulness', 'label' => 'Useful', 'text' => 'The reader knows what happens, when and where, or what to do next.', 'weight' => 20],
                ['key' => 'style', 'label' => 'Style', 'text' => 'The tone fits the site and the spelling is correct.', 'weight' => 10],
            ],
        ],
        'contact_message' => [
            'kind' => 'classify_labels',
            'label' => 'Contact message types',
            'description' => 'Sorts messages sent through the contact form.',
            'items' => [
                ['key' => 'question', 'label' => 'Question'],
                ['key' => 'prayer_request', 'label' => 'Prayer request'],
                ['key' => 'sacrament', 'label' => 'Sacrament or Mass request'],
                ['key' => 'feedback', 'label' => 'Feedback or complaint'],
                ['key' => 'spam', 'label' => 'Spam'],
            ],
        ],
    ],
];
