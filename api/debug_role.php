<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountService.php';

$service = new AccountService();

// 使用反射来测试私有方法
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('resolveRole');
$method->setAccessible(true);

echo "测试 resolveRole 方法:\n";

$tests = [
    'supplier_01' => '应该返回 supplier',
    'reviewer_01' => '应该返回 reviewer',
    'risk_01' => '应该返回 risk',
    'admin_01' => '应该返回 admin',
    'invalid_user' => '应该抛出异常',
    '' => '应该抛出异常',
];

foreach ($tests as $operator => $desc) {
    echo "\n测试: '$operator' - $desc\n";
    try {
        $result = $method->invoke($service, $operator);
        echo "  返回: $result\n";
    } catch (PermissionDeniedException $e) {
        echo "  正确抛出 PermissionDeniedException: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "  抛出其他异常: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
}

echo "\n\n测试 list 方法:\n";
try {
    $result = $service->list(['operator' => 'invalid_user']);
    echo "  list 返回了 " . count($result) . " 条记录，没有抛出异常\n";
} catch (PermissionDeniedException $e) {
    echo "  正确抛出 PermissionDeniedException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "  抛出其他异常: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
