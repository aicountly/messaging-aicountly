/**
 * The shapes the Messaging API returns.
 *
 * These mirror the backend's response envelopes. Where a field is nullable
 * here it is nullable there for a reason worth remembering:
 *
 *  - `contact_name` is null when Aicountly Contacts has no match or could not
 *    be reached. There is no local copy to fall back to, by design.
 *  - `delivery_rate` is null when nothing was accepted, rather than 0 — a rate
 *    with no denominator is not zero, it is unknown.
 *  - `first_response_target_minutes` is null when nobody configured one, and
 *    every screen treats that as "do not report breaches" rather than picking
 *    a default.
 */

import type { SourcePanel } from '../ui'

export interface SessionResponse {
  user: { uuid: string; name: string; kind: string; is_owner: boolean }
  context: { cmp_id: number; bo_id: number }
  permissions: string[]
  catalog: Record<string, Record<string, string>>
  settings: {
    timezone: string
    currency: string
    first_response_target_minutes: number | null
    resolution_target_minutes: number | null
    quiet_hours_start: string | null
    quiet_hours_end: string | null
    languages: Record<string, string>
    configured: boolean
  }
  features: Record<string, { enabled: boolean; reason: string | null }>
  ai: {
    available: boolean
    permitted: { draft: boolean; translate: boolean; summarise: boolean; suggest: boolean; autosend: boolean }
    reason: string | null
  }
  channels: {
    by_channel: Record<string, Record<string, number>>
    connected: string[]
    any_connected: boolean
    planned: string[]
  }
}

export interface Company {
  cmp_id?: number
  comp_id?: number
  id?: number
  cmp_name?: string
  comp_name?: string
  name?: string
}

export interface Suggestion {
  key: string
  weight: number
  title: string
  detail: string
  kind: 'verified_fact' | 'observation' | 'hypothesis' | 'suggestion' | 'estimate'
  why: string
  evidence: Array<{ label: string; value: string; source: string }>
  source: string
  fetched_at: string
  action: { route: string; label: string; params: Record<string, string> }
}

export interface OverviewResponse {
  period: PeriodDescription
  comparison: { period: PeriodDescription; note: string }
  metrics: Array<{
    key: string
    label: string
    value: number | null
    previous?: number | null
    unit: 'count' | 'seconds' | 'money' | 'rate'
    definition?: string
    lower_is_better?: boolean
    spend?: {
      by_currency: Array<{
        currency: string
        provider_cost_minor: number
        estimated_cost_minor: number
        priced_messages: number
        billable_messages: number
        completeness: string
        completeness_note: string | null
      }>
      combined: null
      combined_note: string | null
    }
    basis?: unknown
  }>
  response_times: {
    median_first_human_reply_seconds: number | null
    p90_first_human_reply_seconds: number | null
    median_first_automated_reply_seconds: number | null
    basis: {
      conversations_in_period: number
      answered_by_a_human: number
      still_awaiting_a_reply: number
      note: string
    }
  }
  awaiting: {
    awaiting_reply: number
    unassigned: number
    longest_wait_seconds: number | null
    response_target_minutes: number | null
    breaching_target: number | null
    target_note: string | null
  }
  ai_assisted: { ai_assisted_conversations: number; conversations: number; definition: string }
  delivery_trend: Array<{
    metric_date: string
    channel: string
    accepted: string | number
    delivered: string | number
    failed: string | number
    unconfirmed: string | number
    inbound: string | number
  }>
  channel_health: {
    configured: Array<{
      connection_uuid: string
      channel: string
      display_name: string
      provider: string
      status: string
      status_label: string
      configuration_gap: string | null
      last_health_check_at: string | null
      last_webhook_at: string | null
      can_receive: boolean
      delivery_24h: {
        accepted: number
        delivered: number
        unconfirmed: number
        failed: number
        rate: number | null
        basis: string
      }
    }>
    planned: Array<{ channel: string; label: string; status: string }>
    note: string | null
  }
  agent_workload: {
    agents: Array<{
      user_uuid: string
      name: string
      name_resolved: boolean
      open_conversations: number
      awaiting_reply: number
      resolved_in_period: number
      median_reply_seconds: number | null
    }>
    name_state: string
    name_note: string
  }
  attention_queue: {
    rows: Array<{
      conversation_uuid: string
      customer_address: string
      contact_uuid: string | null
      provider_profile_name: string
      channel: string
      intent: string
      intent_source: string
      priority: string
      assigned_to_uuid: string | null
      last_message: string | null
      waiting_seconds: number
      row_version: number
      past_target: boolean | null
    }>
    target_minutes: number | null
    note: string | null
  }
  suggestions: Suggestion[]
  suggestions_narrative: { text: string; kind: string; kind_note: string } | null
  ai: { available: boolean; status: { reason: string | null; admin_hint: string | null } }
  queue: Record<string, number> | null
}

