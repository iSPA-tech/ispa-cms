<?php

namespace Tests\Unit;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class Laravel13RequestForgeryTest extends TestCase
{
    public function test_origin_and_token_verification_on_the_existing_application_middleware(): void
    {
        $originalContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', 'production');
        $middleware = new class($app, new Encrypter(str_repeat('a', 32), 'AES-256-CBC')) extends VerifyCsrfToken {
            protected $addHttpCookie = false;

            protected function runningUnitTests()
            {
                return false;
            }
        };

        try {
            foreach ([['same-origin', null, true], ['cross-site', null, false], [null, null, false], [null, 'test-token', true]] as [$origin, $token, $accepted]) {
                $request = Request::create('https://example.test/account', 'POST');
                $session = new Store('test', new ArraySessionHandler(120));
                $session->put('_token', 'test-token');
                $request->setLaravelSession($session);
                if ($origin !== null) {
                    $request->headers->set('Sec-Fetch-Site', $origin);
                }
                if ($token !== null) {
                    $request->headers->set('X-CSRF-TOKEN', $token);
                }

                try {
                    $response = $middleware->handle($request, static fn () => new Response('ok'));
                    $this->assertTrue($accepted, 'Request without a valid origin or token must be rejected.');
                    $this->assertSame(200, $response->getStatusCode());
                } catch (TokenMismatchException) {
                    $this->assertFalse($accepted);
                }
            }
        } finally {
            Container::setInstance($originalContainer);
        }
    }
}
