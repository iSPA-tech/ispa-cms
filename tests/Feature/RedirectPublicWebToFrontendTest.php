<?php

namespace Tests\Feature;

use Tests\TestCase;

class RedirectPublicWebToFrontendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['api-host.redirect_page_views_to' => 'https://acme.test']);
    }

    public function test_direct_page_views_redirect_to_the_frontend_domain(): void
    {
        $this->get('https://localhost/bai-viet/mau-xe-moi')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://acme.test/bai-viet/mau-xe-moi');

        $this->get('https://localhost/')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://acme.test/');

        $this->get('https://localhost/xe/toyota?page=2')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://acme.test/xe/toyota?page=2');
    }

    public function test_admin_api_and_infrastructure_paths_are_exempt(): void
    {
        foreach ([
            'https://localhost/iPanel',
            'https://localhost/iPanel/dashboard',
            'https://localhost/api/v2/public/vehicles',
            'https://localhost/health',
            'https://localhost/storage/image/x.webp',
        ] as $path) {
            $location = (string) $this->get($path)->headers->get('Location');
            $this->assertStringNotContainsString(
                'https://acme.test',
                $location,
                "Path {$path} must not redirect to the frontend"
            );
        }
    }

    public function test_middleware_is_inert_without_a_target(): void
    {
        config(['api-host.redirect_page_views_to' => '']);

        $location = (string) $this->get('https://localhost/bai-viet/mau-xe-moi')->headers->get('Location');
        $this->assertStringNotContainsString('https://acme.test', $location);
    }
}