export interface PeriodDescription {
  key: string
  label: string
  from: string
  to: string
  timezone: string
  days: number
}

export interface Conversation {
  conversation_uuid: string
  connection_uuid: string
  channel: string
  customer_address: string
  contact_uuid: string | null
  contact_name?: string | null
  provider_profile_name: string
  status: 'open' | 'pending' | 'resolved'
  priority: 'low' | 'normal' | 'high' | 'urgent'
  intent: string
  intent_source: string
  assigned_to_uuid: string | null
  language: string
  unread_inbound_count: number
  ai_assisted: boolean
  reopened_count: number
  first_inbound_at: string | null
  first_human_outbound_at: string | null
  last_inbound_at: string | null
  last_outbound_at: string | null
  resolved_at: string | null
  created_at: string | null
  row_version: number
  sender_address: string
  connection_name: string
  last_message_body: string | null
  last_message_direction: string | null
}

export interface Message {
  message_uuid: string
  conversation_uuid: string
  direction: 'inbound' | 'outbound'
  status: string
  status_label: string
  body: string
  content_type: string
  language: string
  origin: string
  ai_generated: boolean
  author_uuid: string
  template_uuid: string | null
  template_version: number | null
  template_variables: Record<string, string>
  journey_run_uuid: string | null
  approved_at: string | null
  approved_by_uuid: string | null
  approval_current: boolean
  failure_code: string | null
  failure_detail: string | null
  provider_cost_minor: number | null
  estimated_cost_minor: number | null
  cost_currency: string | null
  created_at: string | null
  dispatched_at: string | null
  delivered_at: string | null
  read_at: string | null
  sent_at: string | null
  row_version: number
  attachments: Array<{
    attachment_uuid: string
    filename: string
    media_type: string
    byte_size: number
    scan_status: string
    storage: string
  }>
}

export interface InternalNote {
  note_uuid: string
  body: string
  author_uuid: string
  is_handoff: boolean
  created_at: string
}

export interface BusinessContext {
  contact: SourcePanel<{
    matched: boolean
    contact_uuid?: string
    name?: string
    mobile?: string
    email?: string
    language?: string
    address: string
    provider_profile_name: string
  }>
  financial: SourcePanel<{
    outstanding_by_currency?: Array<{ currency: string; outstanding_minor: number }>
    invoice_count?: number
    invoices?: Array<{
      reference: string
      outstanding_minor: number
      currency: string
      due_date: string
      overdue_days: number | null
    }>
    combined_total?: null
    combined_note?: string | null
  }>
  payment: SourcePanel<{
    can_create_link?: boolean
    reason?: string
    link?: {
      reference: string
      status: string
      url: string
      amount_minor: number
      currency: string
    } | null
  }>
  orders: SourcePanel<{
    orders?: Array<{
      reference: string
      status: string
      order_date: string
      total_minor: number
      currency: string
    }>
  }>
  appointments: SourcePanel<{
    bookings?: Array<{ reference: string; status: string; starts_at: string; timezone: string; service: string }>
  }>
  links: Array<{ product: string; label: string; url: string }>
}

