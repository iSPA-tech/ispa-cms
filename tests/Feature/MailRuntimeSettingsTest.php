<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Cms\Core\Services\CMSSettingRuntime;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobProcessing;
use ReflectionProperty;
use Tests\TestCase;

class MailRuntimeSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setRuntimeMaps(['env' => null, 'private' => null, 'public' => null]);

        parent::tearDown();
    }

    public function test_app_boot_maps_cms_email_settings_into_laravel_mail_config(): void
    {
        config([
            'ispa-cms.setting_runtime_enabled' => true,
            'mail.default' => 'log',
            'mail.mailers.smtp.host' => 'old.example.test',
            'mail.mailers.smtp.port' => 25,
            'mail.mailers.smtp.encryption' => null,
            'mail.mailers.smtp.username' => 'old@example.test',
            'mail.mailers.smtp.password' => 'old-password',
            'mail.from.address' => 'team@ispa.io',
            'mail.from.name' => 'Old sender',
            'mail.reply' => 'team@ispa.io',
        ]);

        $runtime = new CMSSettingRuntime();
        $this->setRuntimeMaps([
            'env' => [],
            'private' => [
                'email.MAIL_MAILER' => 'smtp',
                'email.MAIL_HOST' => 'smtp.acme.test',
                'email.MAIL_PORT' => '587',
                'email.MAIL_ENCRYPTION' => 'tls',
                'email.MAIL_USERNAME' => 'mailer@acme.test',
                'email.MAIL_PASSWORD' => 'new-password',
                'email.MAIL_FROM_ADDRESS' => 'journal@acme.test',
                'email.MAIL_FROM_NAME' => 'Acme',
                'email.MAIL_REPLY' => 'reply@acme.test',
            ],
            'public' => [],
        ]);
        $this->app->instance(CMSSettingRuntime::class, $runtime);

        $this->app->getProvider(AppServiceProvider::class)->boot();

        self::assertSame('smtp', config('mail.default'));
        self::assertSame('smtp.acme.test', config('mail.mailers.smtp.host'));
        self::assertSame('587', config('mail.mailers.smtp.port'));
        self::assertSame('tls', config('mail.mailers.smtp.encryption'));
        self::assertSame('mailer@acme.test', config('mail.mailers.smtp.username'));
        self::assertSame('new-password', config('mail.mailers.smtp.password'));
        self::assertSame('journal@acme.test', config('mail.from.address'));
        self::assertSame('Acme', config('mail.from.name'));
        self::assertSame('reply@acme.test', config('mail.reply'));
    }

    public function test_app_boot_keeps_environment_mail_config_when_runtime_settings_are_unavailable(): void
    {
        config([
            'ispa-cms.setting_runtime_enabled' => true,
            'mail.from.address' => 'fallback@example.test',
        ]);
        $this->app->instance(CMSSettingRuntime::class, new class extends CMSSettingRuntime {
            public function get(string $path, mixed $default = null): mixed
            {
                throw new \RuntimeException('settings unavailable');
            }
        });

        $thrown = null;
        try {
            $this->app->getProvider(AppServiceProvider::class)->boot();
        } catch (\Throwable $exception) {
            $thrown = $exception::class;
        }

        self::assertNull($thrown);
        self::assertSame('fallback@example.test', config('mail.from.address'));
    }

    public function test_only_mail_queue_jobs_refresh_runtime_settings_before_processing(): void
    {
        config([
            'ispa-cms.setting_runtime_enabled' => true,
            'mail.from.address' => 'stale@example.test',
        ]);

        $runtime = new class extends CMSSettingRuntime {
            public int $forgetCalls = 0;

            public function forget(): void
            {
                $this->forgetCalls++;
            }

            public function get(string $path, mixed $default = null): mixed
            {
                return $path === 'email.MAIL_FROM_ADDRESS'
                    ? 'current@example.test'
                    : null;
            }
        };
        $this->app->instance(CMSSettingRuntime::class, $runtime);

        $mailManager = $this->app->make('mail.manager');
        $staleMailer = $mailManager->mailer();

        event(new JobProcessing('redis', $this->queueJob(\stdClass::class, 'default')));

        self::assertSame(0, $runtime->forgetCalls);
        self::assertSame('stale@example.test', config('mail.from.address'));

        event(new JobProcessing('redis', $this->queueJob(SendQueuedNotifications::class, 'default')));

        self::assertSame(1, $runtime->forgetCalls);
        self::assertSame('current@example.test', config('mail.from.address'));
        self::assertNotSame($staleMailer, $mailManager->mailer());

        event(new JobProcessing('redis', $this->queueJob(SendQueuedMailable::class, 'default')));
        event(new JobProcessing('redis', $this->queueJob(\stdClass::class, 'emails')));

        self::assertSame(3, $runtime->forgetCalls);
    }

    /**
     * @param array{env: ?array, private: ?array, public: ?array} $maps
     */
    private function setRuntimeMaps(array $maps): void
    {
        $cache = new ReflectionProperty(CMSSettingRuntime::class, 'localCache');
        $cache->setValue(null, $maps);
    }

    private function queueJob(string $commandName, string $queue): QueueJob
    {
        $job = \Mockery::mock(QueueJob::class);
        $job->shouldReceive('payload')->andReturn([
            'data' => ['commandName' => $commandName],
        ]);
        $job->shouldReceive('getQueue')->andReturn($queue);

        return $job;
    }
}
