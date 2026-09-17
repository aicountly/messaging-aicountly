/**
 * Dashboard 3 — Journeys & Templates.
 *
 * ## What this screen will not let somebody do
 *
 * Publish a journey whose send step names a template the provider has not
 * approved. Publish one that reads from a product this deployment has not
 * connected. Publish one whose send is not preceded by an eligibility check.
 * Each refusal names the step and says what to fix, because a journey that
 * publishes cleanly and then pauses on every run tells its author nothing.
 *
 * ## Simulation sends nothing, and says so everywhere
 *
 * The test panel states it in the payload, in the heading and beside the
 * counts. Behind it, a simulation run cannot create a dispatch job — the
 * database refuses one — so the guarantee does not rest on this screen.
 *
 * ## The natural-language builder proposes, it does not create
 *
 * An instruction is mapped onto one of this product's starters and this
 * company's own approved templates. Nothing outside that closed vocabulary can
 * survive, and nothing is saved until somebody reads every step and presses
 * save.
 */

import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  FlaskConical,
  Play,
  Plus,
  Send,
  Sparkles,
  Upload,
} from 'lucide-react'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlFilters } from '../hooks/useUrlState'
import { ApiError, api } from '../services/api'
import type {
  Journey,
  JourneyDefinitionShape,
  JourneyNode,
  JourneyRun,
  JourneyValidation,
  SimulationResult,
  Template,
  TemplateVersion,
} from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import {
  Announce,
  Button,
  Drawer,
  EmptyState,
  Field,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  StatusPill,
  formatCount,
  formatDateTime,
  timeAgo,
} from '../ui'
import { channelLabel, humanise } from './CommandCentre'

