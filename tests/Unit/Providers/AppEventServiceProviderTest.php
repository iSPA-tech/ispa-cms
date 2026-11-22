<?php

uses(\Tests\TestCase::class);

use App\Providers\EventServiceProvider;

test('app event service provider no longer keeps a dead order paid mapping', function () {
    $provider = new class(app()) extends EventServiceProvider {
        public function exposeListen(): array
        {
            return $this->listen;
        }
    };

    expect($provider->exposeListen())->not->toHaveKey(\App\Events\OrderPaidEvent::class);
});
