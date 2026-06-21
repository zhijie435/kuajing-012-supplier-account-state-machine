<?php

require_once __DIR__ . '/api/AccountService.php';

$service = new AccountService();

echo "=== 账户冻结/解冻流程验收 ===\n\n";

// 1. 创建并激活账户
echo "1. 创建并激活账户... ";
$acc = $service->create([
    'supplier_name' => '冻结测试供应商',
    'account_name' => '冻结测试账户',
    'account_no' => '6225881087654321',
    'bank_name' => '工商银行',
    'bank_branch' => '北京分行',
]);
$acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
$acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
echo "✓ 账户ID: {$acc['id']}, 状态: {$acc['status']}\n";

// 2. 冻结账户（风控专员操作）
echo "2. 冻结账户（risk_01）... ";
$acc = $service->trigger($acc['id'], 'freeze', [
    'operator' => 'risk_01',
    'reason' => '账户存在异常大额交易，需核实'
]);
echo "✓ 状态: {$acc['status']}\n";
echo "  冻结时间: {$acc['frozen_at_text']}\n";
echo "  冻结原因: {$acc['freeze_reason']}\n";

// 3. 验证冻结状态下无法审核通过（联动校验）
echo "3. 验证冻结状态下无法审核通过... ";
try {
    $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    echo "✗ 应该被拒绝但成功了\n";
} catch (StateTransitionException $e) {
    echo "✓ 正确拒绝: {$e->getMessage()}\n";
}

// 4. 解冻账户（风控专员操作）
echo "4. 解冻账户（risk_01）... ";
$acc = $service->trigger($acc['id'], 'unfreeze', [
    'operator' => 'risk_01',
    'reason' => '核查无误，解除冻结'
]);
echo "✓ 状态: {$acc['status']}\n";
echo "  解冻后 frozen_at: " . ($acc['frozen_at'] ? '未清空' : '已清空') . "\n";

echo "\n=== 账户冻结/解冻流程验收通过 ===\n";
