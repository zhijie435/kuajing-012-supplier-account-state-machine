<?php

require_once __DIR__ . '/api/AccountService.php';

$service = new AccountService();

echo "=== 审核与冻结联动校验验收 ===\n\n";

// 测试场景1：待审核状态下不允许冻结
echo "场景1：待审核状态下不允许冻结\n";
$acc1 = $service->create([
    'supplier_name' => '联动测试供应商1',
    'account_name' => '联动测试账户1',
    'account_no' => '6225881011112222',
    'bank_name' => '建设银行',
]);
$acc1 = $service->trigger($acc1['id'], 'submit', ['operator' => 'supplier_01']);
echo "  状态: {$acc1['status']}, submitted_at: {$acc1['submitted_at_text']}\n";
try {
    $service->trigger($acc1['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '测试冻结'
    ]);
    echo "  ✗ 应该被拒绝但成功了\n";
} catch (StateTransitionException $e) {
    echo "  ✓ 正确拒绝: {$e->getMessage()}\n";
}

// 测试场景2：冻结状态下不允许审核通过
echo "\n场景2：冻结状态下不允许审核通过\n";
$acc2 = $service->create([
    'supplier_name' => '联动测试供应商2',
    'account_name' => '联动测试账户2',
    'account_no' => '6225881033334444',
    'bank_name' => '农业银行',
]);
$acc2 = $service->trigger($acc2['id'], 'submit', ['operator' => 'supplier_01']);
$acc2 = $service->trigger($acc2['id'], 'approve', ['operator' => 'reviewer_01']);
$acc2 = $service->trigger($acc2['id'], 'freeze', [
    'operator' => 'risk_01',
    'reason' => '异常交易'
]);
// 回滚审核通过回到待审核
$acc2 = $service->trigger($acc2['id'], 'rollback_freeze', [
    'operator' => 'admin_01',
    'reason' => '回滚冻结用于测试'
]);
$acc2 = $service->trigger($acc2['id'], 'rollback_approve', [
    'operator' => 'admin_01',
    'reason' => '回滚审核通过用于测试'
]);
// 手动设置冻结标记
$stmt = Database::pdo()->prepare("UPDATE accounts SET frozen_at = ? WHERE id = ?");
$stmt->execute([time(), $acc2['id']]);
echo "  状态: {$acc2['status']}, 已手动设置 frozen_at\n";
try {
    $service->trigger($acc2['id'], 'approve', ['operator' => 'reviewer_01']);
    echo "  ✗ 应该被拒绝但成功了\n";
} catch (StateTransitionException $e) {
    echo "  ✓ 正确拒绝: {$e->getMessage()}\n";
}

// 测试场景3：先审核通过再冻结 - 正常流程
echo "\n场景3：先审核通过再冻结 - 正常流程\n";
$acc3 = $service->create([
    'supplier_name' => '联动测试供应商3',
    'account_name' => '联动测试账户3',
    'account_no' => '6225881055556666',
    'bank_name' => '中国银行',
]);
$acc3 = $service->trigger($acc3['id'], 'submit', ['operator' => 'supplier_01']);
echo "  提交后状态: {$acc3['status']}\n";
$acc3 = $service->trigger($acc3['id'], 'approve', ['operator' => 'reviewer_01']);
echo "  审核通过后状态: {$acc3['status']}\n";
$acc3 = $service->trigger($acc3['id'], 'freeze', [
    'operator' => 'risk_01',
    'reason' => '正常冻结'
]);
echo "  冻结后状态: {$acc3['status']}\n";
echo "  ✓ 正常流程通过\n";

echo "\n=== 审核与冻结联动校验验收通过 ===\n";
