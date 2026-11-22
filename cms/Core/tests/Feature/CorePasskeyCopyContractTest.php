<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Passkey errors shown on the website print translation keys only.
 */
final class CorePasskeyCopyContractTest extends TestCase
{
    public function test_passkey_errors_print_only_translated_copy(): void
    {
        $root = base_path('cms/Core');
        $sources = [
            $root . '/app/Http/Requests/PasskeyCredentialRequest.php',
            $root . '/app/Services/Security/PasskeyService.php',
        ];
        $lang = [
            'en' => require $root . '/lang/en/text.php',
            'vi' => require $root . '/lang/vi/text.php',
        ];

        foreach ($sources as $file) {
            $source = (string) file_get_contents($file);
            $label = str_replace($root . '/', '', $file);
            $code = preg_replace('#/\*.*?\*/|^\s*//.*$|\{\{--.*?--\}\}#ms', '', $source);

            $this->assertDoesNotMatchRegularExpression('/[À-ỹ]/u', (string) $code, "{$label} prints raw Vietnamese copy");

            preg_match_all("/core::text\.(core\.[A-Za-z0-9_.\-]+[A-Za-z0-9_])['\"]/", $source, $matches);
            foreach (array_unique($matches[1]) as $key) {
                foreach ($lang as $locale => $tree) {
                    $this->assertIsString(Arr::get($tree, $key), "{$label}: {$key} is missing from lang/{$locale}/text.php");
                }
            }
        }
    }

    public function test_web_rows_in_the_sidecar_match_the_generated_lang_files(): void
    {
        $root = base_path('cms/Core');
        $rows = array_filter(
            require $root . '/database/seeders/sql/translate-admin-authored.php',
            static fn (array $row): bool => (bool) $row['for_web'] && str_starts_with($row['name'], 'text.core.web.')
        );
        $names = array_column($rows, 'name');

        $this->assertNotEmpty($rows);
        $this->assertCount(count($names), array_unique($names));

        foreach ($rows as $row) {
            foreach (['en', 'vi'] as $locale) {
                $tree = require $root . "/lang/{$locale}/text.php";
                $this->assertSame(
                    $row['text'][$locale],
                    Arr::get($tree, substr($row['name'], strlen('text.'))),
                    "{$row['name']} ({$locale})"
                );
            }
        }
    }

    public function test_keys_follow_the_page_locale(): void
    {
        app()->setLocale('en');
        $this->assertSame('The passkey credential is invalid.', __('core::text.core.web.passkey.invalid_credential'));

        app()->setLocale('vi');
        $this->assertSame('Credential passkey không hợp lệ.', __('core::text.core.web.passkey.invalid_credential'));
    }
}
