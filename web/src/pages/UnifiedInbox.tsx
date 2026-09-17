/**
 * Dashboard 2 — the Unified Inbox.
 *
 * Three columns on a desktop: the conversation list, the selected thread, and
 * live business context. On a tablet the context panel moves below the thread;
 * on a phone the list and the thread are separate ROUTES, which is why the
 * selection lives in the URL.
 *
 * ## The three things this screen is careful about
 *
 * 1. THE COMPOSER IS DISABLED WHEN IT SHOULD BE, WITH A REASON. An alphanumeric
 *    SMS sender cannot receive replies, so the reply box is replaced by a
 *    sentence saying so rather than silently discarding what an agent types.
 *    Missing permission, an unconfigured channel and an outbound-only sender
 *    are three different messages.
 *
 * 2. NO FABRICATED PAYMENT LINK. The assistant's draft is checked before an
 *    agent can send it, and a draft that refers to a payment link when none
 *    exists cannot be dispatched — the backend refuses it at gate 7 as well,
 *    so the button being disabled here is a courtesy rather than the control.
 *
 * 3. EVERY CONTEXT PANEL SAYS WHERE IT CAME FROM AND WHEN. One failing degrades
 *    one panel. Nothing is ever substituted from a local copy, because there
 *    is no local copy.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft,
  CalendarClock,
  CheckCircle2,
  ExternalLink,
  Languages,
  Link2,
  Paperclip,
  RefreshCw,
  Scissors,
  Send,
  ShoppingCart,
  Sparkles,
  StickyNote,
  UserPlus,
} from 'lucide-react'
import { useApi, useMutation, usePolledApi } from '../hooks/useApi'
import { useUrlFilters } from '../hooks/useUrlState'
import { ApiError, api } from '../services/api'
import type {
  BusinessContext,
  Conversation,
  ConversationDetail,
  DraftSuggestion,
  Message,
} from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import {
  Announce,
  Button,
  Drawer,
  EmptyState,
  Field,
  LoadingRows,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  SourceBadge,
  SourcePanelState,
  StatusPill,
  formatCount,
  formatDateTime,
  formatMoney,
  messageStatusTone,
  productLabel,
  timeAgo,
} from '../ui'
import { channelLabel, humanise } from './CommandCentre'

export function UnifiedInboxPage() {
  const { conversationUuid } = useParams<{ conversationUuid: string }>()
  const { can } = useMessaging()

  const [filters, setFilters] = useUrlFilters({
    status: '',
    channel: '',
    assignment: '',
    awaiting_reply: '',
    q: '',
    unmatched: '',
  })

  const list = usePolledApi(
    (signal) =>
      api.list<Conversation>(
        'v1/conversations',
        {
          status: filters.status || undefined,
          channel: filters.channel || undefined,
          assignment: filters.assignment || undefined,
          awaiting_reply: filters.awaiting_reply === '1' ? '1' : undefined,
          q: filters.q || undefined,
          limit: 60,
        },
        signal,
      ),
    [filters.status, filters.channel, filters.assignment, filters.awaiting_reply, filters.q],
    45,
    can('messaging.conversations.view'),
  )

  if (!can('messaging.conversations.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="the inbox" />
      </div>
    )
  }

  const counts = (list.data?.meta.counts as Record<string, number> | undefined) ?? {}
  const contactNote = list.data?.meta.contact_note as string | undefined

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Unified Inbox</h1>
          <p>Context-aware conversations, with you in control.</p>
        </div>
        <div className="msg-page-actions">
          <StatusPill tone="neutral">{formatCount(counts.open ?? 0)} open</StatusPill>
          <StatusPill tone={(counts.awaiting ?? 0) > 0 ? 'warning' : 'success'}>
            {formatCount(counts.awaiting ?? 0)} awaiting a reply
          </StatusPill>
        </div>
      </div>

      {contactNote && (
        <Notice tone="warning" title="Customer names are not available">
          {contactNote}
        </Notice>
      )}

      <div className="msg-inbox">
        <Panel>
          <ConversationFilters filters={filters} setFilters={setFilters} counts={counts} />
          <ConversationList
            state={list}
            selectedUuid={conversationUuid ?? null}
            mobileHidden={conversationUuid !== undefined}
          />
        </Panel>

        {conversationUuid === undefined ? (
          <Panel>
            <EmptyState title="Pick a conversation">
              Choose a conversation on the left. Its live business context — what the customer owes, their recent
              orders, their appointments — loads alongside it, read from the products that own it.
            </EmptyState>
          </Panel>
        ) : (
          <ConversationWorkspace conversationUuid={conversationUuid} onChanged={list.reload} />
        )}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------

function ConversationFilters({
  filters,
  setFilters,
  counts,
}: {
  filters: Record<string, string>
  setFilters: (next: Record<string, string>) => void
  counts: Record<string, number>
}) {
  const [search, setSearch] = useState(filters.q)

  // Debounced, so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (search !== filters.q) setFilters({ q: search })
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search, filters.q, setFilters])

  return (
    <>
      <div className="msg-chips" style={{ marginBottom: '0.6rem' }} role="group" aria-label="Assignment">
        {[
          { key: '', label: 'All', count: counts.open },
          { key: 'mine', label: 'Mine', count: counts.mine },
          { key: 'unassigned', label: 'Unassigned', count: counts.unassigned },
        ].map((option) => (
          <button
            key={option.key || 'all'}
            type="button"
            className="msg-chip"
            aria-pressed={filters.assignment === option.key}
            onClick={() => setFilters({ assignment: option.key })}
          >
            {option.label}
            {option.count !== undefined && <span className="msg-count-pill">{formatCount(option.count)}</span>}
          </button>
        ))}
      </div>

      <label className="msg-visually-hidden" htmlFor="inbox-search">
        Search conversations
      </label>
      <input
        id="inbox-search"
        className="msg-input"
        placeholder="Search conversations…"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        style={{ marginBottom: '0.5rem' }}
      />

      <div style={{ display: 'flex', gap: '0.4rem', marginBottom: '0.6rem' }}>
        <label className="msg-visually-hidden" htmlFor="inbox-status">
          Status
        </label>
        <select
          id="inbox-status"
          className="msg-select"
          value={filters.status}
          onChange={(event) => setFilters({ status: event.target.value })}
        >
          <option value="">Any status</option>
          <option value="open">Open</option>
          <option value="pending">Pending</option>
          <option value="resolved">Resolved</option>
        </select>

        <label className="msg-visually-hidden" htmlFor="inbox-channel">
          Channel
        </label>
        <select
          id="inbox-channel"
          className="msg-select"
          value={filters.channel}
          onChange={(event) => setFilters({ channel: event.target.value })}
        >
          <option value="">Any channel</option>
          <option value="whatsapp">WhatsApp</option>
          <option value="rcs">RCS</option>
          <option value="sms">SMS</option>
        </select>
      </div>

      <div className="msg-chips" style={{ marginBottom: '0.75rem' }}>
        <button
          type="button"
          className="msg-chip"
          aria-pressed={filters.awaiting_reply === '1'}
          onClick={() => setFilters({ awaiting_reply: filters.awaiting_reply === '1' ? '' : '1' })}
        >
          Awaiting a reply
        </button>
      </div>
    </>
  )
}

function ConversationList({
  state,
  selectedUuid,
  mobileHidden,
}: {
  state: ReturnType<typeof usePolledApi<{ data: Conversation[]; meta: Record<string, unknown> }>>
  selectedUuid: string | null
  mobileHidden: boolean
}) {
  const navigate = useNavigate()

  return (
    <div data-mobile-hidden={mobileHidden}>
      <PanelState
        loading={state.loading}
        error={state.error}
        data={state.data}
        onRetry={state.reload}
        skeletonRows={6}
        isEmpty={(response) => response.data.length === 0}
        emptyTitle="No conversations match"
        emptyBody="Change the filters, or wait for a customer to write in."
      >
        {(response) => (
          <div className="msg-conversation-list" role="list">
            {response.data.map((conversation) => {
              const awaiting =
                conversation.last_inbound_at !== null &&
                (conversation.last_outbound_at === null ||
                  conversation.last_outbound_at < conversation.last_inbound_at)

              return (
                <button
                  key={conversation.conversation_uuid}
                  type="button"
                  role="listitem"
                  className="msg-conversation-item"
                  aria-current={selectedUuid === conversation.conversation_uuid}
                  onClick={() => navigate(`/inbox/${conversation.conversation_uuid}`)}
                >
                  <span className="msg-conversation-top">
                    <strong>
                      {/* A name from Contacts when there is one. The WhatsApp
                          profile name is the fallback and is NOT presented as
                          the customer's identity; the number is always shown. */}
                      {conversation.contact_name || conversation.provider_profile_name || conversation.customer_address}
                    </strong>
                    <span className="msg-muted" style={{ fontSize: '0.74rem', flex: 'none' }}>
                      {timeAgo(conversation.last_inbound_at ?? conversation.created_at)}
                    </span>
                  </span>

                  <span className="msg-conversation-preview">
                    {conversation.last_message_direction === 'outbound' && (
                      <span className="msg-muted" aria-hidden>
                        ↩{' '}
                      </span>
                    )}
                    {conversation.last_message_body ?? 'No messages yet'}
                  </span>

                  <span className="msg-conversation-meta">
                    <StatusPill tone="neutral">{channelLabel(conversation.channel)}</StatusPill>
                    {conversation.intent && (
                      <StatusPill tone={conversation.intent_source === 'ai' ? 'info' : 'neutral'}>
                        {humanise(conversation.intent)}
                      </StatusPill>
                    )}
                    {conversation.priority === 'urgent' && <StatusPill tone="danger">Urgent</StatusPill>}
                    {conversation.priority === 'high' && <StatusPill tone="warning">High</StatusPill>}
                    {awaiting && <StatusPill tone="warning">Awaiting reply</StatusPill>}
                    {conversation.unread_inbound_count > 0 && (
                      <span className="msg-count-pill msg-count-pill-alert">
                        {formatCount(conversation.unread_inbound_count)}
                      </span>
                    )}
                    {conversation.assigned_to_uuid === null && <StatusPill tone="neutral">Unassigned</StatusPill>}
                  </span>
                </button>
              )
            })}
          </div>
        )}
      </PanelState>
    </div>
  )
}

