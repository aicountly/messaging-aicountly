/**
 * Dashboard 1 — the Command Centre.
 *
 * ## The care taken with every number on this screen
 *
 * Provider acceptance is not delivery, and this page never shows one as the
 * other: the delivered tile counts confirmed deliveries, and the accepted tile
 * says how many of its own messages have no confirmation yet.
 *
 * Missing confirmation is not success. Unconfirmed messages are reported as
 * their own figure rather than folded into either column.
 *
 * Response time distinguishes human from automated. The headline is the median
 * first HUMAN reply; an automated acknowledgement is reported separately and
 * never blended in, because a product that counted it would report a
 * two-second median and mean nothing by it.
 *
 * Spend separates provider-reported cost from estimate and never sums two
 * currencies. Where a business messages in two, two figures are shown.
 *
 * Every suggestion under "Your next best moves" is a SQL count over this
 * product's own data, labelled `Measured`, with its evidence and its source
 * beneath it. Nothing here is a prediction, because there is no model fitted
 * to make one.
 */

import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  HelpCircle,
  Info,
  MessageSquare,
  Plus,
  Send,
  Sparkles,
  TrendingUp,
  Wallet,
  X,
} from 'lucide-react'
import { usePolledApi } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { OverviewResponse, Suggestion } from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import { resolveRoute } from '../shell/navConfig'
import {
  Button,
  EmptyState,
  MetricCard,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  SourceBadge,
  StatusPill,
  formatCount,
  formatDuration,
  formatRate,
} from '../ui'
import { StackedAreaChart } from '../ui/charts'

const PERIODS = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: '7d', label: 'Last 7 days' },
  { key: '30d', label: 'Last 30 days' },
] as const

const METRIC_ICONS: Record<string, { icon: typeof Send; tone: 'brand' | 'warning' | 'danger' | 'info' }> = {
  messages_delivered: { icon: Send, tone: 'brand' },
  messages_accepted: { icon: CheckCircle2, tone: 'info' },
  awaiting_reply: { icon: MessageSquare, tone: 'warning' },
  median_first_human_reply: { icon: Clock, tone: 'brand' },
  messages_failed: { icon: AlertTriangle, tone: 'danger' },
  submission_unknown: { icon: HelpCircle, tone: 'warning' },
  spend: { icon: Wallet, tone: 'brand' },
}

