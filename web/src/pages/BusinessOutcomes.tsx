/**
 * Dashboard 4 — Business Outcomes.
 *
 * ## "Linked to messaging" is not "caused by messaging"
 *
 * That sentence is on this screen, not in a footnote somebody removes. Every
 * figure states its window, its matching method, its timezone, its
 * denominators and its currency, and the strength of each attribution method
 * is spelled out — a customer quoting an invoice number back at us is not the
 * same evidence as the same contact happening to pay within three days.
 *
 * ## Three specific things this screen does not do
 *
 * It does not double-count a payment preceded by five reminders: the backend
 * keeps one primary link per outcome and this screen reports that count.
 *
 * It does not derive appointment confirmations from delivered reminders. They
 * come from Aicountly Appointments, and where Appointments is not connected
 * the tile says so rather than showing a number.
 *
 * It does not derive collections from payment-link clicks. Amounts are read
 * live from Books and Pay when this screen loads, which is also why a refunded
 * payment stops counting.
 *
 * ## Partial is said, not hidden
 *
 * Where some linked outcomes could not be valued, the total is labelled
 * partial and the count of unresolved ones is shown. A partial total presented
 * as complete is the single most damaging thing an attribution dashboard can
 * do.
 */

import { Link } from 'react-router-dom'
import {
  BarChart3,
  CalendarCheck,
  CircleDollarSign,
  Download,
  FlaskConical,
  Lightbulb,
  ShoppingCart,
} from 'lucide-react'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { Insight, OutcomeBlock, OutcomesResponse } from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import {
  Button,
  EmptyState,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  StatusPill,
  formatCount,
  formatDuration,
  formatMoney,
  formatRate,
} from '../ui'
import { Funnel, LineChart } from '../ui/charts'
import { channelLabel, humanise } from './CommandCentre'

const PERIODS = [
  { key: '7d', label: 'Last 7 days' },
  { key: '30d', label: 'Last 30 days' },
  { key: '90d', label: 'Last 90 days' },
  { key: 'last_month', label: 'Last month' },
] as const

