<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Requests\V3;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aliases are the only accepted target vocabulary. Callers never send a
     * model class name, and anything outside config('tracking.target_aliases')
     * is rejected before it can reach the tracking pipeline.
     *
     * @return list<string>
     */
    public static function allowedTargets(): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $alias): string => (string) $alias,
                (array) config('tracking.target_aliases', [])
            )
        )));
    }

    /**
     * The pseudo target for pages that map to no entity: the beacon carries the
     * page path instead of an id, and the resolver turns it into a hashed path
     * target when it matches the public_path_patterns allowlist.
     */
    public const PAGE_TARGET = 'page';

    /**
     * @return list<string>
     */
    public static function uuidAddressableAliases(): array
    {
        return array_values(array_map(
            static fn (mixed $alias): string => (string) $alias,
            (array) config('tracking.uuid_addressable_aliases', [])
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = (string) $this->input('target');
        $uuidAddressable = in_array($target, static::uuidAddressableAliases(), true);

        return [
            'target' => [
                'required',
                'string',
                Rule::in([...static::allowedTargets(), self::PAGE_TARGET]),
            ],
            'id' => [
                $uuidAddressable
                    ? 'required_without:uuid'
                    : 'required_unless:target,' . self::PAGE_TARGET,
                'integer',
                'min:1',
            ],
            //  Public vehicle payloads expose no integer ids, so those beacons
            //  address the entity by uuid; the controller resolves the key.
            'uuid' => [
                $uuidAddressable ? 'required_without:id' : 'prohibited',
                'uuid',
            ],
            'path' => [
                'required_if:target,' . self::PAGE_TARGET,
                'prohibited_unless:target,' . self::PAGE_TARGET,
                'string',
                'max:190',
                'regex:#^/[A-Za-z0-9/_-]*$#',
            ],
            //  Opaque, client generated and stable per browser. It replaces the
            //  session id for throttling and unique counting, because stateless
            //  API requests have no session of their own.
            'visitor' => ['nullable', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'target' => 'target type',
            'id' => 'target id',
            'visitor' => 'visitor key',
        ];
    }
}
