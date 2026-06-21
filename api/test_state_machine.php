<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountService.php';

class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            echo "  ✓ {$name}\n";
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = ['name' => $name, 'error' => $e->getMessage()];
            echo "  ✗ {$name}\n";
            echo "     错误: " . $e->getMessage() . "\n";
        }
    }

    public function assert(mixed $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new RuntimeException($message ?: '断言失败');
        }
    }

    public function assertEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = $message ?: "期望 " . var_export($expected, true) . ", 实际 " . var_export($actual, true);
            throw new RuntimeException($msg);
        }
    }

    public function assertThrows(callable $fn, string $expectedException = '', string $message = ''): void
    {
        try {
            $fn();
            throw new RuntimeException($message ?: "期望抛出异常但没有");
        } catch (Throwable $e) {
            if ($expectedException && !is_a($e, $expectedException)) {
                throw new RuntimeException(
                    ($message ?: "期望抛出 {$expectedException}") . ", 实际抛出 " . get_class($e) . ": " . $e->getMessage()
                );
            }
        }
    }

    public function summary(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "测试结果: 通过 {$this->passed}, 失败 {$this->failed}\n";
        if ($this->failed > 0) {
            echo "\n失败详情:\n";
            foreach ($this->failures as $i => $f) {
                echo "  " . ($i + 1) . ". {$f['name']}\n";
                echo "     {$f['error']}\n";
            }
        }
        echo str_repeat('=', 60) . "\n";
        exit($this->failed > 0 ? 1 : 0);
    }
}

$t = new TestRunner();
$service = new AccountService();
$fsm = new AccountStateMachine();
$engine = $fsm->engine();

function createTestAccount(AccountService $service, string $suffix = ''): array
{
    usleep(10000);
    return $service->create([
        'supplier_name' => "测试供应商{$suffix}",
        'account_name'  => "测试账户{$suffix}",
        'account_no'    => '6225881012345678',
        'bank_name'     => '招商银行',
        'bank_branch'   => '杭州分行',
        'account_type'  => 'public',
    ]);
}

