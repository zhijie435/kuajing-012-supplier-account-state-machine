<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft,
  CheckCircle2,
  AlertTriangle,
  Lock,
  Unlock,
  Send,
  PowerOff,
  Power,
  Undo2,
  RotateCcw,
  Info,
} from 'lucide-vue-next'
import type { Account, AccountEvent } from '@/api/types'
import { statusMeta } from '@/lib/statusMeta'
import { useAccounts } from '@/composables/useAccounts'
import StatusBadge from '@/components/StatusBadge.vue'
import StateMachineDiagram from '@/components/StateMachineDiagram.vue'
import HistoryTimeline from '@/components/HistoryTimeline.vue'
import TransitionDialog from '@/components/TransitionDialog.vue'

const route = useRoute()
const router = useRouter()

const { loadOne, loadHistory, updateAccount, markDirty } = useAccounts()

const account = ref<Account | null>(null)
const history = ref<import('@/api/types').TransitionLog[]>([])
const loading = ref(false)
const dialogOpen = ref(false)
const dialogEvent = ref<AccountEvent | null>(null)
const toast = ref<{ ok: boolean; msg: string } | null>(null)

const id = computed(() => Number(route.params.id))
const meta = computed(() => (account.value ? statusMeta(account.value.status) : null))

const linkageWarning = computed(() => {
  if (!account.value) return null
  const a = account.value
  // 联动校验 1：已冻结状态下不允许审核相关操作，提示
  if (a.status === 'frozen') {
    return {
      level: 'warn' as const,
      title: '账户已冻结',
      message: '当前账户处于冻结状态，审核相关操作已被联动拦截。如需审核，请先解冻或使用「回滚冻结」撤销冻结。',
    }
  }
  // 联动校验 2：回滚审核通过需确保无冻结
  if (a.status === 'active' && a.frozen_at) {
    return {
      level: 'warn' as const,
      title: '存在冻结历史记录',
      message: '该账户曾被冻结，回滚审核通过前需确保当前无有效冻结记录。',
    }
  }
  // 联动校验 3：待审核状态下冻结风险提示
  if (a.status === 'pending_review') {
    return {
      level: 'info' as const,
      title: '审核流程进行中',
      message: '账户正处于待审核状态，审核完成后才能进行冻结/解冻操作。',
    }
  }
  return null
})

const EVENT_BTN: Partial<Record<AccountEvent, { icon: typeof Lock; tone: 'primary' | 'danger' | 'ghost' }>> = {
  submit: { icon: Send, tone: 'primary' },
  approve: { icon: CheckCircle2, tone: 'primary' },
  reject: { icon: AlertTriangle, tone: 'danger' },
  resubmit: { icon: Send, tone: 'ghost' },
  freeze: { icon: Lock, tone: 'danger' },
  unfreeze: { icon: Unlock, tone: 'primary' },
  disable: { icon: PowerOff, tone: 'danger' },
  enable: { icon: Power, tone: 'ghost' },
  rollback_submit: { icon: Undo2, tone: 'ghost' },
  rollback_approve: { icon: RotateCcw, tone: 'danger' },
  rollback_reject: { icon: Undo2, tone: 'ghost' },
  rollback_freeze: { icon: RotateCcw, tone: 'primary' },
}

const availableList = computed<AccountEvent[]>(() =>
  account.value ? (Object.keys(account.value.available_events) as AccountEvent[]) : [],
)

async function load(force = false) {
  loading.value = true
  try {
    const [acc, hist] = await Promise.all([
      loadOne(id.value, force),
      loadHistory(id.value, force),
    ])
    account.value = acc
    history.value = hist
  } catch (e) {
    toast.value = { ok: false, msg: (e as Error).message }
  } finally {
    loading.value = false
  }
}

function openDialog(event: AccountEvent) {
  dialogEvent.value = event
  dialogOpen.value = true
}

function onDone(updated: Account) {
  account.value = updated
  updateAccount(updated)
  dialogOpen.value = false
  toast.value = { ok: true, msg: '操作成功' }
  loadHistory(id.value, true).then((h) => (history.value = h))
  setTimeout(() => (toast.value = null), 2400)
}

function onError(msg: string) {
  toast.value = { ok: false, msg }
  setTimeout(() => (toast.value = null), 3200)
}

function btnClass(tone: 'primary' | 'danger' | 'ghost') {
  return tone === 'danger' ? 'btn-danger' : tone === 'ghost' ? 'btn-ghost' : 'btn-primary'
}

const infoRows = computed(() => {
  if (!account.value) return []
  const a = account.value
  return [
    { label: '结算户名', value: a.account_name || '—' },
    { label: '银行账号', value: a.account_no_masked, mono: true },
    { label: '开户行', value: [a.bank_name, a.bank_branch].filter(Boolean).join(' · ') || '—' },
    { label: '账户类型', value: a.account_type === 'public' ? '对公账户' : '对私账户' },
    { label: '建档时间', value: a.created_at_text, mono: true },
    { label: '提交时间', value: a.submitted_at_text || '—', mono: true },
    { label: '审核时间', value: a.reviewed_at_text || '—', mono: true },
    { label: '冻结时间', value: a.frozen_at_text || '—', mono: true },
  ]
})

function goBack() {
  markDirty()
  router.push('/')
}

onMounted(load)
watch(id, () => load(true))
</script>

