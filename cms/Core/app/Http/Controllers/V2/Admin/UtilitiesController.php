<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Str;
use ReflectionClass;
use ReflectionProperty;
use RecursiveIteratorIterator;
use Illuminate\View\Component;
use RecursiveDirectoryIterator;
use Cms\Core\Http\Controllers\AdminController;
use App\Repositories\Interfaces\CalendarInterface;

/**
 * Miscellaneous admin utility endpoints.
 *
 * Provides helper endpoints for the admin panel, including project listing,
 * Blade component discovery, and calendar day views with event data.
 * Extends AdminController.
 *
 * @see \App\Repositories\Interfaces\CalendarInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class UtilitiesController extends AdminController
{
    public CalendarInterface $calendar;

    /**
     * Controller constructor
     *
     * @param CalendarInterface $calendar
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(CalendarInterface $calendar)
    {
        $this->calendar = $calendar;

        parent::__construct();
    }

    /**
     * Get project list
     *
     * @param  mixed $res
     * @return JsonResponse|Response
     * @author malayvuong
     * @since 7.160.1175 - 2025-05-02, 16:32 ICT
     */
    public function projectList()
    {
        return ires()->data(get_project_list());
    }

    /**
     * Get component list
     *
     * @param $res
     * @param  mixed $res
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2025-04-03, 19:45 ICT
     */
    public function listComponents()
    {
        $componentsPath = app_path('View/Components');
        $components = [];

        // Scan directory for all PHP files
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($componentsPath));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Generate fully qualified class name
                $relativePath = str_replace($componentsPath . '/', '', $file->getPathname());
                $className = 'App\\View\\Components\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                // Check if class exists and is a valid component
                if (class_exists($className)) {
                    $reflection = new ReflectionClass($className);

                    // Only include classes that extend the Blade component base class
                    if ($reflection->isSubclassOf(Component::class)) {
                        $properties = [];

                        // Get public properties (excluding inherited ones)
                        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                            if ($property->getDeclaringClass()->getName() === $reflection->getName()) {
                                $properties[] = $property->getName();
                            }
                        }

                        // Add the component to the list
                        $components[] = [
                            'name' => Str::replaceFirst('App\\View\\Components\\', '', $className),
                            'full_name' => $className,
                            'parameters' => $properties,
                        ];
                    }
                }
            }
        }

        return ires()->data($components);
    }

    /**
     * Get list of days from now
     *
     * @param  mixed $res
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-05-06, 11:15 ICT
     */
    public function listDays()
    {
        $list = collect([]);
        $offset = (int) request('offset', 0);
        $month = now()->addMonths($offset);

        $totalDays = $month->daysInMonth;
        $firstDay = $month->firstOfMonth()->format('l');
        $lastDay = $month->lastOfMonth()->format('l');

        //  Loop current month
        for ($i = (get_days_offset($firstDay)); $i < ($totalDays + get_days_offset($lastDay, false)); $i++) {
            $date = now()->parse(now()->addMonths($offset)->firstOfMonth()->addDays($i)->toString());
            //  Get calendar by date
            $calendar_list = $this->calendar->filterByDate($date, ['id', 'name', 'date_from', 'date_to', 'is_all_day', 'language_id']);

            $list->push([
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('d'),
                'month' => $date->format('m'),
                'name' => $date->shortEnglishDayOfWeek,
                'now' => $date->format('Y-m-d') === now()->format('Y-m-d'),
                'current_month' => $date->format('m') === $month->format('m'),
                'events' => $calendar_list->toCamel(),
            ]);
        }

        /** Todo: make timezone exists here */
        return ires()->data([
            'month' => $month->englishMonth . ' ' . $month->format('Y'),
            'list' => $list,
            'lastDay' => $lastDay,
            'grid' => ceil($list->count() / 7),
        ]);
    }
}