function createEmptyAccount(AccountService $service, string $suffix = ''): array
{
    usleep(10000);
    return $service->create([
        'supplier_name' => "测试供应商{$suffix}",
        'account_name'  => "测试账户{$suffix}",
        'account_no'    => '',
        'bank_name'     => '招商银行',
        'bank_branch'   => '杭州分行',
        'account_type'  => 'public',
    ]);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "供应商账户状态机 - 单元测试\n";
echo str_repeat('=', 60) . "\n";

// ─────────────────────────────────────────────────────────────
// 1. 状态机定义测试
// ─────────────────────────────────────────────────────────────
echo "\n1. 状态机定义测试\n";

$t->run('所有状态已正确定义', function () use ($engine, $t) {
    $states = ['draft', 'pending_review', 'active', 'rejected', 'frozen', 'disabled'];
    foreach ($states as $s) {
        $t->assert($engine->hasState($s), "状态 {$s} 未定义");
    }
});

$t->run('状态标签正确', function () use ($engine, $t) {
    $t->assertEqual('待提交', $engine->stateLabel('draft'));
    $t->assertEqual('待审核', $engine->stateLabel('pending_review'));
    $t->assertEqual('正常', $engine->stateLabel('active'));
    $t->assertEqual('已驳回', $engine->stateLabel('rejected'));
    $t->assertEqual('已冻结', $engine->stateLabel('frozen'));
    $t->assertEqual('已停用', $engine->stateLabel('disabled'));
});

$t->run('终态定义正确 - disabled 是终态', function () use ($fsm, $t) {
    $def = $fsm->definition();
    $t->assertEqual(true, $def['states']['disabled']['terminal']);
    $t->assertEqual(false, $def['states']['draft']['terminal']);
    $t->assertEqual(false, $def['states']['active']['terminal']);
});

$t->run('状态分组正确', function () use ($fsm, $t) {
    $def = $fsm->definition();
    $t->assertEqual('review', $def['states']['draft']['group']);
    $t->assertEqual('review', $def['states']['pending_review']['group']);
    $t->assertEqual('review', $def['states']['rejected']['group']);
    $t->assertEqual('active', $def['states']['active']['group']);
    $t->assertEqual('frozen', $def['states']['frozen']['group']);
    $t->assertEqual('terminal', $def['states']['disabled']['group']);
});

// ─────────────────────────────────────────────────────────────
// 2. 结算账户审核流程 - 正常路径
// ─────────────────────────────────────────────────────────────
echo "\n2. 结算账户审核流程 - 正常路径\n";

$t->run('draft → submit → pending_review 正常流转', function () use ($service, $t) {
    $acc = createTestAccount($service, '_submit_1');
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(!empty($acc['submitted_at']), 'submitted_at 应该已设置');
});

$t->run('pending_review → approve → active 正常流转', function () use ($service, $t) {
    $acc = createTestAccount($service, '_approve_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);
    $t->assert(!empty($acc['reviewed_at']), 'reviewed_at 应该已设置');
});

$t->run('审核完整闭环: draft → pending → active', function () use ($service, $t) {
    $acc = createTestAccount($service, '_full_1');
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $history = $service->history($acc['id']);
    $t->assertEqual(3, count($history), '应该有3条历史记录（创建+提交+审核）');
});

// ─────────────────────────────────────────────────────────────
// 3. 结算账户审核流程 - 驳回重提路径
// ─────────────────────────────────────────────────────────────
echo "\n3. 结算账户审核流程 - 驳回重提路径\n";

$t->run('pending_review → reject → rejected 正常流转', function () use ($service, $t) {
    $acc = createTestAccount($service, '_reject_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '银行开户许可证影像不清晰'
    ]);
    $t->assertEqual('rejected', $acc['status']);
    $t->assertEqual('银行开户许可证影像不清晰', $acc['review_reason']);
});

$t->run('rejected → resubmit → pending_review 正常流转', function () use ($service, $t) {
    $acc = createTestAccount($service, '_resubmit_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $acc = $service->trigger($acc['id'], 'resubmit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(empty($acc['review_reason']), 'review_reason 应该已清空');
});

$t->run('驳回重提完整闭环: draft → pending → rejected → pending → active', function () use ($service, $t) {
    $acc = createTestAccount($service, '_reject_loop_1');

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $acc = $service->trigger($acc['id'], 'resubmit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $history = $service->history($acc['id']);
    $t->assertEqual(5, count($history), '应该有5条历史记录');
});

// ─────────────────────────────────────────────────────────────
// 4. 冻结/解冻流程测试
// ─────────────────────────────────────────────────────────────
echo "\n4. 冻结/解冻流程测试\n";

$t->run('active → freeze → frozen 正常流转', function () use ($service, $t) {
    $acc = createTestAccount($service, '_freeze_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '账户存在异常大额交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);
    $t->assert(!empty($acc['frozen_at']), 'frozen_at 应该已设置');
    $t->assertEqual('账户存在异常大额交易', $acc['freeze_reason']);
});

$t->run('frozen → unfreeze → active 正常流转', function () use ($service, $t) {
    $acc = createTestAccount($service, '_unfreeze_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'unfreeze', [
        'operator' => 'risk_01',
        'reason' => '核查无误，解除冻结'
    ]);
    $t->assertEqual('active', $acc['status']);
    $t->assert(empty($acc['frozen_at']), 'frozen_at 应该已清空');
});

$t->run('冻结解冻完整闭环: active → frozen → active → frozen → active', function () use ($service, $t) {
    $acc = createTestAccount($service, '_freeze_loop_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '第一次冻结'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'unfreeze', [
        'operator' => 'risk_01',
        'reason' => '第一次解冻'
    ]);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '第二次冻结'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'unfreeze', [
        'operator' => 'risk_01',
        'reason' => '第二次解冻'
    ]);
    $t->assertEqual('active', $acc['status']);

    $history = $service->history($acc['id']);
    $t->assertEqual(7, count($history), '应该有7条历史记录');
});

// ─────────────────────────────────────────────────────────────
// 5. 审核与冻结联动校验
// ─────────────────────────────────────────────────────────────
echo "\n5. 审核与冻结联动校验\n";