<template>
  <div class="mx-auto max-w-7xl px-6 py-8">
    <button class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-300 transition-colors hover:text-ink-100" @click="goBack">
      <ArrowLeft :size="15" />
      返回列表
    </button>

    <div v-if="loading && !account" class="py-24 text-center text-ink-400">加载中…</div>

    <template v-else-if="account && meta">
      <!-- 头部 -->
      <header class="panel mb-6 flex flex-wrap items-center justify-between gap-4 p-6">
        <div class="min-w-0">
          <div class="flex items-center gap-3">
            <h1 class="truncate font-display text-2xl font-bold text-ink-50">{{ account.supplier_name }}</h1>
            <StatusBadge :status="account.status" :pulse="account.status === 'pending_review'" />
          </div>
          <p class="mt-1 font-mono text-xs text-ink-400">
            {{ account.supplier_code }} · ID #{{ account.id }}
          </p>
          <p class="mt-2 max-w-xl text-sm text-ink-300">{{ meta.desc }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
          <button
            v-for="ev in availableList"
            :key="ev"
            :class="btnClass(EVENT_BTN[ev]?.tone ?? 'ghost')"
            @click="openDialog(ev)"
          >
            <component :is="EVENT_BTN[ev]?.icon" :size="15" />
            {{ account.available_events[ev] }}
          </button>
          <span v-if="availableList.length === 0" class="text-sm text-ink-400">当前状态无可执行操作</span>
        </div>
      </header>

      <!-- 状态机图 + 可执行事件 -->
      <section class="panel mb-6 p-6">
        <div class="mb-4 flex items-center justify-between">
          <div>
            <h2 class="font-display text-lg font-semibold text-ink-50">状态机视图</h2>
            <p class="text-xs text-ink-400">高亮当前状态与可执行迁移路径</p>
          </div>
        </div>
        <StateMachineDiagram :current="account.status" :available="availableList" />
      </section>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <!-- 账户信息 -->
        <section class="panel lg:col-span-2 p-6">
          <h2 class="mb-4 font-display text-lg font-semibold text-ink-50">账户信息</h2>

          <!-- 审核-冻结联动校验提示 -->
          <div
            v-if="linkageWarning"
            class="mb-4 flex items-start gap-3 rounded-xl border px-4 py-3"
            :class="
              linkageWarning.level === 'warn'
                ? 'border-state-frozen/40 bg-state-frozen/10'
                : 'border-brand/30 bg-brand/5'
            "
          >
            <component
              :is="linkageWarning.level === 'warn' ? AlertTriangle : Info"
              :size="16"
              class="mt-0.5 flex-shrink-0"
              :class="linkageWarning.level === 'warn' ? 'text-state-frozen' : 'text-brand'"
            />
            <div class="min-w-0">
              <p
                class="text-sm font-semibold"
                :class="linkageWarning.level === 'warn' ? 'text-state-frozen' : 'text-brand'"
              >
                {{ linkageWarning.title }}
              </p>
              <p class="mt-0.5 text-sm text-ink-200">{{ linkageWarning.message }}</p>
            </div>
          </div>

          <dl class="space-y-3">
            <div v-for="row in infoRows" :key="row.label" class="flex items-baseline justify-between gap-4 border-b border-ink-800/60 pb-2.5">
              <dt class="text-xs text-ink-400">{{ row.label }}</dt>
              <dd class="text-right text-sm" :class="row.mono ? 'font-mono text-ink-200' : 'text-ink-100'">{{ row.value }}</dd>
            </div>
          </dl>

          <div v-if="account.freeze_reason" class="mt-4 rounded-lg border border-state-frozen/30 bg-state-frozen/10 p-3">
            <p class="flex items-center gap-1.5 text-xs font-medium text-state-frozen">
              <Lock :size="13" /> 冻结原因
            </p>
            <p class="mt-1 text-sm text-ink-100">{{ account.freeze_reason }}</p>
          </div>
          <div v-if="account.review_reason" class="mt-3 rounded-lg border border-state-rejected/30 bg-state-rejected/10 p-3">
            <p class="flex items-center gap-1.5 text-xs font-medium text-state-rejected">
              <AlertTriangle :size="13" /> 审核驳回原因
            </p>
            <p class="mt-1 text-sm text-ink-100">{{ account.review_reason }}</p>
          </div>
        </section>

        <!-- 操作历史 -->
        <section class="panel lg:col-span-3 p-6">
          <div class="mb-2 flex items-center justify-between">
            <h2 class="font-display text-lg font-semibold text-ink-50">操作链路</h2>
            <span class="font-mono text-xs text-ink-400">{{ history.length }} 条记录</span>
          </div>
          <HistoryTimeline :logs="history" />
        </section>
      </div>
    </template>

    <TransitionDialog
      :open="dialogOpen"
      :account="account"
      :event="dialogEvent"
      @close="dialogOpen = false"
      @done="onDone"
      @error="onError"
    />

    <!-- toast -->
    <Transition name="toast">
      <div
        v-if="toast"
        class="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg border px-4 py-2.5 text-sm shadow-panel"
        :class="toast.ok ? 'border-state-active/40 bg-state-active/10 text-state-active' : 'border-state-rejected/40 bg-state-rejected/10 text-state-rejected'"
      >
        {{ toast.msg }}
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translate(-50%, 12px);
}
</style>
