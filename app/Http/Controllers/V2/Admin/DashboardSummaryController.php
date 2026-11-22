<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin;

use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Services\Platform\HubCredentials;
use App\Services\Dashboard\DashboardSummaryService;
use Cms\Core\Http\Controllers\AdminController;

/**
 * Serves the aggregate-only summary used by the main admin dashboard.
 */
class DashboardSummaryController extends AdminController
{
    /**
     * @param DashboardSummaryService $service Aggregation service.
     * @param AttentionRegistry $registry Per-user attention providers.
     */
    public function __construct(protected DashboardSummaryService $service, protected AttentionRegistry $registry)
    {
    }

    /**
     * Return the dashboard summary for the requested range.
     *
     * @return mixed Admin API response.
     */
    public function summary(): mixed
    {
        $range = (string) request()->query('range', '7d');
        $from = request()->query('from');
        $to = request()->query('to');
        $reload = filter_var(request()->query('reload'), FILTER_VALIDATE_BOOLEAN);

        $result = $this->service->summary(
            $range,
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            $reload
        );

        // Read on every request, never cached: a site connected a minute ago must not show "not connected".
        $result['hub'] = $this->hub();

        // Per-user entries are appended after the cached, user-independent result.
        $user = request()->user();
        if ($user) {
            $extra = $this->registry->forUser($user);
            if ($extra !== []) {
                $result['attention'] = array_merge($result['attention'] ?? [], $extra);
            }
        }

        return ires()->data($result);
    }

    /**
     * iSPA Hub state for the dashboard band: connected (a complete site id + secret pair), development (the site's own
     * domain is one the hub never calls back, so only free modules install) and the customer portal address.
     *
     * @return array{connected: bool, development: bool, portal_url: string}
     */
    private function hub(): array
    {
        $host = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));

        return [
            'connected' => app(HubCredentials::class)->isConfigured(),
            'development' => self::isDevelopmentHost($host),
            'portal_url' => rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/') . '/portal',
        ];
    }

    private static function isDevelopmentHost(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '') {
            // Same rule as the hub (CmsSiteService::domainType): no readable host is not a development domain.
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