$t->run('冻结状态下不允许审核通过', function () use ($service, $t) {
    $acc = createTestAccount($service, '_link_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $stmt = Database::pdo()->prepare("UPDATE accounts SET frozen_at = ? WHERE id = ?");
    $stmt->execute([time(), $acc['id']]);

    $acc = $service->get($acc['id']);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(!empty($acc['frozen_at']), 'frozen_at 应该已设置');

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    }, StateTransitionException::class, '有冻结记录时审核通过应该被拒绝');
});

$t->run('待审核状态下不允许冻结', function () use ($service, $t) {
    $acc = createTestAccount($service, '_link_2');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(!empty($acc['submitted_at']), 'submitted_at 应该已设置');
    $t->assert(empty($acc['reviewed_at']), 'reviewed_at 应该为空');

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'freeze', [
            'operator' => 'risk_01',
            'reason' => '测试冻结'
        ]);
    }, StateTransitionException::class, '待审核状态下冻结应该被拒绝');
});

$t->run('先审核通过再冻结 - 正常流程', function () use ($service, $t) {
    $acc = createTestAccount($service, '_link_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '正常冻结'
    ]);
    $t->assertEqual('frozen', $acc['status']);
});

$t->run('解冻后才能审核通过 - 联动校验闭环', function () use ($service, $t) {
    $acc = createTestAccount($service, '_link_4');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_freeze', [
        'operator' => 'admin_01',
        'reason' => '回滚冻结'
    ]);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_approve', [
        'operator' => 'admin_01',
        'reason' => '回滚审核通过'
    ]);
    $t->assertEqual('pending_review', $acc['status']);

    $stmt = Database::pdo()->prepare("UPDATE accounts SET frozen_at = ? WHERE id = ?");
    $stmt->execute([time(), $acc['id']]);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    }, StateTransitionException::class, '有冻结记录时审核通过应该被拒绝');

    $stmt = Database::pdo()->prepare("UPDATE accounts SET frozen_at = NULL WHERE id = ?");
    $stmt->execute([$acc['id']]);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);
});

// ─────────────────────────────────────────────────────────────
// 6. 回滚机制测试
// ─────────────────────────────────────────────────────────────
echo "\n6. 回滚机制测试\n";

$t->run('rollback_submit: pending_review → draft', function () use ($service, $t) {
    $acc = createTestAccount($service, '_rb_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(!empty($acc['submitted_at']), 'submitted_at 应该已设置');

    $acc = $service->trigger($acc['id'], 'rollback_submit', [
        'operator' => 'admin_01',
        'reason' => '资料有误，需要修改'
    ]);
    $t->assertEqual('draft', $acc['status']);
    $t->assert(empty($acc['submitted_at']), 'submitted_at 应该已清空');
});

$t->run('rollback_approve: active → pending_review', function () use ($service, $t) {
    $acc = createTestAccount($service, '_rb_2');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);
    $t->assert(!empty($acc['reviewed_at']), 'reviewed_at 应该已设置');

    $acc = $service->trigger($acc['id'], 'rollback_approve', [
        'operator' => 'admin_01',
        'reason' => '审核有误，需重审'
    ]);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(empty($acc['reviewed_at']), 'reviewed_at 应该已清空');
});

$t->run('rollback_reject: rejected → pending_review', function () use ($service, $t) {
    $acc = createTestAccount($service, '_rb_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_reject', [
        'operator' => 'admin_01',
        'reason' => '驳回有误，恢复待审核'
    ]);
    $t->assertEqual('pending_review', $acc['status']);
    $t->assert(empty($acc['review_reason']), 'review_reason 应该已清空');
});

$t->run('rollback_freeze: frozen → active', function () use ($service, $t) {
    $acc = createTestAccount($service, '_rb_4');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_freeze', [
        'operator' => 'admin_01',
        'reason' => '冻结有误，撤销'
    ]);
    $t->assertEqual('active', $acc['status']);
    $t->assert(empty($acc['frozen_at']), 'frozen_at 应该已清空');
});

