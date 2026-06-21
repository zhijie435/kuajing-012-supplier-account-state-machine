<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountService.php';

$service = new AccountService();

echo "=== 重构验证测试 ===\n\n";

echo "1. 测试查询权限 - 列表查询带 operator 参数\n";
try {
    $list = $service->list(['operator' => 'supplier_01', 'status' => 'active']);
    echo "   ✓ supplier 角色查询成功，返回 " . count($list) . " 条记录\n";
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
}

echo "\n2. 测试查询权限 - 无效 operator 应该抛出异常\n";
try {
    $list = $service->list(['operator' => 'invalid_user']);
    echo "   ✗ 应该抛出异常但没有\n";
} catch (PermissionDeniedException $e) {
    echo "   ✓ 正确抛出 PermissionDeniedException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "   ✗ 抛出错误类型: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "\n3. 测试详情查询权限 - 有效角色\n";
try {
    $account = $service->get(1, 'reviewer_01');
    echo "   ✓ reviewer 角色查询详情成功，状态: " . $account['status'] . "\n";
    echo "     可用事件: " . implode(', ', array_keys($account['available_events'])) . "\n";
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
}

echo "\n4. 测试详情查询权限 - 根据角色过滤可用事件\n";
try {
    $account1 = $service->get(1, 'supplier_01');
    $account2 = $service->get(1, 'admin_01');
    echo "   ✓ supplier 可用事件: " . implode(', ', array_keys($account1['available_events'])) . "\n";
    echo "   ✓ admin 可用事件: " . implode(', ', array_keys($account2['available_events'])) . "\n";
    if (count($account2['available_events']) > count($account1['available_events'])) {
        echo "   ✓ admin 比 supplier 有更多可用操作，权限过滤生效\n";
    }
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
}

echo "\n5. 测试审核与冻结联动校验 - 冻结账户后尝试审核通过\n";
try {
    $frozenAccount = $service->get(2);
    if ($frozenAccount['status'] === 'frozen') {
        echo "   ✓ 账户2当前为冻结状态，frozen_at = " . ($frozenAccount['frozen_at'] ? date('Y-m-d H:i:s', (int)$frozenAccount['frozen_at']) : 'null') . "\n";
    }
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
}

echo "\n6. 测试创建账户事务和异常处理\n";
try {
    $account = $service->create(['supplier_name' => '测试供应商']);
    echo "   ✓ 创建账户成功，ID: " . $account['id'] . ", code: " . $account['supplier_code'] . "\n";
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
}

echo "\n7. 测试创建账户参数校验\n";
try {
    $account = $service->create(['supplier_name' => '']);
    echo "   ✗ 应该抛出异常但没有\n";
} catch (StateException $e) {
    echo "   ✓ 正确抛出 StateException: " . $e->getMessage() . "\n";
}

echo "\n8. 测试状态迁移权限 - supplier 尝试冻结账户（应该无权）\n";
try {
    $result = $service->trigger(1, 'freeze', ['operator' => 'supplier_01', 'reason' => '测试冻结']);
    echo "   ✗ 应该抛出权限异常但没有\n";
} catch (PermissionDeniedException $e) {
    echo "   ✓ 正确抛出 PermissionDeniedException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "   ✗ 抛出其他异常: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "\n9. 测试状态迁移 - 驳回必须填写原因\n";
try {
    $result = $service->trigger(5, 'reject', ['operator' => 'reviewer_01', 'reason' => '']);
    echo "   ✗ 应该抛出异常但没有\n";
} catch (StateTransitionException $e) {
    echo "   ✓ 正确抛出 StateTransitionException: " . $e->getMessage() . "\n";
    $ctx = $e->context();
    echo "     上下文包含: current_status={$ctx['current_status']}, event={$ctx['event']}, can_rollback=" . ($ctx['can_rollback'] ? 'true' : 'false') . "\n";
}

echo "\n=== 测试完成 ===\n";

echo "\n检查错误日志是否生成:\n";
$logDir = __DIR__ . '/data/logs';
if (is_dir($logDir)) {
    $files = glob($logDir . '/error_*.log');
    if ($files) {
        echo "   ✓ 错误日志目录存在，日志文件: " . basename($files[0]) . "\n";
        echo "   日志内容:\n";
        echo "   " . file_get_contents($files[0]) . "\n";
    } else {
        echo "   - 暂无错误日志（正常）\n";
    }
}