// ---------------------------------------------------------------------------

function ConversationWorkspace({
  conversationUuid,
  onChanged,
}: {
  conversationUuid: string
  onChanged: () => void
}) {
  const { timezone } = useMessaging()
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const detail = useApi(
    (signal) =>
      api
        .get<{ data: ConversationDetail }>(`v1/conversations/${conversationUuid}`, undefined, signal)
        .then((r) => r.data),
    [conversationUuid],
  )

  const refreshContext = useMutation(async () => {
    const response = await api.get<{ data: { context: BusinessContext } }>(
      `v1/conversations/${conversationUuid}/context`,
    )
    return response.data.context
  })

  const [liveContext, setLiveContext] = useState<BusinessContext | null>(null)
  const context = liveContext ?? detail.data?.context ?? null

  const onRefreshContext = useCallback(async () => {
    const next = await refreshContext.run()
    if (next !== null) {
      setLiveContext(next)
      setAnnouncement('Business context refreshed.')
    }
  }, [refreshContext])

  // A new conversation drops any previously refreshed context, so the panel
  // cannot show one customer's balance beside another's thread.
  useEffect(() => {
    setLiveContext(null)
  }, [conversationUuid])

  return (
    <>
      <Panel>
        {/* On a phone this is the way back to the list, which is the other
            screen rather than the other column. */}
        <Link
          to="/inbox"
          className="msg-button msg-button-small msg-button-ghost"
          style={{ marginBottom: '0.75rem' }}
        >
          <ArrowLeft size={13} aria-hidden />
          All conversations
        </Link>

        <PanelState
          loading={detail.loading}
          error={detail.error}
          data={detail.data}
          onRetry={detail.reload}
          skeletonRows={5}
          context="This conversation could not be opened"
        >
          {(data) => (
            <ConversationThread
              detail={data}
              timezone={timezone}
              onChanged={() => {
                detail.reload()
                onChanged()
              }}
              onAnnounce={setAnnouncement}
            />
          )}
        </PanelState>
      </Panel>

      <aside className="msg-inbox-context">
        <Panel
          title="Live business context"
          subtitle="Read from the products that own it, when this loaded"
          action={
            <Button small tone="ghost" onClick={onRefreshContext} pending={refreshContext.pending}>
              <RefreshCw size={13} aria-hidden />
              Refresh
            </Button>
          }
        >
          {context === null ? (
            <LoadingRows rows={4} height={72} />
          ) : (
            <BusinessContextPanel
              context={context}
              timezone={timezone}
              conversationUuid={conversationUuid}
              onChanged={() => {
                detail.reload()
                onRefreshContext()
              }}
            />
          )}
        </Panel>
      </aside>

      <Announce message={announcement} />
    </>
  )
}

// ---------------------------------------------------------------------------

