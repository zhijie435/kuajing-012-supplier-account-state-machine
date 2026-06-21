<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import { AlertTriangle, CheckCircle2, Lock, Unlock, Send, XCircle, Info } from 'lucide-vue-next'
import { api } from '@/api/client'
import type { Account, AccountEvent } from '@/api/types'
import { statusMeta } from '@/lib/statusMeta'
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
}>()

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
    tips: '审核通过前必须确保结算银行账号已填写完整',
  },
  reject: {
    title: '审核驳回',
    subtitle: '驳回后账户回到「已驳回」，供应商可补正后重新提交',
    reasonRequired: true,
    reasonLabel: '驳回原因（必填）',
    reasonPlaceholder: '请说明驳回的具体原因，以便供应商补正',
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
}

const reason = ref('')
const operator = ref('ops-admin')
const submitting = ref(false)
const errorMsg = ref('')

const ui = computed<EventUi | null>(() =>
  props.event ? EVENT_UI[props.event] ?? null : null,
)

const currentMeta = computed(() =>
  props.account ? statusMeta(props.account.status) : null,
)

watch(
  () => props.open,
  (v) => {
    if (v) {
      reason.value = ''
      errorMsg.value = ''
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
  try {
    const updated = await api.trigger(props.account.id, props.event, {
      reason: reason.value.trim() || undefined,
      operator: operator.value.trim() || 'ops-admin',
    })
    emit('done', updated)
  } catch (e) {
    const msg = (e as Error).message || '操作失败'
    errorMsg.value = msg
    emit('error', msg)
  } finally {
    submitting.value = false
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
        v-if="errorMsg"
        class="flex items-start gap-3 rounded-xl border border-state-rejected/40 bg-state-rejected/10 px-4 py-3 shadow-lg shadow-state-rejected/5"
      >
        <XCircle :size="18" class="mt-0.5 flex-shrink-0 text-state-rejected" />
        <div class="min-w-0">
          <p class="text-sm font-semibold text-state-rejected">操作失败</p>
          <p class="mt-0.5 text-sm text-ink-200">{{ errorMsg }}</p>
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
