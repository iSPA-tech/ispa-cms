<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Events;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use ReflectionProperty;

class SaveTracking
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, SerializesModels {
        SerializesModels::__unserialize as private restoreSerializedModels;
    }

    public readonly array $tags;
    public readonly ?string $type;
    public readonly int $id;
    public readonly ?string $ip;
    public readonly ?string $agent;
    public readonly ?string $url;
    public readonly ?string $sessionId;
    public readonly CarbonImmutable $occurredAt;
    public readonly string $eventId;
    public readonly ?string $routeName;
    public readonly array $routeParameters;
    public readonly ?string $host;
    public readonly ?string $path;

    /**
     * Save Tracking constructor
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-19, 11:6
     */
    public function __construct(
        array $tags,
        string $type = null,
        int $id = 0,
        ?string $visitorKey = null,
        ?string $publicUrl = null
    ) {
        $this->tags = $tags;
        $this->type = $type;
        $this->id = $id;
        $this->ip = request()->ip();
        $this->agent = $this->toQueueSafeUtf8((string)request()->userAgent());
        $this->occurredAt = CarbonImmutable::now('UTC');
        //  Stateless entry points (the API middleware group has no StartSession)
        //  still get an id back from session()->getId(), but it is freshly
        //  generated per request. Feeding that into the throttle and dedupe keys
        //  would make every event unique, so only a really started session
        //  counts; API callers pass their own stable visitor key instead.
        $this->sessionId = $visitorKey
            ?: (session()->isStarted() ? session()->getId() : null);
        $this->eventId = (string) Str::ulid();

        if ($publicUrl !== null) {
            //  A relayed page view. The current request is the ingest endpoint,
            //  so its own url and route name describe the relay, not the page
            //  the reader saw. Classifying by the ingest route would collapse
            //  every page into one target, so route context is dropped and the
            //  target resolver works from the reader facing url alone.
            $this->url = $this->toQueueSafeUtf8($publicUrl);
            $parts = parse_url($publicUrl);
            $this->host = is_array($parts) ? ($parts['host'] ?? null) : null;
            $this->path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
            $this->routeName = null;
            $this->routeParameters = [];

            return;
        }

        $this->url = $this->toQueueSafeUtf8((string)request()->fullUrl());
        $this->host = request()->getHost();
        $this->path = request()->getPathInfo();

        $route = request()->route();
        $this->routeName = is_object($route) && method_exists($route, 'getName')
            ? $route->getName()
            : null;
        $parameters = is_object($route) && method_exists($route, 'parameters')
            ? $route->parameters()
            : [];
        $this->routeParameters = $this->normalizeRouteParameters((array) $parameters);
    }

    protected function toQueueSafeUtf8(string $value): string
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return '';
        }

        $decoded = json_decode($encoded, true);
        return is_string($decoded) ? $decoded : '';
    }

    private function normalizeRouteParameters(array $parameters): array
    {
        $normalized = [];

        foreach ($parameters as $key => $value) {
            if (is_object($value) && method_exists($value, 'getRouteKey')) {
                $value = $value->getRouteKey();
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                $normalized[(string) $key] = $this->toQueueSafeUtf8((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * Restore envelopes queued before the immutable identity fields existed.
     *
     * @param array<string, mixed> $values
     */
    public function __unserialize(array $values): void
    {
        if (($values['occurredAt'] ?? null) instanceof DateTimeInterface) {
            $values['occurredAt'] = CarbonImmutable::instance($values['occurredAt'])->utc();
        }

        $this->restoreSerializedModels($values);

        $parts = parse_url($this->initializedValue('url', null) ?: '');
        $this->initializeReadonly('tags', []);
        $this->initializeReadonly('type', null);
        $this->initializeReadonly('id', 0);
        $this->initializeReadonly('ip', null);
        $this->initializeReadonly('agent', null);
        $this->initializeReadonly('url', null);
        $this->initializeReadonly('sessionId', null);
        $this->initializeReadonly('occurredAt', CarbonImmutable::now('UTC'));
        $this->initializeReadonly('eventId', (string) Str::ulid());
        $this->initializeReadonly('routeName', null);
        $this->initializeReadonly('routeParameters', []);
        $this->initializeReadonly('host', is_array($parts) ? ($parts['host'] ?? null) : null);
        $this->initializeReadonly('path', is_array($parts) ? ($parts['path'] ?? null) : null);
    }

    private function initializedValue(string $property, mixed $default): mixed
    {
        $reflection = new ReflectionProperty($this, $property);

        return $reflection->isInitialized($this) ? $this->{$property} : $default;
    }

    private function initializeReadonly(string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($this, $property);
        if (!$reflection->isInitialized($this)) {
            $this->{$property} = $value;
        }
    }
}