export function BusinessOutcomesPage() {
  const { can, currency } = useMessaging()
  const [period, setPeriod] = useUrlState('period', '30d')
  const [window, setWindow] = useUrlState('window_hours', '72')

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api
        .get<{ data: OutcomesResponse }>('v1/outcomes', { period, window_hours: window }, signal)
        .then((r) => r.data),
    [period, window],
    can('messaging.outcomes.view'),
  )

  const exportRows = useMutation(async () => {
    const response = await api.get<{
      data: {
        rows: Array<Record<string, string | number>>
        row_count: number
        truncated: boolean
        truncated_note: string | null
        caveats: string[]
        period: { label: string }
        generated_at: string
      }
    }>('v1/outcomes/export', { period })
    return response.data
  })

  if (!can('messaging.outcomes.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="Business Outcomes" />
      </div>
    )
  }

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Business Outcomes</h1>
          <p>Connect messaging activity to measurable business events.</p>
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

          {can('messaging.export') && (
            <Button
              small
              pending={exportRows.pending}
              onClick={async () => {
                const result = await exportRows.run()
                if (result !== null) downloadCsv(result)
              }}
            >
              <Download size={13} aria-hidden />
              Export evidence
            </Button>
          )}
        </div>
      </div>

      {exportRows.error && <Notice tone="danger">{exportRows.error.message}</Notice>}

      <PanelState loading={loading} error={error} data={data} onRetry={reload} skeletonRows={4}>
        {(outcomes) => (
          <>
            {/* The caveat, first and unmissable. */}
            <Notice tone="info" title="These are linked outcomes, not proven causes">
              {outcomes.attribution.causation_note} Attribution window:{' '}
              <strong style={{ display: 'inline' }}>{outcomes.attribution.window_hours} hours</strong>, evaluated in{' '}
              {outcomes.attribution.timezone}.
            </Notice>

            <div className="msg-metric-row">
              <OutcomeTile
                label="Collections linked"
                block={outcomes.collections}
                icon={CircleDollarSign}
                fallbackCurrency={currency}
              />
              <OutcomeTile
                label="Orders linked"
                block={outcomes.orders}
                icon={ShoppingCart}
                fallbackCurrency={currency}
                countOnly
              />
              <AppointmentsTile block={outcomes.appointments} />
              <CostTile outcomes={outcomes} />
            </div>

            <div className="msg-grid msg-split">
              <Panel
                title="Conversation-to-outcome funnel"
                subtitle="Each stage names the denominator its share is measured against"
              >
                <Funnel stages={outcomes.funnel.stages} />
                <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
                  {outcomes.funnel.note}
                </p>
                <details style={{ marginTop: '0.75rem' }}>
                  <summary style={{ cursor: 'pointer', fontSize: '0.8rem' }}>What each stage means</summary>
                  <dl className="msg-definition-list" style={{ marginTop: '0.6rem', gridTemplateColumns: 'auto 1fr' }}>
                    {outcomes.funnel.stages.map((stage) => (
                      <div key={stage.key} style={{ display: 'contents' }}>
                        <dt>{stage.label}</dt>
                        <dd style={{ textAlign: 'left' }}>{stage.note}</dd>
                      </div>
                    ))}
                  </dl>
                </details>
              </Panel>

              <InsightsPanel insights={outcomes.insights} />
            </div>

            <div className="msg-grid msg-split">
              <Panel
                title="Reply rate by hour"
                subtitle={`Share of delivered messages that received a reply · ${outcomes.reply_rate.timezone}`}
                action={
                  <span className="msg-source">
                    Overall {formatRate(outcomes.reply_rate.overall_rate)}
                  </span>
                }
              >
                {outcomes.reply_rate.by_hour.length === 0 ? (
                  <EmptyState title="Not enough messages">
                    A reply rate by hour needs delivered messages spread across the day.
                  </EmptyState>
                ) : (
                  <>
                    <LineChart
                      labels={outcomes.reply_rate.by_hour.map((entry) =>
                        String(entry.hour).padStart(2, '0'),
                      )}
                      values={outcomes.reply_rate.by_hour.map((entry) => entry.rate)}
                      ariaLabel={`Reply rate by hour of day. Overall ${formatRate(
                        outcomes.reply_rate.overall_rate,
                      )} of ${formatCount(outcomes.reply_rate.delivered)} delivered messages received a reply.`}
                    />
                    <p className="msg-muted msg-small" style={{ margin: '0.5rem 0 0', lineHeight: 1.5 }}>
                      {outcomes.reply_rate.basis} {formatCount(outcomes.reply_rate.replied)} of{' '}
                      {formatCount(outcomes.reply_rate.delivered)} delivered messages.
                    </p>
                  </>
                )}
              </Panel>

              <Panel title="Resolution" subtitle="Conversations opened in this period">
                <dl className="msg-definition-list">
                  <dt>Opened</dt>
                  <dd>{formatCount(outcomes.resolution.conversations_opened)}</dd>
                  <dt>Resolved</dt>
                  <dd>{formatCount(outcomes.resolution.conversations_resolved)}</dd>
                  <dt>Resolution rate</dt>
                  <dd>{formatRate(outcomes.resolution.resolution_rate)}</dd>
                  <dt>Median time to resolve</dt>
                  <dd>{formatDuration(outcomes.resolution.median_resolution_seconds)}</dd>
                  <dt>Reopened</dt>
                  <dd>{formatCount(outcomes.resolution.reopened)}</dd>
                </dl>
                <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
                  {outcomes.resolution.basis.note}
                </p>
              </Panel>
            </div>

            <div className="msg-grid msg-split">
              <ChannelComparison outcomes={outcomes} fallbackCurrency={currency} />
              <JourneyPerformance outcomes={outcomes} />
            </div>

            <EvidenceTable period={period} windowHours={window} />

            <AttributionDefinitions outcomes={outcomes} windowHours={window} onWindowChange={setWindow} />
          </>
        )}
      </PanelState>
    </div>
  )
}

// ---------------------------------------------------------------------------

/**
 * An outcome tile.
 *
 * `pending` means the owning product is not connected, so the value cannot be
 * read — the tile says that rather than showing zero, because zero collections
 * and "we cannot see your collections" are completely different statements.
 */
