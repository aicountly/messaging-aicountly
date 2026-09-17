/**
 * The notification centre.
 *
 * Shows the SAME suggestions the Command Centre computes, so the bell and the
 * page cannot disagree. Every entry carries its evidence and a route into the
 * screen that can act on it — a notification you cannot act on is a nag.
 *
 * There is deliberately no separate notifications table. These are derived
 * from live counts on each open, which means dismissing one on the Command
 * Centre also clears it here.
 */

import { useNavigate } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'
import { useApi } from '../hooks/useApi'
import { api } from '../services/api'
import type { OverviewResponse } from '../services/types'
import { Button, PanelState, StatusPill, timeAgo } from '../ui'
import { resolveRoute } from '../shell/navConfig'

export function NotificationCentre({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api.get<{ data: OverviewResponse }>('v1/overview', { period: 'today' }, signal).then((r) => r.data),
    [],
  )

  return (
    <div className="shell-notifications" role="region" aria-label="Notifications">
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          padding: '0.5rem 0.7rem',
        }}
      >
        <strong style={{ fontSize: '0.85rem' }}>Needs attention</strong>
        <Button tone="ghost" small onClick={onClose} ariaLabel="Close notifications">
          ✕
        </Button>
      </div>

      <PanelState
        loading={loading}
        error={error}
        data={data}
        onRetry={reload}
        skeletonRows={2}
        isEmpty={(overview) => overview.suggestions.length === 0}
        emptyTitle="Nothing needs you"
        emptyBody="No drafts waiting, no delivery problems and nothing past a response target."
      >
        {(overview) => (
          <div className="msg-stack" style={{ gap: '0.3rem' }}>
            {overview.suggestions.map((suggestion) => {
              const to = resolveRoute(suggestion.action.route, suggestion.action.params)

              return (
                <button
                  key={suggestion.key}
                  type="button"
                  className="shell-search-result"
                  onClick={() => {
                    if (to !== null) navigate(to)
                    onClose()
                  }}
                  disabled={to === null}
                >
                  <span style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
                    {suggestion.title}
                    {/* Labelled as a measured fact rather than a guess. */}
                    {suggestion.kind === 'verified_fact' && (
                      <StatusPill tone="brand" icon={CheckCircle2}>
                        Measured
                      </StatusPill>
                    )}
                  </span>
                  <small>
                    {suggestion.detail} · read {timeAgo(suggestion.fetched_at)}
                  </small>
                </button>
              )
            })}
          </div>
        )}
      </PanelState>
    </div>
  )
}
