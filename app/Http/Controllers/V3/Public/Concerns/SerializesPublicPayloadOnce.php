<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Public\Concerns;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Collapse the double serialization on endpoints that also write a shared cache.
 *
 * Several V3 public endpoints write their payload to a Redis key that the separate frontend
 * reads directly, and RedisHelper json_encodes whatever it is handed. Encoding
 * an Eloquent model or paginator re-fires every appended accessor — `slug`,
 * `image`, `has_children`, `total_view` — so the endpoint paid for the whole
 * payload twice: once for the cache write, once for the HTTP response.
 *
 * Converting to an array first pays it once. The JSON is byte-identical:
 * response()->json() encodes a paginator through the same toArray() this calls.
 *
 * @package App\Http\Controllers\V3\Public\Concerns
 * @author  iSPA Team
 * @since   8.1.0 (2026-08-03)
 */
trait SerializesPublicPayloadOnce
{
    /**
     * @param  mixed $payload
     * @return mixed  An array when the payload could be converted, else as-is.
     */
    protected function serializeOnce(mixed $payload): mixed
    {
        if ($payload instanceof Arrayable) {
            $array = $payload->toArray();

            // AppResponse decides whether to emit `data` with a plain truthiness
            // check, and an empty array is falsy where an empty Collection is
            // not. Handing back the original in that case keeps the response
            // shape identical; there is nothing to serialize twice anyway.
            return $array === [] ? $payload : $array;
        }

        if (is_array($payload)) {
            return array_map(fn (mixed $item): mixed => $this->serializeOnce($item), $payload);
        }

        return $payload;
    }
}
