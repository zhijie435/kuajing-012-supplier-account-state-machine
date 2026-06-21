<?php

declare(strict_types=1);

final class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, string $message = 'ok'): void
    {
        self::json(['code' => 0, 'message' => $message, 'data' => $data]);
    }

    public static function fail(string $message, int $status = 400, $data = null): void
    {
        self::json(['code' => $status, 'message' => $message, 'data' => $data], $status);
    }
}
