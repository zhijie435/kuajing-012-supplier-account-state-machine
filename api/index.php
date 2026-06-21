<?php

declare(strict_types=1);

/**
 * 供应商结算账户状态机 —— PHP 后端入口 / 前置控制器
 * 启动：php -S 127.0.0.1:8000 -t api api/index.php
 * 所有 /api/** 请求由此分发。
 */

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/AccountService.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 去掉 /api 前缀，得到资源路径
$base = '/api';
if (str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri = '/' . trim($uri, '/');
if ($uri === '/') {
    $uri = '';
}

$segments = $uri === '' ? [] : explode('/', ltrim($uri, '/'));
$body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

$service = new AccountService();

try {
    // GET /definition —— 状态机定义（供前端可视化）
    if ($method === 'GET' && $segments === ['definition']) {
        Response::ok($service->definition(), '状态机定义');
    }

    // GET /accounts —— 列表
    if ($method === 'GET' && $segments === ['accounts']) {
        Response::ok($service->list($_GET), '账户列表');
    }

    // POST /accounts —— 创建
    if ($method === 'POST' && $segments === ['accounts']) {
        Response::ok($service->create($body), '创建成功', 201);
    }

    // GET /accounts/{id}/history —— 操作历史
    if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'accounts' && $segments[2] === 'history') {
        Response::ok($service->history((int)$segments[1]), '操作历史');
    }

    // POST /accounts/{id}/{event} —— 触发状态迁移
    if ($method === 'POST' && count($segments) === 3 && $segments[0] === 'accounts') {
        $id = (int)$segments[1];
        $event = $segments[2];
        Response::ok($service->trigger($id, $event, $body), '操作成功');
    }

    // GET /accounts/{id} —— 详情
    if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'accounts') {
        Response::ok($service->get((int)$segments[1]), '账户详情');
    }

    Response::fail('Not Found: ' . $method . ' ' . $uri, 404);
} catch (StateException $e) {
    Response::fail($e->getMessage(), $e->status(), $e->context());
} catch (Throwable $e) {
    Response::fail('服务器内部错误：' . $e->getMessage(), 500);
}