function OutcomeTile({
  label,
  block,
  icon: Icon,
  fallbackCurrency,
  countOnly = false,
}: {
  label: string
  block: OutcomeBlock
  icon: typeof CircleDollarSign
  fallbackCurrency: string
  countOnly?: boolean
}) {
  const first = block.by_currency[0]
  const value = countOnly
    ? formatCount(block.counted ?? block.linked_count)
    : first
      ? formatMoney(first.amount_minor, first.currency)
      : formatMoney(0, fallbackCurrency)

  return (
    <div className="msg-metric">
      <div
        className={
          block.state === 'pending'
            ? 'msg-metric-icon msg-metric-icon-warning'
            : block.state === 'partial'
              ? 'msg-metric-icon msg-metric-icon-warning'
              : 'msg-metric-icon'
        }
        aria-hidden
      >
        <Icon size={19} />
      </div>
      <div className="msg-metric-body">
        <span className="msg-metric-label">{label}</span>

        {block.state === 'pending' ? (
          <>
            <span className="msg-metric-value" style={{ fontSize: '1.15rem' }}>
              Unavailable
            </span>
            <div className="msg-metric-foot">
              <StatusPill tone="warning">Connection pending</StatusPill>
            </div>
          </>
        ) : (
          <>
            <span className="msg-metric-value">{value}</span>
            <div className="msg-metric-foot">
              {block.state === 'partial' && <StatusPill tone="warning">Partial</StatusPill>}
              <span className="msg-muted">
                {formatCount(block.linked_count)} linked
                {block.unresolved > 0 && `, ${formatCount(block.unresolved)} not valued`}
              </span>
            </div>
          </>
        )}

        {block.message && (
          <p className="msg-muted msg-small" style={{ margin: '0.35rem 0 0', lineHeight: 1.45, fontSize: '0.74rem' }}>
            {block.message}
          </p>
        )}

        {/* Two currencies are never added together. */}
        {block.by_currency.length > 1 && (
          <p className="msg-muted msg-small" style={{ margin: '0.25rem 0 0', fontSize: '0.74rem' }}>
            {block.combined_note ??
              `Also ${block.by_currency
                .slice(1)
                .map((entry) => formatMoney(entry.amount_minor, entry.currency))
                .join(', ')}, shown separately.`}
          </p>
        )}
      </div>
    </div>
  )
}

/**
 * Appointment confirmations.
 *
 * Read from Aicountly Appointments. NOT derived from delivered reminders — a
 * delivery receipt says a phone buzzed, and the tile says so where the
 * integration is absent.
 */
function AppointmentsTile({ block }: { block: OutcomeBlock & { confirmed?: number | null } }) {
  return (
    <div className="msg-metric">
      <div
        className={block.state === 'pending' ? 'msg-metric-icon msg-metric-icon-warning' : 'msg-metric-icon'}
        aria-hidden
      >
        <CalendarCheck size={19} />
      </div>
      <div className="msg-metric-body">
        <span className="msg-metric-label">Appointments confirmed</span>

        {block.state === 'pending' || block.confirmed === null || block.confirmed === undefined ? (
          <>
            <span className="msg-metric-value" style={{ fontSize: '1.15rem' }}>
              Unavailable
            </span>
            <div className="msg-metric-foot">
              <StatusPill tone="warning">Connection pending</StatusPill>
            </div>
          </>
        ) : (
          <>
            <span className="msg-metric-value">{formatCount(block.confirmed)}</span>
            <div className="msg-metric-foot">
              {block.state === 'partial' && <StatusPill tone="warning">Partial</StatusPill>}
              <span className="msg-muted">{formatCount(block.linked_count)} linked</span>
            </div>
          </>
        )}

        <p className="msg-muted msg-small" style={{ margin: '0.35rem 0 0', lineHeight: 1.45, fontSize: '0.74rem' }}>
          {block.message ||
            'Confirmation status read live from Aicountly Appointments. Not inferred from delivered reminders.'}
        </p>
      </div>
    </div>
  )
}