export function CommandCentrePage() {
  const { can, currency } = useMessaging()
  const [period, setPeriod] = useUrlState('period', 'today')

  // Polled, because this screen sits open on a wall. 60s while the tab is
  // visible; paused entirely when it is not.
  const { data, loading, error, reload, paused } = usePolledApi(
    (signal) => api.get<{ data: OverviewResponse }>('v1/overview', { period }, signal).then((r) => r.data),
    [period],
    60,
    can('messaging.command_centre.view'),
  )

  if (!can('messaging.command_centre.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="the Command Centre" />
      </div>
    )
  }

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Messaging Command Centre</h1>
          <p>Every conversation. A clearer next step.</p>
        </div>
        <div className="msg-page-actions">
          <div className="msg-chips" role="group" aria-label="Period">
            {PERIODS.map((option) => (
              <button
                key={option.key}
                type="button"
                className="msg-chip"
                aria-pressed={period === option.key}
                onClick={() => setPeriod(option.key)}
              >
                {option.label}
              </button>
            ))}
          </div>
          <Link to="/inbox" className="msg-button msg-button-primary">
            <Plus size={15} aria-hidden />
            New message
          </Link>
        </div>
      </div>

      <PanelState loading={loading} error={error} data={data} onRetry={reload} skeletonRows={4}>
        {(overview) => (
          <>
            {/* The period and its timezone, stated. "Today" means nothing
                without saying whose day. */}
            <p className="msg-muted msg-small" style={{ margin: '-0.5rem 0 1rem' }}>
              {overview.period.label} · {overview.period.timezone}
              {paused && ' · paused while this tab is in the background'}
            </p>

            {overview.channel_health.note && (
              <Notice
                tone="warning"
                title="No channel is connected"
                action={
                  can('messaging.channels.manage') ? (
                    <Link to="/trust" className="msg-button msg-button-small">
                      Connect a channel
                    </Link>
                  ) : undefined
                }
              >
                {overview.channel_health.note}
              </Notice>
            )}

            <div className="msg-metric-row">
              {overview.metrics.slice(0, 4).map((metric) => {
                const config = METRIC_ICONS[metric.key] ?? { icon: TrendingUp, tone: 'brand' as const }
                return (
                  <MetricCard key={metric.key} metric={metric} icon={config.icon} tone={config.tone} currency={currency} />
                )
              })}
            </div>

            {/* The second row: the figures that matter when something is
                wrong. Kept below the headline rather than mixed into it. */}
            {overview.metrics.length > 4 && (
              <div className="msg-metric-row">
                {overview.metrics.slice(4).map((metric) => {
                  const config = METRIC_ICONS[metric.key] ?? { icon: TrendingUp, tone: 'brand' as const }
                  return (
                    <MetricCard
                      key={metric.key}
                      metric={metric}
                      icon={config.icon}
                      tone={config.tone}
                      currency={currency}
                    />
                  )
                })}
              </div>
            )}

            <NextBestMoves overview={overview} onDismissed={reload} />

            <div className="msg-grid msg-split">
              <Panel
                title="Delivery trend"
                subtitle={`Messages delivered by channel · ${overview.period.timezone}`}
              >
                <DeliveryTrend overview={overview} />
              </Panel>

              <Panel title="Channel health" subtitle="Configuration and the last 24 hours">
                <ChannelHealth overview={overview} />
              </Panel>
            </div>

            <div className="msg-grid msg-split">
              <Panel
                title="Needs your attention"
                subtitle="Conversations where the customer spoke last"
                action={
                  <Link to="/inbox?awaiting_reply=1" className="msg-button msg-button-small">
                    View inbox
                  </Link>
                }
              >
                <AttentionQueue overview={overview} />
              </Panel>

              <Panel title="Agent workload" subtitle="Open conversations by assignee">
                <AgentWorkload overview={overview} />
              </Panel>
            </div>

            <ResponseTimeDetail overview={overview} />
          </>
        )}
      </PanelState>
    </div>
  )
}

// ---------------------------------------------------------------------------

/**
 * "Your next best moves".
 *
 * Each row exposes WHY it appeared, its evidence with sources, when the data
 * was read, whether it is a fact or a suggestion, and a concrete action. That
 * is the brief's list, and the reason each one is present rather than
 * decorative: a suggestion nobody can verify is a suggestion nobody should
 * act on.
 */
function NextBestMoves({ overview, onDismissed }: { overview: OverviewResponse; onDismissed: () => void }) {
  const navigate = useNavigate()
  const [expanded, setExpanded] = useState<string | null>(null)

  if (overview.suggestions.length === 0) {
    return (
      <Panel title="Your next best moves" mint>
        <EmptyState title="Nothing needs you right now" icon={CheckCircle2}>
          No drafts waiting for review, no delivery problems, and nothing past a response target.
        </EmptyState>
      </Panel>
    )
  }

  return (
    <Panel
      title="Your next best moves"
      subtitle="Counted from your own records and prioritised by impact"
      mint
      action={
        <span className="msg-source">
          <Sparkles size={13} aria-hidden />
          {overview.ai.available ? 'AI narration available' : 'Rule-based'}
        </span>
      }
    >
      {/* The narration, where a model is configured, labelled as prose over
          counts the model did not produce. Absent entirely without one, and
          the suggestions below are unaffected. */}
      {overview.suggestions_narrative && (
        <p style={{ margin: '0 0 1rem', lineHeight: 1.6 }}>
          {overview.suggestions_narrative.text}{' '}
          <span className="msg-muted msg-small">({overview.suggestions_narrative.kind_note})</span>
        </p>
      )}

      <div className="msg-stack" style={{ gap: 0 }}>
        {overview.suggestions.map((suggestion, index) => (
          <SuggestionRow
            key={suggestion.key}
            suggestion={suggestion}
            rank={index + 1}
            expanded={expanded === suggestion.key}
            onToggle={() => setExpanded(expanded === suggestion.key ? null : suggestion.key)}
            onAct={() => {
              const to = resolveRoute(suggestion.action.route, suggestion.action.params)
              if (to !== null) navigate(to)
            }}
            onDismiss={async () => {
              await api.del(`v1/suggestions/${encodeURIComponent(suggestion.key)}`)
              onDismissed()
            }}
          />
        ))}
      </div>
    </Panel>
  )
}

