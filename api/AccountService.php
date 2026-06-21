<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/StateMachine.php';
require_once __DIR__ . '/AccountStateMachine.php';

/**
 * 领域异常：状态机不允许的迁移或资源不存在时抛出。
 * 由路由层（index.php）捕获并翻译为 HTTP 响应，保持服务层与传输解耦。
 */
class StateException extends RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function context(): array
    {
        return [];
    }
}

/**
 * 权限不足异常：当操作人角色无权执行指定操作时抛出（HTTP 403）。
 */
final class PermissionDeniedException extends StateException
{
    public function __construct(
        string $message,
        private string $requiredRole = '',
        private string $currentRole = ''
    ) {
        parent::__construct($message, 403);
    }

    public function context(): array
    {
        return [
            'required_role' => $this->requiredRole,
            'current_role' => $this->currentRole,
            'error_type' => 'permission',
        ];
    }
}

/**
 * 状态迁移异常：包含更丰富的上下文信息，
 * 用于前端展示回滚提示和重试入口。
 */
final class StateTransitionException extends StateException
{
    public function __construct(
        string $message,
        int $status = 422,
        private string $currentStatus = '',
        private string $event = '',
        private ?string $rollbackEvent = null,
        private bool $canRollback = false,
        private bool $retryable = false
    ) {
        parent::__construct($message, $status);
    }

    public function context(): array
    {
        return [
            'current_status' => $this->currentStatus,
            'event' => $this->event,
            'rollback_event' => $this->rollbackEvent,
            'can_rollback' => $this->canRollback,
            'retryable' => $this->retryable,
            'error_type' => 'transition',
        ];
    }
}

/**
 * 操作角色定义：
 * - supplier    供应商运营：可提交/重新提交审核资料
 * - reviewer    审核专员：可审核通过/驳回
 * - risk        风控专员：可冻结/解冻账户
 * - admin       系统管理员：可停用/启用账户，以及所有回滚操作
 */
final class AccountRole
{
    public const SUPPLIER = 'supplier';
    public const REVIEWER = 'reviewer';
    public const RISK     = 'risk';
    public const ADMIN    = 'admin';

    public const LABELS = [
        self::SUPPLIER => '供应商运营',
        self::REVIEWER => '审核专员',
        self::RISK     => '风控专员',
        self::ADMIN    => '系统管理员',
    ];

    public static function all(): array
    {
        return [self::SUPPLIER, self::REVIEWER, self::RISK, self::ADMIN];
    }
}

/**
 * 供应商结算账户服务：在 StateMachine 引擎之上叠加持久化与审计日志。
 * 所有状态变更都会落入 state_transitions 表，形成完整的操作链路。
 */
final class AccountService
{
    private AccountStateMachine $fsm;
    private PDO $pdo;

    /** @var array<string, list<string>> 事件 => 允许的角色列表 */
    private array $eventPermissions;

    public function __construct()
    {
        $this->fsm = new AccountStateMachine();
        $this->pdo = Database::pdo();

        $this->eventPermissions = [
            AccountStateMachine::E_SUBMIT            => [AccountRole::SUPPLIER, AccountRole::ADMIN],
            AccountStateMachine::E_RESUBMIT          => [AccountRole::SUPPLIER, AccountRole::ADMIN],
            AccountStateMachine::E_APPROVE           => [AccountRole::REVIEWER, AccountRole::ADMIN],
            AccountStateMachine::E_REJECT            => [AccountRole::REVIEWER, AccountRole::ADMIN],
            AccountStateMachine::E_FREEZE            => [AccountRole::RISK, AccountRole::ADMIN],
            AccountStateMachine::E_UNFREEZE          => [AccountRole::RISK, AccountRole::ADMIN],
            AccountStateMachine::E_DISABLE           => [AccountRole::ADMIN],
            AccountStateMachine::E_ENABLE            => [AccountRole::ADMIN],
            AccountStateMachine::E_ROLLBACK_SUBMIT   => [AccountRole::ADMIN],
            AccountStateMachine::E_ROLLBACK_APPROVE  => [AccountRole::ADMIN],
            AccountStateMachine::E_ROLLBACK_REJECT   => [AccountRole::ADMIN],
            AccountStateMachine::E_ROLLBACK_FREEZE   => [AccountRole::ADMIN],
        ];
    }