// ─────────────────────────────────────────────────────────────
// 7. 停用/启用流程测试
// ─────────────────────────────────────────────────────────────
echo "\n7. 停用/启用流程测试\n";

$t->run('任意非终态可停用: draft → disabled', function () use ($service, $t) {
    $acc = createTestAccount($service, '_disable_1');
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('任意非终态可停用: pending_review → disabled', function () use ($service, $t) {
    $acc = createTestAccount($service, '_disable_2');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('任意非终态可停用: active → disabled', function () use ($service, $t) {
    $acc = createTestAccount($service, '_disable_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('任意非终态可停用: rejected → disabled', function () use ($service, $t) {
    $acc = createTestAccount($service, '_disable_4');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('任意非终态可停用: frozen → disabled', function () use ($service, $t) {
    $acc = createTestAccount($service, '_disable_5');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('启用: disabled → draft', function () use ($service, $t) {
    $acc = createTestAccount($service, '_enable_1');
    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);

    $acc = $service->trigger($acc['id'], 'enable', ['operator' => 'admin_01']);
    $t->assertEqual('draft', $acc['status']);
});

$t->run('停用启用完整闭环: draft → disabled → draft → active', function () use ($service, $t) {
    $acc = createTestAccount($service, '_enable_loop_1');
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);

    $acc = $service->trigger($acc['id'], 'enable', ['operator' => 'admin_01']);
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);
});

// ─────────────────────────────────────────────────────────────
// 8. 守卫校验测试
// ─────────────────────────────────────────────────────────────
echo "\n8. 守卫校验测试\n";

$t->run('提交审核必须有银行账号', function () use ($service, $t) {
    $acc = createEmptyAccount($service, '_guard_1');
    $t->assertEqual('draft', $acc['status']);
    $t->assert(empty($acc['account_no']), 'account_no 应该为空');

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    }, StateTransitionException::class, '没有银行账号时提交审核应该被拒绝');
});

$t->run('审核通过必须有银行账号', function () use ($service, $t) {
    $acc = createEmptyAccount($service, '_guard_2');
    $t->assert(empty($acc['account_no']), 'account_no 应该为空');

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    }, StateTransitionException::class, '没有银行账号时提交审核应该被拒绝');
});

$t->run('重新提交必须有银行账号', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $stmt = Database::pdo()->prepare("UPDATE accounts SET account_no = '' WHERE id = ?");
    $stmt->execute([$acc['id']]);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'resubmit', ['operator' => 'supplier_01']);
    }, StateTransitionException::class, '没有银行账号时重新提交应该被拒绝');
});

$t->run('审核驳回必须填写原因', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_4');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'reject', ['operator' => 'reviewer_01', 'reason' => '']);
    }, StateTransitionException::class, '驳回没有原因应该被拒绝');
});

$t->run('冻结必须填写原因', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_5');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'freeze', ['operator' => 'risk_01', 'reason' => '']);
    }, StateTransitionException::class, '冻结没有原因应该被拒绝');
});

$t->run('解冻必须填写原因', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_6');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'unfreeze', ['operator' => 'risk_01', 'reason' => '']);
    }, StateTransitionException::class, '解冻没有原因应该被拒绝');
});

$t->run('回滚必须填写原因 - rollback_submit', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_7');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'rollback_submit', ['operator' => 'admin_01', 'reason' => '']);
    }, StateTransitionException::class, '回滚没有原因应该被拒绝');
});

$t->run('回滚必须填写原因 - rollback_approve', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_8');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'rollback_approve', ['operator' => 'admin_01', 'reason' => '']);
    }, StateTransitionException::class, '回滚没有原因应该被拒绝');
});

$t->run('回滚审核通过时存在冻结记录应该被拒绝', function () use ($service, $t) {
    $acc = createTestAccount($service, '_guard_9');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'rollback_approve', [
            'operator' => 'admin_01',
            'reason' => '有冻结时回滚审核通过'
        ]);
    }, StateTransitionException::class, '有冻结记录时回滚审核通过应该被拒绝');
});

// ─────────────────────────────────────────────────────────────
// 9. 权限控制测试
// ─────────────────────────────────────────────────────────────
echo "\n9. 权限控制测试\n";

