<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Client;

use Cms\Core\Responses\AppResponse;

use Exception;
use JsonException;
use App\Constants\CacheConstants;
use App\Http\Controllers\Controller;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Http\Requests\Client\Account\PostUpdateAccountRequest;

class AccountController extends Controller
{
    /**
     * Get account informations
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 00:57 ICT
     */
    public function myAccount(): mixed
    {
        $user = user();

        $user->load('roles.permissions');

        RedisHelper::set('users:' . $user->uuid, $user, CacheConstants::CLIENT_USER_SNAPSHOT_TTL);

        return ires()->data();
    }

    /**
     * Client Update account
     *
     * @throws JsonException
     * @since 6.62.6 - 2023-03-07, 21:21 ICT
     * @author malayvuong
     */
    public function update(PostUpdateAccountRequest $request): mixed
    {
        $user = user();

        try {
            $user->name = $request->input('name');
            $user->mobile = $request->input('mobile');

            if (user()->can('username', $user)) {
                $user->username = $request->input('username');
            }
            if (user()->can('email', $user)) {
                $user->email = $request->input('email');
            }

            $user->save();
            //  Get all metadata available for user
            $metaData = json_decode(RedisHelper::get('meta.user.' . lang('path', 'vi')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($metaData as $key => $item) {
                foreach ($item->options as $opt) {
                    $meta[$key . '_' . $item->id][$opt->id] = $request->input($key . '_' . $item->id . '_' . $opt->id);
                }
            }
            //  Save Meta data
            $list = [];
            foreach ($request->input('meta', []) as $value) {
                if (is_array($value) && !empty($value['value'])) {
                    $valueId = $value['value']['id'] ?? null;
                    if (!is_array($value['value']) || in_array($value['type'], ['string', 'date', 'url'])) {
                        $valueId = setMetaValue($value, $value['type'] === 'date')?->id ?: null;
                    }

                    $list[] = [
                        'meta_class_id' => $value['class_id'],
                        'meta_attribute_id' => $value['id'],
                        'value_type' => get_value_class($value['type']),
                        'value_id' => $valueId,
                    ];
                }
            }

            $user->meta()->delete();
            $user->meta()->createMany($list);
        } catch (Exception $e) {
            return ires()->error()->message(__('Update failed!'));
        }

        //  Process to update metadata
        $metaDefault = json_decode(RedisHelper::get('meta.user.' . lang('path', 'vi')), true, 512, JSON_THROW_ON_ERROR);
        $meta = [];
        foreach ($metaDefault as $key => $item) {
            foreach ($item->options as $opt) {
                $meta[$key . '_' . $item->id][$opt->id] = $request->input($key . '_' . $item->id . '_' . $opt->id);
            }
        }


        //  Store redis temporary database
        $user?->load(['roles.permissions', 'meta']);

        $meta = [];
        foreach ($user?->meta as $value) {
            $meta[$value->attribute->name . '_' . $value->attribute->id] = [
                'class_id' => $value->meta_class_id,
                'type' => $value->attribute->type,
                'id' => $value->meta_attribute_id,
                'value' => $value->value ?? '',
            ];
        }

        $user?->setAttribute('currentMeta', $meta);

        RedisHelper::set('users:' . ($user->uuid ?? $user->id ?? ''), $user, CacheConstants::CLIENT_USER_SNAPSHOT_TTL);

        return ires()->data($user)->message(__('Update success!'));
    }

    /**
     * Process to sign out by token
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-01, 08:39 ICT
     */
    public function signOut(): mixed
    {
        auth()->user()?->tokens()->delete();


        return ires()->data(request()->all());
    }
}