function CostTile({ outcomes }: { outcomes: OutcomesResponse }) {
  const cost = outcomes.cost_per_resolved_conversation
  const first = cost.by_currency[0]

  return (
    <div className="msg-metric">
      <div className="msg-metric-icon" aria-hidden>
        <BarChart3 size={19} />
      </div>
      <div className="msg-metric-body">
        <span className="msg-metric-label">Cost per resolved conversation</span>

        {cost.state === 'unavailable' || first === undefined ? (
          <>
            <span className="msg-metric-value" style={{ fontSize: '1.15rem' }}>
              —
            </span>
            <p className="msg-muted msg-small" style={{ margin: '0.25rem 0 0', fontSize: '0.74rem' }}>
              {cost.message}
            </p>
          </>
        ) : (
          <>
            <span className="msg-metric-value">
              {formatMoney(first.cost_per_resolved_minor, first.currency)}
            </span>
            <div className="msg-metric-foot">
              {first.completeness === 'partial' && <StatusPill tone="warning">A floor, not final</StatusPill>}
              <span className="msg-muted">over {formatCount(first.resolved)} resolved</span>
            </div>
            <p className="msg-muted msg-small" style={{ margin: '0.35rem 0 0', lineHeight: 1.45, fontSize: '0.74rem' }}>
              {first.note ?? cost.basis}
            </p>
          </>
        )}
      </div>
    </div>
  )
}

/**
 * "What could improve?"
 *
 * Observations, hypotheses — and NO predictions, stated explicitly. An
 * observation is a difference this product measured, with its arms and its
 * caveats. A hypothesis is a question with a proposed test attached, and the
 * test is a proposal that somebody reviews: pressing the button does not
 * split an audience or send anything.
 */
function InsightsPanel({ insights }: { insights: OutcomesResponse['insights'] }) {
  if (insights.observations.length === 0 && insights.hypotheses.length === 0) {
    return (
      <Panel title="What could improve?" mint>
        <EmptyState title="Not enough data to compare anything yet" icon={Lightbulb}>
          Differences are only reported once each group has at least {insights.minimum_sample} messages. Below that,
          a difference is noise and reporting it would be worse than saying nothing.
        </EmptyState>
      </Panel>
    )
  }

  return (
    <Panel
      title="What could improve?"
      subtitle="Measured differences, and the tests that would explain them"
      mint
      action={<StatusPill tone="neutral">Beta</StatusPill>}
    >
      <div className="msg-stack">
        {insights.observations.map((insight) => (
          <InsightCard key={insight.key} insight={insight} />
        ))}
        {insights.hypotheses.map((insight) => (
          <InsightCard key={insight.key} insight={insight} />
        ))}
      </div>

      <p className="msg-muted msg-small" style={{ margin: '0.85rem 0 0', lineHeight: 1.55 }}>
        {insights.predictions_note}
      </p>
    </Panel>
  )
}

function InsightCard({ insight }: { insight: Insight }) {
  return (
    <div className="msg-card">
      <div className="msg-card-body">
        <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', marginBottom: '0.4rem' }}>
          {/* Observation and hypothesis are labelled differently because they
              are different claims. */}
          <StatusPill tone={insight.kind === 'observation' ? 'brand' : 'info'}>
            {insight.kind === 'observation' ? 'Measured' : 'Hypothesis'}
          </StatusPill>
        </div>

        <strong style={{ display: 'block', fontSize: '0.92rem', lineHeight: 1.4 }}>{insight.title}</strong>
        <p style={{ margin: '0.4rem 0 0', lineHeight: 1.55, fontSize: '0.85rem' }}>{insight.detail}</p>

        {insight.measured && (
          <details style={{ marginTop: '0.6rem' }}>
            <summary style={{ cursor: 'pointer', fontSize: '0.8rem' }}>Review the evidence</summary>
            <dl className="msg-definition-list" style={{ marginTop: '0.5rem', gridTemplateColumns: 'auto 1fr' }}>
              <dt>Metric</dt>
              <dd style={{ textAlign: 'left' }}>{insight.measured.metric}</dd>
              <dt>Period</dt>
              <dd style={{ textAlign: 'left' }}>
                {insight.measured.period.label} · {insight.measured.period.timezone}
              </dd>
              <dt>Cohort</dt>
              <dd style={{ textAlign: 'left' }}>{insight.measured.cohort}</dd>
            </dl>

            {insight.caveats && insight.caveats.length > 0 && (
              <>
                <p className="msg-small" style={{ margin: '0.6rem 0 0.3rem', fontWeight: 650 }}>
                  What this does not show
                </p>
                <ul style={{ margin: 0, paddingLeft: '1.1rem', fontSize: '0.79rem', lineHeight: 1.55 }}>
                  {insight.caveats.map((caveat, index) => (
                    <li key={index} className="msg-muted">
                      {caveat}
                    </li>
                  ))}
                </ul>
              </>
            )}
          </details>
        )}

        {insight.proposed_test && (
          <details style={{ marginTop: '0.6rem' }}>
            <summary style={{ cursor: 'pointer', fontSize: '0.8rem' }}>
              <FlaskConical size={12} aria-hidden style={{ verticalAlign: '-1px' }} /> A test that would answer this
            </summary>
            <div style={{ marginTop: '0.5rem', fontSize: '0.82rem', lineHeight: 1.55 }}>
              <p style={{ margin: '0 0 0.4rem' }}>
                <strong style={{ display: 'inline' }}>Design:</strong> {insight.proposed_test.design}
              </p>
              <p style={{ margin: '0 0 0.4rem' }}>
                <strong style={{ display: 'inline' }}>Measure:</strong> {insight.proposed_test.metric}
              </p>
              <p style={{ margin: '0 0 0.4rem' }}>
                <strong style={{ display: 'inline' }}>Needs at least:</strong>{' '}
                {formatCount(insight.proposed_test.minimum_per_arm)} per group
              </p>
              <ul style={{ margin: '0 0 0.4rem', paddingLeft: '1.1rem' }}>
                {insight.proposed_test.requires.map((requirement, index) => (
                  <li key={index}>{requirement}</li>
                ))}
              </ul>
              <Notice tone="warning">{insight.proposed_test.note}</Notice>
            </div>
          </details>
        )}
      </div>
    </div>
  )
}