export interface ConversationDetail {
  conversation: Conversation
  messages: Message[]
  notes: InternalNote[]
  context: BusinessContext
  channel: {
    connection_uuid: string | null
    provider: string | null
    sender_address: string | null
    status: string | null
    capabilities: Array<{ capability: string; label: string; supported: boolean }>
  }
  composer: {
    enabled: boolean
    reason: string
    detail: string
    outbound_only?: boolean
    freeform_allowed?: boolean
    template_required?: boolean
    template_note?: string | null
    attachments_allowed?: boolean
    links_allowed?: boolean
  }
  consent: {
    visible: boolean
    address?: string
    by_purpose?: Record<string, { allowed: boolean; reason: string; detail: string }>
  }
  external_references: Array<{
    owner_product: string
    external_id: string
    external_label: string
    relationship: string
    created_at: string
  }>
  permissions: {
    can_reply: boolean
    can_approve: boolean
    can_send: boolean
    can_assign: boolean
    can_resolve: boolean
    can_note: boolean
  }
}

export interface DraftSuggestion {
  ok: boolean
  ai_run_uuid: string
  draft: string
  language: string
  tone: string
  kind: string
  kind_note: string
  verification: {
    checked: boolean
    findings: Array<{ kind: string; blocks_send: boolean; detail: string; remedy: string }>
    blocks_send: boolean
    note: string
  }
  evidence: Array<{ label: string; value: string; source: string; fetched_at: string; kind: string }>
  sources: Array<{ product: string; fetched_at: string }>
  blocked_claims: Array<{ product: string; reason: string }>
}

export interface Template {
  template_uuid: string
  name: string
  channel: string
  category: string
  active_version: number | null
  is_active: boolean
  created_at: string | null
  updated_at: string | null
  languages: string[]
  latest_version: TemplateVersion | null
  sendable: boolean
  versions?: TemplateVersion[]
}

export interface TemplateVersion {
  version: number
  language: string
  body: string
  header: string
  footer: string
  components: unknown[]
  variable_schema: Array<{ name: string; type: string; example: string; required: boolean; source: string }>
  provider_template_id: string | null
  provider_status: string
  provider_rejection_reason: string | null
  provider_status_read_at: string | null
  submitted_at: string | null
  created_at: string | null
  sendable: boolean
}

export interface JourneyNode {
  id: string
  type: string
  label?: string
  source?: string
  expression?: string
  template_uuid?: string
  language?: string
  channel?: string
  purpose?: string
  delay_minutes?: number
  outcome?: string
  next?: string
  on_true?: string
  on_false?: string
  on_eligible?: string
  on_ineligible?: string
  on_unavailable?: string
  on_changed?: string
  on_approved?: string
  on_rejected?: string
  variables?: Record<string, string>
  [key: string]: unknown
}

export interface JourneyDefinitionShape {
  entry?: string
  nodes?: JourneyNode[]
}

export interface JourneyValidation {
  valid: boolean
  errors: Array<{ node: string; message: string }>
  warnings: Array<{ node: string; message: string }>
  summary: {
    nodes?: number
    entry?: string
    sends?: number
    source_products?: string[]
    requires_approval?: boolean
  }
}

export interface Journey {
  journey_uuid: string
  name: string
  description: string
  kind: string
  status: string
  published_version: number | null
  draft_version: number
  run_count: number | null
  last_run_at: string | null
  created_at: string | null
  updated_at: string | null
  runnable: boolean
  version?: {
    version: number
    definition: JourneyDefinitionShape
    validation: JourneyValidation
    published_at: string | null
    published_by: string | null
    definition_hash: string
    editable: boolean
  } | null
  versions?: Array<{ version: number; published_at: string | null; published_by: string | null; definition_hash: string }>
}

