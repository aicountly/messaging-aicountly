/**
 * One journey run, step by step.
 *
 * ## Why this screen exists
 *
 * "Why did this customer get a reminder?" and "why didn't this one?" are the
 * only two questions anybody asks about an automation, and a journey that
 * cannot answer them is one nobody will trust with their customers. Every step
 * records its decision in plain words, which product it consulted and when.
 *
 * The definition shown is the IMMUTABLE published version this run executed,
 * with its hash — not the journey as it stands now. Those diverge the moment
 * somebody edits the draft, and a run history that showed the current version
 * would be fiction.
 */

import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Play } from 'lucide-react'
import { useApi, useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type { JourneyRun } from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import {
  Button,
  EmptyState,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  SourceBadge,
  StatusPill,
  formatDateTime,
  formatDuration,
} from '../ui'
import { humanise } from './CommandCentre'
import { runTone } from './Journeys'

export function JourneyRunPage() {
  const { runUuid } = useParams<{ runUuid: string }>()
  const { can, timezone } = useMessaging()

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api
        .get<{ data: { run: JourneyRun; note: string } }>(`v1/journey-runs/${runUuid}`, undefined, signal)
        .then((r) => r.data),
    [runUuid],
    can('messaging.journeys.view'),
  )

  const resume = useMutation(async () =>
    api.postRaw<{ data: { detail: string } }>(`v1/journey-runs/${runUuid}/resume`, {}),
  )

  if (!can('messaging.journeys.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="journey runs" />
      </div>
    )
  }

  return (
    <div className="msg-ui">
      <Link to="/journeys?tab=runs" className="msg-button msg-button-small msg-button-ghost" style={{ marginBottom: '1rem' }}>
        <ArrowLeft size={13} aria-hidden />
        All runs
      </Link>

      <PanelState loading={loading} error={error} data={data} onRetry={reload} skeletonRows={4}>
        {(detail) => {
          const { run } = detail
          const duration =
            run.finished_at !== null
              ? (new Date(run.finished_at).getTime() - new Date(run.started_at).getTime()) / 1000
              : null

          return (
            <>
              <div className="msg-page-header">
                <div>
                  <h1>{run.journey_name}</h1>
                  <p>
                    {run.mode === 'simulation'
                      ? 'A test run. Nothing was sent.'
                      : 'A live run, executing published version ' + run.journey_version + '.'}
                  </p>
                </div>
                <div className="msg-page-actions">
                  <StatusPill tone={runTone(run.status)}>{humanise(run.status)}</StatusPill>
                  {run.mode === 'simulation' && <StatusPill tone="info">Test · nothing sent</StatusPill>}
                  {can('messaging.journeys.publish') &&
                    (run.status === 'paused' || run.status === 'awaiting_approval') && (
                      <Button
                        small
                        pending={resume.pending}
                        onClick={async () => {
                          const result = await resume.run()
                          if (result !== null) reload()
                        }}
                      >
                        <Play size={13} aria-hidden />
                        Resume
                      </Button>
                    )}
                </div>
              </div>

              {resume.error && <Notice tone="danger">{resume.error.message}</Notice>}

              {run.outcome_detail !== null && (
                <Notice
                  tone={
                    run.status === 'completed'
                      ? run.outcome === 'sent'
                        ? 'success'
                        : 'info'
                      : run.status === 'failed'
                        ? 'danger'
                        : 'warning'
                  }
                  title={run.outcome !== '' ? humanise(run.outcome) : 'Outcome'}
                >
                  {run.outcome_detail}
                </Notice>
              )}

              <div className="msg-grid msg-split">
                <Panel title="What happened" subtitle="Every step, and why it went that way">
                  {(run.steps ?? []).length === 0 ? (
                    <EmptyState title="No steps recorded">This run has not executed a step yet.</EmptyState>
                  ) : (
                    <div className="msg-timeline">
                      {(run.steps ?? []).map((step) => (
                        <div key={step.sequence} className="msg-timeline-row">
                          <span
                            className={
                              step.status === 'failed' || step.status === 'blocked'
                                ? 'msg-timeline-dot msg-timeline-dot-danger'
                                : step.status === 'paused'
                                  ? 'msg-timeline-dot msg-timeline-dot-warning'
                                  : step.status === 'skipped'
                                    ? 'msg-timeline-dot msg-timeline-dot-muted'
                                    : 'msg-timeline-dot'
                            }
                            aria-hidden
                          />
                          <div className="msg-timeline-body">
                            <strong>
                              {humanise(step.node_type)}
                              {step.branch !== '' && (
                                <>
                                  {' '}
                                  <StatusPill tone="neutral">{humanise(step.branch)}</StatusPill>
                                </>
                              )}
                            </strong>
                            {/* The explanation in plain words. This is the
                                product, not diagnostics. */}
                            <small>{step.explanation}</small>
                            <small>
                              {formatDateTime(step.started_at, timezone)}
                              {/* Which product was consulted, and when. */}
                              {step.source_product !== '' && (
                                <>
                                  {' · '}
                                  <SourceBadge
                                    source={step.source_product}
                                    fetchedAt={step.source_fetched_at}
                                    live={false}
                                  />
                                </>
                              )}
                            </small>
                            {step.message_uuid !== null && run.conversation_uuid !== null && (
                              <small>
                                <Link to={`/inbox/${run.conversation_uuid}`}>See the message</Link>
                              </small>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </Panel>

                <div className="msg-stack">
                  <Panel title="This run" headingLevel={3}>
                    <dl className="msg-definition-list">
                      <dt>Started</dt>
                      <dd>{formatDateTime(run.started_at, timezone)}</dd>
                      {run.finished_at !== null && (
                        <>
                          <dt>Finished</dt>
                          <dd>{formatDateTime(run.finished_at, timezone)}</dd>
                          <dt>Took</dt>
                          <dd>{formatDuration(duration)}</dd>
                        </>
                      )}
                      {run.resume_after !== null && (
                        <>
                          <dt>Resumes</dt>
                          <dd>{formatDateTime(run.resume_after, timezone)}</dd>
                        </>
                      )}
                      <dt>Triggered by</dt>
                      <dd>{humanise(run.trigger_source)}</dd>
                      {run.subject_ref !== '' && (
                        <>
                          <dt>About</dt>
                          <dd>
                            {run.subject_ref}
                            {run.subject_product !== '' && (
                              <>
                                <br />
                                <span className="msg-muted msg-small">
                                  Aicountly {humanise(run.subject_product)}
                                </span>
                              </>
                            )}
                          </dd>
                        </>
                      )}
                      {run.conversation_uuid !== null && (
                        <>
                          <dt>Conversation</dt>
                          <dd>
                            <Link to={`/inbox/${run.conversation_uuid}`}>Open</Link>
                          </dd>
                        </>
                      )}
                    </dl>
                  </Panel>

                  <Panel title="The version it executed" headingLevel={3}>
                    <dl className="msg-definition-list">
                      <dt>Version</dt>
                      <dd>v{run.journey_version}</dd>
                      {run.executed_definition_hash !== undefined && run.executed_definition_hash !== '' && (
                        <>
                          <dt>Definition hash</dt>
                          <dd style={{ wordBreak: 'break-all', fontSize: '0.72rem' }}>
                            {run.executed_definition_hash.slice(0, 16)}…
                          </dd>
                        </>
                      )}
                      <dt>Steps in it</dt>
                      <dd>{run.executed_definition?.nodes?.length ?? '—'}</dd>
                    </dl>
                    <p className="msg-muted msg-small" style={{ margin: '0.6rem 0 0', lineHeight: 1.55 }}>
                      {detail.note}
                    </p>
                  </Panel>
                </div>
              </div>
            </>
          )
        }}
      </PanelState>
    </div>
  )
}
