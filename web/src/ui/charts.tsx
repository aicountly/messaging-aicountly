/**
 * Charts, as inline SVG.
 *
 * No charting library: these four shapes are all this product needs, and a
 * dependency that renders to canvas would take the axis labels out of the
 * accessibility tree. Every chart here carries `role="img"` and an
 * `aria-label` that states the actual figures, so the information is available
 * without seeing it — and a table alternative is offered where the series is
 * long enough to matter.
 *
 * COLOUR IS NEVER THE ONLY ENCODING. Series are distinguished by a legend with
 * text labels, and the delivery chart stacks in a fixed order that the legend
 * states. A reader who cannot separate the greens can still read the numbers.
 */

import { useId } from 'react'

/**
 * The series palette.
 *
 * Built from the brand green outward rather than from a generic categorical
 * scheme, and ordered so adjacent series are distinguishable in greyscale as
 * well as in colour.
 */
export const SERIES_COLOURS = ['#25b003', '#0f9b8e', '#7f8c85', '#805200', '#175cd3'] as const

export interface Series {
  key: string
  label: string
  values: number[]
  colour?: string
}

// ---------------------------------------------------------------------------

export function Legend({ series }: { series: Array<{ label: string; colour: string }> }) {
  return (
    <div className="msg-legend">
      {series.map((entry) => (
        <span key={entry.label} className="msg-legend-item">
          <span className="msg-legend-swatch" style={{ background: entry.colour }} aria-hidden />
          {entry.label}
        </span>
      ))}
    </div>
  )
}

/**
 * A stacked area chart, for delivery by channel over time.
 *
 * Stacked because the question is "how much went out in total, and how was it
 * split", which is what the Command Centre's delivery trend answers.
 */
