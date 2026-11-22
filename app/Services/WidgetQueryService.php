<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use Closure;
use Throwable;
use JsonException;
use RuntimeException;
use App\Models\Banner;
use Illuminate\Support\Arr;
use App\Enums\StatusEnum;
use App\Support\Widget\WidgetEditMarkers;
use App\Support\Widget\WidgetRepeater;
use App\Support\Modules\ModuleStatus;
use Cms\Content\Models\Post;
use Cms\Core\Facades\CMSLogger;
use Cms\Product\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;

/**
 * Class WidgetQueryService
 * - user will select query by name
 * - based on name, we will get the query and execute it, then return the result to the widget by template
 * - group by post, product, category, tag, user, comment, etc
 *
 * @package App\Services
 * @author malayvuong
 * @since 7.160.1175 - 2025-03-30, 22:02 ICT
 */
class WidgetQueryService
{
    /**
     * Every `{query:type.name}` this service resolves in {@see self::getQueryData()}, with its label. The widget hub
     * resolver inventory reads this list, so a new query type is added here and in getQueryData() together.
     */
    public const QUERY_TYPES = [
        'product.latest' => 'Latest Products',
        'product.featured' => 'Featured Products',
        'product.hot' => 'Hot Products',
        'post.latest' => 'Latest Posts',
        'banner.main_slide' => 'Main Slide Banners',
        'banner.popup_ads' => 'Popup Ads Banners',
    ];

    /** @var bool */
    public bool $isDiscovery = false;

    /**
     * Hub preview captures only ({@see \Cms\Core\Services\Widget\Hub\WidgetPreviewCapture}): sample rows for a
     * `{query:…}` that has no rows on the authoring copy, given the query name and limit. Null on every site render.
     *
     * @var (Closure(string, int): iterable<int, mixed>)|null
     */
    public static ?Closure $sampleQueryData = null;

    public static function sanitizeInputValues(array $values, iterable $definitions): array
    {
        foreach ($definitions as $definition) {
            $name = is_array($definition) ? ($definition['name'] ?? '') : ($definition->name ?? '');
            $type = is_array($definition) ? ($definition['type'] ?? 'text') : ($definition->type ?? 'text');

            if (!$name || !array_key_exists($name, $values)) {
                continue;
            }

            if (in_array($type, ['image', 'upload'], true)) {
                $values[$name] = self::sanitizeMediaValue($values[$name]);
            } elseif ($type === 'textarea') {
                $values[$name] = self::sanitizeRichText((string) $values[$name]);
            } elseif ($type === 'list' && is_array($values[$name])) {
                $values[$name] = array_map(
                    static fn ($value) => is_scalar($value) ? e((string) $value) : $value,
                    $values[$name]
                );
            } elseif (is_scalar($values[$name])) {
                $values[$name] = e((string) $values[$name]);
            }
        }

        return $values;
    }

    private static function sanitizeRichText(string $value): string
    {
        $value = strip_tags($value, '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote><h2><h3><h4><a>');
        $value = (string) preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value);

