/**
 * The shared product components.
 *
 * Two things here are load-bearing rather than decorative:
 *
 *  1. `SourceBadge` and `FreshnessNote`. Every panel showing another product's
 *     data says WHERE it came from and WHEN it was read. An agent about to
 *     promise a customer a figure needs to know whether it is four seconds old
 *     or four minutes.
 *
 *  2. `PanelState`. Loading, empty, error, PERMISSION and PENDING are five
 *     different situations and this product never collapses them. "Nothing
 *     here", "you may not see this" and "Pay is not connected yet" send a user
 *     to three different places, and showing the wrong one wastes their time.
 */

import type { ReactNode } from 'react'
import {
  AlertTriangle,
  CheckCircle2,
  CircleAlert,
  Clock,
  Info,
  Loader2,
  Lock,
  PlugZap,
  RefreshCw,
  TrendingDown,
  TrendingUp,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { ApiError } from '../services/api'

/** The five adapter states the backend reports, plus our own loading. */
export type SourceState = 'ready' | 'pending' | 'unavailable' | 'forbidden' | 'unsupported'

export interface SourcePanel<T = unknown> {
  state: SourceState
  source: string
  fetched_at: string
  message: string
  retryable?: boolean
  data: T
}

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------

export function Button({
  children,
  onClick,
  tone = 'default',
  small = false,
  disabled = false,
  pending = false,
  type = 'button',
  title,
  ariaLabel,
}: {
  children: ReactNode
  onClick?: () => void
  tone?: 'default' | 'primary' | 'danger' | 'ghost'
  small?: boolean
  disabled?: boolean
  pending?: boolean
  type?: 'button' | 'submit'
  title?: string
  ariaLabel?: string
}) {
  const classes = ['msg-button']
  if (tone === 'primary') classes.push('msg-button-primary')
  if (tone === 'danger') classes.push('msg-button-danger')
  if (tone === 'ghost') classes.push('msg-button-ghost')
  if (small) classes.push('msg-button-small')

  return (
    <button
      type={type}
      className={classes.join(' ')}
      onClick={onClick}
      disabled={disabled || pending}
      title={title}
      aria-label={ariaLabel}
      // Announced, so a screen reader hears that a send is in flight rather
      // than silence.
      aria-busy={pending || undefined}
    >
      {pending && <Loader2 size={14} className="msg-spin" aria-hidden />}
      {children}
    </button>
  )
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/**
 * Money from minor units, with the currency named.
 *
 * The currency is never optional and never defaulted silently: a figure
 * rendered without one is a figure somebody will read as their own currency.
 */
export function formatMoney(minor: number | null | undefined, currency: string): string {
  if (minor === null || minor === undefined) return '—'

  try {
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency,
      maximumFractionDigits: 2,
    }).format(minor / 100)
  } catch {
    return `${currency} ${(minor / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
  }
}

export function formatCount(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return value.toLocaleString('en-IN')
}

/**
 * A duration a person can read.
 *
 * "2m 14s" rather than 134, because a median response time is read at a glance
 * on a wall display.
 */
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 60) return `${Math.round(seconds)}s`

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) {
    const remainder = Math.round(seconds % 60)
    return remainder > 0 ? `${minutes}m ${remainder}s` : `${minutes}m`
  }

  const hours = Math.floor(minutes / 60)
  if (hours < 24) {
    const remainder = minutes % 60
    return remainder > 0 ? `${hours}h ${remainder}m` : `${hours}h`
  }

  const days = Math.floor(hours / 24)
  return `${days}d ${hours % 24}h`
}

/** A rate as a percentage, or an em dash when the denominator was zero. */
export function formatRate(rate: number | null | undefined): string {
  if (rate === null || rate === undefined) return '—'
  return `${(rate * 100).toFixed(1)}%`
}

export function timeAgo(iso: string | null | undefined): string {
  if (!iso) return 'never'

  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return 'unknown'

  const seconds = Math.max(0, Math.round((Date.now() - then) / 1000))
  if (seconds < 10) return 'just now'
  if (seconds < 60) return `${seconds}s ago`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  return `${Math.floor(seconds / 86400)}d ago`
}

export function formatDateTime(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'

  try {
    return new Intl.DateTimeFormat('en-IN', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: timezone,
    }).format(new Date(iso))
  } catch {
    return iso
  }
}

export function formatDate(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'

  try {
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeZone: timezone }).format(new Date(iso))
  } catch {
    return iso
  }
}

// ---------------------------------------------------------------------------
// Metric tiles
// ---------------------------------------------------------------------------

export interface MetricTile {
  key: string
  label: string
  value: number | null
  previous?: number | null
  unit: 'count' | 'seconds' | 'money' | 'rate'
  definition?: string
  lower_is_better?: boolean
  spend?: SpendBlock
  [key: string]: unknown
}

export interface SpendBlock {
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

export function MetricCard({
  metric,
  icon: Icon,
  tone = 'brand',
  currency = 'INR',
}: {
  metric: MetricTile
  icon?: LucideIcon
  tone?: 'brand' | 'warning' | 'danger' | 'info'
  currency?: string
}) {
  const iconClass =
    tone === 'warning'
      ? 'msg-metric-icon msg-metric-icon-warning'
      : tone === 'danger'
        ? 'msg-metric-icon msg-metric-icon-danger'
        : tone === 'info'
          ? 'msg-metric-icon msg-metric-icon-info'
          : 'msg-metric-icon'

  return (
    <div className="msg-metric">
      {Icon && (
        <div className={iconClass} aria-hidden>
          <Icon size={19} />
        </div>
      )}
      <div className="msg-metric-body">
        <span className="msg-metric-label">{metric.label}</span>
        <span className="msg-metric-value">{renderMetricValue(metric, currency)}</span>
        <div className="msg-metric-foot">
          {metric.unit === 'money' && metric.spend ? (
            <SpendFoot spend={metric.spend} />
          ) : (
            <ChangeChip
              value={metric.value}
              previous={metric.previous ?? null}
              lowerIsBetter={metric.lower_is_better === true}
            />
          )}
        </div>
        {metric.definition && (
          // The definition is always present in the DOM for a screen reader
          // and revealed on demand for everyone else. A metric whose
          // definition is not written down is a metric two people read
          // differently.
          <details className="msg-small" style={{ marginTop: '0.4rem' }}>
            <summary className="msg-muted" style={{ cursor: 'pointer', fontSize: '0.74rem' }}>
              How this is measured
            </summary>
            <p className="msg-muted" style={{ margin: '0.35rem 0 0', lineHeight: 1.5, fontSize: '0.76rem' }}>
              {metric.definition}
            </p>
          </details>
        )}
      </div>
    </div>
  )
}

function renderMetricValue(metric: MetricTile, currency: string): string {
  if (metric.unit === 'money') {
    const entries = metric.spend?.by_currency ?? []
    if (entries.length === 0) return formatMoney(0, currency)
    // More than one currency: show the first and let SpendFoot say the rest.
    // NEVER a sum — see the backend's MetricsService::spend.
    return formatMoney(entries[0].provider_cost_minor, entries[0].currency)
  }
  if (metric.unit === 'seconds') return formatDuration(metric.value)
  if (metric.unit === 'rate') return formatRate(metric.value)
  return formatCount(metric.value)
}

function SpendFoot({ spend }: { spend: SpendBlock }) {
  const first = spend.by_currency[0]

  return (
    <>
      {first?.completeness === 'partial' && (
        <span className="msg-status msg-status-warning">
          <Clock size={11} aria-hidden />
          Partial
        </span>
      )}
      {spend.by_currency.length > 1 && (
        <span className="msg-muted">
          + {spend.by_currency.length - 1} other {spend.by_currency.length === 2 ? 'currency' : 'currencies'}, shown
          separately
        </span>
      )}
      {first?.completeness_note && <span className="msg-muted">{first.completeness_note}</span>}
    </>
  )
}

/**
 * The period-on-period change.
 *
 * `lowerIsBetter` exists because a UI cannot infer direction from a number: a
 * rising failure count is bad and a rising delivered count is good, and
 * colouring both green because they went up is worse than no colour at all.
 * The arrow and the sign are text, so the meaning survives without colour.
 */
export function ChangeChip({
  value,
  previous,
  lowerIsBetter = false,
}: {
  value: number | null
  previous: number | null
  lowerIsBetter?: boolean
}) {
  if (value === null || previous === null || previous === 0) {
    return <span className="msg-muted">no comparison</span>
  }

  const delta = ((value - previous) / Math.abs(previous)) * 100
  if (Math.abs(delta) < 0.5) {
    return <span className="msg-tone-neutral">unchanged vs previous period</span>
  }

  const rising = delta > 0
  const good = lowerIsBetter ? !rising : rising
  const Arrow = rising ? TrendingUp : TrendingDown

  return (
    <span className={`msg-change ${good ? 'msg-tone-positive' : 'msg-tone-negative'}`}>
      <Arrow size={13} aria-hidden />
      {rising ? '+' : ''}
      {delta.toFixed(0)}%
      <span className="msg-muted" style={{ fontWeight: 400 }}>
        vs previous period
      </span>
    </span>
  )
}

// ---------------------------------------------------------------------------
// Panels
// ---------------------------------------------------------------------------

export function Panel({
  title,
  subtitle,
  action,
  children,
  mint = false,
  headingLevel = 2,
}: {
  title?: string
  subtitle?: string
  action?: ReactNode
  children: ReactNode
  mint?: boolean
  headingLevel?: 2 | 3
}) {
  const Heading = headingLevel === 3 ? 'h3' : 'h2'

  return (
    <section className={mint ? 'msg-panel msg-mint' : 'msg-panel'}>
      {(title || action) && (
        <div className="msg-panel-header">
          <div style={{ minWidth: 0 }}>
            {title && <Heading style={{ margin: 0, fontSize: '1rem', fontWeight: 650 }}>{title}</Heading>}
            {subtitle && <p>{subtitle}</p>}
          </div>
          {action && <div className="msg-actions">{action}</div>}
        </div>
      )}
      {children}
    </section>
  )
}

export function Notice({
  tone = 'info',
  title,
  children,
  action,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success' | 'brand'
  title?: string
  children: ReactNode
  action?: ReactNode
}) {
  const Icon =
    tone === 'danger' ? CircleAlert : tone === 'warning' ? AlertTriangle : tone === 'success' ? CheckCircle2 : Info

  return (
    <div className={`msg-notice msg-notice-${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon size={17} aria-hidden style={{ flex: 'none', marginTop: '0.1rem' }} />
      <div className="msg-notice-body">
        {title && <strong>{title}</strong>}
        {children}
      </div>
      {action && <div className="msg-notice-action">{action}</div>}
    </div>
  )
}

// ---------------------------------------------------------------------------
// The five states, kept apart on purpose
// ---------------------------------------------------------------------------

export function LoadingRows({ rows = 3, height = 56 }: { rows?: number; height?: number }) {
  return (
    <div className="msg-stack" aria-busy="true" aria-live="polite" aria-label="Loading">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="msg-skeleton" style={{ height }} />
      ))}
    </div>
  )
}

