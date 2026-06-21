<script setup lang="ts">
import { ref, watch } from 'vue'
import { Plus } from 'lucide-vue-next'
import { api } from '@/api/client'
import type { Account } from '@/api/types'
import BaseModal from './BaseModal.vue'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
  done: [account: Account]
}>()

const form = ref({
  supplier_name: '',
  account_name: '',
  account_no: '',
  bank_name: '',
  bank_branch: '',
  account_type: 'public',
})
const submitting = ref(false)
const errorMsg = ref('')

watch(
  () => props.open,
  (v) => {
    if (v) {
      form.value = {
        supplier_name: '',
        account_name: '',
        account_no: '',
        bank_name: '',
        bank_branch: '',
        account_type: 'public',
      }
      errorMsg.value = ''
      submitting.value = false
    }
  },
)

async function submit() {
  if (!form.value.supplier_name.trim()) {
    errorMsg.value = '供应商名称不能为空'
    return
  }
  submitting.value = true
  errorMsg.value = ''
  try {
    const created = await api.createAccount(form.value)
    emit('done', created)
  } catch (e) {
    errorMsg.value = (e as Error).message
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    title="新建结算账户"
    subtitle="建档后账户初始为「待提交」，补全信息后可提交审核"
    width="max-w-2xl"
    @close="emit('close')"
  >
    <div class="space-y-4">
      <div>
        <label class="label">供应商名称 *</label>
        <input v-model="form.supplier_name" class="input" placeholder="如：杭州数智贸易股份有限公司" />
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="label">结算账户户名</label>
          <input v-model="form.account_name" class="input" placeholder="与银行开户名一致" />
        </div>
        <div>
          <label class="label">结算银行账号</label>
          <input v-model="form.account_no" class="input font-mono" placeholder="审核通过前必填" />
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="label">开户银行</label>
          <input v-model="form.bank_name" class="input" placeholder="如：招商银行" />
        </div>
        <div>
          <label class="label">开户支行</label>
          <input v-model="form.bank_branch" class="input" placeholder="如：杭州分行营业部" />
        </div>
      </div>
      <div>
        <label class="label">账户类型</label>
        <div class="flex gap-2">
          <button
            v-for="opt in [{ v: 'public', l: '对公账户' }, { v: 'private', l: '对私账户' }]"
            :key="opt.v"
            class="btn"
            :class="form.account_type === opt.v ? 'border-brand/60 bg-brand/10 text-brand' : 'btn-ghost'"
            @click="form.account_type = opt.v"
          >
            {{ opt.l }}
          </button>
        </div>
      </div>
      <p v-if="errorMsg" class="rounded-lg border border-state-rejected/30 bg-state-rejected/10 px-3 py-2 text-sm text-state-rejected">
        {{ errorMsg }}
      </p>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3">
        <button class="btn-ghost" :disabled="submitting" @click="emit('close')">取消</button>
        <button class="btn-primary" :disabled="submitting" @click="submit">
          <Plus :size="16" />
          {{ submitting ? '创建中…' : '建档' }}
        </button>
      </div>
    </template>
  </BaseModal>
</template>