function ConversationThread({
  detail,
  timezone,
  onChanged,
  onAnnounce,
}: {
  detail: ConversationDetail
  timezone: string
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const { conversation, messages, notes, permissions, channel, consent } = detail
  const bottomRef = useRef<HTMLDivElement>(null)
  const [assigning, setAssigning] = useState(false)
  const [notesOpen, setNotesOpen] = useState(false)

  // Scroll to the newest message when the thread changes.
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: 'end' })
  }, [conversation.conversation_uuid, messages.length])

  const setStatus = useMutation(async (status: string) =>
    api.patch(`v1/conversations/${conversation.conversation_uuid}`, {
      status,
      row_version: conversation.row_version,
    }),
  )

  const displayName =
    (detail.context.contact.state === 'ready' && detail.context.contact.data.name) ||
    conversation.provider_profile_name ||
    conversation.customer_address

  return (
    <>
      <div className="msg-panel-header">
        <div style={{ minWidth: 0 }}>
          <h2 style={{ margin: 0, fontSize: '1.05rem', fontWeight: 650 }}>{displayName}</h2>
          <p style={{ margin: '0.2rem 0 0', color: 'var(--muted)', fontSize: '0.82rem' }}>
            {channelLabel(conversation.channel)} · {conversation.customer_address}
            {channel.sender_address && ` → ${channel.sender_address}`}
          </p>
          {/* When the name came from a WhatsApp profile rather than Contacts,
              say so. It is provider metadata, not the customer's identity. */}
          {detail.context.contact.state === 'ready' &&
            !detail.context.contact.data.matched &&
            conversation.provider_profile_name && (
              <p className="msg-muted msg-small" style={{ margin: '0.2rem 0 0' }}>
                “{conversation.provider_profile_name}” is the name on their WhatsApp profile. No contact in Aicountly
                Contacts matches this number yet.
              </p>
            )}
        </div>

        <div className="msg-actions">
          {permissions.can_note && (
            <Button small tone="ghost" onClick={() => setNotesOpen(true)}>
              <StickyNote size={13} aria-hidden />
              Notes
              {notes.length > 0 && <span className="msg-count-pill">{notes.length}</span>}
            </Button>
          )}
          {permissions.can_assign && (
            <Button small onClick={() => setAssigning(true)}>
              <UserPlus size={13} aria-hidden />
              {conversation.assigned_to_uuid === null ? 'Assign' : 'Reassign'}
            </Button>
          )}
          {permissions.can_resolve && (
            <Button
              small
              tone={conversation.status === 'resolved' ? 'default' : 'primary'}
              pending={setStatus.pending}
              onClick={async () => {
                const next = conversation.status === 'resolved' ? 'open' : 'resolved'
                const result = await setStatus.run(next)
                if (result !== null) {
                  onAnnounce(next === 'resolved' ? 'Conversation resolved.' : 'Conversation reopened.')
                  onChanged()
                }
              }}
            >
              {conversation.status === 'resolved' ? 'Reopen' : 'Resolve'}
            </Button>
          )}
        </div>
      </div>

      {setStatus.error && (
        <Notice tone={setStatus.error instanceof ApiError && setStatus.error.isVersionConflict ? 'warning' : 'danger'}>
          {setStatus.error.message}
        </Notice>
      )}

      {conversation.reopened_count > 0 && (
        <p className="msg-muted msg-small" style={{ margin: '0 0 0.75rem' }}>
          This conversation has been reopened {conversation.reopened_count}{' '}
          {conversation.reopened_count === 1 ? 'time' : 'times'}.
        </p>
      )}

      {/* Consent, before anybody replies. An agent should not discover at send
          time that this customer has opted out. */}
      {consent.visible && consent.by_purpose && <ConsentSummary byPurpose={consent.by_purpose} />}

      <div className="msg-thread" role="log" aria-label="Conversation" aria-live="polite">
        {messages.length === 0 && (
          <EmptyState title="No messages yet">
            Nothing has been sent or received on this conversation.
          </EmptyState>
        )}

        {messages.map((message) => (
          <MessageBubble
            key={message.message_uuid}
            message={message}
            timezone={timezone}
            canApprove={permissions.can_approve}
            canSend={permissions.can_send}
            conversationUuid={conversation.conversation_uuid}
            onChanged={onChanged}
            onAnnounce={onAnnounce}
          />
        ))}
        <div ref={bottomRef} />
      </div>

      <ReplyComposer
        detail={detail}
        onChanged={onChanged}
        onAnnounce={onAnnounce}
      />

      {assigning && (
        <AssignDrawer
          conversation={conversation}
          onClose={() => setAssigning(false)}
          onAssigned={() => {
            setAssigning(false)
            onAnnounce('Assignment saved.')
            onChanged()
          }}
        />
      )}

      {notesOpen && (
        <NotesDrawer
          conversationUuid={conversation.conversation_uuid}
          notes={notes}
          timezone={timezone}
          canNote={permissions.can_note}
          onClose={() => setNotesOpen(false)}
          onAdded={() => {
            onAnnounce('Internal note saved. It is not visible to the customer.')
            onChanged()
          }}
        />
      )}
    </>
  )
}

function ConsentSummary({
  byPurpose,
}: {
  byPurpose: Record<string, { allowed: boolean; reason: string; detail: string }>
}) {
  const blocked = Object.entries(byPurpose).filter(([, verdict]) => !verdict.allowed)

  if (blocked.length === 0) {
    return (
      <p className="msg-muted msg-small" style={{ margin: '0 0 0.75rem' }}>
        <CheckCircle2 size={12} aria-hidden style={{ verticalAlign: '-2px' }} /> Consent is recorded for service,
        transactional and promotional messages on this channel.
      </p>
    )
  }

  // The service purpose is the one that governs a reply to a customer who
  // wrote in, so it is called out first and separately from promotional.
  const serviceBlocked = byPurpose.service && !byPurpose.service.allowed

  return (
    <Notice
      tone={serviceBlocked ? 'danger' : 'warning'}
      title={serviceBlocked ? 'You cannot reply to this customer' : 'Some message purposes are blocked'}
    >
      {blocked.map(([purpose, verdict]) => (
        <div key={purpose} style={{ marginTop: '0.2rem' }}>
          <strong style={{ display: 'inline' }}>{humanise(purpose)}:</strong> {verdict.detail}
        </div>
      ))}
    </Notice>
  )
}

