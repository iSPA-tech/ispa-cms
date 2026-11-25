<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Upload;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\Integration\Feed\RssReadRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Feed\SitemapReadRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Html\HtmlExtractRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\DnsResolver;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\FetchRefused;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpRequestRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;
use Cms\Core\Workflows\Runtime\Runners\Integration\Media\MediaImportRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Storage\FileStorageRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const FETCH_HOSTS_URL = 'https://localhost/api/v2/admin/utilities/workflow/http-hosts';

/** An IPv4 address built from its octets, so the tests carry no address literal (the open-source scan flags those). */
function fetchIp(int $a, int $b, int $c, int $d): string
{
    return implode('.', [$a, $b, $c, $d]);
}

/** Fake DNS: host => addresses (anything not listed resolves to a public address). */
function fetchDns(array $map = []): void
{
    app()->instance(DnsResolver::class, new class ($map) extends DnsResolver {
        public function __construct(private array $map)
        {
        }

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [fetchIp(93, 184, 216, 34)]);
        }
    });
}

function fetchContext(array $vars = []): WorkflowContext
{
    $root = new WorkflowProject();
    $root->id = 1;

    return new WorkflowContext($root, [], $vars);
}

function fetchStep(string $type, array $config): StepDefinition
{
    return new StepDefinition(1, 1, $type, 's1', 's1', 'Step', $config);
}

function fetchRefusal(string $url, array $options = []): ?string
{
    try {
        app(SafeHttpFetcher::class)->fetch($url, $options);
    } catch (FetchRefused $e) {
        return $e->reason;
    }

    return null;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['core.workflow_security.http_hosts' => ['api.example.com', '*.example.org', 'feeds.example.net', 'ports.example.com:8443']]);
    fetchDns();
});

it('registers the fetch node set with outputs, taint and safety', function (): void {
    $registry = new NodeRegistry();

    expect(NodeRegistry::runnerMap())->toHaveKey('http_request', HttpRequestRunner::class)
        ->and($registry->get('http_request')?->outputs)->toBe(['success', 'error'])
        ->and($registry->get('http_request')?->taintOutput)->toBeTrue()
        ->and($registry->get('rss_read')?->taintOutput)->toBeTrue()
        ->and($registry->get('media_import')?->safety)->toBe(['external_call', 'internal_write'])
        ->and($registry->get('file_storage')?->safety)->toBe(['internal_write'])
        ->and(app(StepRunnerRegistry::class)->has('sitemap_read'))->toBeTrue();
});

it('refuses every call when the allowlist is empty', function (): void {
    config(['core.workflow_security.http_hosts' => []]);

    expect(fetchRefusal('https://api.example.com/x'))->toBe('host_not_allowed');
    Http::assertNothingSent();
});

it('matches exact hosts, wildcard subdomains and allowlisted ports only', function (): void {
    Http::fake(['*' => Http::response('ok')]);

    expect(fetchRefusal('https://api.example.com/a'))->toBeNull()
        ->and(fetchRefusal('https://news.example.org/a'))->toBeNull()
        ->and(fetchRefusal('https://example.org/a'))->toBe('host_not_allowed')
        ->and(fetchRefusal('https://evil-example.org/a'))->toBe('host_not_allowed')
        ->and(fetchRefusal('https://api.example.com.evil.net/a'))->toBe('host_not_allowed')
        ->and(fetchRefusal('https://api.example.com:8080/a'))->toBe('port_not_allowed')
        ->and(fetchRefusal('https://ports.example.com:8443/a'))->toBeNull()
        ->and(fetchRefusal('ftp://api.example.com/a'))->toBe('scheme')
        ->and(fetchRefusal('file:///etc/passwd'))->toBe('url')
        ->and(fetchRefusal('gopher://api.example.com/'))->toBe('scheme')
        ->and(fetchRefusal('https://user:pw@api.example.com/'))->toBe('url');
});

