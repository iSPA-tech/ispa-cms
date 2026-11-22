<?php

declare(strict_types=1);

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Contracts\Widget\WidgetDataProviderRegistrar;
use App\Support\Widget\NullWidgetExtensionRegistrar;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetResolverInventory;
use Cms\Core\Services\Widget\Hub\WidgetResolverRequirements;

it('requirements read query placeholders and query column', function () {
    $designs = [
        ['content' => '<div>{query:product.latest variable="$p" limit="4" template=`{{ $p->name }}`}</div>{query:post.latest variable="$x" limit="1" template=``}', 'query' => null],
        ['content' => '{query:product.latest variable="$q" limit="2" template=`a`}', 'query' => ['banner.popup_ads']],
        ['content' => '<p>{title}</p>', 'query' => '["banner.main_slide"]'],
        ['content' => '<p>plain</p>', 'query' => []],
    ];

    expect(WidgetResolverRequirements::forDesigns($designs))->toBe([
        'query:banner.main_slide' => 1,
        'query:banner.popup_ads' => 1,
        'query:post.latest' => 1,
        'query:product.latest' => 1,
    ]);
    expect(WidgetResolverRequirements::forDesigns([['content' => '<p>x</p>', 'query' => null]]))->toBe([]);
});

it('inventory lists core query types with the widget module off', function () {
    app()->instance(WidgetDataProviderRegistrar::class, new NullWidgetExtensionRegistrar());

    $inventory = new WidgetResolverInventory();

    expect($inventory->available())->toBe([
        'query:banner.main_slide' => 1,
        'query:banner.popup_ads' => 1,
        'query:post.latest' => 1,
        'query:product.featured' => 1,
        'query:product.hot' => 1,
        'query:product.latest' => 1,
    ]);
    expect($inventory->missing(['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION, 'resolvers' => ['query:post.latest' => 1]]))->toBe([])
        ->and($inventory->missing(['renderer' => 1, 'resolvers' => ['query:post.latest' => 2, 'provider:menu.by_group' => 1]]))
        ->toBe(['query:post.latest' => 2, 'provider:menu.by_group' => 1])
        ->and($inventory->missing(['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION + 1, 'resolvers' => []]))
        ->toBe(['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION + 1]);
});

it('inventory adds registered providers at their highest version', function () {
    $provider = static fn (string $key, int $version): WidgetDataProvider => new class ($key, $version) implements WidgetDataProvider {
        public function __construct(private string $k, private int $v)
        {
        }

        public function key(): string
        {
            return $this->k;
        }

        public function version(): int
        {
            return $this->v;
        }

        public function fetch(array $arguments, WidgetRenderContext $context): WidgetProviderResult
        {
            return new WidgetProviderResult(items: []);
        }
    };

    $registrar = new class ([$provider('menu.by_group', 1), $provider('menu.by_group', 2)]) implements WidgetDataProviderRegistrar {
        public function __construct(private array $list)
        {
        }

        public function registerProvider(WidgetDataProvider $provider): void
        {
        }

        public function providers(): array
        {
            return $this->list;
        }
    };
    app()->instance(WidgetDataProviderRegistrar::class, $registrar);

    $available = (new WidgetResolverInventory())->available();

    expect($available['provider:menu.by_group'])->toBe(2)
        ->and($available['query:product.latest'])->toBe(1)
        ->and((new WidgetResolverInventory())->missing(['renderer' => 1, 'resolvers' => ['provider:menu.by_group' => 2]]))->toBe([]);
});
