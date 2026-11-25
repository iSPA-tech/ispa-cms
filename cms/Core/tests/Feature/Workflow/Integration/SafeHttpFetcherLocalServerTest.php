<?php

declare(strict_types=1);

/**
 * SafeHttpFetcher against a real HTTP listener on this machine (review findings 10 and 22): the real Guzzle handler
 * runs, nothing is faked and nothing leaves the machine.
 *
 * The fetcher used here only differs from production in two seams: the allowlist allows the test host, and the address
 * guard accepts the loopback listener (production refuses loopback). Every other step (DNS answer, pinning, timeouts,
 * size limit) is the production code path.
 */

use Cms\Core\Workflows\Runtime\Runners\Integration\Http\DnsResolver;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\FetchRefused;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;

uses(Cms\Core\Tests\TestCase::class);

/**
 * A name that never resolves (RFC 6761 `.invalid`). If the handler resolved the name itself at connect time instead
 * of using the address that was checked, the call could not connect at all.
 */
const LOCAL_FETCH_REBIND_HOST = 'rebind.invalid';

const LOCAL_FETCH_ROUTER = <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
while (ob_get_level() > 0) {
    ob_end_flush();
}
if ($path === '/drip') {
    header('Content-Type: text/plain');
    for ($i = 0; $i < 40; $i++) {
        echo 'x';
        flush();
        usleep(250000);
    }
    exit;
}
if ($path === '/flood') {
    header('Content-Type: text/plain');
    $chunk = str_repeat('a', 65536);
    for ($i = 0; $i < 300; $i++) {
        echo $chunk;
        flush();
        usleep(20000);
    }
    exit;
}
header('Content-Type: text/plain');
echo 'pinned host=' . ($_SERVER['HTTP_HOST'] ?? '');
PHP;

/** @return array{process: resource, dir: string, ip: string, port: int} */
function localFetchServerStart(): array
{
    $ip = gethostbyname('localhost');
    $probe = stream_socket_server('tcp://' . $ip . ':0', $errno, $errstr);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $dir = sys_get_temp_dir() . '/wf-fetch-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/router.php', LOCAL_FETCH_ROUTER);
    $process = proc_open([PHP_BINARY, '-S', $ip . ':' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen($ip, $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);

            return ['process' => $process, 'dir' => $dir, 'ip' => $ip, 'port' => $port];
        }
        usleep(50000);
    }

    throw new RuntimeException('the local test server did not start.');
}

/** @param array{process: resource, dir: string} $server */
function localFetchServerStop(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
    @unlink($server['dir'] . '/router.php');
    @rmdir($server['dir']);
}

/** The fetcher with only the allowlist and the address guard opened for the local listener. */
function localFetcher(string $ip): SafeHttpFetcher
{
    $allowlist = new class () extends HttpHostAllowlist {
        public function allows(string $host, int $port): bool
        {
            return true;
        }
    };
    // Check-time DNS answer: the listener's address. The connect-time answer for the name is "does not exist".
    $dns = new class ($ip) extends DnsResolver {
        public function __construct(private readonly string $ip)
        {
        }

        public function resolve(string $host): array
        {
            return [$this->ip];
        }
    };

    return new class ($allowlist, $dns, $ip) extends SafeHttpFetcher {
        public function __construct(HttpHostAllowlist $allowlist, DnsResolver $dns, private readonly string $listener)
        {
            parent::__construct($allowlist, $dns);
        }

        protected function addressAllowed(string $ip): bool
        {
            return $ip === $this->listener;
        }
    };
}

function localFetchRefusal(callable $call): ?string
{
    try {
        $call();
    } catch (FetchRefused $e) {
        return $e->reason;
    }

    return null;
}

beforeEach(function (): void {
    $this->server = localFetchServerStart();
});

afterEach(function (): void {
    localFetchServerStop($this->server);
});

it('connects to the checked address, not to whatever the name resolves to at connect time (finding 10)', function (): void {
    $port = $this->server['port'];

    $response = localFetcher($this->server['ip'])->fetch('http://' . LOCAL_FETCH_REBIND_HOST . ':' . $port . '/hello');

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('pinned host=' . LOCAL_FETCH_REBIND_HOST . ':' . $port);
});

it('stops a response that drips slowly at the timeout, not when the body ends (finding 22)', function (): void {
    $started = microtime(true);

    $reason = localFetchRefusal(fn () => localFetcher($this->server['ip'])
        ->fetch('http://' . $this->server['ip'] . ':' . $this->server['port'] . '/drip', ['timeout' => 2]));

    expect($reason)->toBe('connection')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('aborts a body without Content-Length once it passes max_bytes', function (): void {
    $started = microtime(true);

    $reason = localFetchRefusal(fn () => localFetcher($this->server['ip'])
        ->fetch('http://' . $this->server['ip'] . ':' . $this->server['port'] . '/flood', ['max_bytes' => 100000, 'timeout' => 20]));

    expect($reason)->toBe('too_large')
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});