function ChannelComparison({
  outcomes,
  fallbackCurrency,
}: {
  outcomes: OutcomesResponse
  fallbackCurrency: string
}) {
  if (outcomes.channel_comparison.length === 0) {
    return (
      <Panel title="Channel comparison">
        <EmptyState title="No messages in this period" />
      </Panel>
    )
  }

  return (
    <Panel title="Channel comparison" subtitle="Provider acceptance, confirmed delivery and cost">
      <div className="msg-table-wrap">
        <table className="msg-table">
          <caption className="msg-visually-hidden">Delivery and cost by channel</caption>
          <thead>
            <tr>
              <th scope="col">Channel</th>
              <th scope="col">Accepted</th>
              <th scope="col">Confirmed</th>
              <th scope="col">Unconfirmed</th>
              <th scope="col">Failed</th>
              <th scope="col">Cost</th>
            </tr>
          </thead>
          <tbody>
            {outcomes.channel_comparison.map((row) => {
              const accepted = Number(row.accepted)
              const delivered = Number(row.delivered)
              const priced = Number(row.priced)

              return (
                <tr key={row.channel}>
                  <td>{channelLabel(row.channel)}</td>
                  <td className="num">{formatCount(accepted)}</td>
                  <td className="num">
                    {formatCount(delivered)}
                    <br />
                    <span className="msg-muted msg-small">
                      {accepted > 0 ? formatRate(delivered / accepted) : '—'}
                    </span>
                  </td>
                  <td className="num">{formatCount(Number(row.unconfirmed))}</td>
                  <td className="num">{formatCount(Number(row.failed))}</td>
                  <td className="num">
                    {formatMoney(Number(row.cost_minor), fallbackCurrency)}
                    {priced < accepted && (
                      <>
                        <br />
                        {/* Said, because a cost total over partially-priced
                            messages is a floor rather than the bill. */}
                        <span className="msg-muted msg-small">
                          {formatCount(priced)} of {formatCount(accepted)} priced
                        </span>
                      </>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.5 }}>
        “Confirmed” counts only messages a provider reported as delivered or read. Unconfirmed messages are counted
        separately and are in the denominator of the rate, so it does not flatter itself.
      </p>
    </Panel>
  )
}

function JourneyPerformance({ outcomes }: { outcomes: OutcomesResponse }) {
  const rows = outcomes.journey_performance.filter((row) => Number(row.runs) > 0)

  if (rows.length === 0) {
    return (
      <Panel title="Journey performance">
        <EmptyState title="No journey runs in this period">
          Once a journey runs, this shows what happened to each one and why.
        </EmptyState>
      </Panel>
    )
  }

  return (
    <Panel title="Journey performance" subtitle="Runs, and what became of them">
      <div className="msg-table-wrap">
        <table className="msg-table">
          <caption className="msg-visually-hidden">Journey runs and outcomes in this period</caption>
          <thead>
            <tr>
              <th scope="col">Journey</th>
              <th scope="col">Runs</th>
              <th scope="col">Sent</th>
              <th scope="col">Not eligible</th>
              <th scope="col">Paused</th>
              <th scope="col">Linked</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.journey_uuid}>
                <td>
                  <Link to={`/journeys?journey=${row.journey_uuid}`}>{row.name}</Link>
                </td>
                <td className="num">{formatCount(Number(row.runs))}</td>
                <td className="num">{formatCount(Number(row.sent))}</td>
                <td className="num">{formatCount(Number(row.not_eligible))}</td>
                <td className="num">
                  {formatCount(Number(row.paused))}
                  {Number(row.source_unavailable) > 0 && (
                    <>
                      <br />
                      <span className="msg-muted msg-small">
                        {formatCount(Number(row.source_unavailable))} source unavailable
                      </span>
                    </>
                  )}
                </td>
                <td className="num">{formatCount(Number(row.linked_outcomes))}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.5 }}>
        A paused run sent nothing: it stopped rather than messaging from data it could not confirm. “Not eligible”
        is a customer who has not consented, which is the journey working correctly.
      </p>
    </Panel>
  )
}

/**
 * The evidence table.
 *
 * Deliberately shows the MATCHING METHOD per row, because the strength of the
 * link varies enormously and a row that says "contact window" should not read
 * the same as one that says "the customer quoted the invoice number".
 */
function EvidenceTable({ period, windowHours }: { period: string; windowHours: string }) {
  const { timezone } = useMessaging()

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api.list<Record<string, unknown>>(
        'v1/outcomes/evidence',
        { period, window_hours: windowHours, limit: 25 },
        signal,
      ),
    [period, windowHours],
  )

  return (
    <Panel title="Outcome evidence" subtitle="Recent conversations linked to business events">
      <PanelState
        loading={loading}
        error={error}
        data={data}
        onRetry={reload}
        skeletonRows={4}
        isEmpty={(response) => response.data.length === 0}
        emptyTitle="Nothing linked in this period"
        emptyBody="A link is recorded when a business event follows a message within the attribution window."
      >
        {(response) => (
          <>
            <div className="msg-table-wrap">
              <table className="msg-table">
                <caption className="msg-visually-hidden">Linked business outcomes, newest first</caption>
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">Conversation</th>
                    <th scope="col">Source event</th>
                    <th scope="col">How it was matched</th>
                    <th scope="col">Window</th>
                  </tr>
                </thead>
                <tbody>
                  {response.data.map((row) => (
                    <tr key={String(row.outcome_uuid)}>
                      <td className="msg-nowrap">
                        {new Date(String(row.outcome_at)).toLocaleString('en-IN', {
                          dateStyle: 'medium',
                          timeStyle: 'short',
                          timeZone: timezone,
                        })}
                      </td>
                      <td>
                        {row.conversation_uuid !== null ? (
                          <Link to={`/inbox/${String(row.conversation_uuid)}`}>
                            {String(row.customer_address ?? 'Conversation')}
                          </Link>
                        ) : (
                          <span className="msg-muted">—</span>
                        )}
                        {row.journey_name !== null && row.journey_name !== undefined && (
                          <>
                            <br />
                            <span className="msg-muted msg-small">{String(row.journey_name)}</span>
                          </>
                        )}
                      </td>
                      <td>
                        {String(row.external_label || row.external_id)}
                        <br />
                        <span className="msg-muted msg-small">
                          Aicountly {humanise(String(row.owner_product))} ·{' '}
                          {humanise(String(row.outcome_kind))}
                        </span>
                      </td>
                      <td>
                        <StatusPill tone={matchTone(String(row.match_method))}>
                          {humanise(String(row.match_method))}
                        </StatusPill>
                      </td>
                      <td className="msg-nowrap msg-muted msg-small">
                        {String(row.window_hours)}h · {String(row.window_timezone)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
              {String(data?.meta.note ?? '')}
            </p>
          </>
        )}
      </PanelState>
    </Panel>
  )
}

/**
 * The strength of an attribution method, as a tone.
 *
 * A payment-link callback is Pay telling us the link we sent was paid. A
 * contact window is "the same person paid something within three days". Those
 * are not the same evidence and the screen does not present them as such.
 */
function matchTone(method: string): 'success' | 'brand' | 'warning' | 'neutral' {
  switch (method) {
    case 'payment_link_callback':
      return 'success'
    case 'journey_subject':
    case 'reference_match':
      return 'brand'
    case 'contact_window':
      return 'warning'
    default:
      return 'neutral'
  }
}

/**
 * Every definition, in one place, on the screen.
 *
 * Not a tooltip and not documentation somebody has to go and find. The window
 * is adjustable here, and changing it does NOT rewrite history — each existing
 * link keeps the window it was made under, which is why the evidence table
 * shows it per row.
 */
function AttributionDefinitions({
  outcomes,
  windowHours,
  onWindowChange,
}: {
  outcomes: OutcomesResponse
  windowHours: string
  onWindowChange: (next: string) => void
}) {
  return (
    <Panel title="How these numbers are made" subtitle="Every definition this screen relies on">
      <div className="msg-grid msg-grid-2" style={{ marginBottom: 0 }}>
        <div>
          <label className="msg-field" style={{ maxWidth: '18rem' }}>
            <span style={{ display: 'block', fontSize: '0.82rem', fontWeight: 650, marginBottom: '0.3rem' }}>
              Attribution window
            </span>
            <span className="msg-field-hint">
              How long after a message a business event still counts as linked. Changing this affects NEW links
              only — existing ones keep the window they were made under.
            </span>
            <select
              className="msg-select"
              value={windowHours}
              onChange={(event) => onWindowChange(event.target.value)}
            >
              <option value="24">24 hours</option>
              <option value="72">72 hours</option>
              <option value="168">7 days</option>
              <option value="720">30 days</option>
            </select>
          </label>

          <dl className="msg-definition-list" style={{ gridTemplateColumns: 'auto 1fr' }}>
            <dt>Timezone</dt>
            <dd style={{ textAlign: 'left' }}>{outcomes.attribution.timezone}</dd>
            <dt>Cardinality</dt>
            <dd style={{ textAlign: 'left' }}>{outcomes.attribution.cardinality}</dd>
            <dt>Deduplication</dt>
            <dd style={{ textAlign: 'left' }}>{outcomes.attribution.deduplication}</dd>
          </dl>
        </div>

        <div>
          <p className="msg-small" style={{ margin: '0 0 0.5rem', fontWeight: 650 }}>
            How a link is made, strongest first
          </p>
          <dl className="msg-definition-list" style={{ gridTemplateColumns: 'auto 1fr' }}>
            {Object.entries(outcomes.attribution.matching_methods).map(([method, description]) => (
              <div key={method} style={{ display: 'contents' }}>
                <dt>
                  <StatusPill tone={matchTone(method)}>{humanise(method)}</StatusPill>
                </dt>
                <dd style={{ textAlign: 'left' }}>{description}</dd>
              </div>
            ))}
          </dl>

          <Notice tone="warning" title="Currency is never summed across currencies">
            Where outcomes are in more than one currency they are reported separately. No conversion is applied
            unless a rate source is configured, because a total with no stated rate is a number that is confidently
            wrong.
          </Notice>
        </div>
      </div>
    </Panel>
  )
}

/**
 * The export, as a CSV the browser downloads.
 *
 * The caveats are written INTO the file, above the header row, because a
 * spreadsheet outlives the screen it came from and somebody will read it in
 * six months with no idea what "linked" meant.
 */
function downloadCsv(payload: {
  rows: Array<Record<string, string | number>>
  caveats: string[]
  period: { label: string }
  generated_at: string
  truncated_note: string | null
}): void {
  if (payload.rows.length === 0) return

  const escape = (value: string | number): string => {
    const text = String(value ?? '')
    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
  }

  const lines: string[] = [
    `# Aicountly Messaging — linked business outcomes`,
    `# Period: ${payload.period.label}`,
    `# Generated: ${payload.generated_at}`,
  ]
  for (const caveat of payload.caveats) {
    lines.push(`# ${caveat}`)
  }
  if (payload.truncated_note) {
    lines.push(`# ${payload.truncated_note}`)
  }
  lines.push('')

  const headers = Object.keys(payload.rows[0])
  lines.push(headers.map(escape).join(','))
  for (const row of payload.rows) {
    lines.push(headers.map((header) => escape(row[header])).join(','))
  }

  const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `messaging-outcomes-${new Date().toISOString().slice(0, 10)}.csv`
  anchor.click()
  URL.revokeObjectURL(url)
}
