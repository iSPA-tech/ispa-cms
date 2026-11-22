<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Responses;

use JsonException;
use App\Constants\Headers;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\Paginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Standardized HTTP/JSON response builder for iSPA CMS.
 *
 * Provides a fluent API to compose consistent API payloads, map JsonResource meta,
 * attach security headers, and emit audit logs for 5xx responses. Includes helpers
 * for common HTTP codes (ok, created, badRequest, unauthorized, etc.) and tools to
 * build paginated payloads from Laravel Paginators.
 *
 * @package Cms\Core\Responses
 * @author  malayvuong
 *
 * @since   6.0.0  (2022-01-05)
 * @last_updated 8.0.0 - 2025-09-06, 10:35 (+07:00)
 */
class AppResponse implements Responsable
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_WARNING = 'warning';
    public const STATUS_INFO = 'info';

    /** @var string $iStatus */
    protected string $iStatus = self::STATUS_SUCCESS;

    /** @var mixed|null $iData */
    protected mixed $iData = null;

    /** @var string|null $iMessage */
    protected ?string $iMessage = null;

    /** @var string $previousUrl */
    protected string $previousUrl = '';

    /** @var string $nextUrl */
    protected string $nextUrl = '';

    /** @var bool $withInput */
    protected bool $withInput = false;

    /** @var int $code */
    protected int $code = 200;

    protected string $errorCode = '';

    /** @var array $headers */
    protected array $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'deny',
        // 'Content-Security-Policy' => 'default-src \'none\'',
        //'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; media-src 'none'; object-src 'none'",
    ];

    /**
     * Factory: create a fresh AppResponse instance.
     *
     * @return static
     */
    public static function make(): static
    {
        return new static();
    }

    /**
     * @param string $previous_url
     *
     * @return AppResponse
     */
    public function previousUrl(string $previous_url): static
    {
        $this->previousUrl = $previous_url;

        return $this;
    }

    /**
     * @param string $next_url
     *
     * @return AppResponse
     */
    public function nextUrl(string $next_url): static
    {
        $this->nextUrl = $next_url;

        return $this;
    }

    /**
     * Set HTTP status code.
     *
     * @param int $code Any valid HTTP status (100–599).
     *
     * @return static
     *
     * @throws InvalidArgumentException If the code is outside the valid HTTP range.
     */
    public function code(int $code): static
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException('Invalid HTTP status code');
        }

        $this->code = $code;
        return $this;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->iMessage ?? '';
    }

    /**
     * Set a machine-readable error code to accompany error responses.
     *
     * @param string $error_code
     *
     * @return static
     *
     * @author malayvuong
     * @since  7.160.1175 - 2024-10-04, 20:41 (+07:00)
     * @last_updated 8.0.0   - 2025-09-06, 10:35 (+07:00)
     */
    public function errorCode(string $error_code = ''): static
    {
        $this->errorCode = $error_code;

        return $this;
    }

    /**
     * Set failed status
     *
     * @return static
     * @author malayvuong
     * @since 6.62.6 - 2022-10-16, 10:42 ICT
     */
    public function failed(): static
    {
        $this->code = Response::HTTP_NOT_ACCEPTABLE;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * Set internal response status string.
     *
     * @param string $status One of: success | error | warning | info
     *
     * @return static
     *
     * @author malayvuong
     * @since  6.62.6 - 2022-10-16, 11:18 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 10:35 (+07:00)
     */
    protected function setStatus(string $status = self::STATUS_SUCCESS): static
    {
        $this->iStatus = $status;

        return $this;
    }

    /**
     * Merge extra headers into the response. Existing keys are preserved unless overridden.
     *
     * @param array $headers Header key/value pairs (e.g. ['X-Trace-Id' => '...']).
     *
     * @return static
     */
    public function headers(array $headers = []): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Mark response as a warning (non-error), optionally attach a message.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function warning(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        return $this->setStatus(self::STATUS_WARNING);
    }

    /**
     * @param string|null $message
     *
     * @return AppResponse
     */
    public function message(?string $message): static
    {
        $this->iMessage = $message;

        return $this;
    }

    /**
     * Mark response as informational (non-error), optionally attach a message.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function info(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        return $this->setStatus(self::STATUS_INFO);
    }

    /**
     * 201 Created response.
     *
     * @param mixed|null $data
     * @param string|null $message
     *
     * @return static
     */
    public function created(mixed $data = null, ?string $message = null): static
    {
        if (!is_null($data)) {
            $this->data($data);
        }
        if (!is_null($message)) {
            $this->message($message);
        }
        $this->code = Response::HTTP_CREATED;

        return $this->setStatus();
    }

    /**
     * Attach payload data; optionally convert arrays/collections to camelCase.
     *
     * @param mixed $data Arbitrary payload (array|Collection|JsonResource|scalar|null).
     * @param bool $isCamel Convert array/collection keys to camelCase via collection macro.
     *
     * @return static
     */
    public function data(mixed $data = null, bool $isCamel = false): static
    {
        if ($isCamel) {
            $this->iData = collect($data)->toCamel();
        } else {
            $this->iData = $data;
        }

        return $this;
    }

    /**
     * 204 No Content response (no body expected).
     *
     * @return static
     */
    public function noContent(): static
    {
        $this->code = Response::HTTP_NO_CONTENT;

        return $this->setStatus();
    }

    /**
     * 400 Bad Request response.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function badRequest(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_BAD_REQUEST;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * 401 Unauthorized response.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function unauthorized(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_UNAUTHORIZED;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * 403 Forbidden response.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function forbidden(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_FORBIDDEN;

        return $this->setStatus(self::STATUS_ERROR);
    }

    // Cms\Core\Responses\AppResponse.php

    /**
     * 404 Not Found response.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function notFound(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_NOT_FOUND;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * 409 Conflict response.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function conflict(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_CONFLICT;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * 202 Accepted response (asynchronous processing).
     *
     * @param mixed|null $data
     * @param string|null $message
     *
     * @return static
     */
    public function accepted(mixed $data = null, ?string $message = null): static
    { /* 202 */
        if (!is_null($data)) {
            $this->data($data);
        }
        if (!is_null($message)) {
            $this->message($message);
        }
        $this->code = Response::HTTP_ACCEPTED;

        return $this->setStatus();
    }

    /**
     * 429 Too Many Requests response (rate limited).
     *
     * @param string|null $message
     *
     * @return static
     */
    public function tooManyRequests(?string $message = null): static
    { /* 429 */
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_TOO_MANY_REQUESTS;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * 500 Internal Server Error response.
     *
     * @param string|null $message
     *
     * @return static
     */
    public function serverError(?string $message = null): static
    {
        if ($message) {
            $this->message($message);
        }
        $this->code = Response::HTTP_INTERNAL_SERVER_ERROR;
        CMSLogger::error([
            'message' => $this->iMessage,
            'error_code' => $this->errorCode,
        ]);

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * @return AppResponse
     */
    public function error(): static
    {
        $this->code = Response::HTTP_BAD_REQUEST;

        return $this->setStatus(self::STATUS_ERROR);
    }

    /**
     * Wrap a JsonResource within the standardized response envelope.
     *
     * @param JsonResource $resource The resource instance to render.
     * @param string|null $message Optional message to include in meta.
     *
     * @return JsonResource
     *
     * @throws ConnectionException
     * @throws JsonException
     */
    public function fromResource(JsonResource $resource, ?string $message = null): JsonResource
    {
        $this->iMessage = $message;

        return $this->data($resource)->toApiResponse();
    }

    /**
     * Render API response.
     *
     * If the current data is a `JsonResource`, append `message`, `status`, and optional
     * `error_code` to its top-level `meta` via `additional()`. Otherwise, delegates to
     * `toResponse()` to build a JsonResponse.
     *
     * @return JsonResponse|JsonResource
     *
     * @throws ConnectionException
     * @throws JsonException
     */
    public function toApiResponse(): JsonResponse|JsonResource
    {
        if ($this->iData instanceof JsonResource) {
            $meta = [
                'message' => $this->iMessage,
                'status' => $this->iStatus,
            ];
            if ($this->errorCode) {
                $meta['error_code'] = $this->errorCode;
            }
            return $this->iData->additional($meta);
        }

        return $this->toResponse(request());
    }

    /**
     * Build the final JsonResponse with headers and optional server-error logging.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function toResponse($request): JsonResponse
    {
        $json = [
            'message' => $this->iMessage,
        ];
        if ($this->iData) {
            $json['data'] = $this->iData;
        }
        if ($this->errorCode) {
            $json['error_code'] = $this->errorCode;
        }
        $json['status'] = $this->iStatus;

        if ($this->code >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            CMSLogger::error([
                'message' => $this->iMessage,
                'error_code' => $this->errorCode,
                'status' => $this->iStatus,
            ]);
        }

        return response()->json($json, $this->code, $this->headers, JSON_INVALID_UTF8_SUBSTITUTE)
            ->header('Access-Control-Expose-Headers',
                implode(', ', Headers::getAll())
            );
    }

    /**
     * Build a normalized paginated payload from a Paginator/LengthAwarePaginator.
     *
     * @param LengthAwarePaginator|Paginator $paginator
     * @param bool $camel Convert items to camelCase.
     *
     * @return static
     */
    public function fromPaginator(Paginator|LengthAwarePaginator $paginator, bool $camel = true): static
    {
        // Standardized meta + links payload
        $payload = [
            'data' => $camel ? collect($paginator->items())->toCamel() : $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];

        return $this->ok($payload);
    }

    /**
     * 200 OK response.
     *
     * @param mixed|null $data
     * @param string|null $message
     *
     * @return static
     */
    public function ok(mixed $data = null, ?string $message = null): static
    {
        if (!is_null($data)) {
            $this->data($data);
        }
        if (!is_null($message)) {
            $this->message($message);
        }
        $this->code = Response::HTTP_OK;

        return $this->setStatus();
    }

    /**
     * Reset the response to defaults (status, data, headers, HTTP code, etc.).
     *
     * @return static
     */
    public function reset(): static
    {
        $this->iStatus = self::STATUS_SUCCESS;
        $this->iData = null;
        $this->iMessage = null;
        $this->previousUrl = '';
        $this->nextUrl = '';
        $this->withInput = false;
        $this->code = 200;
        $this->errorCode = '';
        $this->headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'deny',
            //'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; media-src 'none'; object-src 'none'",
        ];

        return $this;
    }

    /**
     * Build a RedirectResponse with session flash message and optional old input.
     *
     * @param string $url Absolute or relative target URL.
     *
     * @return RedirectResponse
     */
    protected function responseRedirect(string $url): RedirectResponse
    {
        if ($this->withInput) {
            return redirect()
                ->to($url)
                ->with($this->getFlashKey(), $this->iMessage)
                ->withInput();
        }

        return redirect()
            ->to($url)
            ->with($this->getFlashKey(), $this->iMessage);
    }

    /**
     * @param bool $with_input
     *
     * @return AppResponse
     */
    public function withInput(bool $with_input = true): static
    {
        $this->withInput = $with_input;

        return $this;
    }

    /**
     * Resolve the session flash key based on current response status.
     *
     * @return string One of: success_msg, error_msg, warning_msg, info_msg
     */
    protected function getFlashKey(): string
    {
        return match ($this->iStatus) {
            self::STATUS_SUCCESS => 'success_msg',
            self::STATUS_ERROR => 'error_msg',
            self::STATUS_WARNING => 'warning_msg',
            self::STATUS_INFO => 'info_msg',
            default => 'flash_msg'
        };
    }
}