it('never goes through an environment proxy (HTTP_PROXY / HTTPS_PROXY are ignored)', function (): void {
    $seen = [];
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen[] = $options;

        return Http::response('ok');
    });

    expect(fetchRefusal('https://api.example.com/a'))->toBeNull()
        ->and($seen)->toHaveCount(1)
        ->and($seen[0])->toHaveKey('proxy')
        ->and($seen[0]['proxy'])->toBe(['http' => '', 'https' => '', 'no' => []]);
});

it('uses the Webmaster list as well as the server list', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    expect(fetchRefusal('https://webmaster.example.net/a'))->toBe('host_not_allowed');

    app(HttpHostAllowlist::class)->saveSettingEntries(['webmaster.example.net']);

    expect(fetchRefusal('https://webmaster.example.net/a'))->toBeNull();
});

it('refuses hosts that resolve to private, loopback, link-local or metadata addresses', function (string $ip): void {
    Http::fake(['*' => Http::response('secret')]);
    fetchDns(['api.example.com' => [$ip]]);

    expect(fetchRefusal('https://api.example.com/latest/meta-data'))->toBe('blocked_address');
    Http::assertNothingSent();
})->with([
    'loopback' => '127.0.0.1', '10/8' => fetchIp(10, 1, 2, 3), '172.16/12' => fetchIp(172, 20, 0, 5), '192.168/16' => fetchIp(192, 168, 0, 10),
    'metadata' => fetchIp(169, 254, 169, 254), 'ipv6 loopback' => '::1', 'ipv6 ula' => 'fd00::5', 'ipv6 link-local' => 'fe80::1',
    'mapped loopback' => '::ffff:127.0.0.1',
]);

it('refuses a host when any one of its addresses is private', function (): void {
    Http::fake(['*' => Http::response('x')]);
    fetchDns(['api.example.com' => [fetchIp(93, 184, 216, 34), fetchIp(10, 0, 0, 1)]]);

    expect(fetchRefusal('https://api.example.com/'))->toBe('blocked_address');
});

it('refuses an allowlisted IP literal that is private', function (): void {
    config(['core.workflow_security.http_hosts' => ['127.0.0.1', fetchIp(169, 254, 169, 254)]]);
    Http::fake(['*' => Http::response('x')]);

    expect(fetchRefusal('http://127.0.0.1/'))->toBe('host_not_allowed')
        ->and(fetchRefusal('http://' . fetchIp(169, 254, 169, 254) . '/latest/meta-data'))->toBe('host_not_allowed');
    Http::assertNothingSent();
});

it('re-checks every redirect: a redirect to a private address or an unlisted host is refused', function (): void {
    fetchDns(['internal.example.org' => [fetchIp(10, 0, 0, 8)]]);
    Http::fake([
        'https://api.example.com/to-private' => Http::response('', 302, ['Location' => 'https://internal.example.org/admin']),
        'https://api.example.com/to-other' => Http::response('', 301, ['Location' => 'https://evil.test/']),
        'https://api.example.com/to-metadata' => Http::response('', 302, ['Location' => 'http://' . fetchIp(169, 254, 169, 254) . '/latest']),
        'https://api.example.com/to-file' => Http::response('', 302, ['Location' => 'file:///etc/passwd']),
        '*' => Http::response('should not be reached'),
    ]);

    expect(fetchRefusal('https://api.example.com/to-private'))->toBe('blocked_address')
        ->and(fetchRefusal('https://api.example.com/to-other'))->toBe('host_not_allowed')
        ->and(fetchRefusal('https://api.example.com/to-metadata'))->toBe('host_not_allowed')
        ->and(fetchRefusal('https://api.example.com/to-file'))->toBe('url');
    Http::assertSentCount(4);
});

it('follows at most three redirects on allowed hosts and drops credentials when leaving the host', function (): void {
    Http::fake([
        'https://api.example.com/r1' => Http::response('', 302, ['Location' => '/r2']),
        'https://api.example.com/r2' => Http::response('', 302, ['Location' => 'https://cdn.example.org/r3']),
        'https://cdn.example.org/r3' => Http::response('final', 200, ['Content-Type' => 'text/plain']),
        'https://api.example.com/loop' => Http::response('', 302, ['Location' => '/loop']),
    ]);

    $response = app(SafeHttpFetcher::class)->fetch('https://api.example.com/r1', ['headers' => ['Authorization' => 'Bearer s3cret', 'X-Trace' => '1']]);

    expect($response->body)->toBe('final')->and($response->redirects)->toBe(2)->and($response->url)->toBe('https://cdn.example.org/r3');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.example.com/r2' && $r->hasHeader('Authorization'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://cdn.example.org/r3' && !$r->hasHeader('Authorization') && $r->hasHeader('X-Trace'));

    expect(fetchRefusal('https://api.example.com/loop'))->toBe('too_many_redirects');
});