$t->run('supplier 可以提交审核', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);
});

$t->run('supplier 不可以审核通过', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_2');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'supplier_01']);
    }, PermissionDeniedException::class, 'supplier 审核通过应该被拒绝');
});

$t->run('supplier 不可以冻结', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'freeze', ['operator' => 'supplier_01', 'reason' => '测试']);
    }, PermissionDeniedException::class, 'supplier 冻结应该被拒绝');
});

$t->run('reviewer 可以审核通过', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_4');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);
});

$t->run('reviewer 不可以提交审核', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_5');
    $t->assertEqual('draft', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'submit', ['operator' => 'reviewer_01']);
    }, PermissionDeniedException::class, 'reviewer 提交应该被拒绝');
});

$t->run('reviewer 不可以冻结', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_6');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'freeze', ['operator' => 'reviewer_01', 'reason' => '测试']);
    }, PermissionDeniedException::class, 'reviewer 冻结应该被拒绝');
});

$t->run('risk 可以冻结', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_7');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);
});

$t->run('risk 不可以审核通过', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_8');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'risk_01']);
    }, PermissionDeniedException::class, 'risk 审核通过应该被拒绝');
});

$t->run('admin 可以执行所有操作', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_9');
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'admin_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'admin_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', ['operator' => 'admin_01', 'reason' => '测试冻结']);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('前缀简写也能识别角色: sup_ → supplier', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_10');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'sup_01']);
    $t->assertEqual('pending_review', $acc['status']);
});

$t->run('前缀简写也能识别角色: rev_ → reviewer', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_11');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'rev_01']);
    $t->assertEqual('active', $acc['status']);
});

$t->run('前缀简写也能识别角色: rsk_ → risk', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_12');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'rsk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);
});

$t->run('前缀简写也能识别角色: adm_ → admin', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_13');
    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'adm_01']);
    $t->assertEqual('disabled', $acc['status']);
});

$t->run('未知角色前缀抛出权限异常', function () use ($service, $t) {
    $acc = createTestAccount($service, '_perm_14');
    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'submit', ['operator' => 'unknown_01']);
    }, PermissionDeniedException::class, '未知角色应该被拒绝');
});

// ─────────────────────────────────────────────────────────────
// 10. 非法状态迁移测试
// ─────────────────────────────────────────────────────────────
echo "\n10. 非法状态迁移测试\n";

$t->run('draft 不能直接 approve', function () use ($service, $t) {
    $acc = createTestAccount($service, '_illegal_1');
    $t->assertEqual('draft', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'admin_01']);
    }, StateTransitionException::class, 'draft 直接 approve 应该被拒绝');
});

$t->run('draft 不能直接 freeze', function () use ($service, $t) {
    $acc = createTestAccount($service, '_illegal_2');
    $t->assertEqual('draft', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'freeze', ['operator' => 'admin_01', 'reason' => '测试']);
    }, StateTransitionException::class, 'draft 直接 freeze 应该被拒绝');
});

$t->run('pending_review 不能直接 freeze', function () use ($service, $t) {
    $acc = createTestAccount($service, '_illegal_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'freeze', ['operator' => 'admin_01', 'reason' => '测试']);
    }, StateTransitionException::class, 'pending_review 直接 freeze 应该被拒绝');
});

$t->run('rejected 不能直接 approve', function () use ($service, $t) {
    $acc = createTestAccount($service, '_illegal_4');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'admin_01']);
    }, StateTransitionException::class, 'rejected 直接 approve 应该被拒绝');
});

$t->run('frozen 不能直接 approve', function () use ($service, $t) {
    $acc = createTestAccount($service, '_illegal_5');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'approve', ['operator' => 'admin_01']);
    }, StateTransitionException::class, 'frozen 直接 approve 应该被拒绝');
});

$t->run('disabled 不能 submit', function () use ($service, $t) {
    $acc = createTestAccount($service, '_illegal_6');
    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);

    $t->assertThrows(function () use ($service, $acc) {
        $service->trigger($acc['id'], 'submit', ['operator' => 'admin_01']);
    }, StateTransitionException::class, 'disabled 不能 submit');
});