export interface JourneyRun {
  run_uuid: string
  journey_uuid: string
  journey_name: string
  kind: string
  journey_version: number
  mode: 'live' | 'simulation'
  trigger_source: string
  subject_product: string
  subject_ref: string
  contact_uuid: string | null
  conversation_uuid: string | null
  status: string
  outcome: string
  outcome_detail: string | null
  started_at: string
  finished_at: string | null
  resume_after: string | null
  steps?: Array<{
    node_id: string
    node_type: string
    sequence: number
    status: string
    branch: string
    explanation: string
    source_product: string
    source_fetched_at: string | null
    message_uuid: string | null
    started_at: string
    finished_at: string | null
  }>
  executed_definition?: JourneyDefinitionShape | null
  executed_definition_hash?: string
}

export interface SimulationResult {
  ok: boolean
  dispatched: false
  dispatch_note: string
  data_source: 'live_readonly' | 'synthetic'
  data_source_note: string
  journey: { journey_uuid: string; name: string; version: number; published: boolean; version_note: string }
  validation: JourneyValidation
  subjects: { considered: number; source: string; completeness: string; note: string }
  outcomes: Record<string, number>
  reasons: Record<string, number>
  walks: Array<{
    subject: string
    reference: string
    bucket: string
    reason: string
    trace: Array<{ node: string; type: string; outcome: string; explanation: string }>
  }>
}

export interface OutcomesResponse {
  period: PeriodDescription
  attribution: {
    window_hours: number
    timezone: string
    matching_methods: Record<string, string>
    cardinality: string
    deduplication: string
    causation_note: string
  }
  funnel: {
    stages: Array<{
      key: string
      label: string
      count: number
      denominator: number | null
      denominator_label?: string
      share: number | null
      note: string
    }>
    note: string
  }
  collections: OutcomeBlock
  orders: OutcomeBlock
  appointments: OutcomeBlock & { confirmed?: number | null }
  resolution: {
    conversations_opened: number
    conversations_resolved: number
    reopened: number
    resolution_rate: number | null
    median_resolution_seconds: number | null
    basis: { numerator: string; denominator: string; note: string }
  }
  cost_per_resolved_conversation: {
    state: string
    message: string
    by_currency: Array<{
      currency: string
      cost_minor: number
      resolved: number
      cost_per_resolved_minor: number
      completeness: string
      note: string | null
    }>
    basis?: string
  }
  reply_rate: {
    overall_rate: number | null
    delivered: number
    replied: number
    by_hour: Array<{ hour: number; delivered: number; replied: number; rate: number | null }>
    timezone: string
    basis: string
  }
  channel_comparison: Array<{
    channel: string
    accepted: string | number
    delivered: string | number
    failed: string | number
    unconfirmed: string | number
    cost_minor: string | number
    priced: string | number
  }>
  journey_performance: Array<{
    journey_uuid: string
    name: string
    kind: string
    runs: string | number
    sent: string | number
    cancelled: string | number
    paused: string | number
    awaiting_approval: string | number
    source_unavailable: string | number
    not_eligible: string | number
    linked_outcomes: string | number
  }>
  insights: {
    observations: Insight[]
    hypotheses: Insight[]
    predictions: never[]
    predictions_note: string
    evidence_note: string
    minimum_sample: number
  }
}

export interface OutcomeBlock {
  state: string
  message: string
  linked_count: number
  valued_count?: number
  counted?: number
  by_currency: Array<{ currency: string; amount_minor: number }>
  unresolved: number
  completeness: string
  combined?: null
  combined_note?: string | null
  source_note?: string
  included_statuses?: string
}

export interface Insight {
  kind: 'observation' | 'hypothesis'
  key: string
  title: string
  detail: string
  measured?: {
    metric: string
    period: PeriodDescription
    cohort: string
    arms?: Array<Record<string, unknown>>
    ratio?: number
  }
  caveats?: string[]
  based_on?: string
  proposed_test?: {
    design: string
    metric: string
    minimum_per_arm: number
    requires: string[]
    note: string
  }
}