export function JourneysPage() {
  const { can } = useMessaging()
  const [filters, setFilters] = useUrlFilters({ tab: 'journeys', journey: '', template: '', status: '' })

  if (!can('messaging.journeys.view') && !can('messaging.templates.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="journeys and templates" />
      </div>
    )
  }

  const tab = filters.tab === 'templates' ? 'templates' : filters.tab === 'runs' ? 'runs' : 'journeys'

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Journeys &amp; Templates</h1>
          <p>Turn intent into a reviewed messaging journey.</p>
        </div>
        <div className="msg-page-actions">
          <div className="msg-chips" role="tablist" aria-label="Journeys and templates">
            {[
              { key: 'journeys', label: 'Journeys' },
              { key: 'templates', label: 'Templates' },
              { key: 'runs', label: 'Run history' },
            ].map((option) => (
              <button
                key={option.key}
                type="button"
                role="tab"
                className="msg-chip"
                aria-selected={tab === option.key}
                aria-pressed={tab === option.key}
                onClick={() => setFilters({ tab: option.key, journey: '', template: '' })}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {tab === 'journeys' && <JourneysTab selectedUuid={filters.journey} onSelect={(uuid) => setFilters({ journey: uuid })} />}
      {tab === 'templates' && (
        <TemplatesTab selectedUuid={filters.template} onSelect={(uuid) => setFilters({ template: uuid })} />
      )}
      {tab === 'runs' && <RunsTab />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Journeys
// ---------------------------------------------------------------------------

function JourneysTab({
  selectedUuid,
  onSelect,
}: {
  selectedUuid: string
  onSelect: (uuid: string) => void
}) {
  const { can } = useMessaging()
  const [creating, setCreating] = useState(false)
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const list = useApi((signal) => api.list<Journey>('v1/journeys', { limit: 50 }, signal), [])

  const starters =
    (list.data?.meta.starters as Array<{ kind: string; name: string; description: string }> | undefined) ?? []

  return (
    <>
      <div className="msg-grid msg-split">
        <div className="msg-stack">
          <Panel
            title="Journeys"
            subtitle="A journey runs the published version, never the draft"
            action={
              can('messaging.journeys.manage') ? (
                <Button small tone="primary" onClick={() => setCreating(true)}>
                  <Plus size={13} aria-hidden />
                  New journey
                </Button>
              ) : undefined
            }
          >
            <PanelState
              loading={list.loading}
              error={list.error}
              data={list.data}
              onRetry={list.reload}
              skeletonRows={3}
              isEmpty={(response) => response.data.length === 0}
              emptyTitle="No journeys yet"
              emptyBody="Start from one of the operational journeys this product ships with, or describe what you want in words."
            >
              {(response) => (
                <div className="msg-stack" style={{ gap: 0 }}>
                  {response.data.map((journey) => (
                    <button
                      key={journey.journey_uuid}
                      type="button"
                      className="msg-conversation-item"
                      aria-current={selectedUuid === journey.journey_uuid}
                      onClick={() => onSelect(journey.journey_uuid)}
                    >
                      <span className="msg-conversation-top">
                        <strong>{journey.name}</strong>
                        <StatusPill tone={journey.runnable ? 'success' : 'warning'}>
                          {journey.runnable ? `v${journey.published_version} published` : humanise(journey.status)}
                        </StatusPill>
                      </span>
                      <span className="msg-conversation-preview">{journey.description || humanise(journey.kind)}</span>
                      <span className="msg-conversation-meta">
                        {journey.run_count !== null && journey.run_count > 0 && (
                          <span className="msg-muted msg-small">
                            {formatCount(journey.run_count)} run{journey.run_count === 1 ? '' : 's'}
                            {journey.last_run_at && ` · last ${timeAgo(journey.last_run_at)}`}
                          </span>
                        )}
                        {journey.published_version !== null && journey.draft_version > journey.published_version && (
                          <StatusPill tone="info">Draft v{journey.draft_version} unpublished</StatusPill>
                        )}
                      </span>
                    </button>
                  ))}
                </div>
              )}
            </PanelState>
          </Panel>

          {can('messaging.journeys.manage') && starters.length > 0 && (
            <Panel title="Start from an operational journey" subtitle="Reviewed and published by you, not on install">
              <div className="msg-stack" style={{ gap: 0 }}>
                {starters.map((starter) => (
                  <div key={starter.kind} className="msg-row msg-row-tight">
                    <div className="msg-row-body">
                      <strong>{starter.name}</strong>
                      <small>{starter.description}</small>
                    </div>
                    <StarterButton
                      starter={starter}
                      onCreated={(uuid) => {
                        onSelect(uuid)
                        list.reload()
                        setAnnouncement(`${starter.name} created as a draft. Review every step before publishing.`)
                      }}
                    />
                  </div>
                ))}
              </div>
            </Panel>
          )}
        </div>

        {selectedUuid === '' ? (
          <Panel>
            <EmptyState title="Pick a journey">
              Choose a journey to see its steps, what it reads, and whether it can be published.
            </EmptyState>
          </Panel>
        ) : (
          <JourneyEditor journeyUuid={selectedUuid} onChanged={list.reload} onAnnounce={setAnnouncement} />
        )}
      </div>

      {creating && (
        <NaturalLanguageBuilder
          onClose={() => setCreating(false)}
          onCreated={(uuid) => {
            setCreating(false)
            onSelect(uuid)
            list.reload()
            setAnnouncement('Journey saved as a draft. It cannot run until it is published.')
          }}
        />
      )}

      <Announce message={announcement} />
    </>
  )
}

function StarterButton({
  starter,
  onCreated,
}: {
  starter: { kind: string; name: string }
  onCreated: (uuid: string) => void
}) {
  const create = useMutation(async () => {
    const response = await api.postRaw<{ data: { journey: Journey } }>('v1/journeys', {
      starter: starter.kind,
      name: starter.name,
    })
    return response.data.journey
  })

  return (
    <>
      <Button
        small
        pending={create.pending}
        onClick={async () => {
          const journey = await create.run()
          if (journey !== null) onCreated(journey.journey_uuid)
        }}
      >
        Use this
      </Button>
      {create.error && <Notice tone="danger">{create.error.message}</Notice>}
    </>
  )
}

/**
 * The journey editor.
 *
 * The validation panel is the point of this screen. It lists exactly what
 * would stop the journey working, per step, against THIS deployment's
 * configuration — so an unapproved template or a disconnected channel is
 * visible before publishing rather than after four hundred runs paused.
 */
function JourneyEditor({
  journeyUuid,
  onChanged,
  onAnnounce,
}: {
  journeyUuid: string
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const { can, timezone } = useMessaging()
  const [simulating, setSimulating] = useState(false)

  const detail = useApi(
    (signal) =>
      api.get<{ data: { journey: Journey } }>(`v1/journeys/${journeyUuid}`, undefined, signal).then((r) => r.data.journey),
    [journeyUuid],
  )

  const publish = useMutation(async (version: number) =>
    api.postRaw<{ data: { detail: string } }>(`v1/journeys/${journeyUuid}/publish`, { version }),
  )

  const setStatus = useMutation(async (status: string) =>
    api.post(`v1/journeys/${journeyUuid}/status`, { status }),
  )

  return (
    <div className="msg-stack">
      <PanelState
        loading={detail.loading}
        error={detail.error}
        data={detail.data}
        onRetry={detail.reload}
        skeletonRows={4}
      >
        {(journey) => (
          <>
            <Panel
              title={journey.name}
              subtitle={journey.description}
              action={
                <div className="msg-actions">
                  {can('messaging.journeys.simulate') && (
                    <Button small onClick={() => setSimulating(true)}>
                      <FlaskConical size={13} aria-hidden />
                      Test journey
                    </Button>
                  )}
                  {can('messaging.journeys.publish') && journey.version?.editable && (
                    <Button
                      small
                      tone="primary"
                      pending={publish.pending}
                      disabled={journey.version?.validation.valid === false}
                      title={
                        journey.version?.validation.valid === false
                          ? 'Fix the problems below before publishing'
                          : undefined
                      }
                      onClick={async () => {
                        const result = await publish.run(journey.version?.version ?? journey.draft_version)
                        if (result !== null) {
                          onAnnounce(result.data.detail)
                          detail.reload()
                          onChanged()
                        }
                      }}
                    >
                      <Upload size={13} aria-hidden />
                      Review &amp; publish
                    </Button>
                  )}
                  {can('messaging.journeys.publish') && journey.runnable && (
                    <Button
                      small
                      pending={setStatus.pending}
                      onClick={async () => {
                        const result = await setStatus.run(journey.status === 'paused' ? 'published' : 'paused')
                        if (result !== null) {
                          detail.reload()
                          onChanged()
                        }
                      }}
                    >
                      {journey.status === 'paused' ? 'Resume' : 'Pause'}
                    </Button>
                  )}
                </div>
              }
            >
              <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', marginBottom: '0.85rem' }}>
                <StatusPill tone={journey.runnable ? 'success' : 'warning'}>
                  {journey.runnable ? `Published v${journey.published_version}` : humanise(journey.status)}
                </StatusPill>
                {journey.version && (
                  <StatusPill tone="neutral">
                    Viewing v{journey.version.version}
                    {journey.version.editable ? ' (draft)' : ' (published, read only)'}
                  </StatusPill>
                )}
                {journey.version?.published_at && (
                  <span className="msg-muted msg-small">
                    Published {formatDateTime(journey.version.published_at, timezone)}
                  </span>
                )}
              </div>

              {publish.error && (
                <PublishRefusal error={publish.error} validation={extractValidation(publish.error)} />
              )}

              {journey.version && <JourneyValidationPanel validation={journey.version.validation} />}
            </Panel>

            {journey.version && (
              <Panel title="Workflow" subtitle="Every step, and what it reads">
                <JourneyGraph
                  definition={journey.version.definition}
                  validation={journey.version.validation}
                />
              </Panel>
            )}

            {journey.version && !journey.version.editable && (
              <p className="msg-muted msg-small">
                This version is published and cannot be edited. Runs reference these exact steps, which is what makes
                the run history meaningful — saving a change creates a new draft version instead.
              </p>
            )}
          </>
        )}
      </PanelState>

      {simulating && (
        <SimulationDrawer journeyUuid={journeyUuid} onClose={() => setSimulating(false)} />
      )}
    </div>
  )
}

/**
 * Why a journey cannot be published.
 *
 * Errors block. Warnings do not. Both name their step, because "this journey
 * is invalid" is not something anybody can act on.
 */
function JourneyValidationPanel({ validation }: { validation: JourneyValidation }) {
  if (validation.valid && validation.warnings.length === 0) {
    return (
      <Notice tone="success" title="Ready to publish">
        {validation.summary.sends ?? 0} send step
        {(validation.summary.sends ?? 0) === 1 ? '' : 's'}, reading from{' '}
        {(validation.summary.source_products ?? []).length === 0
          ? 'no other product'
          : (validation.summary.source_products ?? []).map((p) => `Aicountly ${humanise(p)}`).join(', ')}
        .{validation.summary.requires_approval ? ' A human approves each draft before it sends.' : ''}
      </Notice>
    )
  }

  return (
    <>
      {validation.errors.length > 0 && (
        <Notice
          tone="danger"
          title={`${validation.errors.length} problem${validation.errors.length === 1 ? '' : 's'} must be fixed before publishing`}
        >
          <ul style={{ margin: '0.3rem 0 0', paddingLeft: '1.1rem', lineHeight: 1.55 }}>
            {validation.errors.map((problem, index) => (
              <li key={index}>
                {problem.node !== '' && <strong style={{ display: 'inline' }}>{problem.node}: </strong>}
                {problem.message}
              </li>
            ))}
          </ul>
        </Notice>
      )}

      {validation.warnings.length > 0 && (
        <Notice tone="warning" title="Worth looking at">
          <ul style={{ margin: '0.3rem 0 0', paddingLeft: '1.1rem', lineHeight: 1.55 }}>
            {validation.warnings.map((warning, index) => (
              <li key={index}>
                {warning.node !== '' && <strong style={{ display: 'inline' }}>{warning.node}: </strong>}
                {warning.message}
              </li>
            ))}
          </ul>
        </Notice>
      )}
    </>
  )
}

function PublishRefusal({ error, validation }: { error: ApiError | Error; validation: JourneyValidation | null }) {
  return (
    <>
      <Notice tone="danger" title="Not published">
        {error.message}
      </Notice>
      {validation && <JourneyValidationPanel validation={validation} />}
    </>
  )
}

function extractValidation(error: ApiError | Error): JourneyValidation | null {
  if (!(error instanceof ApiError)) return null
  const validation = error.details.validation
  return validation !== undefined ? (validation as JourneyValidation) : null
}

/**
 * The node graph.
 *
 * Laid out in execution order with the branch each step can take. Nodes that
 * the validator flagged are outlined, so the problem list and the picture
 * agree.
 */
function JourneyGraph({
  definition,
  validation,
}: {
  definition: JourneyDefinitionShape
  validation: JourneyValidation
}) {
  const nodes = definition.nodes ?? []
  const invalidNodes = new Set(validation.errors.map((problem) => problem.node))

  if (nodes.length === 0) {
    return <EmptyState title="No steps yet">This journey has no steps.</EmptyState>
  }

  return (
    <div className="msg-canvas">
      <ol className="msg-nodes">
        {nodes.map((node, index) => (
          <li
            key={node.id}
            className={[
              'msg-node',
              node.type === 'approval' ? 'msg-node-approval' : '',
              node.type === 'pause_notify' ? 'msg-node-failure' : '',
              invalidNodes.has(node.id) ? 'msg-node-invalid' : '',
            ]
              .filter(Boolean)
              .join(' ')}
          >
            <span className="msg-node-eyebrow">
              {String(index + 1).padStart(2, '0')} · {humanise(node.type)}
            </span>
            <b>{node.label ?? humanise(node.type)}</b>
            <p>{describeNode(node)}</p>

            {/* Where this step can go. A failure branch is named, because a
                journey whose failure path is invisible is a journey that fails
                silently. */}
            <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap', marginTop: '0.5rem' }}>
              {branchesOf(node).map((branch) => (
                <StatusPill
                  key={branch.label}
                  tone={branch.tone}
                >
                  {branch.label} → {branch.target}
                </StatusPill>
              ))}
            </div>
          </li>
        ))}
      </ol>
    </div>
  )
}

function describeNode(node: JourneyNode): string {
  switch (node.type) {
    case 'fetch_source':
      return `Reads live from Aicountly ${humanise(String(node.source ?? '').split('_')[0])}. Nothing is stored.`
    case 'condition':
      return node.expression ? `Continues when ${node.expression}.` : 'Branches on the source reading.'
    case 'check_eligibility':
      return `Checks consent for ${node.purpose ?? 'transactional'} messages, plus channel capability and suppression.`
    case 'draft':
      return node.template_uuid
        ? `Builds the message from a provider-approved template in ${String(node.language ?? 'en').toUpperCase()}.`
        : 'No template chosen yet. This step cannot run.'
    case 'approval':
      return 'Waits for a person. Nothing is sent until somebody reads the draft.'
    case 'delay':
      return `Waits ${node.delay_minutes ?? 0} minutes.`
    case 'revalidate':
      return 'Re-reads the source immediately before sending, and cancels if the facts have changed.'
    case 'send':
      return `Dispatches once on ${channelLabel(String(node.channel ?? ''))}. Consent is checked again first.`
    case 'pause_notify':
      return 'Stops and tells somebody. Nothing is sent from data that could not be confirmed.'
    case 'stop':
      return node.outcome ? `Ends the run: ${humanise(String(node.outcome))}.` : 'Ends the run.'
    default:
      return ''
  }
}

function branchesOf(node: JourneyNode): Array<{ label: string; target: string; tone: 'neutral' | 'success' | 'warning' | 'danger' }> {
  const out: Array<{ label: string; target: string; tone: 'neutral' | 'success' | 'warning' | 'danger' }> = []

  const map: Array<[string, string, 'neutral' | 'success' | 'warning' | 'danger']> = [
    ['next', 'Next', 'neutral'],
    ['on_true', 'Yes', 'success'],
    ['on_false', 'No', 'neutral'],
    ['on_eligible', 'Eligible', 'success'],
    ['on_ineligible', 'Not eligible', 'warning'],
    ['on_approved', 'Approved', 'success'],
    ['on_rejected', 'Declined', 'warning'],
    ['on_changed', 'Changed', 'warning'],
    ['on_unavailable', 'Unavailable', 'danger'],
  ]

  for (const [key, label, tone] of map) {
    const target = node[key]
    if (typeof target === 'string' && target !== '') out.push({ label, target, tone })
  }

  return out
}

/**
 * The natural-language builder.
 *
 * Says "proposal" in the heading, in the body and on the button. Nothing is
 * created until somebody reads the steps and saves.
 */
function NaturalLanguageBuilder({
  onClose,
  onCreated,
}: {
  onClose: () => void
  onCreated: (uuid: string) => void
}) {
  const { session } = useMessaging()
  const [instruction, setInstruction] = useState('')
  const [name, setName] = useState('')

  const propose = useMutation(async () => {
    const response = await api.postRaw<{
      data: {
        proposed: { name: string; description: string; kind: string; definition: JourneyDefinitionShape } | null
        message?: string
        understood: Record<string, string>
        validation: JourneyValidation
        created: false
        kind_note: string
        requires_review: boolean
        data_sources: string[]
        approval_required: boolean
      }
    }>('v1/journeys/propose', { instruction })
    return response.data
  })

  const save = useMutation(async (proposed: { name: string; description: string; kind: string; definition: JourneyDefinitionShape }) => {
    const response = await api.postRaw<{ data: { journey: Journey } }>('v1/journeys', {
      name: name || proposed.name,
      description: proposed.description,
      kind: proposed.kind,
      definition: proposed.definition,
    })
    return response.data.journey
  })

  const aiAvailable = session?.ai.available ?? false
  const proposal = propose.data ?? null

  return (
    <Drawer
      title="Describe what you want"
      subtitle="An instruction becomes a PROPOSAL you review. Nothing is created or sent."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          {proposal?.proposed && (
            <Button
              tone="primary"
              pending={save.pending}
              onClick={async () => {
                const journey = await save.run(proposal.proposed!)
                if (journey !== null) onCreated(journey.journey_uuid)
              }}
            >
              Save as a draft
            </Button>
          )}
        </>
      }
    >
      {!aiAvailable && (
        <Notice tone="warning" title="The assistant is not configured">
          An instruction cannot be interpreted without a model. You can still start from one of the operational
          journeys on the previous screen and edit it.
        </Notice>
      )}

      <Field
        label="What should this journey do?"
        htmlFor="journey-instruction"
        hint="For example: when an invoice becomes overdue, draft a polite reminder in the customer's preferred language."
      >
        <textarea
          id="journey-instruction"
          className="msg-textarea"
          value={instruction}
          onChange={(event) => setInstruction(event.target.value)}
          disabled={!aiAvailable}
        />
      </Field>

      <Button
        pending={propose.pending}
        disabled={!aiAvailable || instruction.trim() === ''}
        onClick={() => propose.run()}
      >
        <Sparkles size={14} aria-hidden />
        Propose a journey
      </Button>

      {propose.error && <Notice tone="danger">{propose.error.message}</Notice>}
      {save.error && <Notice tone="danger">{save.error.message}</Notice>}

      {proposal !== null && proposal.proposed === null && (
        <Notice tone="warning" title="That did not match a journey kind">
          {proposal.message}
        </Notice>
      )}

      {proposal?.proposed && (
        <div style={{ marginTop: '1.25rem' }}>
          <Notice tone="brand" title="This is a proposal">
            {proposal.kind_note}
          </Notice>

          <Field label="Name" htmlFor="journey-name">
            <input
              id="journey-name"
              className="msg-input"
              value={name || proposal.proposed.name}
              onChange={(event) => setName(event.target.value)}
            />
          </Field>

          <dl className="msg-definition-list" style={{ marginBottom: '1rem' }}>
            <dt>Reads from</dt>
            <dd>
              {proposal.data_sources.length === 0
                ? 'Nothing outside Messaging'
                : proposal.data_sources.map((source: string) => `Aicountly ${humanise(source.split('_')[0])}`).join(', ')}
            </dd>
            <dt>Human approval</dt>
            <dd>{proposal.approval_required ? 'Required before sending' : 'Not in this journey'}</dd>
            <dt>Steps</dt>
            <dd>{proposal.proposed.definition.nodes?.length ?? 0}</dd>
          </dl>

          <JourneyValidationPanel validation={proposal.validation} />

          <JourneyGraph definition={proposal.proposed.definition} validation={proposal.validation} />
        </div>
      )}
    </Drawer>
  )
}

/**
 * The simulator.
 *
 * "Sends nothing" is said three times on this panel, and the counts are the
 * product: eligible, excluded, blocked, paused, each with its reason.
 */
function SimulationDrawer({ journeyUuid, onClose }: { journeyUuid: string; onClose: () => void }) {
  const [dataSource, setDataSource] = useState<'live_readonly' | 'synthetic'>('live_readonly')

  const simulate = useMutation(async () => {
    const response = await api.postRaw<{ data: SimulationResult }>(`v1/journeys/${journeyUuid}/simulate`, {
      data_source: dataSource,
      count: dataSource === 'synthetic' ? 5 : 25,
    })
    return response.data
  })

  // Run once on open, so the drawer is useful immediately.
  useEffect(() => {
    simulate.run()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dataSource])

  const result = simulate.data ?? null

  return (
    <Drawer
      title="Test this journey"
      subtitle="Test mode · no messages are sent"
      onClose={onClose}
      footer={<Button onClick={onClose}>Close</Button>}
    >
      <Notice tone="brand" title="Nothing will be sent">
        A test evaluates the journey and reports what would happen. No dispatch job is created, and the queue
        refuses one for a test run at the database level — so this cannot message anybody even by mistake.
      </Notice>

      <Field
        label="What should it evaluate?"
        htmlFor="sim-source"
        hint="Live read-only uses your real records and real consent, and writes nothing. Synthetic uses clearly-labelled made-up subjects."
      >
        <select
          id="sim-source"
          className="msg-select"
          value={dataSource}
          onChange={(event) => setDataSource(event.target.value as 'live_readonly' | 'synthetic')}
        >
          <option value="live_readonly">Live, read-only — your real records</option>
          <option value="synthetic">Synthetic fixtures — made-up subjects</option>
        </select>
      </Field>

      <Button pending={simulate.pending} onClick={() => simulate.run()}>
        <Play size={14} aria-hidden />
        Run test
      </Button>

      {simulate.error && <Notice tone="danger">{simulate.error.message}</Notice>}

      {result !== null && (
        <div style={{ marginTop: '1.25rem' }}>
          <p className="msg-muted msg-small" style={{ lineHeight: 1.55 }}>
            {result.journey.version_note} {result.data_source_note}
          </p>

          <div className="msg-metric-row" style={{ gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' }}>
            {[
              { key: 'eligible', label: 'Would send', tone: 'success' as const },
              { key: 'excluded', label: 'Excluded', tone: 'neutral' as const },
              { key: 'blocked', label: 'Blocked', tone: 'danger' as const },
              { key: 'paused', label: 'Paused', tone: 'warning' as const },
            ].map((bucket) => (
              <div key={bucket.key} className="msg-metric">
                <div className="msg-metric-body">
                  <span className="msg-metric-label">{bucket.label}</span>
                  <span className="msg-metric-value">{formatCount(result.outcomes[bucket.key] ?? 0)}</span>
                </div>
              </div>
            ))}
          </div>

          <p className="msg-muted msg-small">
            {formatCount(result.subjects.considered)} subject
            {result.subjects.considered === 1 ? '' : 's'} evaluated from {result.subjects.source}.{' '}
            {result.subjects.note}
          </p>

          {Object.keys(result.reasons).length > 0 && (
            <Panel title="Why each one went the way it did" headingLevel={3}>
              <div className="msg-stack" style={{ gap: 0 }}>
                {Object.entries(result.reasons)
                  .sort((left, right) => right[1] - left[1])
                  .map(([reason, count]) => (
                    <div key={reason} className="msg-row msg-row-tight">
                      <div className="msg-row-body">
                        <strong>{humanise(reason)}</strong>
                      </div>
                      <span className="msg-count-pill">{formatCount(count)}</span>
                    </div>
                  ))}
              </div>
            </Panel>
          )}

          {result.walks.length > 0 && (
            <details style={{ marginTop: '1rem' }}>
              <summary style={{ cursor: 'pointer', fontSize: '0.85rem', fontWeight: 650 }}>
                Step by step, for the first {Math.min(result.walks.length, 5)} subject
                {result.walks.length === 1 ? '' : 's'}
              </summary>
              <div className="msg-stack" style={{ marginTop: '0.75rem' }}>
                {result.walks.slice(0, 5).map((walk: SimulationResult['walks'][number], index: number) => (
                  <div key={index} className="msg-card">
                    <div className="msg-card-body">
                      <strong style={{ fontSize: '0.87rem' }}>{walk.subject || walk.reference}</strong>{' '}
                      <StatusPill
                        tone={
                          walk.bucket === 'eligible'
                            ? 'success'
                            : walk.bucket === 'paused'
                              ? 'warning'
                              : walk.bucket === 'blocked'
                                ? 'danger'
                                : 'neutral'
                        }
                      >
                        {humanise(walk.reason)}
                      </StatusPill>
                      <div className="msg-timeline" style={{ marginTop: '0.6rem' }}>
                        {walk.trace.map((step: SimulationResult['walks'][number]['trace'][number], stepIndex: number) => (
                          <div key={stepIndex} className="msg-timeline-row">
                            <span
                              className={
                                step.outcome === 'blocked' || step.outcome === 'unavailable'
                                  ? 'msg-timeline-dot msg-timeline-dot-danger'
                                  : step.outcome === 'paused'
                                    ? 'msg-timeline-dot msg-timeline-dot-warning'
                                    : 'msg-timeline-dot'
                              }
                              aria-hidden
                            />
                            <div className="msg-timeline-body">
                              <strong>{humanise(step.type)}</strong>
                              <small>{step.explanation}</small>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </details>
          )}
        </div>
      )}
    </Drawer>
  )
}

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------

/**
 * Templates, with the PROVIDER'S approval status and the time it was read.
 *
 * Nothing on this screen decides that a template is approved. A version is
 * submitted, the provider answers, and the answer is shown with its timestamp
 * — because "approved" without one is a claim about a moment that may have
 * passed.
 */
function TemplatesTab({ selectedUuid, onSelect }: { selectedUuid: string; onSelect: (uuid: string) => void }) {
  const { can } = useMessaging()
  const [editing, setEditing] = useState<Template | 'new' | null>(null)
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const list = useApi((signal) => api.list<Template>('v1/templates', { limit: 60 }, signal), [])

  return (
    <>
      <div className="msg-grid msg-split">
        <Panel
          title="Templates"
          subtitle="Only a provider-approved version can be sent"
          action={
            can('messaging.templates.manage') ? (
              <Button small tone="primary" onClick={() => setEditing('new')}>
                <Plus size={13} aria-hidden />
                New template
              </Button>
            ) : undefined
          }
        >
          <PanelState
            loading={list.loading}
            error={list.error}
            data={list.data}
            onRetry={list.reload}
            skeletonRows={4}
            isEmpty={(response) => response.data.length === 0}
            emptyTitle="No templates yet"
            emptyBody="A business-initiated message on WhatsApp needs a provider-approved template. Create one, then submit it."
          >
            {(response) => (
              <div className="msg-stack" style={{ gap: 0 }}>
                {response.data.map((template) => (
                  <button
                    key={template.template_uuid}
                    type="button"
                    className="msg-conversation-item"
                    aria-current={selectedUuid === template.template_uuid}
                    onClick={() => onSelect(template.template_uuid)}
                  >
                    <span className="msg-conversation-top">
                      <strong>{template.name}</strong>
                      <ProviderStatusPill version={template.latest_version} />
                    </span>
                    <span className="msg-conversation-preview">
                      {channelLabel(template.channel)} · {template.languages.join(', ').toUpperCase()}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </PanelState>
        </Panel>

        {selectedUuid === '' ? (
          <Panel>
            <EmptyState title="Pick a template">
              Choose a template to preview it and see what the provider has said about it.
            </EmptyState>
          </Panel>
        ) : (
          <TemplateDetail
            templateUuid={selectedUuid}
            onEdit={(template) => setEditing(template)}
            onChanged={() => {
              list.reload()
            }}
            onAnnounce={setAnnouncement}
          />
        )}
      </div>

      {editing !== null && (
        <TemplateEditor
          template={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(uuid) => {
            setEditing(null)
            onSelect(uuid)
            list.reload()
            setAnnouncement('Template saved. It cannot be sent until the provider approves it.')
          }}
        />
      )}

      <Announce message={announcement} />
    </>
  )
}

function ProviderStatusPill({ version }: { version: TemplateVersion | null }) {
  if (version === null) return <StatusPill tone="neutral">No version</StatusPill>

  const tone =
    version.provider_status === 'approved'
      ? 'success'
      : version.provider_status === 'rejected' || version.provider_status === 'disabled'
        ? 'danger'
        : version.provider_status === 'not_submitted'
          ? 'neutral'
          : 'warning'

  return <StatusPill tone={tone}>{humanise(version.provider_status)}</StatusPill>
}

function TemplateDetail({
  templateUuid,
  onEdit,
  onChanged,
  onAnnounce,
}: {
  templateUuid: string
  onEdit: (template: Template) => void
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const { can } = useMessaging()

  const detail = useApi(
    (signal) =>
      api
        .get<{ data: { template: Template } }>(`v1/templates/${templateUuid}`, undefined, signal)
        .then((r) => r.data.template),
    [templateUuid],
  )

  const submit = useMutation(async (version: number, language: string) =>
    api.postRaw<{ data: { detail: string } }>(`v1/templates/${templateUuid}/submit`, { version, language }),
  )

  return (
    <div className="msg-stack">
      <PanelState
        loading={detail.loading}
        error={detail.error}
        data={detail.data}
        onRetry={detail.reload}
        skeletonRows={4}
      >
        {(template) => (
          <>
            <Panel
              title={template.name}
              subtitle={`${channelLabel(template.channel)} · ${humanise(template.category)}`}
              action={
                can('messaging.templates.manage') ? (
                  <Button small onClick={() => onEdit(template)}>
                    Edit
                  </Button>
                ) : undefined
              }
            >
              {submit.error && <Notice tone="danger">{submit.error.message}</Notice>}

              {(template.versions ?? []).map((version) => (
                <div key={`${version.version}-${version.language}`} className="msg-card" style={{ marginBottom: '0.75rem' }}>
                  <div className="msg-card-body">
                    <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', alignItems: 'center' }}>
                      <strong style={{ fontSize: '0.88rem' }}>
                        v{version.version} · {version.language.toUpperCase()}
                      </strong>
                      <ProviderStatusPill version={version} />
                      {/* ALWAYS with the read time. */}
                      {version.provider_status_read_at && (
                        <span className="msg-muted msg-small">
                          as the provider reported {timeAgo(version.provider_status_read_at)}
                        </span>
                      )}
                    </div>

                    {version.provider_rejection_reason && (
                      <Notice tone="danger" title="The provider rejected this">
                        {version.provider_rejection_reason}
                      </Notice>
                    )}

                    <div className="msg-preview" style={{ marginTop: '0.6rem' }}>
                      <StatusPill tone="brand">{channelLabel(template.channel)}</StatusPill>
                      <div className="msg-preview-message">
                        {version.header && (
                          <>
                            <strong>{version.header}</strong>
                            <br />
                            <br />
                          </>
                        )}
                        {version.body}
                        {version.footer && (
                          <>
                            <br />
                            <br />
                            <span className="msg-muted msg-small">{version.footer}</span>
                          </>
                        )}
                      </div>
                    </div>

                    {version.variable_schema.length > 0 && (
                      <dl className="msg-definition-list" style={{ marginTop: '0.75rem' }}>
                        {version.variable_schema.map((variable) => (
                          <div key={variable.name} style={{ display: 'contents' }}>
                            <dt>
                              {`{{${variable.name}}}`}
                              {variable.required && <span className="msg-muted"> (required)</span>}
                            </dt>
                            <dd>{variable.example || humanise(variable.type)}</dd>
                          </div>
                        ))}
                      </dl>
                    )}

                    <div className="msg-composer-actions">
                      {!version.sendable && can('messaging.templates.submit') && version.provider_status === 'not_submitted' && (
                        <Button
                          small
                          pending={submit.pending}
                          onClick={async () => {
                            const result = await submit.run(version.version, version.language)
                            if (result !== null) {
                              onAnnounce(result.data.detail)
                              detail.reload()
                              onChanged()
                            }
                          }}
                        >
                          <Send size={13} aria-hidden />
                          Submit for approval
                        </Button>
                      )}
                    </div>

                    {!version.sendable && (
                      <p className="msg-muted msg-small" style={{ margin: '0.5rem 0 0', lineHeight: 1.5 }}>
                        This version cannot be dispatched. Only a version the provider has approved can be sent, and
                        a journey that names it will refuse to publish until then.
                      </p>
                    )}
                  </div>
                </div>
              ))}
            </Panel>
          </>
        )}
      </PanelState>
    </div>
  )
}

function TemplateEditor({
  template,
  onClose,
  onSaved,
}: {
  template: Template | null
  onClose: () => void
  onSaved: (uuid: string) => void
}) {
  const { session } = useMessaging()
  const existing = template?.latest_version ?? null

  const [name, setName] = useState(template?.name ?? '')
  const [channel, setChannel] = useState(template?.channel ?? 'whatsapp')
  const [language, setLanguage] = useState(existing?.language ?? 'en')
  const [body, setBody] = useState(existing?.body ?? '')
  const [header, setHeader] = useState(existing?.header ?? '')
  const [footer, setFooter] = useState(existing?.footer ?? '')

  const languages = session?.settings.languages ?? { en: 'English', hi: 'Hindi' }
  const connectedChannels = session?.channels.connected ?? []

  const save = useMutation(async () => {
    const payload = { name, channel, language, body, header, footer }
    const response = template === null
      ? await api.postRaw<{ data: { template: Template } }>('v1/templates', payload)
      : await api.postRaw<{ data: { template: Template } }>(`v1/templates/${template.template_uuid}`, payload)
    return response.data.template
  })

  // The placeholders the body actually uses, shown as the schema that will be
  // inferred. A variable in the body that nobody binds is a send refused at
  // dispatch, so it is better seen here.
  const placeholders = [...new Set([...body.matchAll(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g)].map((match) => match[1]))]

  return (
    <Drawer
      title={template === null ? 'New template' : `Edit ${template.name}`}
      subtitle="Saving creates a new version. A submitted version is never edited in place."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            tone="primary"
            pending={save.pending}
            disabled={name.trim() === '' || body.trim() === ''}
            onClick={async () => {
              const saved = await save.run()
              if (saved !== null) onSaved(saved.template_uuid)
            }}
          >
            Save
          </Button>
        </>
      }
    >
      {save.error && <Notice tone="danger">{save.error.message}</Notice>}

      <Field label="Name" htmlFor="template-name" required>
        <input
          id="template-name"
          className="msg-input"
          value={name}
          onChange={(event) => setName(event.target.value)}
          placeholder="payment_reminder"
        />
      </Field>

      <Field
        label="Channel"
        htmlFor="template-channel"
        hint={
          connectedChannels.length === 0
            ? 'No channel is connected yet. You can still write a template, but it cannot be submitted or sent.'
            : undefined
        }
        required
      >
        <select
          id="template-channel"
          className="msg-select"
          value={channel}
          onChange={(event) => setChannel(event.target.value)}
          disabled={template !== null}
        >
          <option value="whatsapp">WhatsApp Business</option>
          <option value="rcs">RCS</option>
          <option value="sms">SMS</option>
        </select>
      </Field>

      <Field label="Language" htmlFor="template-language" required>
        <select
          id="template-language"
          className="msg-select"
          value={language}
          onChange={(event) => setLanguage(event.target.value)}
        >
          {Object.entries(languages).map(([code, label]) => (
            <option key={code} value={code}>
              {label}
            </option>
          ))}
        </select>
      </Field>

      <Field label="Header" htmlFor="template-header" hint="Optional.">
        <input
          id="template-header"
          className="msg-input"
          value={header}
          onChange={(event) => setHeader(event.target.value)}
        />
      </Field>

      <Field
        label="Body"
        htmlFor="template-body"
        hint="Use {{variable_name}} for anything that changes. Every placeholder must have a value at send time, or the send is refused."
        required
      >
        <textarea
          id="template-body"
          className="msg-textarea"
          style={{ minHeight: 160 }}
          value={body}
          onChange={(event) => setBody(event.target.value)}
          placeholder={'Hello {{customer_name}},\n\nInvoice {{invoice_number}} for {{amount}} is overdue.'}
        />
      </Field>

      <Field label="Footer" htmlFor="template-footer" hint="Optional.">
        <input
          id="template-footer"
          className="msg-input"
          value={footer}
          onChange={(event) => setFooter(event.target.value)}
        />
      </Field>

      {placeholders.length > 0 && (
        <Notice tone="info" title="Variables in this template">
          {placeholders.map((placeholder) => `{{${placeholder}}}`).join(', ')}. Each one must be bound when the
          message is sent.
        </Notice>
      )}

      <Notice tone="warning" title="The provider decides">
        Saving does not make this sendable. It has to be submitted, and the provider has to approve it. Messaging
        records their answer with the time it was read and never decides it.
      </Notice>
    </Drawer>
  )
}

// ---------------------------------------------------------------------------
// Run history
// ---------------------------------------------------------------------------

function RunsTab() {
  const { timezone } = useMessaging()
  const [filters, setFilters] = useUrlFilters({ status: '', mode: '' })

  const list = useApi(
    (signal) =>
      api.list<JourneyRun>(
        'v1/journey-runs',
        { status: filters.status || undefined, mode: filters.mode || undefined, limit: 60 },
        signal,
      ),
    [filters.status, filters.mode],
  )

  return (
    <Panel title="Run history" subtitle="Every execution, with the reason it went the way it did">
      <div className="msg-filters">
        <label className="msg-visually-hidden" htmlFor="runs-status">
          Status
        </label>
        <select
          id="runs-status"
          className="msg-select"
          value={filters.status}
          onChange={(event) => setFilters({ status: event.target.value })}
        >
          <option value="">Any status</option>
          <option value="completed">Completed</option>
          <option value="awaiting_approval">Awaiting approval</option>
          <option value="paused">Paused</option>
          <option value="cancelled">Cancelled</option>
          <option value="failed">Failed</option>
        </select>

        <label className="msg-visually-hidden" htmlFor="runs-mode">
          Mode
        </label>
        <select
          id="runs-mode"
          className="msg-select"
          value={filters.mode}
          onChange={(event) => setFilters({ mode: event.target.value })}
        >
          <option value="">Live and test</option>
          <option value="live">Live only</option>
          <option value="simulation">Test runs only</option>
        </select>
      </div>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        onRetry={list.reload}
        skeletonRows={5}
        isEmpty={(response) => response.data.length === 0}
        emptyTitle="No runs yet"
        emptyBody="A journey run appears here the first time one executes."
      >
        {(response) => (
          <div className="msg-table-wrap">
            <table className="msg-table">
              <caption className="msg-visually-hidden">Journey runs, newest first</caption>
              <thead>
                <tr>
                  <th scope="col">Journey</th>
                  <th scope="col">Subject</th>
                  <th scope="col">Status</th>
                  <th scope="col">Outcome</th>
                  <th scope="col">Started</th>
                  <th scope="col">Version</th>
                </tr>
              </thead>
              <tbody>
                {response.data.map((run) => (
                  <tr key={run.run_uuid}>
                    <td>
                      <Link to={`/journey-runs/${run.run_uuid}`}>{run.journey_name}</Link>
                      {run.mode === 'simulation' && (
                        <>
                          {' '}
                          <StatusPill tone="info">Test · nothing sent</StatusPill>
                        </>
                      )}
                    </td>
                    <td>
                      {run.subject_ref ? (
                        <>
                          {run.subject_ref}
                          {run.subject_product && (
                            <>
                              <br />
                              <span className="msg-muted msg-small">Aicountly {humanise(run.subject_product)}</span>
                            </>
                          )}
                        </>
                      ) : (
                        <span className="msg-muted">—</span>
                      )}
                    </td>
                    <td>
                      <StatusPill tone={runTone(run.status)}>{humanise(run.status)}</StatusPill>
                    </td>
                    <td className="msg-truncate" style={{ maxWidth: '16rem' }}>
                      {run.outcome ? humanise(run.outcome) : <span className="msg-muted">—</span>}
                    </td>
                    <td className="msg-nowrap">{formatDateTime(run.started_at, timezone)}</td>
                    <td className="num">v{run.journey_version}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PanelState>
    </Panel>
  )
}

export function runTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' | 'info' {
  switch (status) {
    case 'completed':
      return 'success'
    case 'awaiting_approval':
    case 'paused':
      return 'warning'
    case 'failed':
      return 'danger'
    case 'running':
      return 'info'
    default:
      return 'neutral'
  }
}