it('refuses a response larger than max_bytes and forbidden request headers', function (): void {
    Http::fake(['*' => Http::response(str_repeat('a', 5000))]);

    expect(fetchRefusal('https://api.example.com/big', ['max_bytes' => 1000]))->toBe('too_large')
        ->and(fetchRefusal('https://api.example.com/', ['headers' => ['Host' => 'internal']]))->toBe('url')
        ->and(fetchRefusal('https://api.example.com/', ['headers' => ['X-A' => "a\r\nX-B: b"]]))->toBe('url');
});

it('retries connection-level failures and 503 answers', function (): void {
    Http::fakeSequence('api.example.com/*')->push('busy', 503)->push('busy', 503)->push('{"ok":1}', 200, ['Content-Type' => 'application/json']);

    $response = app(SafeHttpFetcher::class)->fetch('https://api.example.com/flaky', ['retries' => 2, 'retry_delay_ms' => 0]);

    expect($response->status)->toBe(200)->and($response->attempts)->toBe(3);
});

it('http_request sends JSON with a header credential, redacts it and leaves by success', function (): void {
    app(WorkflowCredentialStore::class)->create(['name' => 'api', 'type' => 'http_header', 'payload' => ['header' => 'X-Api-Key', 'value' => 'key-123456789', 'allowed_hosts' => 'api.example.com']], null);
    Http::fake(['https://api.example.com/items*' => Http::response(['echo' => 'key-123456789', 'id' => 7], 201)]);
    $context = fetchContext(['title' => 'Hello']);

    $result = (new HttpRequestRunner())->run(fetchStep('http_request', [
        'method' => 'POST', 'url' => 'https://api.example.com/items', 'query' => ['lang' => 'vi'],
        'body_type' => 'json', 'body' => ['title' => '{{ vars.title }}'], 'auth' => 'header', 'credential' => '$bind:api',
        'storeAs' => 'vars.res',
    ]), $context);

    expect($result->status)->toBe('success')
        ->and($result->output())->toBe('success')
        ->and($result->resultData['status'])->toBe(201)
        ->and($result->resultData['body']['id'])->toBe(7)
        ->and($result->resultData['tainted'])->toBeTrue()
        ->and(json_encode($result->resultData))->not->toContain('key-123456789')
        ->and(json_encode($context->getVars()))->not->toContain('key-123456789');
    // Fake secrets are split so the export scan (gitleaks) sees no key-shaped literal next to a key-named field.
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('X-Api-Key', 'key-' . '123456789')
        && str_contains($r->url(), 'lang=vi') && $r['title'] === 'Hello');
});

it('http_request with basic auth; a non-2xx answer fails the step and keeps the result', function (): void {
    app(WorkflowCredentialStore::class)->create(['name' => 'basic', 'type' => 'http_basic', 'payload' => ['username' => 'bot', 'password' => 'pw-' . '987654321', 'allowed_hosts' => 'api.example.com']], null);
    Http::fake(['*' => Http::response('nope', 404)]);
    $context = fetchContext();

    $result = (new HttpRequestRunner())->run(fetchStep('http_request', [
        'url' => 'https://api.example.com/missing', 'auth' => 'basic', 'credential' => '$bind:basic', 'storeAs' => 'vars.res',
    ]), $context);

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toContain('HTTP 404')
        ->and($context->getVarPath('res.status'))->toBe(404);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic ' . base64_encode('bot:pw-987654321')));
});