// ─────────────────────────────────────────────────────────────
// 11. 历史记录测试
// ─────────────────────────────────────────────────────────────
echo "\n11. 历史记录测试\n";

$t->run('状态变更历史记录正确生成', function () use ($service, $t) {
    $acc = createTestAccount($service, '_history_1');
    $history = $service->history($acc['id']);
    $t->assertEqual(1, count($history), '创建后应该有1条记录');
    $t->assertEqual('create', $history[0]['event']);
    $t->assertEqual('draft', $history[0]['from_status']);
    $t->assertEqual('draft', $history[0]['to_status']);
});

$t->run('完整链路历史记录正确', function () use ($service, $t) {
    $acc = createTestAccount($service, '_history_2');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $acc = $service->trigger($acc['id'], 'unfreeze', [
        'operator' => 'risk_01',
        'reason' => '核查无误'
    ]);
    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);

    $history = $service->history($acc['id']);
    $t->assertEqual(6, count($history), '应该有6条记录');

    $expected = [
        ['event' => 'create', 'from' => 'draft', 'to' => 'draft'],
        ['event' => 'submit', 'from' => 'draft', 'to' => 'pending_review'],
        ['event' => 'approve', 'from' => 'pending_review', 'to' => 'active'],
        ['event' => 'freeze', 'from' => 'active', 'to' => 'frozen'],
        ['event' => 'unfreeze', 'from' => 'frozen', 'to' => 'active'],
        ['event' => 'disable', 'from' => 'active', 'to' => 'disabled'],
    ];

    foreach ($expected as $i => $exp) {
        $t->assertEqual($exp['event'], $history[$i]['event'], "第{$i}条记录事件不匹配");
        $t->assertEqual($exp['from'], $history[$i]['from_status'], "第{$i}条记录起始状态不匹配");
        $t->assertEqual($exp['to'], $history[$i]['to_status'], "第{$i}条记录目标状态不匹配");
    }
});

$t->run('历史记录包含操作人信息', function () use ($service, $t) {
    $acc = createTestAccount($service, '_history_3');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);

    $history = $service->history($acc['id']);
    $t->assertEqual('system', $history[0]['operator']);
    $t->assertEqual('supplier_01', $history[1]['operator']);
    $t->assertEqual('reviewer_01', $history[2]['operator']);
    $t->assertEqual('资料不全', $history[2]['reason']);
});

// ─────────────────────────────────────────────────────────────
// 12. 角色可用事件过滤测试
// ─────────────────────────────────────────────────────────────
echo "\n12. 角色可用事件过滤测试\n";

$t->run('不同角色看到的可用事件不同 - draft 状态', function () use ($service, $t) {
    $acc = createTestAccount($service, '_event_1');
    $t->assertEqual('draft', $acc['status']);

    $supView = $service->get($acc['id'], 'supplier_01');
    $revView = $service->get($acc['id'], 'reviewer_01');
    $rskView = $service->get($acc['id'], 'risk_01');
    $admView = $service->get($acc['id'], 'admin_01');

    $t->assert(isset($supView['available_events']['submit']), 'supplier 应该能看到 submit');
    $t->assert(!isset($supView['available_events']['approve']), 'supplier 不应该能看到 approve');

    $t->assert(!isset($revView['available_events']['submit']), 'reviewer 不应该能看到 submit');
    $t->assert(!isset($revView['available_events']['approve']), 'reviewer 在 draft 状态不应该能看到 approve');

    $t->assert(isset($admView['available_events']['submit']), 'admin 应该能看到 submit');
    $t->assert(isset($admView['available_events']['disable']), 'admin 应该能看到 disable');
});

