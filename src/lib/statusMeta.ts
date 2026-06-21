import type { AccountStatus } from '@/api/types'

export interface StatusStyle {
  key: AccountStatus
  label: string
  color: string
  text: string
  border: string
  bg: string
  dot: string
  desc: string
  group: string
}

export const STATUS_META: Record<AccountStatus, StatusStyle> = {
  draft: {
    key: 'draft',
    label: '待提交',
    color: '#94a3b8',
    text: 'text-slate-300',
    border: 'border-slate-500/40',
    bg: 'bg-slate-500/10',
    dot: 'bg-slate-400',
    desc: '账户已建档，尚未提交审核。补全结算信息后可提交。',
    group: 'review',
  },
  pending_review: {
    key: 'pending_review',
    label: '待审核',
    color: '#22d3ee',
    text: 'text-cyan-300',
    border: 'border-cyan-400/40',
    bg: 'bg-cyan-400/10',
    dot: 'bg-cyan-400',
    desc: '已提交审核，等待风控/结算复核通过或驳回。',
    group: 'review',
  },
  active: {
    key: 'active',
    label: '正常',
    color: '#34d399',
    text: 'text-emerald-300',
    border: 'border-emerald-400/40',
    bg: 'bg-emerald-400/10',
    dot: 'bg-emerald-400',
    desc: '审核通过，结算账户正常可用，可发起结算与冻结操作。',
    group: 'active',
  },
  rejected: {
    key: 'rejected',
    label: '已驳回',
    color: '#fb7185',
    text: 'text-rose-300',
    border: 'border-rose-400/40',
    bg: 'bg-rose-400/10',
    dot: 'bg-rose-400',
    desc: '审核未通过。补正资料后可重新提交审核。',
    group: 'review',
  },
  frozen: {
    key: 'frozen',
    label: '已冻结',
    color: '#818cf8',
    text: 'text-indigo-300',
    border: 'border-indigo-400/40',
    bg: 'bg-indigo-400/10',
    dot: 'bg-indigo-400',
    desc: '账户已被冻结，结算暂停。解冻后恢复正常。',
    group: 'frozen',
  },
  disabled: {
    key: 'disabled',
    label: '已停用',
    color: '#71717a',
    text: 'text-zinc-400',
    border: 'border-zinc-500/40',
    bg: 'bg-zinc-500/10',
    dot: 'bg-zinc-500',
    desc: '账户已停用（终态）。可重新启用回到待提交重新走流程。',
    group: 'terminal',
  },
}

export const STATUS_ORDER: AccountStatus[] = [
  'draft',
  'pending_review',
  'active',
  'frozen',
  'rejected',
  'disabled',
]

export function statusMeta(status: AccountStatus): StatusStyle {
  return STATUS_META[status] ?? STATUS_META.draft
}
