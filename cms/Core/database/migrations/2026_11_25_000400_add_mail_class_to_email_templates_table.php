<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail class of a managed e-mail template: `system` (notices such as "message
 * received", welcome, reminders, status) or `business`. Only the workflow `email_send` node reads it: a system mail
 * sends without a switch, a business mail only when the step's switch is on.
 *
 * Existing rows become `business`: they are module mails (Ecommerce order, Journal) sent by module code, which never
 * reads the class, and a workflow step that picks one of them stays off until someone switches it on (fail closed).
 * Additive only.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('email_templates', 'mail_class')) {
            return;
        }

        Schema::table('email_templates', static function (Blueprint $table): void {
            $table->string('mail_class', 16)->default('business')->after('group');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_templates', 'mail_class')) {
            Schema::table('email_templates', static function (Blueprint $table): void {
                $table->dropColumn('mail_class');
            });
        }
    }
};