        return (string) preg_replace('/(?:javascript|vbscript|data)\s*:/i', '', $value);
    }

    private static function sanitizeMediaValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if ($key === 'url' && is_scalar($item)) {
                $url = trim((string) $item);

                $sanitized[$key] = preg_match('/^(?:javascript|vbscript|data):/i', $url) === 1
                    ? ''
                    : e($url);
                continue;
            }

            $sanitized[$key] = is_array($item) ? self::sanitizeMediaValue($item) : $item;
        }

        return $sanitized;
    }

    /**
     * Pre-render data by widget_id and theme_id
     *
     * @param int $widgetId
     * @param int $themeId
     * @param array $inputs
     * @param bool $useCss
     *
     * @return string
     * @since 7.160.1175 - 2025-04-22, 19:29 ICT
     * @author malayvuong
     */
    public static function preRender(int $widgetId = 0, int $themeId = 0, array $inputs = [], bool $useCss = false, bool $editMarkers = false, array $inputTypes = [], array $definitions = []): string
    {
        try {
            $filePath = (new WidgetTemplatePathResolver())->resolve($widgetId, $themeId);

            if (!$filePath) {
                throw new RuntimeException("Widget file not found for widget {$widgetId}, theme {$themeId}");
            }

            //  Get content from file
            $content = file_get_contents($filePath);

            //  Render content
            return self::render($content, $inputs, $useCss, '', $editMarkers, $inputTypes, $definitions);
        } catch (Throwable $e) {
            CMSLogger::error([
                'message' => $e->getMessage(),
                'exception' => $e,
                'isAttach' => true,
            ]);

            return Blade::render('{{-- widget not found --}}');
        }
    }

    /**
     * Render widget content
     *
     * @param string $content
     * @param array $inputs
     * @param bool $useCss
     * @param string|null $additionCss
     * @param bool $editMarkers admin preview only: mark field values for in-place editing
     * @param array<string, string> $inputTypes input name => type, used by the edit markers
     * @param array<string, array<string, mixed>> $definitions canonical schema; repeater entries expand `{repeat:…}`
     *
     * @return string
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2025-03-31, 20:33 ICT
     */
    public static function render(string $content, array $inputs = [], bool $useCss = false, ?string $additionCss = '', bool $editMarkers = false, array $inputTypes = [], array $definitions = []): string
    {
        try {
            $repeaters = [];
            //  Repeaters expand first into flat `{NAME.ID.FIELD}` placeholders whose values are bound like any other field
            if ($definitions !== [] && str_contains($content, '{repeat:')) {
                $expanded = WidgetRepeater::expand($content, $inputs, $definitions, $editMarkers);
                $content = $expanded['content'];
                $inputs = $expanded['values'];
                $repeaters = $expanded['repeaters'];
                foreach ($expanded['types'] as $key => $type) {
                    //  The item position is display-only, never an editable field
                    if (!str_ends_with($key, '.#')) {
                        $inputTypes[$key] = $type;
                    }
                }
            }

            $inputCollection = collect($inputs);
            $renderData = [
            ];

            //  Values reach Blade as render data, never as template source: see bindPlaceholders()
            $values = [];
            $bind = static function (mixed $value) use (&$values): string {
                $values[] = $value;

                return '$__widgetValues[' . array_key_last($values) . ']';
            };

            $regexQuery = '/\{query:(\w+)\.(\w+)\s+variable="(\$\w+)"\s+limit="(\d+)"\s+template=`([\s\S]*?)`}/';

            $content = (string)preg_replace_callback($regexQuery, static function ($matches) use (&$renderData, $inputCollection) {
                [$fullMatch, $type, $name, $variable, $limit, $innerTemplate] = $matches;

                $dataVariable = $type . '_' . $name; // no '$'

                //  replace the limit with the actual limit in value
                $limitValue = $inputCollection->firstWhere('query:' . $type . '.' . $name);

                $rows = self::getQueryData("$type.$name", (int)($limitValue ?? $limit));
                if (self::$sampleQueryData !== null && $rows->isEmpty()) {
                    $rows = collect((self::$sampleQueryData)("$type.$name", (int)($limitValue ?? $limit)));
                }
                $renderData[$dataVariable] = $rows;

                return "\n@foreach(\${$dataVariable} as {$variable})\n{$innerTemplate}\n@endforeach";
            }, $content);

            $regexList = '/\{list:(\w+)\s+variable="(\$\w+)"\s+template=`([\s\S]*?)`}/';

            $content = (string)preg_replace_callback($regexList, static function ($matches) use (&$renderData, $inputCollection) {
                [$fullMatch, $listName, $variable, $innerTemplate] = $matches;

                $dataVariable = 'list_' . $listName; // tạo biến chứa danh sách

                // Lấy dữ liệu list từ $inputCollection (nếu có)
                $listInput = $inputCollection->get('list_' . $listName);
//                $listInput = $inputs['list_' . $listName] ?? [];
                $renderData[$dataVariable] = is_array($listInput) ? $listInput : [];

                return "@if (is_array(\${$dataVariable}))\n@foreach(\${$dataVariable} as {$variable})\n{$innerTemplate}\n@endforeach @endif";
            }, $content);


            $regexImage = '/\{image(?:\s+name="([^"]*)")?(?:\s+source="([^"]*)")?(?:\s+class="([^"]*)")?(?:\s+alt="([^"]*)")?}/';

            $content = preg_replace_callback($regexImage, static function ($match) use ($inputCollection, $editMarkers, $bind) {
                $name = $match[1] ?? '';
                $source = $match[2] ?? '';
                $class = $match[3] ?? '';
                $alt = $match[4] ?? 'Image';

                $key = $name ?: $source;

                if (!$key || !$inputCollection->has($key)) {
                    return '';
                }

                $image = $inputCollection->firstWhere(function ($value, $key) use ($name) {
                    return $key === $name;
                });

                $url = $image[0]['url'] ?? $image['url'] ?? '';
                $altText = $image[0]['name'] ?? $image['name'] ?? $alt;

                //  add anonymous attribute to bypass CSP
                if ($editMarkers) {
                    $html = $url
                        ? WidgetEditMarkers::markImage('<img src="' . e($url) . '" class="' . e($class) . '" alt="' . e($altText) . '" anonymous>', $key)
                        : WidgetEditMarkers::emptyImage($key, $class);
                } else {
                    $html = $url ? '<img src="' . e($url) . '" class="' . e($class) . '" alt="' . e($altText) . '" anonymous>' : '';
                }

                //  The URL and alt text are field values: the tag goes to Blade as data, not as source
                return $html === '' ? '' : '{!! ' . $bind($html) . ' !!}';
            }, $content);

            $regexUpload = '/\{upload(?:\s+name="([^"]*)")?}/';

            $content = (string)preg_replace_callback($regexUpload, static function ($match) use ($inputCollection, $bind) {
                $name = $match[1] ?? '';

                if (!$name || !$inputCollection->has($name)) {
                    return '';
                }

                $file = $inputCollection->get($name);

                return '{!! ' . $bind(e($file['url'] ?? '')) . ' !!}';
            }, $content);

            //  Regex iSeo:key_name. Site settings are bound before field values, so an iSeo placeholder keeps winning
            $placeholders = [];
            if (preg_match_all('/\{iSeo:([\w.]+)}/', $content, $seoMatches)) {
                foreach (array_unique($seoMatches[1]) as $key) {
                    $placeholders['{iSeo:' . $key . '}'] = iSeo($key);
                }
            }

            if ($editMarkers) {
                $inputs = WidgetEditMarkers::wrapValues($inputs, $inputTypes);
            }

            foreach ($inputs as $key => $value) {
                if (is_array($value)) {
                    $replacement = $value['value'] ?? $value['default_value'] ?? '-';
                } else {
                    $replacement = (string)$value;
                }

                $placeholders += ['{' . $key . '}' => $replacement];
            }

            $content = self::bindPlaceholders($content, $placeholders, $bind);
            $renderData['__widgetValues'] = $values;

            if ($useCss) {
                //  Add css file into content
                $content = '<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>'
                    . ("@vite([
                    'resources/css/app.css',
                    config('ispa-cms.template_tailwind_css'),
                ])")
                    . $content;
            }
            if ($additionCss) {
                $content = '<style>' . $additionCss . '</style>' . $content;
            }

            $rendered = Blade::render($content, $renderData);

            return $editMarkers ? WidgetEditMarkers::finalize($rendered, $inputTypes, $repeaters) : $rendered;
        } catch (Throwable $e) {
            CMSLogger::error([
                'message' => $e->getMessage(),
                'exception' => $e,
                'isAttach' => true,
            ]);

            return '-n-';
        }
    }

    /**
     * Binds the values a template names (`{title}`, `{iSeo:address}`) as Blade render data and leaves only a variable
     * in the template source, so a value is never compiled: a saved `@php … @endphp` or `{{ … }}` shows as text
     * instead of running on the server. A placeholder written as a whole quoted string inside a Blade expression
     * (`@if("{title}" !== "")`) becomes the variable itself, so the condition still sees the value; every other
     * placeholder becomes a raw echo, which prints exactly what pasting the value used to print.
     *
     * @param array<string, mixed> $placeholders placeholder text => value
     * @param Closure(mixed): string $bind stores a value and returns the PHP variable that holds it
     */
    private static function bindPlaceholders(string $content, array $placeholders, Closure $bind): string
    {
        $variables = [];
        foreach ($placeholders as $placeholder => $value) {
            if (str_contains($content, (string)$placeholder)) {
                $variables[(string)$placeholder] = $bind($value);
            }
        }

        if ($variables === []) {
            return $content;
        }

        $quoted = [];
        foreach ($variables as $placeholder => $variable) {
            $quoted['"' . $placeholder . '"'] = $variable;
            $quoted["'" . $placeholder . "'"] = $variable;
        }

        //  Blade expressions: directive arguments (balanced parentheses, quotes respected), echoes and @php blocks.
        //  On a regex failure the quoted placeholders fall through to the raw echo below, which is still inert.
        $inExpressions = preg_replace_callback(
            '/@\w+\s*(\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?1))*+\))|\{\{.*?}}|\{!!.*?!!}|@php\b.*?@endphp/s',
            static fn (array $match): string => strtr($match[0], $quoted),
            $content
        );

        $echoes = [];
        foreach ($variables as $placeholder => $variable) {
            $echoes[$placeholder] = '{!! ' . $variable . ' !!}';
            //  `@{!! … !!}` is Blade's escaped echo and would print the tag itself (templates write `@{brand}`)
            $echoes['@' . $placeholder] = '@<?php echo ' . $variable . '; ?>';
        }

        return strtr($inExpressions ?? $content, $echoes);
    }

    /**
     * @throws Throwable
     */
    public static function getQueryData(string $queryName, int $limit): Collection
    {
        //  A site without the product catalog (never installed, or uninstalled while a widget still names a
        //  product query) renders an empty list instead of a fatal class-not-found.
        if (str_starts_with($queryName, 'product.') && ! class_exists(Product::class)) {
            return collect();
        }

        //  Content can be disabled; a site created without it has no posts table, so post queries answer empty.
        if (str_starts_with($queryName, 'post.') && ! ModuleStatus::isEnabled('Content')) {
            return collect();
        }

        return match ($queryName) {
            //  Latest products
            //  Cache key includes $limit (disclosed narrow fix): the key
            //  was previously limit-invariant ('widget.product.latest'),
            //  so a second call with a different $limit inside the 3600s
            //  TTL silently replayed the FIRST call's differently-sized
            //  cached Collection instead of querying again. Same query,
            //  same TTL, same eager loads -- only the cache dimension
            //  changes, so every caller of this legacy query name now gets
            //  a correctly limit-scoped cache. (This comment deliberately
            //  omits the namespace of the module whose task introduced the
            //  fix: the module-boundary regression suite scans root/app
            //  files for that namespace token to keep the dependency
            //  direction one-way.)
            'product.latest' => cache()->remember('widget.product.latest.' . $limit, 3600, function () use ($limit) {
                return collect(Product::query()->where([
                    'status' => StatusEnum::PUBLISHED->value,
                ])
                    ->where(function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    })
                    ->with([
                        'language:id,name,path,flag',
                        'images:id,target_id,name,path,mime_type',
                        'categories:id,name',
                        'slugs:target_id,slug',
                        'variants',
                        'first_option',
                        'second_option',
                    ])
                    ->whereHas('slugs')
                    ->latest()->limit($limit)->get());
            }),
            //  Featured products
            'product.featured' => collect(Product::query()->where([
                'status' => StatusEnum::PUBLISHED->value,
            ])
                ->where('custom->featured', true)
                ->where(function ($query) {
                    return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->with([
                    'language:id,name,path,flag',
                    'images:id,target_id,name,path,mime_type',
                    'categories:id,name',
                    'slugs:target_id,slug',
                    'variants',
                    'first_option',
                    'second_option',
                ])
                ->whereHas('slugs')
                ->latest()->limit($limit)->get()),
            'product.hot' => collect(Product::query()->where('is_hot', true)->latest()->limit($limit)->get()),
            'post.latest' => collect(Post::query()->select('id', 'name', 'description', 'category_id')
                ->whereHas('slugs', fn($q) => $q->select('target_id', 'slug'))
                ->whereHas('category')
                ->with('slugs')
                ->with('category', fn($q) => $q->select('id', 'name')
                    ->with('slugs')
                )
                ->where([
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                    'type' => 'post',
                ])
                ->where(function ($q) {
                    $q->where('published_at', '<=', now())
                        ->orWhereNull('published_at');
                })
                ->with([
                    'slugs:target_id,slug',
                    'images:id,target_id,path,name,mime_type,type,config',
                ])
                ->orderBy('published_at', 'desc')
                ->orderBy('id', 'desc')
                ->take($limit)->get()),
            'banner.main_slide' => collect(Banner::query()->where('type', 'main_slide')->limit($limit)->get()),
            'banner.popup_ads' => collect(Banner::query()->where('type', 'popup_ads')->limit($limit)->get()),
            default => collect(),
        };
    }

    public function availableQueries(): array
    {
        return self::QUERY_TYPES;
    }
}