export function EmptyState({
  title,
  children,
  action,
  icon: Icon = Info,
}: {
  title: string
  children?: ReactNode
  action?: ReactNode
  icon?: LucideIcon
}) {
  return (
    <div className="msg-state">
      <div className="msg-state-icon">
        <Icon size={26} aria-hidden />
      </div>
      <h3>{title}</h3>
      {children && <p>{children}</p>}
      {action && <div className="msg-state-actions">{action}</div>}
    </div>
  )
}

export function ErrorState({
  error,
  onRetry,
  context,
}: {
  error: ApiError | Error
  onRetry?: () => void
  context?: string
}) {
  const apiError = error instanceof ApiError ? error : null
  const retryable = apiError?.retryable ?? true

  return (
    <div className="msg-state" role="alert">
      <div className="msg-state-icon" style={{ color: 'var(--danger)' }}>
        <CircleAlert size={26} aria-hidden />
      </div>
      <h3>{context ?? 'This could not be loaded'}</h3>
      <p>{error.message}</p>
      {onRetry && retryable && (
        <div className="msg-state-actions">
          <Button small onClick={onRetry}>
            <RefreshCw size={13} aria-hidden />
            Try again
          </Button>
        </div>
      )}
    </div>
  )
}

/**
 * "You may not see this."
 *
 * Deliberately NOT an error. Somebody without financial permission looking at
 * the inbox has not hit a fault, and showing them a red error box makes them
 * report a bug. It names who can change it, because they cannot.
 */
