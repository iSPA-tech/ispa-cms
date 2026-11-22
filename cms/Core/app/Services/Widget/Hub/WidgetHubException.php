<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use RuntimeException;
use Throwable;

/**
 * A hub download or update that was refused: carries the API `error_code`, the HTTP status and the plain Vietnamese
 * message shown to the admin. Nothing was written when this is thrown.
 */
class WidgetHubException extends RuntimeException
{
    public const RESOLVER_MISSING = 'WIDGET_RESOLVER_MISSING';
    public const KEY_TAKEN = 'WIDGET_KEY_TAKEN';
    public const DESIGN_IN_USE = 'WIDGET_UPDATE_DESIGN_IN_USE';
    public const HUB_NOT_FOUND = 'WIDGET_HUB_NOT_FOUND';
    public const NOT_FOUND = 'WIDGET_NOT_FOUND';
    public const SITE_COPY = 'WIDGET_SITE_COPY';
    public const UP_TO_DATE = 'WIDGET_UP_TO_DATE';
    public const AUTHORING_COPY = 'WIDGET_AUTHORING_COPY';

    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function resolverMissing(): self
    {
        return new self(self::RESOLVER_MISSING, 409, 'Widget này cần tính năng mà site chưa có. Hãy cập nhật hệ thống trước.');
    }

    public static function keyTaken(?Throwable $previous = null): self
    {
        return new self(self::KEY_TAKEN, 409, 'Site đã có một widget trùng mã này.', $previous);
    }

    public static function designInUse(): self
    {
        return new self(self::DESIGN_IN_USE, 409, 'Bản mới bỏ một thiết kế đang được dùng, nên chưa cập nhật được.');
    }

    public static function hubNotFound(): self
    {
        return new self(self::HUB_NOT_FOUND, 404, 'Không tìm thấy widget này trên kho.');
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404, 'Không tìm thấy widget.');
    }

    public static function siteCopy(): self
    {
        return new self(self::SITE_COPY, 409, 'Bản riêng của site không nhận cập nhật từ kho.');
    }

    /** The local authoring copy publishes hub widgets; it never downloads or updates them from the hub. */
    public static function authoringCopy(): self
    {
        return new self(self::AUTHORING_COPY, 409, 'Đây là bản soạn widget gốc, nên không tải về hay cập nhật từ kho.');
    }

    public static function upToDate(): self
    {
        return new self(self::UP_TO_DATE, 409, 'Widget đã là bản mới nhất.');
    }
}