    public function definition(): array
    {
        $def = $this->fsm->definition();
        $def['roles'] = array_map(
            fn($r) => ['key' => $r, 'label' => AccountRole::LABELS[$r] ?? $r],
            AccountRole::all()
        );
        $def['event_permissions'] = $this->eventPermissions;
        return $def;
    }

    /**
     * 根据操作人标识推断角色（生产环境应从 session/token 解析）。
     * 约定规则：
     *   - 前缀 supplier_ / sup_  => SUPPLIER
     *   - 前缀 reviewer_ / rev_  => REVIEWER
     *   - 前缀 risk_ / rsk_      => RISK
     *   - 前缀 admin_ / adm_      => ADMIN
     *   - 其他                    => 抛出权限异常
     */
    private function resolveRole(string $operator): string
    {
        $op = strtolower(trim($operator));
        if ($op === '') {
            throw new PermissionDeniedException('操作人不能为空，请先登录', '', '');
        }
        if (str_starts_with($op, 'supplier_') || str_starts_with($op, 'sup_')) {
            return AccountRole::SUPPLIER;
        }
        if (str_starts_with($op, 'reviewer_') || str_starts_with($op, 'rev_')) {
            return AccountRole::REVIEWER;
        }
        if (str_starts_with($op, 'risk_') || str_starts_with($op, 'rsk_')) {
            return AccountRole::RISK;
        }
        if (str_starts_with($op, 'admin_') || str_starts_with($op, 'adm_') || $op === 'ops-admin') {
            return AccountRole::ADMIN;
        }
        throw new PermissionDeniedException(
            "操作人「{$operator}」无法识别身份，请使用指定前缀的账号登录（supplier_ / reviewer_ / risk_ / admin_）",
            '',
            $operator
        );
    }

    /** 校验当前角色是否有权执行指定事件，无权则抛出 PermissionDeniedException。 */
    private function assertPermission(string $event, string $role): void
    {
        $allowed = $this->eventPermissions[$event] ?? null;
        if ($allowed === null) {
            throw new StateTransitionException(
                "未知操作：{$event}",
                400,
                '',
                $event,
                null,
                false,
                false
            );
        }
        if (!in_array($role, $allowed, true)) {
            $roleLabel = AccountRole::LABELS[$role] ?? $role;
            $needLabels = array_map(
                fn($r) => AccountRole::LABELS[$r] ?? $r,
                $allowed
            );
            $eventLabel = $this->eventLabel($event);
            throw new PermissionDeniedException(
                "当前角色「{$roleLabel}」无权执行「{$eventLabel}」，请联系以下角色操作：" . implode('、', $needLabels),
                implode(',', $allowed),
                $role
            );
        }
    }