function SuggestionRow({
  suggestion,
  rank,
  expanded,
  onToggle,
  onAct,
  onDismiss,
}: {
  suggestion: Suggestion
  rank: number
  expanded: boolean
  onToggle: () => void
  onAct: () => void
  onDismiss: () => void
}) {
  const detailId = `suggestion-${suggestion.key}-detail`

  return (
    <div className="msg-row">
      <span className="msg-score" aria-hidden>
        {rank}
      </span>
      <div className="msg-row-body">
        <strong>{suggestion.title}</strong>
        <small>{suggestion.detail}</small>

        <div style={{ display: 'flex', gap: '0.4rem', marginTop: '0.4rem', flexWrap: 'wrap' }}>
          {/* Fact, suggestion or estimate — stated, not implied. */}
          <StatusPill tone={suggestion.kind === 'verified_fact' ? 'brand' : 'warning'}>
            {suggestion.kind === 'verified_fact' ? 'Measured' : 'Suggestion'}
          </StatusPill>
          <SourceBadge source={suggestion.source} fetchedAt={suggestion.fetched_at} />
          <button
            type="button"
            className="msg-button msg-button-ghost msg-button-small"
            aria-expanded={expanded}
            aria-controls={detailId}
            onClick={onToggle}
          >
            <Info size={12} aria-hidden />
            Why this?
          </button>
        </div>

        {expanded && (
          <div id={detailId} style={{ marginTop: '0.6rem' }}>
            <p className="msg-muted msg-small" style={{ margin: '0 0 0.5rem', lineHeight: 1.55 }}>
              {suggestion.why}
            </p>
            <div className="msg-evidence">
              {suggestion.evidence.map((item, index) => (
                <div key={`${item.label}-${index}`} className="msg-evidence-item">
                  <strong>
                    {item.label}: {item.value}
                  </strong>
                  <small>Source: {item.source}</small>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
      <div className="msg-actions">
        <Button small tone="primary" onClick={onAct}>
          {suggestion.action.label}
        </Button>
        <Button small tone="ghost" onClick={onDismiss} ariaLabel={`Dismiss: ${suggestion.title}`}>
          <X size={13} aria-hidden />
        </Button>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------

function DeliveryTrend({ overview }: { overview: OverviewResponse }) {
  const rows = overview.delivery_trend

  if (rows.length === 0) {
    return (
      <EmptyState title="No messages in this period">
        Once messages go out, this shows delivered volume by channel per day.
      </EmptyState>
    )
  }

  const dates = [...new Set(rows.map((row) => row.metric_date))].sort()
  const channels = [...new Set(rows.map((row) => row.channel))].sort()

  const series = channels.map((channel) => ({
    key: channel,
    label: channelLabel(channel),
    values: dates.map((date) => {
      const row = rows.find((entry) => entry.metric_date === date && entry.channel === channel)
      return row ? Number(row.delivered) : 0
    }),
  }))

  const totalUnconfirmed = rows.reduce((sum, row) => sum + Number(row.unconfirmed), 0)

  return (
    <>
      <StackedAreaChart
        labels={dates.map((date) => shortDate(date))}
        series={series}
        valueLabel="delivered messages"
      />
      {totalUnconfirmed > 0 && (
        <p className="msg-muted msg-small" style={{ margin: '0.5rem 0 0' }}>
          {formatCount(totalUnconfirmed)} accepted message(s) in this period have no delivery confirmation yet and
          are not counted above.
        </p>
      )}
    </>
  )
}

function ChannelHealth({ overview }: { overview: OverviewResponse }) {
  const { configured, planned } = overview.channel_health

  return (
    <div className="msg-stack" style={{ gap: 0 }}>
      {configured.map((channel) => (
        <div key={channel.connection_uuid} className="msg-row msg-row-tight">
          <div className="msg-row-body">
            <strong>{channel.display_name}</strong>
            <small>
              {channel.delivery_24h.rate !== null
                ? `${formatRate(channel.delivery_24h.rate)} confirmed of ${formatCount(
                    channel.delivery_24h.accepted,
                  )} accepted, last 24h`
                : 'No messages in the last 24 hours'}
              {channel.can_receive ? '' : ' · outbound only'}
            </small>
            {channel.configuration_gap && (
              <small style={{ color: 'var(--warning)' }}>{channel.configuration_gap}</small>
            )}
          </div>
          <StatusPill tone={channel.status === 'connected' ? 'success' : 'warning'}>
            {channel.status_label}
          </StatusPill>
        </div>
      ))}

      {planned.length > 0 && (
        <div className="msg-row msg-row-tight">
          <div className="msg-row-body">
            <strong>Other OTT channels</strong>
            <small>{planned.map((entry) => entry.label).join(', ')}</small>
          </div>
          {/* Planned, not pending. There is no adapter behind these and they
              cannot be connected — saying "coming soon" is honest where
              "pending" would imply somebody just has to finish. */}
          <StatusPill tone="neutral">Planned</StatusPill>
        </div>
      )}

      {configured.length === 0 && (
        <EmptyState title="No channel connected">
          Messaging cannot send anything until a channel is connected.
        </EmptyState>
      )}
    </div>
  )
}

function AttentionQueue({ overview }: { overview: OverviewResponse }) {
  const navigate = useNavigate()
  const { rows, target_minutes, note } = overview.attention_queue

  if (rows.length === 0) {
    return (
      <EmptyState title="Nothing is waiting" icon={CheckCircle2}>
        Every conversation has had a reply since the customer last wrote.
      </EmptyState>
    )
  }

  return (
    <>
      <div className="msg-table-wrap">
        <table className="msg-table msg-table-clickable">
          <caption className="msg-visually-hidden">
            Conversations awaiting a reply, longest wait first
            {target_minutes !== null ? `, against a ${target_minutes} minute response target` : ''}
          </caption>
          <thead>
            <tr>
              <th scope="col">Customer</th>
              <th scope="col">Last message</th>
              <th scope="col">Channel</th>
              <th scope="col">Intent</th>
              <th scope="col">Waiting</th>
              <th scope="col">Assigned</th>
            </tr>
          </thead>
          <tbody>
            {rows.slice(0, 8).map((row) => (
              <tr
                key={row.conversation_uuid}
                onClick={() => navigate(`/inbox/${row.conversation_uuid}`)}
                tabIndex={0}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') navigate(`/inbox/${row.conversation_uuid}`)
                }}
              >
                <td>
                  {/* The address, because a NAME requires Contacts and this is
                      a summary table. The inbox resolves names live. */}
                  <strong>{row.provider_profile_name || row.customer_address}</strong>
                  {row.provider_profile_name && (
                    <>
                      <br />
                      <small className="msg-muted">{row.customer_address}</small>
                    </>
                  )}
                </td>
                <td className="msg-truncate" style={{ maxWidth: '18rem' }}>
                  {row.last_message ?? '—'}
                </td>
                <td>{channelLabel(row.channel)}</td>
                <td>
                  {row.intent ? (
                    <StatusPill tone={row.intent_source === 'ai' ? 'info' : 'neutral'}>
                      {humanise(row.intent)}
                      {row.intent_source === 'ai' && <span className="msg-visually-hidden"> (AI suggested)</span>}
                    </StatusPill>
                  ) : (
                    <span className="msg-muted">—</span>
                  )}
                </td>
                <td className="num">
                  {/* Red only where a CONFIGURED target has been passed. With
                      no target set, nothing is marked late. */}
                  <span style={row.past_target === true ? { color: 'var(--danger)', fontWeight: 650 } : undefined}>
                    {formatDuration(row.waiting_seconds)}
                    {row.past_target === true && <span className="msg-visually-hidden"> (past target)</span>}
                  </span>
                </td>
                <td>
                  {row.assigned_to_uuid === null ? (
                    <StatusPill tone="warning">Unassigned</StatusPill>
                  ) : (
                    <span className="msg-muted msg-small">Assigned</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {note && (
        <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0' }}>
          {note}
        </p>
      )}
    </>
  )
}

function AgentWorkload({ overview }: { overview: OverviewResponse }) {
  const { agents, name_state, name_note } = overview.agent_workload

  if (agents.length === 0) {
    return <EmptyState title="Nothing is assigned">Conversations appear here once they have an owner.</EmptyState>
  }

  return (
    <>
      <div className="msg-stack" style={{ gap: 0 }}>
        {agents.slice(0, 6).map((agent) => (
          <div key={agent.user_uuid} className="msg-row msg-row-tight">
            <div className="msg-row-body">
              <strong>{agent.name}</strong>
              <small>
                {formatCount(agent.open_conversations)} open · {formatCount(agent.awaiting_reply)} awaiting a reply
                {agent.median_reply_seconds !== null &&
                  ` · median reply ${formatDuration(agent.median_reply_seconds)}`}
              </small>
            </div>
            <span className="msg-count-pill">{formatCount(agent.resolved_in_period)}</span>
          </div>
        ))}
      </div>
      <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0' }}>
        The right-hand figure is conversations resolved in this period.{' '}
        {name_state !== 'ready' ? name_note : 'Names read live from Aicountly Manage.'}
      </p>
    </>
  )
}

/**
 * Response times, with the distinction spelled out.
 *
 * Human and automated are two figures and never one. A conversation still
 * waiting is excluded from the median and counted separately, because
 * including it as a zero would flatter the median and including it at its
 * current age would move it for a reason unrelated to how fast anybody
 * replied.
 */
function ResponseTimeDetail({ overview }: { overview: OverviewResponse }) {
  const { response_times: times, awaiting, ai_assisted: ai } = overview

  return (
    <div className="msg-grid msg-grid-3">
      <Panel title="First response" headingLevel={3}>
        <dl className="msg-definition-list">
          <dt>Median, human reply</dt>
          <dd>{formatDuration(times.median_first_human_reply_seconds)}</dd>
          <dt>90th percentile</dt>
          <dd>{formatDuration(times.p90_first_human_reply_seconds)}</dd>
          <dt>Median, automated reply</dt>
          <dd>{formatDuration(times.median_first_automated_reply_seconds)}</dd>
        </dl>
        <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.5 }}>
          {times.basis.note}
        </p>
      </Panel>

      <Panel title="Awaiting a reply" headingLevel={3}>
        <dl className="msg-definition-list">
          <dt>Waiting now</dt>
          <dd>{formatCount(awaiting.awaiting_reply)}</dd>
          <dt>Unassigned</dt>
          <dd>{formatCount(awaiting.unassigned)}</dd>
          <dt>Longest wait</dt>
          <dd>{formatDuration(awaiting.longest_wait_seconds)}</dd>
          {awaiting.response_target_minutes !== null && (
            <>
              <dt>Past target</dt>
              <dd>{formatCount(awaiting.breaching_target)}</dd>
            </>
          )}
        </dl>
        {awaiting.target_note && (
          <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.5 }}>
            {awaiting.target_note}
          </p>
        )}
      </Panel>

      <Panel title="AI-assisted conversations" headingLevel={3}>
        <dl className="msg-definition-list">
          <dt>AI assisted</dt>
          <dd>{formatCount(ai.ai_assisted_conversations)}</dd>
          <dt>Conversations opened</dt>
          <dd>{formatCount(ai.conversations)}</dd>
        </dl>
        <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.5 }}>
          {ai.definition}
        </p>
      </Panel>
    </div>
  )
}

// ---------------------------------------------------------------------------

export function channelLabel(channel: string): string {
  switch (channel) {
    case 'whatsapp':
      return 'WhatsApp Business'
    case 'rcs':
      return 'RCS'
    case 'sms':
      return 'SMS'
    case 'ott':
      return 'OTT'
    default:
      return channel
  }
}

export function humanise(value: string): string {
  return value.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}

function shortDate(date: string): string {
  try {
    return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' }).format(new Date(date))
  } catch {
    return date
  }
}