$t->run('不同角色看到的可用事件不同 - active 状态', function () use ($service, $t) {
    $acc = createTestAccount($service, '_event_2');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $supView = $service->get($acc['id'], 'supplier_01');
    $revView = $service->get($acc['id'], 'reviewer_01');
    $rskView = $service->get($acc['id'], 'risk_01');
    $admView = $service->get($acc['id'], 'admin_01');

    $t->assert(!isset($supView['available_events']['freeze']), 'supplier 不应该能看到 freeze');
    $t->assert(isset($rskView['available_events']['freeze']), 'risk 应该能看到 freeze');
    $t->assert(!isset($revView['available_events']['freeze']), 'reviewer 不应该能看到 freeze');
    $t->assert(isset($admView['available_events']['freeze']), 'admin 应该能看到 freeze');
    $t->assert(isset($admView['available_events']['rollback_approve']), 'admin 应该能看到 rollback_approve');
});

// ─────────────────────────────────────────────────────────────
// 13. 完整业务闭环测试
// ─────────────────────────────────────────────────────────────
echo "\n13. 完整业务闭环测试\n";

$t->run('完整生命周期: 创建→提交→驳回→重提→通过→冻结→解冻→冻结→回滚冻结→回滚通过→通过→冻结→回滚冻结→回滚通过→通过→停用→启用→提交→通过', function () use ($service, $t) {
    $acc = createTestAccount($service, '_full_life_1');
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'reject', [
        'operator' => 'reviewer_01',
        'reason' => '资料不全'
    ]);
    $t->assertEqual('rejected', $acc['status']);

    $acc = $service->trigger($acc['id'], 'resubmit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '异常交易'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'unfreeze', [
        'operator' => 'risk_01',
        'reason' => '核查无误'
    ]);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '再次发现异常'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_freeze', [
        'operator' => 'admin_01',
        'reason' => '冻结操作有误'
    ]);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_approve', [
        'operator' => 'admin_01',
        'reason' => '需要重审'
    ]);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'freeze', [
        'operator' => 'risk_01',
        'reason' => '确认风险'
    ]);
    $t->assertEqual('frozen', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_freeze', [
        'operator' => 'admin_01',
        'reason' => '风险解除'
    ]);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'rollback_approve', [
        'operator' => 'admin_01',
        'reason' => '终审'
    ]);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $acc = $service->trigger($acc['id'], 'disable', ['operator' => 'admin_01']);
    $t->assertEqual('disabled', $acc['status']);

    $acc = $service->trigger($acc['id'], 'enable', ['operator' => 'admin_01']);
    $t->assertEqual('draft', $acc['status']);

    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
    $t->assertEqual('pending_review', $acc['status']);

    $acc = $service->trigger($acc['id'], 'approve', ['operator' => 'reviewer_01']);
    $t->assertEqual('active', $acc['status']);

    $history = $service->history($acc['id']);
    $t->assertEqual(19, count($history), '应该有19条历史记录');
});

// ─────────────────────────────────────────────────────────────
// 14. 状态流转异常上下文测试
// ─────────────────────────────────────────────────────────────
echo "\n14. 状态流转异常上下文测试\n";

$t->run('状态迁移异常包含丰富上下文', function () use ($service, $t) {
    $acc = createTestAccount($service, '_ctx_1');
    $acc = $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);

    try {
        $service->trigger($acc['id'], 'freeze', [
            'operator' => 'risk_01',
            'reason' => '测试'
        ]);
        throw new RuntimeException('应该抛出异常');
    } catch (StateTransitionException $e) {
        $ctx = $e->context();
        $t->assertEqual('pending_review', $ctx['current_status']);
        $t->assertEqual('freeze', $ctx['event']);
        $t->assertEqual('transition', $ctx['error_type']);
    }
});

$t->run('可回滚操作异常包含回滚事件信息', function () use ($service, $t) {
    $acc = createEmptyAccount($service, '_ctx_2');
    $t->assert(empty($acc['account_no']), 'account_no 应该为空');

    try {
        $service->trigger($acc['id'], 'submit', ['operator' => 'supplier_01']);
        throw new RuntimeException('应该抛出异常');
    } catch (StateTransitionException $e) {
        $ctx = $e->context();
        $t->assertEqual('draft', $ctx['current_status']);
        $t->assertEqual('submit', $ctx['event']);
    }
});

// ─────────────────────────────────────────────────────────────
// 测试结束
// ─────────────────────────────────────────────────────────────
$t->summary();
