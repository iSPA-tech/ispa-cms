<?php

use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\Settings\EnvBoundSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Settings and `.env` (2026-11-27): with the settings runtime on, the database row of an env-bound setting becomes its
 * only source (EnvBoundSettings). Until now the runtime read an `isEnv` row from `.env` first, so on a running site
 * `.env` holds the value in use and the row may be stale (hand edits, a deploy, rows copied from another site).
 *
 * Once, before the new readers take the row first: for every `isEnv` row whose `.env` variable (Setting.name) holds a
 * value that differs from the row, copy the `.env` value into the row (Eloquent save, so secrets stay encrypted). An
 * empty or `null` `.env` value leaves the row alone. Mail keys without `isEnv` were already read from the row and are
 * not touched. Legacy sites (runtime off) keep `.env` first and are skipped. Only key names are logged. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('settings') || !EnvBoundSettings::databaseIsSource()) {
            return;
        }

        $path = app()->environmentFilePath();
        if (!is_file($path)) {
            return;
        }

        try {
            $env = Dotenv\Dotenv::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return;
        }

        $runtime = app(CMSSettingRuntime::class);
        $copied = [];

        foreach (Setting::query()->whereNull('language_id')->get() as $setting) {
            [$isEnv] = $runtime->classifySetting($setting);
            $name = (string) $setting->name;
            if (!$isEnv || $name === '' || !array_key_exists($name, $env)) {
                continue;
            }

            $value = (string) $env[$name];
            if (in_array(strtolower(trim($value)), ['', 'null', '(null)', 'empty', '(empty)'], true)) {
                continue;
            }

            if ($this->plain($setting->value) === $value) {
                continue;
            }

            $setting->value = $value;
            $setting->save();
            $copied[] = (string) $setting->setting_key;
        }

        if ($copied !== []) {
            Log::info('Env-bound settings copied from .env into their rows', ['keys' => $copied]);
            try {
                $runtime->forget();
            } catch (Throwable) {
                // The runtime cache expires on its own (24 h); the next settings save drops it too.
            }
        }
    }

    public function down(): void
    {
        // The copied values stay: they are the values the site was running with.
    }

    private function plain(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
};
