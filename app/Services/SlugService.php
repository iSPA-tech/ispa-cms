<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use Cms\Core\Repositories\Interfaces\SlugInterface;
use Illuminate\Support\Str;

class SlugService
{
    public bool $isDiscovery = false;
    protected SlugInterface $slug;

    /**
     * Cache resolved model namespaces per source string.
     */
    protected static array $targetTypeCache = [];

    /**
     * Slug service constructor
     *
     * @author malayvuong
     * @since 6.19.6 - 2022-06-19, 02:50 ICT
     */
    public function __construct()
    {
        $this->slug = app(SlugInterface::class);
    }

    /**
     * Create a new slug => return slug string
     *
     * @param array $array
     * [slug]: string to check slug
     * [id]: id of slug object
     * [table]: model class name
     * [target_id]: id relationship
     * [language_id]: language of slug
     *
     * @return string
     * @author malayvuong
     * @since 6.19.6 - 2022-06-19, 03:04 ICT
     */
    public function create(array $array): string
    {
        $data = collect($array);
        $slug = str($data->get('slug'))->slug();

        $targetTypeSource = $data->get('target_type', $data->get('table'));
        $target_type = $this->resolveTargetType($targetTypeSource);

        $index = 1;
        $baseSlug = $slug;

        $category = null;
        if (!empty($target_type)) {
            $category = config('setting.prefix.' . str($target_type)->replace('\\', '_'), '');
        }

        //  Check if slug not change when slugId > 0
        $check = $this->slug->findBy(['id' => $data->get('id', 0)], ['id', 'slug']);

        if ($check && $check->slug === $slug) {
            return $slug;
        }

        while ($this->checkIfExistedSlug($slug, $target_type, $category, $data->toArray())) {
            $slug = $baseSlug . '-' . $index++;
        }

        if (empty($slug)) {
            $slug = time();
        }

        return (string)$slug;
    }

    /**
     * Check if slug is exists
     *
     * @param string $slug
     * @param string|null $target_type
     * @param string|null $category
     * @param array|null $data
     *
     * @return bool
     *
     * @author malayvuong
     * @since 6.19.6 - 2022-06-19, 03:07 ICT
     */
    protected function checkIfExistedSlug(string $slug, ?string $target_type = null, ?string $category = null, ?array $data = []): bool
    {
        return $this->slug->model()
                ->select('id')
                ->when(
                    !empty($data['language_id']) && (int)$data['language_id'] > 0,
                    fn($query) => $query->where('language_id', $data['language_id'])
                )
                ->when(
                    !empty($data['target_id']) && (int)$data['target_id'] > 0,
                    fn($query) => $query->where('target_id', '!=', $data['target_id'])
                )
                ->when(
                    !empty($category),
                    fn($query) => $query->where('category', $category)
                )
                ->when(
                    empty($data['target_id']) && !empty($data['id']) && (int)$data['id'] > 0,
                    fn($query) => $query->where('id', '!=', $data['id'])
                )
                ->where('slug', $slug)
                ->when(
                    !empty($target_type),
                    fn($query) => $query->where('target_type', $target_type)
                )
                ->count() > 0;
    }

    /**
     * Attempt to resolve the fully-qualified target type from the provided source.
     */
    protected function resolveTargetType(?string $source): ?string
    {
        if (empty($source)) {
            return null;
        }

        $source = ltrim((string)$source, '\\');
        if (isset(self::$targetTypeCache[$source])) {
            return self::$targetTypeCache[$source];
        }

        if (class_exists($source)) {
            return self::$targetTypeCache[$source] = $source;
        }

        $normalized = Str::studly(Str::singular($source));

        $candidates = [
            'App\\Models\\' . $normalized,
            'Cms\\Core\\Models\\' . $normalized,
            'Cms\\Content\\Models\\' . $normalized,
            'Cms\\Product\\Models\\' . $normalized,
            'Cms\\RealEstate\\Models\\' . $normalized,
            'Cms\\Ecommerce\\Models\\' . $normalized,
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return self::$targetTypeCache[$source] = $candidate;
            }
        }

        foreach ($this->discoverCmsModelNamespaces() as $prefix) {
            $candidate = $prefix . $normalized;
            if (class_exists($candidate)) {
                return self::$targetTypeCache[$source] = $candidate;
            }
        }

        return self::$targetTypeCache[$source] = null;
    }

    /**
     * Discover namespaces for CMS modules that expose models.
     *
     * @return string[]
     */
    protected function discoverCmsModelNamespaces(): array
    {
        static $namespaces = null;
        if ($namespaces !== null) {
            return $namespaces;
        }

        $namespaces = [];
        $cmsPath = base_path('cms');
        if (!is_dir($cmsPath)) {
            return $namespaces;
        }

        $directories = scandir($cmsPath);
        if (!is_array($directories)) {
            return $namespaces;
        }

        foreach ($directories as $directory) {
            if ($directory === '.' || $directory === '..') {
                continue;
            }

            $modelsPath = $cmsPath . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models';
            if (is_dir($modelsPath)) {
                $namespaces[] = 'Cms\\' . trim($directory, '\\') . '\\Models\\';
            }
        }

        return $namespaces;
    }
}
