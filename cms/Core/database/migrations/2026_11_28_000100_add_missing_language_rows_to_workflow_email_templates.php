<?php

use Cms\Core\Models\EmailTemplate;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow e-mail templates in the site's language (2026-11-27, a production site): sites that store their languages as
 * `VN`/`vi` (code/path) got only the English default rows of `contact_auto_reply` and `account_welcome`, because the
 * default rows were matched by `code = 'vi'`; mail then went out in English on a Vietnamese site. ManagedEmailTemplates
 * now matches the code or the path; this adds, once, the default rows of the site's languages a key still lacks. An
 * edited row is never touched, a deleted language row is never recreated, and a key kept as one row without a language
 * is left alone. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('email_templates') || !Schema::hasTable('languages')) {
            return;
        }

        $keys = EmailTemplate::query()->where('group', EmailTemplate::GROUP_WORKFLOW)->distinct()->pluck('key_name')->all();
        $declared = array_values(array_filter($keys, static fn ($key): bool => is_string($key) && is_array(config('core.mail_templates.workflow.defaults.' . $key))));
        if ($declared === []) {
            return;
        }

        $added = app(ManagedEmailTemplates::class)->ensureDefaults($declared);
        if ($added !== []) {
            Log::info('Workflow e-mail templates: missing language rows added', ['keys' => $added]);
        }
    }

    public function down(): void
    {
        // The added rows stay: they are the default text of the site's language.
    }
};