    /** 创建结算账户（初始为 draft）。 */
    public function create(array $input): array
    {
        if (trim((string)($input['supplier_name'] ?? '')) === '') {
            throw new StateException('供应商名称不能为空', 422);
        }
        $now = time();
        $code = $this->genCode();
        $stmt = $this->pdo->prepare("
            INSERT INTO accounts
                (supplier_code, supplier_name, account_name, account_no, bank_name, bank_branch, account_type, status, created_at, updated_at)
            VALUES (:supplier_code, :supplier_name, :account_name, :account_no, :bank_name, :bank_branch, :account_type, 'draft', :now, :now)
        ");
        $stmt->execute([
            ':supplier_code' => $code,
            ':supplier_name' => trim((string)($input['supplier_name'] ?? '')),
            ':account_name'  => trim((string)($input['account_name'] ?? '')),
            ':account_no'     => trim((string)($input['account_no'] ?? '')),
            ':bank_name'      => trim((string)($input['bank_name'] ?? '')),
            ':bank_branch'    => trim((string)($input['bank_branch'] ?? '')),
            ':account_type'   => $input['account_type'] ?? 'public',
            ':now'            => $now,
        ]);
        $id = (int)$this->pdo->lastInsertId();

        $this->logTransition($id, self::E_CREATE(), AccountStateMachine::S_DRAFT, AccountStateMachine::S_DRAFT, 'system', '账户创建', null);

        return $this->get($id);
    }

    public function list(array $query): array
    {
        $where = [];
        $params = [];
        if (!empty($query['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $query['status'];
        }
        if (!empty($query['keyword'])) {
            $where[] = '(supplier_name LIKE :kw OR supplier_code LIKE :kw2 OR account_no LIKE :kw2)';
            $params[':kw'] = '%' . $query['keyword'] . '%';
            $params[':kw2'] = '%' . $query['keyword'] . '%';
        }
        $sql = 'SELECT * FROM accounts';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';

        $rows = $this->pdo->prepare($sql);
        $rows->execute($params);
        $accounts = $rows->fetchAll();

        $role = null;
        if (!empty($query['operator'])) {
            try {
                $role = $this->resolveRole((string)$query['operator']);
            } catch (PermissionDeniedException $e) {
                $role = null;
            }
        }

        foreach ($accounts as &$row) {
            $row = $this->decorate($row, $role);
        }
        return $accounts;
    }

    public function get(int $id, ?string $operator = null): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new StateException('账户不存在', 404);
        }
        $role = null;
        if ($operator !== null && $operator !== '') {
            try {
                $role = $this->resolveRole($operator);
            } catch (PermissionDeniedException $e) {
                $role = null;
            }
        }
        return $this->decorate($row, $role);
    }

    /** 触发状态迁移：审核通过/驳回、冻结/解冻、停用/启用等。 */
    public function trigger(int $id, string $event, array $input): array
    {
        $operator = trim((string)($input['operator'] ?? ''));
        $role = $this->resolveRole($operator);
        $this->assertPermission($event, $role);

        $account = $this->getRaw($id);
        $from = $account['status'];
        $context = [
            'account' => $account,
            'reason'  => trim((string)($input['reason'] ?? '')),
            'operator' => $operator,
        ];

        $check = $this->fsm->engine()->canTransition($from, $event, $context);
        if (!$check['ok']) {
            $rollbackEvent = $this->getRollbackEventFor($event, $from);
            throw new StateTransitionException(
                $check['message'],
                422,
                $from,
                $event,
                $rollbackEvent,
                $rollbackEvent !== null
            );
        }
        $to = $check['to'];

        $now = time();
        $patch = ['status' => $to, 'updated_at' => $now];
        switch ($event) {
            case AccountStateMachine::E_SUBMIT:
            case AccountStateMachine::E_RESUBMIT:
                $patch['submitted_at'] = $now;
                $patch['review_reason'] = null;
                break;
            case AccountStateMachine::E_APPROVE:
                $patch['reviewed_at'] = $now;
                $patch['review_reason'] = null;
                break;
            case AccountStateMachine::E_REJECT:
                $patch['reviewed_at'] = $now;
                $patch['review_reason'] = $context['reason'];
                break;
            case AccountStateMachine::E_FREEZE:
                $patch['frozen_at'] = $now;
                $patch['freeze_reason'] = $context['reason'];
                break;
            case AccountStateMachine::E_UNFREEZE:
                $patch['frozen_at'] = null;
                $patch['freeze_reason'] = $context['reason'];
                break;
            case AccountStateMachine::E_DISABLE:
            case AccountStateMachine::E_ENABLE:
                break;
            // —— 回滚入口：字段回滚处理 ——
            case AccountStateMachine::E_ROLLBACK_SUBMIT:
                $patch['submitted_at'] = null;
                $patch['review_reason'] = null;
                break;
            case AccountStateMachine::E_ROLLBACK_APPROVE:
                $patch['reviewed_at'] = null;
                $patch['review_reason'] = $context['reason'];
                break;
            case AccountStateMachine::E_ROLLBACK_REJECT:
                $patch['reviewed_at'] = null;
                $patch['review_reason'] = null;
                break;
            case AccountStateMachine::E_ROLLBACK_FREEZE:
                $patch['frozen_at'] = null;
                $patch['freeze_reason'] = $context['reason'];
                break;
        }

        $rollbackEvent = $this->getRollbackEventFor($event, $from);

        try {
            $this->pdo->beginTransaction();

            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($patch)));
            $params = array_merge([':id' => $id], array_combine(
                array_map(fn($k) => ":$k", array_keys($patch)),
                array_values($patch)
            ));
            $stmt = $this->pdo->prepare("UPDATE accounts SET $set WHERE id = :id");
            $stmt->execute($params);

            $this->logTransition($id, $event, $from, $to, $context['operator'], $context['reason'], $input['meta'] ?? null);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new StateTransitionException(
                '操作执行失败：' . $e->getMessage(),
                500,
                $from,
                $event,
                $rollbackEvent,
                true,
                true
            );
        }