export function StackedAreaChart({
  labels,
  series,
  height = 200,
  valueLabel = 'messages',
}: {
  labels: string[]
  series: Series[]
  height?: number
  valueLabel?: string
}) {
  const gradientId = useId()

  if (labels.length === 0 || series.length === 0) {
    return (
      <p className="msg-muted msg-small" style={{ padding: '2rem 0', textAlign: 'center' }}>
        No {valueLabel} in this period.
      </p>
    )
  }

  const width = 760
  const padding = { top: 12, right: 12, bottom: 26, left: 42 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  // Cumulative totals per point, so the stack's top is the maximum.
  const totals = labels.map((_, index) =>
    series.reduce((sum, entry) => sum + (entry.values[index] ?? 0), 0),
  )
  const max = Math.max(1, ...totals)

  const x = (index: number) =>
    padding.left + (labels.length === 1 ? plotWidth / 2 : (index / (labels.length - 1)) * plotWidth)
  const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight

  // Build the bands from the bottom up.
  const bands: Array<{ series: Series; path: string; colour: string }> = []
  const running = labels.map(() => 0)

  series.forEach((entry, seriesIndex) => {
    const colour = entry.colour ?? SERIES_COLOURS[seriesIndex % SERIES_COLOURS.length]
    const lower = [...running]
    const upper = labels.map((_, index) => {
      running[index] += entry.values[index] ?? 0
      return running[index]
    })

    const top = upper.map((value, index) => `${index === 0 ? 'M' : 'L'}${x(index)},${y(value)}`).join(' ')
    // Traced back along the lower edge, so the band closes as a filled shape.
    const bottom = lower
      .map((_, index) => {
        const reverse = lower.length - 1 - index
        return `L${x(reverse)},${y(lower[reverse])}`
      })
      .join(' ')

    bands.push({ series: entry, colour, path: `${top} ${bottom} Z` })
  })

  const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => Math.round(max * fraction))
  const description = series
    .map((entry) => `${entry.label}: ${entry.values.reduce((a, b) => a + b, 0).toLocaleString('en-IN')}`)
    .join('; ')

  return (
    <>
      <Legend
        series={series.map((entry, index) => ({
          label: entry.label,
          colour: entry.colour ?? SERIES_COLOURS[index % SERIES_COLOURS.length],
        }))}
      />
      <svg
        className="msg-chart"
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="xMidYMid meet"
        role="img"
        aria-label={`${valueLabel} by channel over ${labels.length} days. Totals — ${description}.`}
      >
        <defs>
          {bands.map((band, index) => (
            <linearGradient key={band.series.key} id={`${gradientId}-${index}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={band.colour} stopOpacity="0.55" />
              <stop offset="100%" stopColor={band.colour} stopOpacity="0.12" />
            </linearGradient>
          ))}
        </defs>

        {/* Gridlines and value axis. */}
        {ticks.map((tick) => (
          <g key={tick}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={y(tick)}
              y2={y(tick)}
              stroke="#e1e9e2"
              strokeWidth="1"
            />
            <text x={padding.left - 8} y={y(tick) + 4} textAnchor="end" fontSize="10" fill="#647168">
              {compact(tick)}
            </text>
          </g>
        ))}

        {bands.map((band, index) => (
          <path
            key={band.series.key}
            d={band.path}
            fill={`url(#${gradientId}-${index})`}
            stroke={band.colour}
            strokeWidth="1.5"
          />
        ))}

        {/* Date axis: first, middle and last only. A label per day is a smear. */}
        {[0, Math.floor(labels.length / 2), labels.length - 1]
          .filter((index, position, all) => all.indexOf(index) === position && labels[index] !== undefined)
          .map((index) => (
            <text
              key={index}
              x={x(index)}
              y={height - 8}
              textAnchor={index === 0 ? 'start' : index === labels.length - 1 ? 'end' : 'middle'}
              fontSize="10"
              fill="#647168"
            >
              {labels[index]}
            </text>
          ))}
      </svg>
    </>
  )
}

/**
 * A line chart with markers, for reply rate by hour.
 */
export function LineChart({
  labels,
  values,
  height = 190,
  valueFormatter = (value: number) => `${(value * 100).toFixed(1)}%`,
  ariaLabel,
}: {
  labels: string[]
  values: Array<number | null>
  height?: number
  valueFormatter?: (value: number) => string
  ariaLabel: string
}) {
  const present = values.filter((value): value is number => value !== null)

  if (present.length === 0) {
    return (
      <p className="msg-muted msg-small" style={{ padding: '2rem 0', textAlign: 'center' }}>
        Not enough data to chart.
      </p>
    )
  }

  const width = 760
  const padding = { top: 12, right: 14, bottom: 26, left: 44 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom
  const max = Math.max(...present) || 1

  const x = (index: number) =>
    padding.left + (values.length === 1 ? plotWidth / 2 : (index / (values.length - 1)) * plotWidth)
  const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight

  // Segments, so a gap where data is missing stays a gap rather than a
  // straight line implying a value nobody measured.
  const segments: string[] = []
  let current: string[] = []
  values.forEach((value, index) => {
    if (value === null) {
      if (current.length > 0) segments.push(current.join(' '))
      current = []
      return
    }
    current.push(`${current.length === 0 ? 'M' : 'L'}${x(index)},${y(value)}`)
  })
  if (current.length > 0) segments.push(current.join(' '))

  const ticks = [0, 0.5, 1].map((fraction) => max * fraction)

  return (
    <svg
      className="msg-chart"
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="xMidYMid meet"
      role="img"
      aria-label={ariaLabel}
    >
      {ticks.map((tick) => (
        <g key={tick}>
          <line
            x1={padding.left}
            x2={width - padding.right}
            y1={y(tick)}
            y2={y(tick)}
            stroke="#e1e9e2"
            strokeWidth="1"
          />
          <text x={padding.left - 8} y={y(tick) + 4} textAnchor="end" fontSize="10" fill="#647168">
            {valueFormatter(tick)}
          </text>
        </g>
      ))}

      {segments.map((segment, index) => (
        <path key={index} d={segment} fill="none" stroke="#25b003" strokeWidth="2" strokeLinejoin="round" />
      ))}

      {values.map((value, index) =>
        value === null ? null : (
          <circle key={index} cx={x(index)} cy={y(value)} r="2.6" fill="#176c09" />
        ),
      )}

      {labels
        .map((label, index) => ({ label, index }))
        .filter(({ index }) => index % Math.max(1, Math.ceil(labels.length / 8)) === 0)
        .map(({ label, index }) => (
          <text key={index} x={x(index)} y={height - 8} textAnchor="middle" fontSize="10" fill="#647168">
            {label}
          </text>
        ))}
    </svg>
  )
}

/**
 * A horizontal bar, for a delivery rate or a share.
 *
 * The number is always beside it as text. A bar on its own is a shape.
 */
export function RateBar({
  value,
  label,
  tone = 'brand',
}: {
  value: number | null
  label: string
  tone?: 'brand' | 'warning' | 'danger'
}) {
  const percentage = value === null ? 0 : Math.max(0, Math.min(1, value)) * 100
  const colour =
    tone === 'danger' ? 'var(--danger)' : tone === 'warning' ? 'var(--warning)' : 'linear-gradient(90deg, #64c843, #25b003)'

  return (
    <div className="msg-funnel-row">
      <span className="msg-funnel-label">{label}</span>
      <div className="msg-funnel-track">
        <div
          className="msg-funnel-fill"
          style={{ width: `${percentage}%`, background: colour }}
          role="img"
          aria-label={`${label}: ${value === null ? 'no data' : `${percentage.toFixed(1)}%`}`}
        />
      </div>
      <span className="msg-funnel-value">{value === null ? '—' : `${percentage.toFixed(1)}%`}</span>
    </div>
  )
}

/**
 * The conversation-to-outcome funnel.
 *
 * Each stage's bar is scaled against the FIRST stage, so the shape is
 * comparable — but the percentage printed beside it is against ITS OWN
 * denominator, which the backend supplies and which is named in the label. A
 * funnel that shows one and implies the other is the most common way this
 * chart misleads.
 */
export function Funnel({
  stages,
}: {
  stages: Array<{
    key: string
    label: string
    count: number
    share: number | null
    denominator: number | null
    denominator_label?: string
    note?: string
  }>
}) {
  if (stages.length === 0) return null

  const top = Math.max(1, stages[0].count)

  return (
    <div className="msg-funnel">
      {stages.map((stage) => (
        <div key={stage.key}>
          <div className="msg-funnel-row">
            <span className="msg-funnel-label">{stage.label}</span>
            <div className="msg-funnel-track">
              <div
                className="msg-funnel-fill"
                style={{ width: `${(stage.count / top) * 100}%` }}
                role="img"
                aria-label={`${stage.label}: ${stage.count.toLocaleString('en-IN')}`}
              />
            </div>
            <span className="msg-funnel-value">{stage.count.toLocaleString('en-IN')}</span>
          </div>
          {stage.share !== null && stage.denominator !== null && (
            <p className="msg-muted msg-small" style={{ margin: '0 0 0 7.75rem', fontSize: '0.74rem' }}>
              {(stage.share * 100).toFixed(1)}% of {stage.denominator.toLocaleString('en-IN')}{' '}
              {stage.denominator_label ?? 'in the previous stage'}
            </p>
          )}
        </div>
      ))}
    </div>
  )
}

/** Compact numbers for an axis: 8K rather than 8,000. */
function compact(value: number): string {
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`
  if (value >= 1000) return `${Math.round(value / 1000)}K`
  return String(value)
}
