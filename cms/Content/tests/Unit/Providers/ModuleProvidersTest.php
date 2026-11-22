<?php

uses(\Tests\TestCase::class);

use App\Events\TextToSpeechContentRequest;
use App\Events\TranslateContentRequest;
use Cms\Content\Listeners\TextToSpeechContentRequestHandler;
use Cms\Content\Listeners\TranslateContentRequestHandler;
use Cms\Content\Models\Content;
use Cms\Content\Models\Post;
use Cms\Content\Policies\ContentPolicy;
use Cms\Content\Policies\PostPolicy;
use Cms\Content\Providers\AuthServiceProvider;
use Cms\Content\Providers\EventServiceProvider;
use Cms\Content\Providers\RouteServiceProvider;

test('auth service provider registers content policies', function () {
    $provider = new class(app()) extends AuthServiceProvider {
        public function exposePolicies(): array
        {
            return $this->policies;
        }
    };

    expect($provider->exposePolicies())->toBe([
        Content::class => ContentPolicy::class,
        Post::class => PostPolicy::class,
    ]);
});

test('event service provider enables discovery', function () {
    $provider = new class(app()) extends EventServiceProvider {
        public function exposeListen(): array
        {
            return $this->listen;
        }

        public function exposeDiscover(): bool
        {
            return self::$shouldDiscoverEvents;
        }
    };

    expect($provider->exposeListen())->toBe([
        TranslateContentRequest::class => [TranslateContentRequestHandler::class],
        TextToSpeechContentRequest::class => [TextToSpeechContentRequestHandler::class],
    ]);
    expect($provider->exposeDiscover())->toBeTrue();
});

test('route service provider maps web and api routes', function () {
    $provider = new class(app()) extends RouteServiceProvider {
        public bool $webMapped = false;
        public bool $apiMapped = false;

        public function exposeName(): string
        {
            return $this->name;
        }

        protected function mapWebRoutes(): void
        {
            $this->webMapped = true;
        }

        protected function mapApiRoutes(): void
        {
            $this->apiMapped = true;
        }
    };

    $provider->map();

    expect($provider->webMapped)->toBeTrue();
    expect($provider->apiMapped)->toBeTrue();
    expect($provider->exposeName())->toBe('Content');
});
