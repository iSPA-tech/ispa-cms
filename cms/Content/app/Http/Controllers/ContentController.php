<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

/**
 * Module scaffold controller for the Content module.
 *
 * Provides basic CRUD view endpoints for the Content module's web routes.
 * Not extended by other controllers — V2 Admin controllers extend AdminController instead.
 *
 * @package Cms\Content\Http\Controllers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ContentController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return JsonResponse|Response
     */
    public function index()
    {
        return view('content::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return JsonResponse|Response
     */
    public function create()
    {
        return view('content::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param  Request $request
     * @return JsonResponse|Response
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     * @param  mixed $id
     * @return JsonResponse|Response
     */
    public function show($id)
    {
        return view('content::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param  mixed $id
     * @return JsonResponse|Response
     */
    public function edit($id)
    {
        return view('content::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param  Request $request
     * @param  mixed $id
     * @return JsonResponse|Response
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     * @param  mixed $id
     * @return JsonResponse|Response
     */
    public function destroy($id) {}
}