export interface ChannelsResponse {
  connections: Array<{
    connection_uuid: string
    channel: string
    provider: string
    display_name: string
    sender_address: string
    status: string
    status_label: string
    status_detail: string | null
    is_active: boolean
    row_version: number
    credential_present: boolean
    credential_ref?: string
    adapter_name: string
    capabilities: Array<{ capability: string; label: string; supported: boolean }>
    configuration_gap: string | null
    webhook_url?: string | null
    last_health_check_at: string | null
    last_health_check_ok: boolean | null
    last_health_check_note: string | null
    last_webhook_at: string | null
    webhook_verified_at: string | null
    provider_quality: string | null
    provider_rate_limit: number | null
    provider_state_read_at: string | null
    delivery_24h: { accepted: number; delivered: number; failed: number; rate: number | null }
    template_status: Record<string, number>
  }>
  planned: Array<{ channel: string; label: string; status: string; note: string }>
  available_providers: Record<string, Array<{ provider: string; display_name: string; channel: string }>>
  delivery_health: {
    window: string
    accepted: number
    delivered: number
    unconfirmed: number
    failed: number
    submission_unknown: number
    in_flight: number
    delivery_rate: number | null
    basis: string
  }
  queue: Record<string, number> | null
  webhooks: {
    counts_24h: Record<string, number>
    last_event_at: string | null
    unattributed_rejected_24h: number
  }
  consent: { opted_in_addresses: number; suppressed_addresses: number; withdrawn_30d: number } | null
  ai_permissions: {
    editable: boolean
    rows: Array<{
      key: string
      label: string
      state: string
      detail: string
      warning?: string | null
      locked?: boolean
    }>
  }
  integrations: Array<{
    product: string
    label: string
    purpose: string
    state: string
    state_label: string
    remedy: string | null
  }>
  compliance_note: string
}

// ---------------------------------------------------------------------------
// Settings, access control and audit
// ---------------------------------------------------------------------------

export interface MessagingSettings {
  cmp_id?: number
  timezone: string
  currency: string
  /** Null when nobody set a target. Never defaulted — see the file header. */
  first_response_target_minutes: number | null
  resolution_target_minutes: number | null
  quiet_hours_start: string | null
  quiet_hours_end: string | null
  ai_draft_allowed: boolean
  ai_translate_allowed: boolean
  ai_summarise_allowed: boolean
  ai_suggest_allowed: boolean
  ai_autosend_allowed: boolean
  default_languages: string[]
  updated_at?: string | null
  updated_by?: string | null
}

export interface SettingsResponse {
  settings: MessagingSettings
  /** Per-field explanations written by the backend, shown as field hints. */
  notes: Record<string, string>
  available_languages: Record<string, string>
}

export interface PermissionProfile {
  profile_id: number
  name: string
  description: string | null
  permissions: string[]
  is_active: boolean
  member_count: number
  created_at: string | null
  updated_at: string | null
}

export interface PermissionAssignment {
  assignment_id: number
  user_uuid: string
  profile_id: number
  profile_name: string
  created_at: string | null
}

export interface AccessResponse {
  profiles: PermissionProfile[]
  assignments: PermissionAssignment[]
  catalog: Record<string, Record<string, string>>
  /**
   * What THIS caller may hand to somebody else. Anything outside it is shown
   * disabled rather than hidden, so an administrator can see that the
   * permission exists and that it is not theirs to grant.
   */
  grantable: string[]
  defaults: string[]
  sensitive: string[]
  notes: string[]
}

export interface AuditEvent {
  audit_id: number
  actor_uuid: string | null
  actor_kind: string | null
  source_app: string | null
  action: string
  entity_type: string | null
  entity_id: string | null
  before_state: Record<string, unknown> | null
  after_state: Record<string, unknown> | null
  reason: string | null
  created_at: string
}