export function PermissionState({ children, what }: { children?: ReactNode; what?: string }) {
  return (
    <div className="msg-state">
      <div className="msg-state-icon">
        <Lock size={24} aria-hidden />
      </div>
      <h3>{what ? `You cannot see ${what}` : 'You do not have access to this'}</h3>
      <p>
        {children ??
          'Your Messaging permissions do not include this. A company owner can grant it under Settings → Access.'}
      </p>
    </div>
  )
}

/**
 * "This integration is not connected yet."
 *
 * Also not an error. Nothing is broken; an administrator has not finished. The
 * remedy comes from the backend and is only populated for somebody who could
 * act on it.
 */
export function PendingIntegrationState({
  product,
  children,
  remedy,
}: {
  product: string
  children?: ReactNode
  remedy?: string | null
}) {
  return (
    <div className="msg-state">
      <div className="msg-state-icon">
        <PlugZap size={24} aria-hidden />
      </div>
      <h3>{product} is not connected</h3>
      <p>{children ?? `This panel reads live from ${product}. Nothing is shown here until it is connected.`}</p>
      {remedy && (
        <p className="msg-small" style={{ marginTop: '0.6rem', color: 'var(--muted)' }}>
          {remedy}
        </p>
      )}
    </div>
  )
}

/**
 * Pick the right one of the five, for an async panel.
 *
 * The order matters: loading before error, error before empty. A panel that
 * showed "nothing here" while a request was in flight would be lying for a
 * second and a half.
 */
