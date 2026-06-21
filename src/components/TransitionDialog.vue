<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { AlertTriangle, CheckCircle2, Lock, Unlock, Send, XCircle, Info, Undo2, RotateCcw, RefreshCw, ShieldAlert } from 'lucide-vue-next'
import { api } from '@/api/client'
import type { Account, AccountEvent, ApiError, ErrorContext, TransitionContext, PermissionContext } from '@/api/types'
import { statusMeta } from '@/lib/statusMeta'
import { useAccounts } from '@/composables/useAccounts'
import BaseModal from './BaseModal.vue'

const props = defineProps<{
  open: boolean
  account: Account | null
  event: AccountEvent | null
}>()

const emit = defineEmits<{
  close: []
  done: [account: Account]
  error: [message: string]
  rollback: [event: AccountEvent]
}>()

const { operator } = useAccounts()

interface EventUi {
  title: string
  subtitle: string
  reasonRequired: boolean
  reasonLabel: string
  reasonPlaceholder: string
  confirmLabel: string
  tone: 'primary' | 'danger' | 'ghost'
  icon: typeof Lock
  tips?: string
}

const EVENT_UI: Partial<Record<AccountEvent, EventUi>> = {
  submit: {
    title: '提交审核',
    subtitle: '将结算账户提交至风控结算复核',
    reasonRequired: false,
    reasonLabel: '备注（选填）',
    reasonPlaceholder: '可填写提交说明',
    confirmLabel: '提交审核',
    tone: 'primary',
    icon: Send,
    tips: '提交审核前必须补全结算银行账号',
  },
  approve: {
    title: '审核通过',
    subtitle: '通过后账户进入「正常」状态，可发起结算',
    reasonRequired: false,
    reasonLabel: '审核意见（选填）',
    reasonPlaceholder: '例如：资质与银行账户核验无误',
    confirmLabel: '确认通过',
    tone: 'primary',
    icon: CheckCircle2,
    tips: '审核通过前必须确保结算银行账号已填写完整，且账户无有效冻结记录',
  },
  reject: {
    title: '审核驳回',
    subtitle: '驳回后账户回到「已驳回」，供应商可补正后重新提交',
    reasonRequired: true,
    reasonLabel: '驳回原因（必填）',
    reasonPlaceholder: '请说明驳回的具体原因，以便供应商补正资料',
    confirmLabel: '确认驳回',
    tone: 'danger',
    icon: AlertTriangle,
  },
  resubmit: {
    title: '重新提交审核',
    subtitle: '补正资料后重新进入审核流程',
    reasonRequired: false,
    reasonLabel: '补正说明（选填）',
    reasonPlaceholder: '说明本次补正的内容',
    confirmLabel: '重新提交',
    tone: 'primary',
    icon: Send,
    tips: '重新提交前必须补全结算银行账号',
  },
  freeze: {
    title: '冻结结算账户',
    subtitle: '冻结后账户结算暂停，解冻后方可恢复',
    reasonRequired: true,
    reasonLabel: '冻结原因（必填）',
    reasonPlaceholder: '例如：账户存在异常大额交易，待风控核查',
    confirmLabel: '冻结账户',
    tone: 'danger',
    icon: Lock,
    tips: '冻结前请先处理进行中的审核流程',
  },
  unfreeze: {
    title: '解冻结算账户',
    subtitle: '解冻后账户恢复「正常」状态',
    reasonRequired: true,
    reasonLabel: '解冻处理说明（必填）',
    reasonPlaceholder: '例如：核查完毕，恢复结算',
    confirmLabel: '解冻账户',
    tone: 'primary',
    icon: Unlock,
  },
  disable: {
    title: '停用账户',
    subtitle: '停用后账户进入终态，可重新启用回到待提交',
    reasonRequired: false,
    reasonLabel: '停用原因（选填）',
    reasonPlaceholder: '说明停用原因',
    confirmLabel: '停用账户',
    tone: 'danger',
    icon: AlertTriangle,
  },
  enable: {
    title: '重新启用账户',
    subtitle: '重新启用后账户回到「待提交」重新走流程',
    reasonRequired: false,
    reasonLabel: '备注（选填）',
    reasonPlaceholder: '可填写启用说明',
    confirmLabel: '重新启用',
    tone: 'ghost',
    icon: Unlock,
  },
  rollback_submit: {
    title: '回滚提交',
    subtitle: '将账户从「待审核」回退到「待提交」状态',
    reasonRequired: true,
    reasonLabel: '回滚原因（必填）',
    reasonPlaceholder: '请说明撤回提交的具体原因',
    confirmLabel: '确认回滚',
    tone: 'ghost',
    icon: Undo2,
    tips: '回滚后账户恢复为草稿状态，需重新提交审核',
  },
  rollback_approve: {
    title: '回滚审核通过',
    subtitle: '将账户从「正常」回退到「待审核」重新复核',
    reasonRequired: true,
    reasonLabel: '回滚原因（必填）',
    reasonPlaceholder: '请说明回滚审核通过的具体原因',
    confirmLabel: '确认回滚',
    tone: 'danger',
    icon: RotateCcw,
    tips: '回滚前需确保账户当前无有效冻结记录，请先解冻或回滚冻结',
  },
  rollback_reject: {
    title: '回滚审核驳回',
    subtitle: '将账户从「已驳回」回退到「待审核」重新处理',
    reasonRequired: true,
    reasonLabel: '回滚原因（必填）',
    reasonPlaceholder: '请说明撤销驳回的原因',
    confirmLabel: '确认回滚',
    tone: 'ghost',
    icon: Undo2,
    tips: '回滚后驳回记录保留，账户回到待审核状态',
  },
  rollback_freeze: {
    title: '回滚冻结',
    subtitle: '将账户从「已冻结」回退到「正常」状态（撤销冻结）',
    reasonRequired: true,
    reasonLabel: '回滚原因（必填）',
    reasonPlaceholder: '请说明撤销冻结的原因',
    confirmLabel: '确认回滚',
    tone: 'primary',
    icon: RotateCcw,
    tips: '回滚冻结不同于解冻，冻结操作历史会被标记为已回滚',
  },
}

