<?php

declare(strict_types=1);

/**
 * 通用有限状态机引擎（Framework-agnostic Finite State Machine）
 *
 * 设计目标：与具体业务解耦，任何"状态 + 事件 + 迁移 + 守卫"驱动的领域
 * 都可以基于此引擎快速沉淀。结算账户审核与冻结流程只是它的一个用例。
 *
 * 核心概念：
 *  - State   状态节点（含展示元数据：标签、阶段分组等）
 *  - Event   触发事件（外部动作）
 *  - Transition from --(event)--> to，可附带 Guard 守卫
 *  - Guard   守卫闭包：(array $context) => ['ok' => bool, 'message' => string]
 *            用于在迁移前做业务校验（如审核必须填写银行账户）
 */
final class StateMachine
{
    /** @var array<string,array{label:string,group:string,terminal:bool}> */
    private array $states = [];

    /** @var array<string,string> 事件标签 */
    private array $eventLabels = [];

    /** @var array<string,list<array{from:string,to:string,guard:?callable}>> event => transitions */
    private array $transitions = [];

    /** @var array<string,callable> 事件钩子：to_status => fn(array $context) */
    private array $onEnter = [];

    public function addState(string $state, string $label, string $group = 'default', bool $terminal = false): self
    {
        $this->states[$state] = ['label' => $label, 'group' => $group, 'terminal' => $terminal];
        return $this;
    }

    public function addEvent(string $event, string $label): self
    {
        $this->eventLabels[$event] = $label;
        return $this;
    }

    public function addTransition(string $event, string $from, string $to, ?callable $guard = null): self
    {
        $this->transitions[$event][] = ['from' => $from, 'to' => $to, 'guard' => $guard];
        return $this;
    }

    public function onEnter(string $state, callable $fn): self
    {
        $this->onEnter[$state] = $fn;
        return $this;
    }

    public function hasState(string $state): bool
    {
        return isset($this->states[$state]);
    }

    public function stateLabel(string $state): string
    {
        return $this->states[$state]['label'] ?? $state;
    }

    /**
     * 是否允许从 $from 通过 $event 迁移，并执行守卫校验。
     * @return array{ok:bool,message:string,to:?string}
     */
    public function canTransition(string $from, string $event, array $context = []): array
    {
        $rules = $this->transitions[$event] ?? [];
        foreach ($rules as $rule) {
            if ($rule['from'] !== $from) {
                continue;
            }
            $guard = $rule['guard'];
            if ($guard !== null) {
                $result = $guard($context);
                if (!is_array($result) || ($result['ok'] ?? false) === false) {
                    return ['ok' => false, 'message' => $result['message'] ?? '守卫校验未通过', 'to' => null];
                }
            }
            return ['ok' => true, 'message' => 'ok', 'to' => $rule['to']];
        }
        $eventLabel = $this->eventLabels[$event] ?? $event;
        $fromLabel = $this->stateLabel($from);
        return ['ok' => false, 'message' => "当前状态「{$fromLabel}」不支持事件「{$eventLabel}」", 'to' => null];
    }

    /**
     * 迁移到目标状态后触发 onEnter 钩子（用于副作用：更新时间戳等）。
     */
    public function enter(string $to, array $context): array
    {
        if (isset($this->onEnter[$to])) {
            $context = ($this->onEnter[$to])($context);
        }
        return $context;
    }

    /** 当前状态可触发的所有事件（已通过 from 匹配，未跑守卫）。 */
    public function eventsFrom(string $from): array
    {
        $available = [];
        foreach ($this->transitions as $event => $rules) {
            foreach ($rules as $rule) {
                if ($rule['from'] === $from) {
                    $available[$event] = $this->eventLabels[$event] ?? $event;
                    break;
                }
            }
        }
        return $available;
    }

    /** 导出完整定义，供前端可视化与下拉使用。 */
    public function definition(): array
    {
        $states = [];
        foreach ($this->states as $key => $meta) {
            $states[$key] = [
                'key' => $key,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'terminal' => $meta['terminal'],
            ];
        }
        $edges = [];
        foreach ($this->transitions as $event => $rules) {
            foreach ($rules as $rule) {
                $edges[] = [
                    'event' => $event,
                    'event_label' => $this->eventLabels[$event] ?? $event,
                    'from' => $rule['from'],
                    'to' => $rule['to'],
                ];
            }
        }
        return ['states' => $states, 'events' => $this->eventLabels, 'edges' => $edges];
    }
}