        return $this->get($id, $operator);
    }

    /** 根据当前事件和状态，获取对应的回滚事件（如果存在）。 */
    private function getRollbackEventFor(string $event, string $currentStatus): ?string
    {
        $map = [
            AccountStateMachine::E_SUBMIT => AccountStateMachine::E_ROLLBACK_SUBMIT,
            AccountStateMachine::E_APPROVE => AccountStateMachine::E_ROLLBACK_APPROVE,
            AccountStateMachine::E_REJECT => AccountStateMachine::E_ROLLBACK_REJECT,
            AccountStateMachine::E_FREEZE => AccountStateMachine::E_ROLLBACK_FREEZE,
        ];

        $rollbackEvent = $map[$event] ?? null;
        if ($rollbackEvent === null) {
            return null;
        }

        $check = $this->fsm->engine()->canTransition($currentStatus, $rollbackEvent, ['reason' => 'check_only']);
        return $check['ok'] ? $rollbackEvent : null;
    }

    public function history(int $id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM state_transitions
            WHERE account_id = :id
            ORDER BY id ASC
        ");
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['meta'] = $row['meta'] ? json_decode($row['meta'], true) : null;
            $row['created_at_text'] = date('Y-m-d H:i:s', (int)$row['created_at']);
            $row['event_label'] = $this->eventLabel((string)$row['event']);
            $row['from_label'] = $this->fsm->engine()->stateLabel((string)$row['from_status']);
            $row['to_label'] = $this->fsm->engine()->stateLabel((string)$row['to_status']);
        }
        return $rows;
    }

    // —— 内部工具 ——

    private function decorate(array $row): array
    {
        $status = (string)$row['status'];
        $row['status_label'] = $this->fsm->engine()->stateLabel($status);
        $row['available_events'] = $this->fsm->engine()->eventsFrom($status);
        foreach (['created_at', 'updated_at', 'submitted_at', 'reviewed_at', 'frozen_at'] as $f) {
            $row[$f . '_text'] = $row[$f] ? date('Y-m-d H:i:s', (int)$row[$f]) : null;
        }
        $row['account_no_masked'] = $this->maskAccountNo((string)$row['account_no']);
        return $row;
    }

    private function getRaw(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new StateException('账户不存在', 404);
        }
        return $row;
    }

    private function logTransition(int $id, string $event, string $from, string $to, string $operator, ?string $reason, $meta): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO state_transitions (account_id, event, from_status, to_status, operator, reason, meta, created_at)
            VALUES (:id, :event, :from, :to, :operator, :reason, :meta, :now)
        ");
        $stmt->execute([
            ':id'       => $id,
            ':event'    => $event,
            ':from'     => $from,
            ':to'       => $to,
            ':operator' => $operator,
            ':reason'   => $reason,
            ':meta'     => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ':now'      => time(),
        ]);
    }

    private function genCode(): string
    {
        return 'SP' . date('Ymd') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function maskAccountNo(string $no): string
    {
        $len = strlen($no);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($no, 0, 4) . str_repeat('*', $len - 8) . substr($no, -4);
    }

    private function eventLabel(string $event): string
    {
        $map = [
            'create' => '创建账户',
            'submit' => '提交审核',
            'approve' => '审核通过',
            'reject' => '审核驳回',
            'resubmit' => '重新提交',
            'freeze' => '冻结账户',
            'unfreeze' => '解冻账户',
            'disable' => '停用账户',
            'enable' => '重新启用',
            'rollback_submit' => '回滚提交',
            'rollback_approve' => '回滚审核通过',
            'rollback_reject' => '回滚审核驳回',
            'rollback_freeze' => '回滚冻结',
        ];
        return $map[$event] ?? $event;
    }

    private static function E_CREATE(): string
    {
        return 'create';
    }
}