const ROLLBACK_LABEL: Partial<Record<AccountEvent, string>> = {
  rollback_submit: '回滚提交',
  rollback_approve: '回滚审核通过',
  rollback_reject: '回滚审核驳回',
  rollback_freeze: '回滚冻结',
}

const ROLE_LABEL: Record<string, string> = {
  supplier: '供应商运营',
  reviewer: '审核专员',
  risk: '风控专员',
  admin: '系统管理员',
}

const reason = ref('')
const submitting = ref(false)
const errorMsg = ref('')
const errorContext = ref<ErrorContext | null>(null)

const ui = computed<EventUi | null>(() =>
  props.event ? EVENT_UI[props.event] ?? null : null,
)

const currentMeta = computed(() =>
  props.account ? statusMeta(props.account.status) : null,
)

const isPermissionError = computed(() => {
  if (!errorContext.value) return false
  return 'error_type' in errorContext.value && errorContext.value.error_type === 'permission'
})

const transitionCtx = computed<TransitionContext | null>(() => {
  if (errorContext.value && 'error_type' in errorContext.value && errorContext.value.error_type === 'transition') {
    return errorContext.value as TransitionContext
  }
  return null
})

const permissionCtx = computed<PermissionContext | null>(() => {
  if (errorContext.value && 'error_type' in errorContext.value && errorContext.value.error_type === 'permission') {
    return errorContext.value as PermissionContext
  }
  return null
})

const canRetry = computed(() => transitionCtx.value?.retryable ?? false)
const canRollback = computed(() => !!(transitionCtx.value?.can_rollback && transitionCtx.value?.rollback_event))
const rollbackEventLabel = computed(() => {
  const ev = transitionCtx.value?.rollback_event
  return ev ? (ROLLBACK_LABEL[ev] ?? '回滚') : ''
})

const requiredRolesLabel = computed(() => {
  if (!permissionCtx.value?.required_role) return ''
  const roles = permissionCtx.value.required_role.split(',')
  return roles.map(r => ROLE_LABEL[r] ?? r).join('、')
})

const currentRoleLabel = computed(() => {
  if (!permissionCtx.value?.current_role) return ''
  return ROLE_LABEL[permissionCtx.value.current_role] ?? permissionCtx.value.current_role
})

watch(
  () => props.open,
  (v) => {
    if (v) {
      reason.value = ''
      errorMsg.value = ''
      errorContext.value = null
      submitting.value = false
    }
  },
)

function close() {
  emit('close')
}

async function submit() {
  if (!props.account || !props.event || !ui.value) return
  if (ui.value.reasonRequired && !reason.value.trim()) {
    errorMsg.value = '请填写' + ui.value.reasonLabel.replace(/（.*）/, '')
    return
  }
  submitting.value = true
  errorMsg.value = ''
  errorContext.value = null
  try {
    const updated = await api.trigger(props.account.id, props.event, {
      reason: reason.value.trim() || undefined,
      operator: operator.value.trim() || 'ops-admin',
    })
    emit('done', updated)
  } catch (e) {
    const err = e as ApiError | Error
    const msg = err.message || '操作失败'
    errorMsg.value = msg
    if ('context' in err && err.context) {
      errorContext.value = err.context
    }
    emit('error', msg)
  } finally {
    submitting.value = false
  }
}

