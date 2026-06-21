<?php

declare(strict_types=1);

require_once __DIR__ . '/StateMachine.php';

/**
 * 供应商结算账户状态机定义
 *
 * 业务领域：供应商在平台上的"结算账户"生命周期管理。
 * 重点沉淀两大能力：
 *   1. 结算账户审核（提交 -> 审核 -> 通过/驳回 -> 重新提交）
 *   2. 账户冻结/解冻（正常 <-> 冻结，并可随时停用）
 *
 * 状态流转图：
 *
 *   draft ──submit──▶ pending_review ──approve──▶ active ──freeze──▶ frozen
 *                          │                        │                  │
 *                        reject                  disable            unfreeze
 *                          ▼                        ▼                  │
 *                       rejected ──resubmit──▶ pending_review        active
 *                          │
 *                        disable
 *                          ▼
 *                       disabled ◀──disable── (任意非终态)
 *                          │
 *                        enable
 *                          ▼
 *                        draft
 */
final class AccountStateMachine
{
    public const S_DRAFT          = 'draft';
    public const S_PENDING       = 'pending_review';
    public const S_ACTIVE        = 'active';
    public const S_REJECTED      = 'rejected';
    public const S_FROZEN        = 'frozen';
    public const S_DISABLED      = 'disabled';

    public const E_SUBMIT        = 'submit';
    public const E_APPROVE       = 'approve';
    public const E_REJECT        = 'reject';
    public const E_RESUBMIT      = 'resubmit';
    public const E_FREEZE        = 'freeze';
    public const E_UNFREEZE      = 'unfreeze';
    public const E_DISABLE       = 'disable';
    public const E_ENABLE        = 'enable';

    private StateMachine $sm;

    public function __construct()
    {
        $sm = new StateMachine();

        $sm->addState(self::S_DRAFT,     '待提交',   'review', false);
        $sm->addState(self::S_PENDING,    '待审核',   'review', false);
        $sm->addState(self::S_ACTIVE,    '正常',     'active', false);
        $sm->addState(self::S_REJECTED,   '已驳回',   'review', false);
        $sm->addState(self::S_FROZEN,     '已冻结',   'frozen', false);
        $sm->addState(self::S_DISABLED,   '已停用',   'terminal', true);

        $sm->addEvent(self::E_SUBMIT,   '提交审核');
        $sm->addEvent(self::E_APPROVE,  '审核通过');
        $sm->addEvent(self::E_REJECT,   '审核驳回');
        $sm->addEvent(self::E_RESUBMIT, '重新提交');
        $sm->addEvent(self::E_FREEZE,   '冻结账户');
        $sm->addEvent(self::E_UNFREEZE, '解冻账户');
        $sm->addEvent(self::E_DISABLE,  '停用账户');
        $sm->addEvent(self::E_ENABLE,   '重新启用');

        // —— 结算账户审核主流程 ——
        $sm->addTransition(self::E_SUBMIT,   self::S_DRAFT,   self::S_PENDING);
        $sm->addTransition(self::E_APPROVE,  self::S_PENDING, self::S_ACTIVE, function ($ctx) {
            if (empty($ctx['account']['account_no'])) {
                return ['ok' => false, 'message' => '审核通过前必须填写结算银行账号'];
            }
            return ['ok' => true, 'message' => 'ok'];
        });
        $sm->addTransition(self::E_REJECT,   self::S_PENDING, self::S_REJECTED, function ($ctx) {
            if (empty($ctx['reason'])) {
                return ['ok' => false, 'message' => '驳回必须填写驳回原因'];
            }
            return ['ok' => true, 'message' => 'ok'];
        });
        $sm->addTransition(self::E_RESUBMIT, self::S_REJECTED, self::S_PENDING, function ($ctx) {
            if (empty($ctx['account']['account_no'])) {
                return ['ok' => false, 'message' => '重新提交前必须补全结算银行账号'];
            }
            return ['ok' => true, 'message' => 'ok'];
        });

        // —— 冻结 / 解冻 ——
        $sm->addTransition(self::E_FREEZE,   self::S_ACTIVE, self::S_FROZEN, function ($ctx) {
            if (empty($ctx['reason'])) {
                return ['ok' => false, 'message' => '冻结必须填写冻结原因'];
            }
            return ['ok' => true, 'message' => 'ok'];
        });
        $sm->addTransition(self::E_UNFREEZE, self::S_FROZEN, self::S_ACTIVE, function ($ctx) {
            if (empty($ctx['reason'])) {
                return ['ok' => false, 'message' => '解冻必须填写处理说明'];
            }
            return ['ok' => true, 'message' => 'ok'];
        });

        // —— 停用（任意非终态可停用）——
        foreach ([self::S_DRAFT, self::S_PENDING, self::S_ACTIVE, self::S_REJECTED, self::S_FROZEN] as $from) {
            $sm->addTransition(self::E_DISABLE, $from, self::S_DISABLED);
        }
        $sm->addTransition(self::E_ENABLE, self::S_DISABLED, self::S_DRAFT);

        $this->sm = $sm;
    }

    public function engine(): StateMachine
    {
        return $this->sm;
    }

    public function definition(): array
    {
        return $this->sm->definition();
    }
}
