<?php

namespace App\Contracts;

interface LoggerServiceInterface
{
    public static function error(array $data = []): void;

    public static function warning(array $data = []): void;

    public static function info(array $data = []): void;
}