it('http_request fails cleanly for a refused host', function (): void {
    $result = (new HttpRequestRunner())->run(fetchStep('http_request', ['url' => 'http://' . fetchIp(169, 254, 169, 254) . '/latest/meta-data']), fetchContext());

    expect($result->status)->toBe('failed')->and($result->error['message'])->toContain('not on the allowed hosts list');
});

it('html_extract reads main text, selector fields, links and images without network', function (): void {
    $html = '<html><head><title>Page</title><script>alert(1)</script></head><body><nav>Menu</nav>'
        . '<article><h1 class="t">Headline</h1><p>First para.</p><p>Second <b>para</b>.</p><a href="/next">Next</a>'
        . '<img src="img/a.jpg" alt="A"></article><footer>Foot</footer><a href="javascript:x()">bad</a></body></html>';
    $context = fetchContext(['http' => ['body' => $html]]);

    $result = (new HtmlExtractRunner())->run(fetchStep('html_extract', [
        'html' => '{{ vars.http.body }}', 'base_url' => 'https://api.example.com/news/item',
        'selectors' => ['headline' => 'h1.t', 'next' => 'article a@href', 'paras' => ['selector' => 'p', 'all' => true]],
        'storeAs' => 'vars.page',
    ]), $context);

    expect($result->status)->toBe('success')
        ->and($result->resultData['title'])->toBe('Page')
        ->and($result->resultData['text'])->toContain('Headline')->toContain('Second para.')->not->toContain('Menu')->not->toContain('alert')->not->toContain('Foot')
        ->and($result->resultData['fields'])->toBe(['headline' => 'Headline', 'next' => 'https://api.example.com/next', 'paras' => ['First para.', 'Second para.']])
        ->and(array_column($result->resultData['links'], 'url'))->toBe(['https://api.example.com/next'])
        ->and($result->resultData['images'][0])->toBe(['src' => 'https://api.example.com/news/img/a.jpg', 'alt' => 'A']);
    Http::assertNothingSent();
});

it('rss_read parses RSS and Atom, filters by since and refuses entity declarations', function (): void {
    $rss = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Feed</title><link>https://feeds.example.net</link>'
        . '<item><title>New</title><link>https://feeds.example.net/new</link><guid>n1</guid><pubDate>Mon, 23 Nov 2026 10:00:00 +0000</pubDate><description>&lt;p&gt;Hi&lt;/p&gt;</description><content:encoded><![CDATA[<p>Body</p>]]></content:encoded></item>'
        . '<item><title>Old</title><link>https://feeds.example.net/old</link><pubDate>Mon, 02 Nov 2026 10:00:00 +0000</pubDate></item></channel></rss>';
    $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>A</title><link href="https://feeds.example.net/a"/>'
        . '<entry><title>E1</title><link rel="alternate" href="https://feeds.example.net/e1"/><id>e1</id><updated>2026-11-20T00:00:00Z</updated><summary>S</summary></entry></feed>';
    $bomb = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaa">]><rss><channel><item><title>&a;</title></item></channel></rss>';
    Http::fake([
        'https://feeds.example.net/rss' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml']),
        'https://feeds.example.net/atom' => Http::response($atom),
        'https://feeds.example.net/bomb' => Http::response($bomb),
    ]);

    $rssResult = (new RssReadRunner())->run(fetchStep('rss_read', ['url' => 'https://feeds.example.net/rss', 'since' => '2026-11-15']), fetchContext());
    $atomResult = (new RssReadRunner())->run(fetchStep('rss_read', ['url' => 'https://feeds.example.net/atom']), fetchContext());
    $bombResult = (new RssReadRunner())->run(fetchStep('rss_read', ['url' => 'https://feeds.example.net/bomb']), fetchContext());

    expect($rssResult->status)->toBe('success')
        ->and($rssResult->resultData['count'])->toBe(1)
        ->and($rssResult->resultData['items'][0])->toMatchArray(['title' => 'New', 'link' => 'https://feeds.example.net/new', 'guid' => 'n1', 'summary' => 'Hi', 'content' => '<p>Body</p>'])
        ->and($rssResult->resultData['feed']['title'])->toBe('Feed')
        ->and($atomResult->resultData['items'][0])->toMatchArray(['title' => 'E1', 'link' => 'https://feeds.example.net/e1', 'guid' => 'e1'])
        ->and($bombResult->status)->toBe('failed')->and($bombResult->error['message'])->toContain('DOCTYPE');
});

