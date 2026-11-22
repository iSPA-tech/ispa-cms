<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Setting;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the extended contact-group settings (organization email, feedback phone,
 * and per-source notification recipients) to the `settings` table.
 *
 * Definition-only: creates the field with an empty default value and, on
 * subsequent runs, refreshes only the definition (type/input_setting) so an
 * admin-entered value is never overwritten. Idempotent + safe on every deploy.
 */
class ContactSettingsExtensionSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable(CoreTableConstant::SETTINGS)) {
            return;
        }

        foreach ($this->fields() as $field) {
            $setting = Setting::query()->firstOrNew(['setting_key' => $field['setting_key']]);
            $isNew = ! $setting->exists;

            $setting->type = $field['type'];
            $setting->group_name = 'contact';
            $setting->name = $field['name'];
            $setting->language_id = null;
            $setting->enumerate_id = null;
            $setting->input_setting = $field['input_setting'];

            if ($isNew) {
                $setting->value = '';
            }

            $setting->save();
        }
    }

    /**
     * @return array<int, array{setting_key:string,name:string,type:string,input_setting:array<string,mixed>}>
     */
    private function fields(): array
    {
        return [
            $this->field('email_organization', 'email', 'text.email_organization', 'text.email_organization'),
            $this->field('feedback_phone', 'text', 'text.feedback_phone', 'text.feedback_phone'),
            $this->field('notify_contact_email', 'email', 'text.notify_contact_email', 'text.e.contact_email'),
            $this->field('notify_feedback_email', 'email', 'text.notify_feedback_email', 'text.e.contact_email'),
            $this->field('notify_journal_email', 'email', 'text.notify_journal_email', 'text.e.contact_email'),
        ];
    }

    /**
     * @return array{setting_key:string,name:string,type:string,input_setting:array<string,mixed>}
     */
    private function field(string $name, string $type, string $title, string $description): array
    {
        return [
            'setting_key' => 'contact.' . $name,
            'name' => $name,
            'type' => $type,
            'input_setting' => [
                'required' => false,
                'multiple' => false,
                'min' => -1,
                'max' => -1,
                'language' => false,
                'title' => $title,
                'description' => $description,
                'rows' => 3,
                'editor' => null,
                'options' => [],
                'parent_group' => null,
                'isEnv' => false,
                'isPublic' => true,
            ],
        ];
    }
}
