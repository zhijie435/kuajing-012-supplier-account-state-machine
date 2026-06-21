<?php

declare(strict_types=1);

/**
 * 生成演示数据：覆盖各状态的结算账户与完整操作链路。
 * 执行：php api/seed.php
 */

require_once __DIR__ . '/AccountService.php';

$service = new AccountService();

function create(AccountService $s, array $data): array
{
    return $s->create($data);
}

$demo = [
    [
        'supplier_name' => '杭州数智贸易股份有限公司',
        'account_name'  => '杭州数智贸易有限公司',
        'account_no'    => '6225881012345678',
        'bank_name'     => '招商银行',
        'bank_branch'   => '杭州分行营业部',
        'account_type'  => 'public',
    ],
    [
        'supplier_name' => '深圳市科创材料科技有限公司',
        'account_name'  => '深圳市科创材料科技有限公司',
        'account_no'    => '6227002029901234567',
        'bank_name'     => '建设银行',
        'bank_branch'   => '深圳科技园支行',
        'account_type'  => 'public',
    ],
    [
        'supplier_name' => '上海绿源食品有限公司',
        'account_name'  => '上海绿源食品有限公司',
        'account_no'    => '6228480402567890123',
        'bank_name'     => '农业银行',
        'bank_branch'   => '上海浦东支行',
        'account_type'  => 'public',
    ],
    [
        'supplier_name' => '北京云图信息技术中心',
        'account_name'  => '北京云图信息技术中心',
        'account_no'    => '',
        'bank_name'     => '工商银行',
        'bank_branch'   => '北京中关村支行',
        'account_type'  => 'private',
    ],
    [
        'supplier_name' => '广州南方物流有限公司',
        'account_name'  => '广州南方物流有限公司',
        'account_no'    => '6217003812345678',
        'bank_name'     => '中国银行',
        'bank_branch'   => '广州天河支行',
        'account_type'  => 'public',
    ],
];

foreach ($demo as $data) {
    create($service, $data);
}

// 推动账户进入不同状态，演示完整链路
// 1) 数智贸易：提交 -> 审核通过 -> 正常
$service->trigger(1, 'submit', ['operator' => 'supplier']);
$service->trigger(1, 'approve', ['operator' => 'reviewer-01']);

// 2) 科创材料：提交 -> 审核通过 -> 正常 -> 冻结
$service->trigger(2, 'submit', ['operator' => 'supplier']);
$service->trigger(2, 'approve', ['operator' => 'reviewer-01']);
$service->trigger(2, 'freeze', ['operator' => 'risk-01', 'reason' => '账户存在异常大额交易，待风控核查']);

// 3) 绿源食品：提交 -> 审核驳回（资料不全）
$service->trigger(3, 'submit', ['operator' => 'supplier']);
$service->trigger(3, 'reject', ['operator' => 'reviewer-02', 'reason' => '银行开户许可证影像不清晰，请重新上传']);

// 4) 云图信息：待提交（草稿，未填账号）
// 5) 南方物流：提交 -> 待审核
$service->trigger(5, 'submit', ['operator' => 'supplier']);

echo "已生成 5 个演示账户，覆盖 draft / pending_review / active / frozen / rejected 状态。\n";
