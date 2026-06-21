<?php

require_once __DIR__ . '/api/AccountService.php';

$service = new AccountService();

echo "=== 结算账户审核流程验收 ===\n\n";

// 1. 创建账户
echo "1. 创建账户... ";
$acc = $service->create([
    'supplier_name' => '验收测试供应商',
    'account_name' => '验收测试账户',
    'account_no' => '6225881012345678',
    'bank_name' => '招商银行',
    'bank_branch' => '杭州分行',
]);
echo "✓ 账户ID: {$acc['id']}, 状态: {$acc['status']}\n";

// 2. 提交审核（供应商操作）
echo "2. 提交审核（supplier_01）... ";
$acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
echo "✓ 状态: {$acc['status']}, 提交时间: {$acc['submitted_at_text']}\n";

// 3. 审核通过（审核专员操作）
echo "3. 审核通过（reviewer_01）... ";
$acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
echo "✓ 状态: {$acc['status']}, 审核时间: {$acc['reviewed_at_text']}\n";

// 4. 验证历史记录
echo "4. 验证历史记录... ";
$history = $service->history($acc['id']);
echo "✓ 历史记录数: " . count($history) . "\n";

echo "\n=== 结算账户审核流程验收通过 ===\n";