export function PanelState<T>({
  loading,
  error,
  data,
  isEmpty,
  emptyTitle,
  emptyBody,
  onRetry,
  context,
  children,
  skeletonRows = 3,
}: {
  loading: boolean
  error: ApiError | Error | null
  data: T | null
  isEmpty?: (data: T) => boolean
  emptyTitle?: string
  emptyBody?: ReactNode
  onRetry?: () => void
  context?: string
  children: (data: T) => ReactNode
  skeletonRows?: number
}) {
  if (loading && data === null) return <LoadingRows rows={skeletonRows} />

  if (error !== null) {
    const apiError = error instanceof ApiError ? error : null
    if (apiError?.status === 403) {
      return <PermissionState>{apiError.message}</PermissionState>
    }
    return <ErrorState error={error} onRetry={onRetry} context={context} />
  }

  if (data === null) {
    return <EmptyState title={emptyTitle ?? 'Nothing to show'}>{emptyBody}</EmptyState>
  }

  if (isEmpty?.(data)) {
    return <EmptyState title={emptyTitle ?? 'Nothing to show'}>{emptyBody}</EmptyState>
  }

  return <>{children(data)}</>
}

/**
 * The same five states for ONE live panel from another product.
 *
 * `SourcePanel` is exactly what the backend's BusinessContextService returns
 * per panel, so the inbox's right-hand column is five of these and one failing
 * takes down one.
 */
export function SourcePanelState<T>({
  panel,
  productName,
  children,
  onRetry,
}: {
  panel: SourcePanel<T> | undefined
  productName: string
  children: (data: T, panel: SourcePanel<T>) => ReactNode
  onRetry?: () => void
}) {
  if (!panel) return <LoadingRows rows={1} height={72} />

  if (panel.state === 'forbidden') {
    return <PermissionState what={productName.toLowerCase() + ' context'}>{panel.message}</PermissionState>
  }
  if (panel.state === 'pending') {
    return <PendingIntegrationState product={productName}>{panel.message}</PendingIntegrationState>
  }
  if (panel.state === 'unsupported') {
    return <EmptyState title={`No ${productName.toLowerCase()} context`}>{panel.message}</EmptyState>
  }
  if (panel.state === 'unavailable') {
    return (
      <Notice
        tone="warning"
        title={`${productName} could not be read`}
        action={
          panel.retryable && onRetry ? (
            <Button small onClick={onRetry}>
              Retry
            </Button>
          ) : undefined
        }
      >
        {panel.message} Nothing has been guessed or substituted.
      </Notice>
    )
  }

  return <>{children(panel.data, panel)}</>
}

// ---------------------------------------------------------------------------
// Provenance
// ---------------------------------------------------------------------------

const PRODUCT_NAMES: Record<string, string> = {
  contacts: 'Contacts',
  books: 'Books',
  sales: 'Sales',
  pay: 'Pay',
  appointments: 'Appointments',
  calendar: 'Calendar',
  docs: 'Drive',
  drive: 'Drive',
  reach: 'Reach',
  billing: 'Billing',
  manage: 'Manage',
  messaging: 'Messaging',
  provider: 'the provider',
}

export function productLabel(source: string): string {
  return PRODUCT_NAMES[source] ?? source
}

/**
 * "Books · read 4 seconds ago".
 *
 * On every panel that shows another product's data. This is not decoration: it
 * is what tells an agent whether the balance they are about to quote was read
 * now or four minutes ago.
 */
export function SourceBadge({
  source,
  fetchedAt,
  live = true,
}: {
  source: string
  fetchedAt?: string | null
  live?: boolean
}) {
  return (
    <span className="msg-source">
      {live && <span aria-hidden>●</span>}
      <strong>
        {source === 'messaging' ? 'Messaging' : `Aicountly ${productLabel(source)}`}
      </strong>
      {fetchedAt && <span>· read {timeAgo(fetchedAt)}</span>}
    </span>
  )
}