function triggerRollback() {
  const ev = transitionCtx.value?.rollback_event
  if (ev) {
    emit('rollback', ev)
  }
}

const confirmClass = computed(() => {
  switch (ui.value?.tone) {
    case 'danger':
      return 'btn-danger'
    case 'ghost':
      return 'btn-ghost'
    default:
      return 'btn-primary'
  }
})
</script>

<template>
  <BaseModal
    :open="open"
    :title="ui?.title ?? '操作'"
    :subtitle="ui?.subtitle"
    @close="close"
  >
    <div v-if="account && currentMeta" class="space-y-5">
      <div class="flex items-center justify-between rounded-xl border border-ink-700/60 bg-ink-900/50 px-4 py-3">
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-ink-100">{{ account.supplier_name }}</p>
          <p class="font-mono text-xs text-ink-300">{{ account.supplier_code }}</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-xs text-ink-400">当前</span>
          <span class="chip" :class="[currentMeta.text, currentMeta.border, currentMeta.bg]">
            <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: currentMeta.color }" />
            {{ currentMeta.label }}
          </span>
        </div>
      </div>

      <div
        v-if="errorMsg && !isPermissionError"
        class="flex items-start gap-3 rounded-xl border border-state-rejected/40 bg-state-rejected/10 px-4 py-3 shadow-lg shadow-state-rejected/5"
      >
        <XCircle :size="18" class="mt-0.5 flex-shrink-0 text-state-rejected" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-state-rejected">操作失败</p>
          <p class="mt-0.5 text-sm text-ink-200">{{ errorMsg }}</p>
          <div v-if="canRollback || canRetry" class="mt-3 flex flex-wrap items-center gap-2">
            <button
              v-if="canRollback"
              class="btn-ghost text-xs"
              @click="triggerRollback"
            >
              <Undo2 :size="14" />
              {{ rollbackEventLabel }}
            </button>
            <button
              v-if="canRetry"
              class="btn-primary text-xs"
              :disabled="submitting"
              @click="submit"
            >
              <RefreshCw :size="14" :class="{ 'animate-spin': submitting }" />
              重试
            </button>
          </div>
          <p v-if="canRollback" class="mt-2 text-xs text-ink-400">
            提示：您可以选择回滚本次操作，将账户恢复到之前的状态
          </p>
        </div>
      </div>

      <div
        v-if="errorMsg && isPermissionError"
        class="flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 shadow-lg shadow-amber-500/5"
      >
        <ShieldAlert :size="18" class="mt-0.5 flex-shrink-0 text-amber-500" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-amber-500">权限不足</p>
          <p class="mt-0.5 text-sm text-ink-200">{{ errorMsg }}</p>
          <div v-if="currentRoleLabel || requiredRolesLabel" class="mt-3 space-y-1">
            <p v-if="currentRoleLabel" class="text-xs text-ink-300">
              当前身份：<span class="font-mono text-amber-400">{{ currentRoleLabel }}</span>
            </p>
            <p v-if="requiredRolesLabel" class="text-xs text-ink-300">
              所需角色：<span class="font-mono text-amber-400">{{ requiredRolesLabel }}</span>
            </p>
          </div>
          <p class="mt-2 text-xs text-ink-400">
            提示：请使用具有相应权限的操作人账号（账号前缀需与角色匹配）
          </p>
        </div>
      </div>

      <div
        v-if="ui?.tips && !errorMsg"
        class="flex items-start gap-3 rounded-xl border border-brand/30 bg-brand/5 px-4 py-3"
      >
        <Info :size="16" class="mt-0.5 flex-shrink-0 text-brand" />
        <p class="text-sm text-ink-200">{{ ui.tips }}</p>
      </div>

      <div>
        <label class="label">{{ ui?.reasonLabel }}</label>
        <textarea
          v-model="reason"
          rows="3"
          class="input resize-none"
          :placeholder="ui?.reasonPlaceholder"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="label">操作人</label>
          <input v-model="operator" class="input font-mono" />
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3">
        <button class="btn-ghost" :disabled="submitting" @click="close">取消</button>
        <button :class="confirmClass" :disabled="submitting" @click="submit">
          <component :is="ui?.icon" :size="16" />
          {{ submitting ? '处理中…' : ui?.confirmLabel }}
        </button>
      </div>
    </template>
  </BaseModal>
</template>