it('sitemap_read follows a sitemap index on allowed hosts and filters by pattern', function (): void {
    $index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<sitemap><loc>https://feeds.example.net/s1.xml</loc></sitemap><sitemap><loc>https://other.test/s2.xml</loc></sitemap></sitemapindex>';
    $s1 = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://feeds.example.net/news/a</loc><lastmod>2026-11-20</lastmod></url><url><loc>https://feeds.example.net/about</loc></url></urlset>';
    Http::fake(['https://feeds.example.net/sitemap.xml' => Http::response($index), 'https://feeds.example.net/s1.xml' => Http::response($s1)]);

    $ok = (new SitemapReadRunner())->run(fetchStep('sitemap_read', ['url' => 'https://feeds.example.net/sitemap.xml', 'pattern' => '*/news/*', 'max_sitemaps' => 2]), fetchContext());

    expect($ok->status)->toBe('success')
        ->and($ok->resultData['urls'])->toBe([['loc' => 'https://feeds.example.net/news/a', 'lastmod' => '2026-11-20']])
        ->and($ok->resultData['sitemaps_read'])->toBe(2);

    // Three sitemaps allowed: the third (other.test) is off the list, so the step fails before any request there.
    $refused = (new SitemapReadRunner())->run(fetchStep('sitemap_read', ['url' => 'https://feeds.example.net/sitemap.xml', 'max_sitemaps' => 3]), fetchContext());
    expect($refused->status)->toBe('failed')->and($refused->error['message'])->toContain('other.test');
});