export function FreshnessNote({
  sources,
  onRefresh,
  refreshing = false,
  note,
}: {
  sources: Array<{ source: string; fetched_at?: string | null }>
  onRefresh?: () => void
  refreshing?: boolean
  note?: string
}) {
  return (
    <div className="msg-freshness">
      {sources.map((entry) => (
        <SourceBadge key={entry.source} source={entry.source} fetchedAt={entry.fetched_at} />
      ))}
      {note && <span>{note}</span>}
      <span className="msg-spacer" />
      {onRefresh && (
        <Button small tone="ghost" onClick={onRefresh} pending={refreshing} ariaLabel="Refresh live data">
          <RefreshCw size={13} aria-hidden />
          Refresh
        </Button>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Status pills — always with their label
// ---------------------------------------------------------------------------

export type StatusTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand'

export function StatusPill({
  children,
  tone = 'neutral',
  icon: Icon,
}: {
  children: ReactNode
  tone?: StatusTone
  icon?: LucideIcon
}) {
  return (
    <span className={`msg-status msg-status-${tone}`}>
      {Icon && <Icon size={11} aria-hidden />}
      {children}
    </span>
  )
}

/**
 * The tone for a message status.
 *
 * `provider_accepted` is deliberately NEUTRAL rather than green. It means a
 * provider took the message and nothing more, and a green tick beside it would
 * be the product claiming a delivery it has not been told about.
 */
export function messageStatusTone(status: string): StatusTone {
  switch (status) {
    case 'delivered':
    case 'read':
      return 'success'
    case 'provider_accepted':
    case 'queued':
    case 'dispatching':
      return 'neutral'
    case 'awaiting_approval':
    case 'approved':
    case 'submission_unknown':
      return 'warning'
    case 'failed':
      return 'danger'
    case 'cancelled':
      return 'neutral'
    default:
      return 'neutral'
  }
}

export function sourceStateTone(state: SourceState): StatusTone {
  switch (state) {
    case 'ready':
      return 'success'
    case 'pending':
      return 'warning'
    case 'forbidden':
      return 'neutral'
    case 'unsupported':
      return 'neutral'
    default:
      return 'danger'
  }
}

export function sourceStateLabel(state: SourceState): string {
  switch (state) {
    case 'ready':
      return 'Live'
    case 'pending':
      return 'Connection pending'
    case 'forbidden':
      return 'Permission required'
    case 'unsupported':
      return 'Not applicable'
    default:
      return 'Unavailable'
  }
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

export function Field({
  label,
  hint,
  error,
  htmlFor,
  children,
  required = false,
}: {
  label: string
  hint?: ReactNode
  error?: string | null
  htmlFor: string
  children: ReactNode
  required?: boolean
}) {
  const hintId = hint ? `${htmlFor}-hint` : undefined
  const errorId = error ? `${htmlFor}-error` : undefined

  return (
    <div className="msg-field">
      {/* A real label bound to a real control. `aria-describedby` wiring is the
          caller's job via the ids below, which is why they are deterministic. */}
      <label htmlFor={htmlFor}>
        {label}
        {required && (
          <span aria-hidden style={{ color: 'var(--danger)' }}>
            {' '}
            *
          </span>
        )}
        {required && <span className="msg-visually-hidden"> (required)</span>}
      </label>
      {hint && (
        <span className="msg-field-hint" id={hintId}>
          {hint}
        </span>
      )}
      {children}
      {error && (
        <span className="msg-field-error" id={errorId} role="alert">
          {error}
        </span>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Drawer
// ---------------------------------------------------------------------------

export function Drawer({
  title,
  subtitle,
  onClose,
  children,
  footer,
}: {
  title: string
  subtitle?: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}) {
  return (
    <>
      <button type="button" className="msg-drawer-scrim" aria-label="Close" onClick={onClose} />
      <div className="msg-drawer" role="dialog" aria-modal="true" aria-label={title}>
        <div className="msg-drawer-head">
          <div style={{ minWidth: 0 }}>
            <h2>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          <Button tone="ghost" small onClick={onClose} ariaLabel="Close">
            ✕
          </Button>
        </div>
        <div className="msg-drawer-body">{children}</div>
        {footer && <div className="msg-drawer-foot">{footer}</div>}
      </div>
    </>
  )
}

// ---------------------------------------------------------------------------
// Announcements
// ---------------------------------------------------------------------------

/**
 * A polite live region for asynchronous outcomes.
 *
 * A sighted user sees a message appear in the thread. A screen reader user
 * needs telling, and "Accepted by the provider. Delivery is confirmed
 * separately" is exactly the kind of thing they must not miss.
 */
export function Announce({ message }: { message: string | null }) {
  return (
    <div className="msg-visually-hidden" role="status" aria-live="polite">
      {message ?? ''}
    </div>
  )
}
