export type AccountStatus =
  | 'draft'
  | 'pending_review'
  | 'active'
  | 'rejected'
  | 'frozen'
  | 'disabled'

export type AccountEvent =
  | 'submit'
  | 'approve'
  | 'reject'
  | 'resubmit'
  | 'freeze'
  | 'unfreeze'
  | 'disable'
  | 'enable'
  | 'rollback_submit'
  | 'rollback_approve'
  | 'rollback_reject'
  | 'rollback_freeze'

export interface Account {
  id: number
  supplier_code: string
  supplier_name: string
  account_name: string
  account_no: string
  bank_name: string
  bank_branch: string | null
  account_type: string
  status: AccountStatus
  status_label: string
  review_reason: string | null
  freeze_reason: string | null
  submitted_at: number | null
  reviewed_at: number | null
  frozen_at: number | null
  created_at: number
  updated_at: number
  created_at_text: string
  updated_at_text: string
  submitted_at_text: string | null
  reviewed_at_text: string | null
  frozen_at_text: string | null
  account_no_masked: string
  available_events: Partial<Record<AccountEvent, string>>
}

export interface StateMeta {
  key: string
  label: string
  group: string
  terminal: boolean
}

export interface Edge {
  event: AccountEvent
  event_label: string
  from: AccountStatus
  to: AccountStatus
}

export interface StateMachineDefinition {
  states: Record<string, StateMeta>
  events: Record<string, string>
  edges: Edge[]
}

export interface TransitionLog {
  id: number
  account_id: number
  event: string
  event_label: string
  from_status: AccountStatus
  to_status: AccountStatus
  from_label: string
  to_label: string
  operator: string
  reason: string | null
  meta: Record<string, unknown> | null
  created_at: number
  created_at_text: string
}

export interface ApiResult<T> {
  code: number
  message: string
  data: T
}