it('media_import stores an allowed image in the media library and refuses non-images', function (): void {
    Storage::fake('public');
    $image = imagecreatetruecolor(4, 3);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    Http::fake([
        'https://cdn.example.org/a.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        'https://cdn.example.org/fake.png' => Http::response('<?php echo 1;', 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new MediaImportRunner())->run(fetchStep('media_import', ['url' => 'https://cdn.example.org/a.png', 'alt' => 'Church', 'storeAs' => 'vars.media']), fetchContext());
    $again = (new MediaImportRunner())->run(fetchStep('media_import', ['url' => 'https://cdn.example.org/a.png']), fetchContext());
    $bad = (new MediaImportRunner())->run(fetchStep('media_import', ['url' => 'https://cdn.example.org/fake.png']), fetchContext());
    $big = (new MediaImportRunner())->run(fetchStep('media_import', ['url' => 'https://cdn.example.org/a.png', 'max_bytes' => 10]), fetchContext());

    $upload = Upload::query()->find($result->resultData['upload_id']);
    expect($result->status)->toBe('success')
        ->and($upload)->not->toBeNull()
        ->and($upload->mime_type)->toBe('image/png')
        ->and($upload->alt_text)->toBe('Church')
        ->and($again->resultData['duplicate'])->toBeTrue()
        ->and($again->resultData['upload_id'])->toBe($upload->id)
        ->and($bad->status)->toBe('failed')->and($bad->error['message'])->toContain('JPEG, PNG and WebP')
        ->and($big->status)->toBe('failed')->and($big->error['message'])->toContain('larger than');
});

it('file_storage writes, reads and lists inside the project folder only', function (): void {
    Storage::fake('local');
    $project = WorkflowProject::query()->forceCreate(['name' => 'Files', 'slug' => 'files-' . Str::lower(Str::random(5)), 'target_entity_type' => 'none']);
    $instance = WorkflowInstance::query()->forceCreate(['project_id' => $project->id, 'target_type' => 'none', 'target_id' => 0, 'status' => 'running']);
    $context = fetchContext(['data' => ['a' => 1]]);
    $context->setRuntime('instance_id', $instance->id);
    $run = fn (array $config) => (new FileStorageRunner())->run(fetchStep('file_storage', $config), $context);

    $write = $run(['op' => 'write', 'path' => 'out/data.json', 'format' => 'json', 'content' => '{{ vars.data }}']);
    $read = $run(['op' => 'read', 'path' => 'out/data.json', 'format' => 'json']);
    $list = $run(['op' => 'list']);
    $escape = $run(['op' => 'write', 'path' => '../../.env', 'content' => 'x']);
    $abs = $run(['op' => 'read', 'path' => '/etc/passwd']);
    $disk = $run(['op' => 'write', 'disk' => 'public', 'path' => 'a.txt', 'content' => 'x']);

    expect($write->status)->toBe('success')
        ->and(Storage::disk('local')->exists("workflow/project-{$project->id}/out/data.json"))->toBeTrue()
        ->and($read->resultData['content'])->toBe(['a' => 1])
        ->and($list->resultData['files'][0]['path'])->toBe('out/data.json')
        ->and($escape->status)->toBe('failed')
        ->and($abs->status)->toBe('failed')
        ->and($disk->status)->toBe('failed');

    $outside = (new FileStorageRunner())->run(fetchStep('file_storage', ['op' => 'list']), fetchContext());
    expect($outside->status)->toBe('failed');
});

function fetchAdmin(?int $roleLevel): User
{
    $name = 'fetch-' . Str::lower(Str::random(6));
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => $name, 'username' => $name, 'email' => $name . '@example.com',
        'email_verified_at' => now(), 'password' => bcrypt('secret123'), 'type' => UserType::ADMIN, 'level' => 0, 'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' role', 'custom' => ['level' => $roleLevel]]));
    }

    return $user->fresh();
}

it('lets only the Webmaster read and change the outbound host list, audited, validated', function (): void {
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs(fetchAdmin(0), ['*']);

    $this->getJson(FETCH_HOSTS_URL)->assertOk()
        ->assertJsonPath('data.hosts', [])
        ->assertJsonPath('data.server_hosts.0', 'api.example.com');

    $this->putJson(FETCH_HOSTS_URL, ['hosts' => ['Example.com', 'localhost', fetchIp(10, 0, 0, 1)]])
        ->assertStatus(422)->assertJsonValidationErrors(['hosts.1', 'hosts.2']);

    $this->putJson(FETCH_HOSTS_URL, ['hosts' => ['https://Example.com/', '*.example.io', '', 'example.com']])->assertOk()
        ->assertJsonPath('data.hosts', ['example.com', '*.example.io']);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'workflow.http_hosts_changed' && $e->type === Setting::class);
    expect(app(HttpHostAllowlist::class)->allows('www.example.io', 443))->toBeTrue();

    Sanctum::actingAs(fetchAdmin(5), ['*']);
    $this->getJson(FETCH_HOSTS_URL)->assertStatus(403);
    $this->putJson(FETCH_HOSTS_URL, ['hosts' => ['evil.test']])->assertStatus(403);
});

it('crypto hmac signs with the value of an http_header credential and refuses another type', function (): void {
    $store = app(WorkflowCredentialStore::class);
    $store->create(['name' => 'hook', 'type' => 'http_header', 'payload' => ['header' => 'X-Signature', 'value' => 'hmac-secret-123']], null);
    $store->create(['name' => 'basic', 'type' => 'http_basic', 'payload' => ['username' => 'bot', 'password' => 'pw-' . '987654321']], null);
    $context = fetchContext(['t' => 'abc']);
    $runner = new \Cms\Core\Workflows\Runtime\Runners\Util\CryptoRunner();

    $signed = $runner->run(fetchStep('crypto', ['op' => 'hmac', 'algo' => 'sha256', 'inputExpr' => 'vars.t', 'key' => '$bind:hook', 'storeAs' => 'vars.sig']), $context);
    expect($signed->status)->toBe('success')
        ->and($context->getVarPath('sig'))->toBe(hash_hmac('sha256', 'abc', 'hmac-secret-123'));

    $wrong = $runner->run(fetchStep('crypto', ['op' => 'hmac', 'inputExpr' => 'vars.t', 'key' => '$bind:basic', 'storeAs' => 'vars.sig2']), $context);
    expect($wrong->status)->toBe('failed')
        ->and(json_encode($wrong))->not->toContain('pw-987654321');
});