function MessageBubble({
  message,
  timezone,
  canApprove,
  canSend,
  conversationUuid,
  onChanged,
  onAnnounce,
}: {
  message: Message
  timezone: string
  canApprove: boolean
  canSend: boolean
  conversationUuid: string
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const isInbound = message.direction === 'inbound'
  const isDraft = message.status === 'draft' || message.status === 'awaiting_approval'

  const approve = useMutation(async () =>
    api.post(`v1/drafts/${message.message_uuid}/approve`, { row_version: message.row_version }),
  )

  const send = useMutation(async () =>
    api.postRaw<{ data: { outcome: string; detail: string; note: string | null } }>(
      `v1/conversations/${conversationUuid}/send`,
      { message_uuid: message.message_uuid, row_version: message.row_version },
    ),
  )

  const bubbleClass = isInbound
    ? 'msg-bubble'
    : isDraft
      ? 'msg-bubble msg-bubble-draft'
      : 'msg-bubble msg-bubble-outbound'

  return (
    <div className={bubbleClass}>
      {isDraft && (
        <div style={{ marginBottom: '0.4rem' }}>
          <StatusPill tone="warning">
            {message.status === 'awaiting_approval' ? 'Awaiting approval' : 'Draft'} · not sent
          </StatusPill>
          {message.ai_generated && (
            <StatusPill tone="info">
              <Sparkles size={10} aria-hidden />
              AI drafted
            </StatusPill>
          )}
        </div>
      )}

      <div className="msg-bubble-body">{message.body}</div>

      {message.attachments.length > 0 && (
        <div className="msg-stack" style={{ gap: '0.3rem', marginTop: '0.5rem' }}>
          {message.attachments.map((attachment) => (
            <div
              key={attachment.attachment_uuid}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '0.4rem',
                fontSize: '0.78rem',
                padding: '0.35rem 0.5rem',
                background: 'var(--surface-2)',
                borderRadius: '0.5rem',
              }}
            >
              <Paperclip size={12} aria-hidden />
              <span className="msg-truncate" style={{ flex: 1 }}>
                {attachment.filename}
              </span>
              {/* A pending or failed scan is stated. An attachment that has not
                  been scanned cannot be dispatched, and an agent should know
                  that before they wonder why Send is refused. */}
              {attachment.scan_status !== 'clean' && (
                <StatusPill tone={attachment.scan_status === 'infected' ? 'danger' : 'warning'}>
                  {attachment.scan_status === 'infected'
                    ? 'Failed malware scan'
                    : attachment.scan_status === 'skipped'
                      ? 'Not scanned'
                      : 'Scanning'}
                </StatusPill>
              )}
            </div>
          ))}
        </div>
      )}

      <div className="msg-bubble-meta">
        <span>{formatDateTime(message.sent_at ?? message.created_at, timezone)}</span>

        {!isInbound && (
          <StatusPill tone={messageStatusTone(message.status)}>{message.status_label}</StatusPill>
        )}

        {message.origin === 'JOURNEY' && message.journey_run_uuid && (
          <Link to={`/journey-runs/${message.journey_run_uuid}`} className="msg-small">
            Sent by a journey
          </Link>
        )}

        {message.origin !== 'AGENT' && message.origin !== 'CUSTOMER' && message.origin !== 'JOURNEY' && (
          <StatusPill tone="neutral">Requested by Aicountly {productLabel(message.origin.toLowerCase())}</StatusPill>
        )}

        {/* Provider-reported cost only, and only where the provider reported
            one. An estimate is never shown as the bill. */}
        {message.provider_cost_minor !== null && message.cost_currency && (
          <span>{formatMoney(message.provider_cost_minor, message.cost_currency)}</span>
        )}

        {/* Edited after approval. The approval no longer applies and the send
            will be refused — said here rather than discovered at the button. */}
        {!isInbound && message.approved_at !== null && !message.approval_current && (
          <StatusPill tone="warning">Edited after approval — needs approving again</StatusPill>
        )}
      </div>

      {message.failure_detail && (
        <p className="msg-small" style={{ color: 'var(--danger)', margin: '0.4rem 0 0', lineHeight: 1.5 }}>
          {message.failure_detail}
        </p>
      )}

      {message.status === 'submission_unknown' && (
        <Notice tone="warning" title="This needs investigating">
          The provider did not confirm whether it took this message. It has NOT been resent, because the customer
          may already have received it.{' '}
          <Link to="/trust?tab=delivery">Investigate delivery</Link>
        </Notice>
      )}

      {isDraft && (
        <>
          {(approve.error || send.error) && (
            <SendRefusal error={(send.error ?? approve.error) as ApiError | Error} />
          )}

          <div className="msg-composer-actions">
            {canApprove && message.status === 'draft' && (
              <Button
                small
                pending={approve.pending}
                onClick={async () => {
                  const result = await approve.run()
                  if (result !== null) {
                    onAnnounce('Draft approved. It has not been sent.')
                    onChanged()
                  }
                }}
              >
                <CheckCircle2 size={13} aria-hidden />
                Approve
              </Button>
            )}
            {canSend && (
              <Button
                small
                tone="primary"
                pending={send.pending}
                onClick={async () => {
                  const result = await send.run()
                  if (result !== null) {
                    onAnnounce(result.data.note ?? result.data.detail)
                    onChanged()
                  }
                }}
              >
                <Send size={13} aria-hidden />
                Review &amp; send
              </Button>
            )}
          </div>
        </>
      )}
    </div>
  )
}

/**
 * A refused send, with the failing gate named.
 *
 * The backend returns every gate's result, so this says "consent" or
 * "promised resource missing" rather than a generic error — which is the
 * difference between an agent fixing it and an agent filing a bug.
 */
function SendRefusal({ error }: { error: ApiError | Error }) {
  const apiError = error instanceof ApiError ? error : null
  const checks = apiError?.gateChecks ?? []
  const failed = checks.find((check) => !check.passed)

  const tone = apiError?.isCustomerDecision ? 'warning' : 'danger'

  return (
    <Notice tone={tone} title={apiError?.isCustomerDecision ? 'The customer has not agreed to this' : 'Not sent'}>
      {error.message}
      {failed && failed.detail !== error.message && (
        <div style={{ marginTop: '0.3rem' }}>
          <strong style={{ display: 'inline' }}>{humanise(failed.gate)}:</strong> {failed.detail}
        </div>
      )}
      {checks.length > 1 && (
        <details style={{ marginTop: '0.5rem' }}>
          <summary style={{ cursor: 'pointer', fontSize: '0.78rem' }}>All pre-send checks</summary>
          <ul style={{ margin: '0.4rem 0 0', paddingLeft: '1.1rem', fontSize: '0.78rem', lineHeight: 1.5 }}>
            {checks.map((check, index) => (
              <li key={index}>
                {check.passed ? '✓' : '✕'} <strong>{humanise(check.gate)}</strong> — {check.detail}
              </li>
            ))}
          </ul>
        </details>
      )}
    </Notice>
  )
}

// ---------------------------------------------------------------------------

