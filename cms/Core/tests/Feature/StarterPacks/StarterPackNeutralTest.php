<?php

declare(strict_types=1);

/*
 * the starter packs that ship in the open export carry sample content only. The help-center knowledge base is
 * written for a sample product, not for a client's product.
 */
uses(Tests\TestCase::class);

it('keeps client product names out of the help-center pack', function (): void {
    $dir = base_path('cms/Core/resources/starter-packs/help-center');
    $offenders = [];
    foreach (['content/helpdesk/kb.json', 'pack.json', 'roles.json', 'config.package.json'] as $file) {
        if (preg_match('/e-?agents/i', (string) file_get_contents($dir . '/' . $file)) === 1) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the sample product consistent in both languages of the knowledge base', function (): void {
    $kb = json_decode((string) file_get_contents(base_path('cms/Core/resources/starter-packs/help-center/content/helpdesk/kb.json')), true);

    expect($kb['product']['name_en'])->toBe('Acme Help Center')
        ->and($kb['product']['name_vi'])->toBe('Trung tâm trợ giúp Acme');
});