function ReplyComposer({
  detail,
  onChanged,
  onAnnounce,
}: {
  detail: ConversationDetail
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const { session } = useMessaging()
  const { conversation, composer, permissions } = detail
  const [body, setBody] = useState('')
  const [draft, setDraft] = useState<DraftSuggestion | null>(null)
  const [language, setLanguage] = useState(conversation.language || 'en')
  const fileRef = useRef<HTMLInputElement>(null)

  const languages = session?.settings.languages ?? { en: 'English', hi: 'Hindi' }
  const aiPermitted = session?.ai.permitted ?? {
    draft: false,
    translate: false,
    summarise: false,
    suggest: false,
    autosend: false,
  }
  const aiAvailable = session?.ai.available ?? false

  // A new conversation clears the composer, so a reply cannot be sent to the
  // wrong customer after a fast click-through.
  useEffect(() => {
    setBody('')
    setDraft(null)
    setLanguage(conversation.language || 'en')
  }, [conversation.conversation_uuid, conversation.language])

  const generateDraft = useMutation(async () => {
    const response = await api.postRaw<{ data: DraftSuggestion }>('v1/ai/draft', {
      conversation_uuid: conversation.conversation_uuid,
      language,
    })
    return response.data
  })

  const translate = useMutation(async (target: string) => {
    const response = await api.postRaw<{ data: { translated: string; preserved: string[] } }>('v1/ai/translate', {
      text: body,
      language: target,
      conversation_uuid: conversation.conversation_uuid,
    })
    return response.data
  })

  const rewrite = useMutation(async (mode: string, tone?: string) => {
    const response = await api.postRaw<{ data: { rewritten: string; warnings: string[] } }>('v1/ai/rewrite', {
      text: body,
      mode,
      tone,
      conversation_uuid: conversation.conversation_uuid,
    })
    return response.data
  })

  const saveDraft = useMutation(async (send: boolean) => {
    const saved = await api.post<{ message_uuid?: string }>(
      `v1/conversations/${conversation.conversation_uuid}/drafts`,
      {
        body,
        content_type: 'text',
        language,
        ai_generated: draft !== null,
        ai_run_uuid: draft?.ai_run_uuid,
      },
    )

    if (!send) return saved

    const messageUuid = (saved as unknown as { data?: { message?: { message_uuid?: string } } }).data?.message
      ?.message_uuid

    if (messageUuid === undefined) return saved

    return api.postRaw<{ data: { outcome: string; detail: string; note: string | null } }>(
      `v1/conversations/${conversation.conversation_uuid}/send`,
      { message_uuid: messageUuid },
    )
  })

  const attach = useMutation(async (file: File) => {
    // An attachment needs a draft to hang off, so one is saved first.
    const saved = await api.post<{ message?: { message_uuid?: string } }>(
      `v1/conversations/${conversation.conversation_uuid}/drafts`,
      { body: body || ' ', content_type: 'text', language },
    )
    const messageUuid = (saved as unknown as { data?: { message?: { message_uuid?: string } } }).data?.message
      ?.message_uuid
    if (messageUuid === undefined) throw new Error('The draft could not be saved, so nothing was attached.')

    const form = new FormData()
    form.append('file', file)
    return api.upload(`v1/drafts/${messageUuid}/attachments`, form)
  })

  // THE COMPOSER IS DISABLED WITH A REASON. Three different reasons, three
  // different messages — see the comment at the top of this file.
  if (!composer.enabled) {
    return (
      <div className="msg-composer">
        <div className="msg-composer-disabled">
          <strong style={{ display: 'block', marginBottom: '0.3rem' }}>
            {composer.reason === 'permission'
              ? 'You cannot reply to conversations'
              : composer.reason === 'outbound_only'
                ? 'This channel is outbound only'
                : 'This channel cannot send right now'}
          </strong>
          {composer.detail}
        </div>
      </div>
    )
  }

  const verificationBlocks = draft?.verification.blocks_send ?? false

  return (
    <div className="msg-composer">
      {/* The assistant's tools. Each is shown only where the company permits
          it AND a model is configured — a button that always fails is worse
          than no button. */}
      {aiAvailable && (
        <div className="msg-composer-tools">
          {aiPermitted.draft && (
            <Button
              small
              pending={generateDraft.pending}
              onClick={async () => {
                const result = await generateDraft.run()
                if (result !== null) {
                  setDraft(result)
                  setBody(result.draft)
                  onAnnounce('A draft has been suggested. Read it before sending.')
                }
              }}
            >
              <Sparkles size={13} aria-hidden />
              Draft a reply
            </Button>
          )}

          {aiPermitted.translate &&
            Object.entries(languages)
              .filter(([code]) => code !== language)
              .slice(0, 2)
              .map(([code, name]) => (
                <Button
                  key={code}
                  small
                  disabled={body.trim() === ''}
                  pending={translate.pending}
                  onClick={async () => {
                    const result = await translate.run(code)
                    if (result !== null) {
                      setBody(result.translated)
                      setLanguage(code)
                      onAnnounce(`Translated into ${name}. Every reference and amount was checked.`)
                    }
                  }}
                >
                  <Languages size={13} aria-hidden />
                  {name}
                </Button>
              ))}

          {aiPermitted.draft && (
            <>
              <Button
                small
                disabled={body.trim() === ''}
                pending={rewrite.pending}
                onClick={async () => {
                  const result = await rewrite.run('shorten')
                  if (result !== null) setBody(result.rewritten)
                }}
              >
                <Scissors size={13} aria-hidden />
                Shorten
              </Button>
              <label className="msg-visually-hidden" htmlFor="composer-tone">
                Tone
              </label>
              <select
                id="composer-tone"
                className="msg-select"
                style={{ width: 'auto' }}
                defaultValue=""
                disabled={body.trim() === ''}
                onChange={async (event) => {
                  if (event.target.value === '') return
                  const result = await rewrite.run('tone', event.target.value)
                  if (result !== null) setBody(result.rewritten)
                  event.target.value = ''
                }}
              >
                <option value="">Adjust tone…</option>
                <option value="friendly">Friendly</option>
                <option value="formal">Formal</option>
                <option value="firm">Firm</option>
                <option value="apologetic">Apologetic</option>
              </select>
            </>
          )}
        </div>
      )}

      {(generateDraft.error || translate.error || rewrite.error) && (
        <Notice tone="warning">
          {(generateDraft.error ?? translate.error ?? rewrite.error)?.message}
        </Notice>
      )}

      {/* The draft's verification, BEFORE the send button. A draft that claims
          a payment link that does not exist is blocked here and refused by the
          backend too. */}
      {draft && <DraftReview draft={draft} />}

      {composer.template_required && (
        <Notice tone="warning" title="A template is needed to start this conversation">
          {composer.template_note}{' '}
          <Link to="/journeys?tab=templates">Open templates</Link>
        </Notice>
      )}

      <Field
        label="Your reply"
        htmlFor="composer-body"
        hint={
          composer.freeform_allowed === false
            ? 'This channel does not accept free-form messages. Use an approved template.'
            : `Sending as ${languages[language] ?? language}. Nothing is sent until you press send.`
        }
      >
        <textarea
          id="composer-body"
          className="msg-textarea"
          value={body}
          onChange={(event) => {
            setBody(event.target.value)
            // Editing the assistant's text means the verification no longer
            // describes what is in the box, so it is dropped rather than left
            // to reassure somebody about text it never saw.
            if (draft !== null && event.target.value !== draft.draft) setDraft(null)
          }}
          placeholder="Write a reply, or ask the assistant to draft one…"
          disabled={composer.freeform_allowed === false}
        />
      </Field>

      {(saveDraft.error || attach.error) && (
        <SendRefusal error={(saveDraft.error ?? attach.error) as ApiError | Error} />
      )}

      <div className="msg-composer-actions">
        {composer.attachments_allowed && (
          <>
            <input
              ref={fileRef}
              type="file"
              className="msg-visually-hidden"
              onChange={async (event) => {
                const file = event.target.files?.[0]
                if (file === undefined) return
                const result = await attach.run(file)
                if (result !== null) {
                  onAnnounce('Attached. It is scanned before it can be sent.')
                  onChanged()
                }
                event.target.value = ''
              }}
            />
            <Button small tone="ghost" pending={attach.pending} onClick={() => fileRef.current?.click()}>
              <Paperclip size={13} aria-hidden />
              Attach
            </Button>
          </>
        )}

        <span className="msg-spacer" />

        <Button
          small
          disabled={body.trim() === ''}
          pending={saveDraft.pending}
          onClick={async () => {
            const result = await saveDraft.run(false)
            if (result !== null) {
              onAnnounce('Draft saved. Nothing has been sent.')
              setBody('')
              setDraft(null)
              onChanged()
            }
          }}
        >
          Save draft
        </Button>

        {permissions.can_send && (
          <Button
            small
            tone="primary"
            disabled={body.trim() === '' || verificationBlocks}
            pending={saveDraft.pending}
            title={
              verificationBlocks
                ? 'This draft refers to something that does not exist. Fix it first.'
                : undefined
            }
            onClick={async () => {
              const result = await saveDraft.run(true)
              if (result !== null) {
                const payload = result as unknown as { data?: { note?: string; detail?: string } }
                onAnnounce(payload.data?.note ?? payload.data?.detail ?? 'Sent.')
                setBody('')
                setDraft(null)
                onChanged()
              }
            }}
          >
            <Send size={13} aria-hidden />
            Review &amp; send
          </Button>
        )}
      </div>
    </div>
  )
}

/**
 * "Why this draft?" plus what it cannot claim.
 *
 * The evidence list is what the model was given, with each fact's source and
 * fetch time. `blocked_claims` is what it was told NOT to say — which is how a
 * missing payment link becomes a draft that says the link is unavailable
 * rather than one that says it is below.
 */
function DraftReview({ draft }: { draft: DraftSuggestion }) {
  const blocking = draft.verification.findings.filter((finding) => finding.blocks_send)
  const advisory = draft.verification.findings.filter((finding) => !finding.blocks_send)

  return (
    <>
      {blocking.map((finding, index) => (
        <Notice key={index} tone="danger" title="This cannot be sent as written">
          {finding.detail}
          <div style={{ marginTop: '0.3rem' }}>
            <strong style={{ display: 'inline' }}>What to do:</strong> {finding.remedy}
          </div>
        </Notice>
      ))}

      {advisory.map((finding, index) => (
        <Notice key={index} tone="warning" title="Check this before sending">
          {finding.detail} {finding.remedy}
        </Notice>
      ))}

      <details className="msg-card" style={{ marginBottom: '0.75rem' }}>
        <summary
          style={{
            cursor: 'pointer',
            padding: '0.6rem 0.85rem',
            fontSize: '0.82rem',
            fontWeight: 650,
          }}
        >
          Why this draft? ({draft.evidence.length} verified{' '}
          {draft.evidence.length === 1 ? 'fact' : 'facts'})
        </summary>
        <div style={{ padding: '0 0.85rem 0.85rem' }}>
          <p className="msg-muted msg-small" style={{ margin: '0 0 0.6rem', lineHeight: 1.55 }}>
            {draft.kind_note}
          </p>

          {draft.evidence.length > 0 ? (
            <div className="msg-evidence">
              {draft.evidence.map((item, index) => (
                <div key={index} className="msg-evidence-item">
                  <strong>
                    {item.label}: {item.value}
                  </strong>
                  <small>
                    {item.source} · read {timeAgo(item.fetched_at)}
                  </small>
                </div>
              ))}
            </div>
          ) : (
            <p className="msg-muted msg-small" style={{ margin: 0 }}>
              No business context could be read, so the draft contains no figures or references.
            </p>
          )}

          {draft.blocked_claims.length > 0 && (
            <>
              <p
                className="msg-small"
                style={{ margin: '0.75rem 0 0.4rem', fontWeight: 650 }}
              >
                What this draft was told not to claim
              </p>
              <ul style={{ margin: 0, paddingLeft: '1.1rem', fontSize: '0.78rem', lineHeight: 1.55 }}>
                {draft.blocked_claims.map((claim, index) => (
                  <li key={index} className="msg-muted">
                    <strong>Aicountly {productLabel(claim.product)}:</strong> {claim.reason}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      </details>
    </>
  )
}

// ---------------------------------------------------------------------------

/**
 * The live business context column.
 *
 * FIVE INDEPENDENT PANELS. One failing degrades one. Every one of them carries
 * its source and the time it was read, and a panel whose product is not
 * connected says so rather than showing nothing or, worse, something.
 */
function BusinessContextPanel({
  context,
  timezone,
  conversationUuid,
  onChanged,
}: {
  context: BusinessContext
  timezone: string
  conversationUuid: string
  onChanged: () => void
}) {
  return (
    <div className="msg-stack">
      {/* Contact */}
      <div className="msg-context">
        <div className="msg-context-head">
          <h3>Customer</h3>
          <SourceBadge source="contacts" fetchedAt={context.contact.fetched_at} />
        </div>
        <SourcePanelState panel={context.contact} productName="Contacts">
          {(data) =>
            data.matched ? (
              <>
                <span className="msg-context-value" style={{ fontSize: '1rem' }}>
                  {data.name || data.address}
                </span>
                <dl className="msg-definition-list" style={{ marginTop: '0.4rem' }}>
                  {data.mobile && (
                    <>
                      <dt>Mobile</dt>
                      <dd>{data.mobile}</dd>
                    </>
                  )}
                  {data.email && (
                    <>
                      <dt>Email</dt>
                      <dd className="msg-truncate">{data.email}</dd>
                    </>
                  )}
                  {data.language && (
                    <>
                      <dt>Prefers</dt>
                      <dd>{data.language.toUpperCase()}</dd>
                    </>
                  )}
                </dl>
              </>
            ) : (
              <MatchContact conversationUuid={conversationUuid} address={data.address} onMatched={onChanged} />
            )
          }
        </SourcePanelState>
      </div>

      {/* Financial — Books */}
      <div className="msg-context">
        <div className="msg-context-head">
          <h3>Outstanding</h3>
          <SourceBadge source="books" fetchedAt={context.financial.fetched_at} />
        </div>
        <SourcePanelState panel={context.financial} productName="Books">
          {(data) => (
            <>
              {(data.outstanding_by_currency ?? []).length === 0 ? (
                <p className="msg-muted msg-small" style={{ margin: 0 }}>
                  Nothing outstanding.
                </p>
              ) : (
                (data.outstanding_by_currency ?? []).map((entry) => (
                  <span key={entry.currency} className="msg-context-value">
                    {formatMoney(entry.outstanding_minor, entry.currency)}
                  </span>
                ))
              )}

              {/* Two currencies are shown separately and never added. */}
              {data.combined_note && (
                <p className="msg-muted msg-small" style={{ margin: '0.3rem 0 0', lineHeight: 1.45 }}>
                  {data.combined_note}
                </p>
              )}

              {(data.invoices ?? []).length > 0 && (
                <dl className="msg-definition-list" style={{ marginTop: '0.5rem' }}>
                  {(data.invoices ?? []).slice(0, 4).map((invoice) => (
                    <div key={invoice.reference} style={{ display: 'contents' }}>
                      <dt>
                        {invoice.reference}
                        {invoice.overdue_days !== null && invoice.overdue_days > 0 && (
                          <>
                            {' '}
                            <span style={{ color: 'var(--danger)' }}>{invoice.overdue_days}d overdue</span>
                          </>
                        )}
                      </dt>
                      <dd>{formatMoney(invoice.outstanding_minor, invoice.currency)}</dd>
                    </div>
                  ))}
                </dl>
              )}
            </>
          )}
        </SourcePanelState>
      </div>

      {/* Payment — Pay. The panel the brief cares most about. */}
      <div className="msg-context">
        <div className="msg-context-head">
          <h3>Payment link</h3>
          <SourceBadge source="pay" fetchedAt={context.payment.fetched_at} />
        </div>
        <SourcePanelState panel={context.payment} productName="Pay">
          {(data) =>
            data.link ? (
              <>
                <StatusPill tone={data.link.status === 'PAID' ? 'success' : 'warning'}>
                  {humanise(data.link.status)}
                </StatusPill>
                <span className="msg-context-value" style={{ fontSize: '1.05rem' }}>
                  {formatMoney(data.link.amount_minor, data.link.currency)}
                </span>
                <a
                  href={data.link.url}
                  target="_blank"
                  rel="noreferrer noopener"
                  className="msg-small"
                  style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}
                >
                  <Link2 size={12} aria-hidden />
                  Open the link
                </a>
              </>
            ) : (
              <p className="msg-muted msg-small" style={{ margin: 0, lineHeight: 1.5 }}>
                No payment link has been created for this conversation.
                {data.can_create_link === false && ` ${data.reason ?? ''}`}
              </p>
            )
          }
        </SourcePanelState>
      </div>

      {/* Orders — Sales */}
      <div className="msg-context">
        <div className="msg-context-head">
          <h3>Recent orders</h3>
          <SourceBadge source="sales" fetchedAt={context.orders.fetched_at} />
        </div>
        <SourcePanelState panel={context.orders} productName="Sales">
          {(data) =>
            (data.orders ?? []).length === 0 ? (
              <p className="msg-muted msg-small" style={{ margin: 0 }}>
                No recent orders.
              </p>
            ) : (
              <dl className="msg-definition-list">
                {(data.orders ?? []).map((order) => (
                  <div key={order.reference} style={{ display: 'contents' }}>
                    <dt>
                      <ShoppingCart size={11} aria-hidden style={{ verticalAlign: '-1px' }} /> {order.reference}
                      <br />
                      <span className="msg-muted msg-small">{humanise(order.status)}</span>
                    </dt>
                    <dd>{formatMoney(order.total_minor, order.currency)}</dd>
                  </div>
                ))}
              </dl>
            )
          }
        </SourcePanelState>
      </div>

      {/* Appointments */}
      <div className="msg-context">
        <div className="msg-context-head">
          <h3>Appointments</h3>
          <SourceBadge source="appointments" fetchedAt={context.appointments.fetched_at} />
        </div>
        <SourcePanelState panel={context.appointments} productName="Appointments">
          {(data) =>
            (data.bookings ?? []).length === 0 ? (
              <p className="msg-muted msg-small" style={{ margin: 0 }}>
                Nothing booked.
              </p>
            ) : (
              <div className="msg-stack" style={{ gap: '0.4rem' }}>
                {(data.bookings ?? []).map((booking) => (
                  <div key={booking.reference} className="msg-small">
                    <CalendarClock size={11} aria-hidden style={{ verticalAlign: '-1px' }} />{' '}
                    <strong>{booking.service || booking.reference}</strong>
                    <br />
                    <span className="msg-muted">
                      {formatDateTime(booking.starts_at, booking.timezone || timezone)} ·{' '}
                      {humanise(booking.status)}
                    </span>
                  </div>
                ))}
              </div>
            )
          }
        </SourcePanelState>
      </div>

      {/* Permission-aware links into the owning products. */}
      {context.links.length > 0 && (
        <div className="msg-context">
          <h3 style={{ margin: '0 0 0.45rem', fontSize: '0.82rem', color: 'var(--muted)' }}>Open in</h3>
          <div className="msg-stack" style={{ gap: '0.3rem' }}>
            {context.links.map((link) => (
              <a
                key={link.url}
                href={link.url}
                target="_blank"
                rel="noreferrer noopener"
                className="msg-small"
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.3rem' }}
              >
                <ExternalLink size={12} aria-hidden />
                {link.label}
              </a>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Matching an unknown number to a contact.
 *
 * The match is a REFERENCE stored on the conversation; the contact stays in
 * Aicountly Contacts. Until it is matched, nothing about this customer's
 * history can be shown, which is what the empty panels above are saying.
 */
function MatchContact({
  conversationUuid,
  address,
  onMatched,
}: {
  conversationUuid: string
  address: string
  onMatched: () => void
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState(address)

  const search = useApi(
    (signal) =>
      api
        .get<{ data: { contacts: Array<{ contact_uuid: string; name: string; mobile: string }>; state: string } }>(
          'v1/contacts',
          { q: query, limit: 8 },
          signal,
        )
        .then((r) => r.data),
    [query],
    open && query.trim().length >= 3,
  )

  const match = useMutation(async (contactUuid: string) =>
    api.post(`v1/conversations/${conversationUuid}/match-contact`, { contact_uuid: contactUuid }),
  )

  return (
    <>
      <p className="msg-muted msg-small" style={{ margin: 0, lineHeight: 1.5 }}>
        No contact matches <strong>{address}</strong> yet, so this customer's invoices, orders and appointments
        cannot be shown.
      </p>
      <Button small onClick={() => setOpen(true)} >
        <UserPlus size={13} aria-hidden />
        Match to a contact
      </Button>

      {open && (
        <Drawer
          title="Match to a contact"
          subtitle="Contacts are searched live in Aicountly Contacts. Messaging stores only the reference."
          onClose={() => setOpen(false)}
        >
          <Field label="Search Aicountly Contacts" htmlFor="match-contact-query">
            <input
              id="match-contact-query"
              className="msg-input"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Name, number or email"
            />
          </Field>

          {match.error && <Notice tone="danger">{match.error.message}</Notice>}

          <PanelState
            loading={search.loading}
            error={search.error}
            data={search.data}
            onRetry={search.reload}
            skeletonRows={3}
            isEmpty={(data) => (data.contacts ?? []).length === 0}
            emptyTitle="No contact matched"
            emptyBody="Create the contact in Aicountly Contacts first, then come back and match it here."
          >
            {(data) => (
              <div className="msg-stack" style={{ gap: 0 }}>
                {(data.contacts ?? []).map((contact) => (
                  <div key={contact.contact_uuid} className="msg-row msg-row-tight">
                    <div className="msg-row-body">
                      <strong>{contact.name || contact.mobile}</strong>
                      <small>{contact.mobile}</small>
                    </div>
                    <Button
                      small
                      pending={match.pending}
                      onClick={async () => {
                        const result = await match.run(contact.contact_uuid)
                        if (result !== null) {
                          setOpen(false)
                          onMatched()
                        }
                      }}
                    >
                      Match
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </PanelState>
        </Drawer>
      )}
    </>
  )
}

function AssignDrawer({
  conversation,
  onClose,
  onAssigned,
}: {
  conversation: Conversation
  onClose: () => void
  onAssigned: () => void
}) {
  const assignees = useApi(
    (signal) =>
      api
        .get<{
          data: {
            assignees: Array<{ user_uuid: string; name: string; email: string }>
            state: string
            message?: string
          }
        }>('v1/conversations/assignees', undefined, signal)
        .then((r) => r.data),
    [],
  )

  const assign = useMutation(async (userUuid: string | null) =>
    api.post(`v1/conversations/${conversation.conversation_uuid}/assign`, {
      assigned_to_uuid: userUuid,
      row_version: conversation.row_version,
    }),
  )

  return (
    <Drawer
      title="Assign this conversation"
      subtitle="The list comes from Aicountly Manage, live."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            tone="danger"
            pending={assign.pending}
            onClick={async () => {
              const result = await assign.run(null)
              if (result !== null) onAssigned()
            }}
          >
            Unassign
          </Button>
        </>
      }
    >
      {assign.error && (
        <Notice tone={assign.error instanceof ApiError && assign.error.isVersionConflict ? 'warning' : 'danger'}>
          {assign.error.message}
        </Notice>
      )}

      <Button
        onClick={async () => {
          const result = await assign.run('me')
          if (result !== null) onAssigned()
        }}
        pending={assign.pending}
      >
        Assign to me
      </Button>

      <div style={{ marginTop: '1rem' }}>
        <PanelState
          loading={assignees.loading}
          error={assignees.error}
          data={assignees.data}
          onRetry={assignees.reload}
          skeletonRows={3}
          isEmpty={(data) => data.assignees.length === 0}
          emptyTitle="No colleagues found"
          emptyBody="Aicountly Manage lists who can open this company. Add people there first."
        >
          {(data) => (
            <>
              {data.state !== 'ready' && <Notice tone="warning">{data.message}</Notice>}
              <div className="msg-stack" style={{ gap: 0 }}>
                {data.assignees.map((person) => (
                  <div key={person.user_uuid} className="msg-row msg-row-tight">
                    <div className="msg-row-body">
                      <strong>{person.name}</strong>
                      {person.email && <small>{person.email}</small>}
                    </div>
                    <Button
                      small
                      pending={assign.pending}
                      onClick={async () => {
                        const result = await assign.run(person.user_uuid)
                        if (result !== null) onAssigned()
                      }}
                    >
                      Assign
                    </Button>
                  </div>
                ))}
              </div>
            </>
          )}
        </PanelState>
      </div>
    </Drawer>
  )
}

/**
 * Internal notes.
 *
 * In their own drawer, with their own styling, and the words "not visible to
 * the customer" on the form. A note that looks like a message is a note that
 * gets sent.
 */
function NotesDrawer({
  conversationUuid,
  notes,
  timezone,
  canNote,
  onClose,
  onAdded,
}: {
  conversationUuid: string
  notes: ConversationDetail['notes']
  timezone: string
  canNote: boolean
  onClose: () => void
  onAdded: () => void
}) {
  const [body, setBody] = useState('')
  const [isHandoff, setIsHandoff] = useState(false)

  const add = useMutation(async () =>
    api.post(`v1/conversations/${conversationUuid}/notes`, { body, is_handoff: isHandoff }),
  )

  return (
    <Drawer
      title="Internal notes"
      subtitle="Only your colleagues see these. They are never sent to the customer."
      onClose={onClose}
    >
      {notes.length === 0 ? (
        <EmptyState title="No notes yet" icon={StickyNote}>
          Notes are for your colleagues — a handoff summary, something the customer said on the phone.
        </EmptyState>
      ) : (
        <div className="msg-stack">
          {notes.map((note) => (
            <div key={note.note_uuid} className="msg-bubble msg-bubble-note">
              {note.is_handoff && <StatusPill tone="warning">Handoff summary</StatusPill>}
              <div className="msg-bubble-body" style={{ marginTop: note.is_handoff ? '0.4rem' : 0 }}>
                {note.body}
              </div>
              <div className="msg-bubble-meta">{formatDateTime(note.created_at, timezone)}</div>
            </div>
          ))}
        </div>
      )}

      {canNote && (
        <div style={{ marginTop: '1.25rem' }}>
          {add.error && <Notice tone="danger">{add.error.message}</Notice>}

          <Field
            label="Add a note"
            htmlFor="note-body"
            hint="This stays internal. It is not sent to the customer and never appears in the conversation."
          >
            <textarea
              id="note-body"
              className="msg-textarea"
              value={body}
              onChange={(event) => setBody(event.target.value)}
              placeholder="What should the next person know?"
            />
          </Field>

          <label style={{ display: 'flex', alignItems: 'center', gap: '0.45rem', fontSize: '0.85rem' }}>
            <input
              type="checkbox"
              checked={isHandoff}
              onChange={(event) => setIsHandoff(event.target.checked)}
            />
            Mark as a handoff summary
          </label>

          <div className="msg-composer-actions">
            <Button
              tone="primary"
              small
              disabled={body.trim() === ''}
              pending={add.pending}
              onClick={async () => {
                const result = await add.run()
                if (result !== null) {
                  setBody('')
                  setIsHandoff(false)
                  onAdded()
                }
              }}
            >
              Save note
            </Button>
          </div>
        </div>
      )}
    </Drawer>
  )
}
