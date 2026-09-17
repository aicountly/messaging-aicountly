/**
 * Dashboard 5 — Channels & Trust.
 *
 * ## The rule this screen exists to honour
 *
 * A pending integration must not look connected. Every card reports what is
 * actually configured, names what is missing, and never shows a credential —
 * `credential_present` is a boolean, and the environment variable NAME appears
 * only for somebody who holds `messaging.channels.manage` and could act on it.
 *
 * ## What it does not claim
 *
 * There is no "fully compliant" badge anywhere on this screen. It shows the
 * checks this deployment actually performs and the states providers actually
 * report. Whether that satisfies a particular regulation is a judgement for
 * the business and its advisers, and a product that printed a compliance claim
 * would be making a promise it is in no position to make.
 *
 * ## Capabilities are data, not assumptions
 *
 * The capability matrix is what the adapter declared and what the provider
 * reported, per connection. An SMS sender that cannot receive replies is shown
 * as such — which is what disables the inbox composer for it rather than
 * giving an agent a box that eats what they type.
 */

import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  Ban,
  CheckCircle2,
  Lock,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Sparkles,
  Webhook,
} from 'lucide-react'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlFilters } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { ChannelsResponse, Message } from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import {
  Announce,
  Button,
  Drawer,
  Field,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  StatusPill,
  formatCount,
  formatDateTime,
  formatRate,
  messageStatusTone,
  timeAgo,
} from '../ui'
import { channelLabel, humanise } from './CommandCentre'

export function ChannelsTrustPage() {
  const { can } = useMessaging()
  const [filters, setFilters] = useUrlFilters({ tab: 'channels', status: '' })

  if (!can('messaging.channels.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="Channels & Trust" />
      </div>
    )
  }

  const tabs = [
    { key: 'channels', label: 'Channels' },
    ...(can('messaging.dispatch.manage') ? [{ key: 'delivery', label: 'Delivery investigation' }] : []),
    ...(can('messaging.consent.view') ? [{ key: 'consent', label: 'Consent & suppression' }] : []),
  ]

  const tab = tabs.some((entry) => entry.key === filters.tab) ? filters.tab : 'channels'

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Channels &amp; Trust</h1>
          <p>Healthy delivery. Clear consent. Controlled AI.</p>
        </div>
        <div className="msg-page-actions">
          <div className="msg-chips" role="tablist" aria-label="Channels and trust">
            {tabs.map((entry) => (
              <button
                key={entry.key}
                type="button"
                role="tab"
                className="msg-chip"
                aria-selected={tab === entry.key}
                aria-pressed={tab === entry.key}
                onClick={() => setFilters({ tab: entry.key })}
              >
                {entry.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {tab === 'channels' && <ChannelsTab />}
      {tab === 'delivery' && <DeliveryTab />}
      {tab === 'consent' && <ConsentTab />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Channels
// ---------------------------------------------------------------------------

function ChannelsTab() {
  const { can } = useMessaging()
  const [connecting, setConnecting] = useState(false)
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const { data, loading, error, reload } = useApi(
    (signal) => api.get<{ data: ChannelsResponse }>('v1/channels', undefined, signal).then((r) => r.data),
    [],
  )

  return (
    <>
      <PanelState loading={loading} error={error} data={data} onRetry={reload} skeletonRows={4}>
        {(channels) => (
          <>
            <div className="msg-grid msg-grid-3">
              {channels.connections.map((connection) => (
                <ChannelCard
                  key={connection.connection_uuid}
                  connection={connection}
                  onChanged={reload}
                  onAnnounce={setAnnouncement}
                />
              ))}

              {/* Planned, not pending. There is no adapter behind these and
                  they cannot be connected — "coming later" is honest where
                  "pending" would imply somebody just has to finish. */}
              <Panel title="Other OTT channels" subtitle="On our roadmap">
                <div className="msg-stack" style={{ gap: '0.4rem' }}>
                  {channels.planned.map((entry) => (
                    <div key={entry.channel} className="msg-row msg-row-tight">
                      <div className="msg-row-body">
                        <strong>{entry.label}</strong>
                      </div>
                      <StatusPill tone="neutral">Planned</StatusPill>
                    </div>
                  ))}
                </div>
                <p className="msg-muted msg-small" style={{ margin: '0.6rem 0 0', lineHeight: 1.5 }}>
                  {channels.planned[0]?.note ??
                    'These have no adapter yet, so they cannot be connected or chosen on a journey.'}
                </p>
              </Panel>
            </div>

            {channels.connections.length === 0 && (
              <Notice
                tone="warning"
                title="No channel is connected"
                action={
                  can('messaging.channels.manage') ? (
                    <Button small tone="primary" onClick={() => setConnecting(true)}>
                      Connect one
                    </Button>
                  ) : undefined
                }
              >
                Messaging cannot send anything until a channel is connected. The inbox still receives nothing and
                journeys will refuse to publish.
              </Notice>
            )}

            {can('messaging.channels.manage') && channels.connections.length > 0 && (
              <div className="msg-page-actions" style={{ marginBottom: '1rem' }}>
                <Button small tone="primary" onClick={() => setConnecting(true)}>
                  <Plus size={13} aria-hidden />
                  Connect channel
                </Button>
              </div>
            )}

            <div className="msg-grid msg-split">
              <DeliveryHealthPanel channels={channels} />
              <WebhookHealthPanel channels={channels} />
            </div>

            <div className="msg-grid msg-split">
              <AiPermissionsPanel channels={channels} onChanged={reload} onAnnounce={setAnnouncement} />
              <IntegrationsPanel channels={channels} />
            </div>

            <Notice tone="info" title="What this screen claims">
              {channels.compliance_note}
            </Notice>
          </>
        )}
      </PanelState>

      {connecting && (
        <ChannelOnboarding
          providers={data?.available_providers ?? {}}
          onClose={() => setConnecting(false)}
          onCreated={(detail) => {
            setConnecting(false)
            reload()
            setAnnouncement(detail)
          }}
        />
      )}

      <Announce message={announcement} />
    </>
  )
}

function ChannelCard({
  connection,
  onChanged,
  onAnnounce,
}: {
  connection: ChannelsResponse['connections'][number]
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const { can, timezone } = useMessaging()
  const [showCapabilities, setShowCapabilities] = useState(false)

  const healthCheck = useMutation(async () =>
    api.postRaw<{ data: { ok: boolean; detail: string } }>(
      `v1/channels/${connection.connection_uuid}/health-check`,
      {},
    ),
  )

  const tone =
    connection.status === 'connected'
      ? 'success'
      : connection.status === 'suspended' || connection.status === 'disconnected'
        ? 'danger'
        : 'warning'

  const supported = connection.capabilities.filter((entry) => entry.supported)
  const templateApproved = connection.template_status.approved ?? 0
  const templatePending =
    (connection.template_status.submitted ?? 0) + (connection.template_status.in_review ?? 0)

  return (
    <Panel
      title={connection.display_name || channelLabel(connection.channel)}
      subtitle={connection.adapter_name}
      action={<StatusPill tone={tone}>{connection.status_label}</StatusPill>}
    >
      {/* What is missing, named. Only for somebody who could fix it — the
          backend decides that and sends a generic sentence otherwise. */}
      {connection.configuration_gap && (
        <Notice tone="warning" title="Not ready to send">
          {connection.configuration_gap}
        </Notice>
      )}

      <dl className="msg-definition-list">
        <dt>Sender</dt>
        <dd>{connection.sender_address || <span className="msg-muted">not set</span>}</dd>

        <dt>Credential</dt>
        <dd>
          {/* A boolean. Never the value, and the NAME only where the caller
              could act on it. */}
          {connection.credential_present ? (
            <StatusPill tone="success" icon={Lock}>
              Present
            </StatusPill>
          ) : (
            <StatusPill tone="warning">Missing</StatusPill>
          )}
          {can('messaging.channels.manage') && connection.credential_ref && (
            <>
              <br />
              <span className="msg-muted msg-small">from {connection.credential_ref}</span>
            </>
          )}
        </dd>

        <dt>Last health check</dt>
        <dd>
          {connection.last_health_check_at === null ? (
            <span className="msg-muted">never</span>
          ) : (
            <>
              {timeAgo(connection.last_health_check_at)}
              {connection.last_health_check_ok === false && (
                <>
                  <br />
                  <StatusPill tone="danger">Failed</StatusPill>
                </>
              )}
            </>
          )}
        </dd>

        <dt>Last webhook</dt>
        <dd>
          {connection.last_webhook_at === null ? (
            <span className="msg-muted">never</span>
          ) : (
            timeAgo(connection.last_webhook_at)
          )}
        </dd>

        {/* Provider-reported, WITH the time it was read. Nullable, because
            most providers do not report it — and a zero here would read as
            "throttled to nothing". */}
        {connection.provider_quality !== null && (
          <>
            <dt>Provider quality</dt>
            <dd>
              {humanise(connection.provider_quality)}
              {connection.provider_state_read_at && (
                <>
                  <br />
                  <span className="msg-muted msg-small">read {timeAgo(connection.provider_state_read_at)}</span>
                </>
              )}
            </dd>
          </>
        )}

        {connection.provider_rate_limit !== null && (
          <>
            <dt>Rate limit</dt>
            <dd>{formatCount(connection.provider_rate_limit)}/s</dd>
          </>
        )}

        <dt>Last 24 hours</dt>
        <dd>
          {connection.delivery_24h.rate === null ? (
            <span className="msg-muted">no messages</span>
          ) : (
            <>
              {formatRate(connection.delivery_24h.rate)} confirmed
              <br />
              <span className="msg-muted msg-small">
                of {formatCount(connection.delivery_24h.accepted)} accepted
                {connection.delivery_24h.failed > 0 && `, ${formatCount(connection.delivery_24h.failed)} failed`}
              </span>
            </>
          )}
        </dd>

        {(templateApproved > 0 || templatePending > 0) && (
          <>
            <dt>Templates</dt>
            <dd>
              {formatCount(templateApproved)} approved
              {templatePending > 0 && (
                <>
                  <br />
                  <span className="msg-muted msg-small">{formatCount(templatePending)} awaiting the provider</span>
                </>
              )}
            </dd>
          </>
        )}
      </dl>

      <div className="msg-composer-actions">
        <Button small tone="ghost" onClick={() => setShowCapabilities(!showCapabilities)}>
          {supported.length} of {connection.capabilities.length} capabilities
        </Button>
        {can('messaging.channels.manage') && (
          <Button
            small
            pending={healthCheck.pending}
            onClick={async () => {
              const result = await healthCheck.run()
              if (result !== null) {
                onAnnounce(result.data.detail)
                onChanged()
              }
            }}
          >
            <RefreshCw size={13} aria-hidden />
            Check
          </Button>
        )}
      </div>

      {healthCheck.error && <Notice tone="danger">{healthCheck.error.message}</Notice>}

      {/* The capability matrix. Status as TEXT beside each one, never a
          coloured tick alone. */}
      {showCapabilities && (
        <div className="msg-stack" style={{ gap: 0, marginTop: '0.5rem' }}>
          {connection.capabilities.map((entry) => (
            <div key={entry.capability} className="msg-row msg-row-tight">
              <div className="msg-row-body">
                <span className="msg-small">{entry.label}</span>
              </div>
              <StatusPill tone={entry.supported ? 'success' : 'neutral'}>
                {entry.supported ? 'Supported' : 'Not supported'}
              </StatusPill>
            </div>
          ))}
        </div>
      )}

      {can('messaging.channels.manage') && connection.webhook_url && (
        <details style={{ marginTop: '0.75rem' }}>
          <summary style={{ cursor: 'pointer', fontSize: '0.8rem' }}>
            <Webhook size={12} aria-hidden style={{ verticalAlign: '-1px' }} /> Webhook URL for the provider
          </summary>
          <p
            className="msg-small"
            style={{
              margin: '0.5rem 0 0',
              wordBreak: 'break-all',
              background: 'var(--surface-2)',
              padding: '0.5rem',
              borderRadius: '0.5rem',
            }}
          >
            {connection.webhook_url}
          </p>
          <p className="msg-muted msg-small" style={{ margin: '0.4rem 0 0', lineHeight: 1.5 }}>
            Register this with the provider. Events are rejected unless they carry a valid signature, and the
            company is resolved from this connection rather than from anything in the payload.
            {connection.webhook_verified_at !== null &&
              ` Verified ${formatDateTime(connection.webhook_verified_at, timezone)}.`}
          </p>
        </details>
      )}
    </Panel>
  )
}

function DeliveryHealthPanel({ channels }: { channels: ChannelsResponse }) {
  const health = channels.delivery_health
  const queue = channels.queue

  return (
    <Panel title="Delivery health" subtitle={health.window}>
      <div className="msg-metric-row" style={{ gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', marginBottom: '1rem' }}>
        <div className="msg-metric">
          <div className="msg-metric-body">
            <span className="msg-metric-label">Confirmed delivered</span>
            <span className="msg-metric-value">{formatCount(health.delivered)}</span>
            <div className="msg-metric-foot">
              <span className="msg-muted">
                {health.delivery_rate === null ? 'no messages' : `${formatRate(health.delivery_rate)} of accepted`}
              </span>
            </div>
          </div>
        </div>
        <div className="msg-metric">
          <div className="msg-metric-body">
            <span className="msg-metric-label">Accepted by provider</span>
            <span className="msg-metric-value">{formatCount(health.accepted)}</span>
            <div className="msg-metric-foot">
              <span className="msg-muted">not the same as delivered</span>
            </div>
          </div>
        </div>
      </div>

      <div className="msg-stack" style={{ gap: 0 }}>
        <div className="msg-row msg-row-tight">
          <div className="msg-row-body">
            <strong>Awaiting confirmation</strong>
            <small>Accepted with no terminal receipt yet. Counted as neither delivered nor failed.</small>
          </div>
          <span className="msg-count-pill">{formatCount(health.unconfirmed)}</span>
        </div>

        <div className="msg-row msg-row-tight">
          <div className="msg-row-body">
            <strong>Failed</strong>
            <small>The provider reported these as failed.</small>
          </div>
          <span className={health.failed > 0 ? 'msg-count-pill msg-count-pill-alert' : 'msg-count-pill'}>
            {formatCount(health.failed)}
          </span>
        </div>

        {/* THE ONE THAT MATTERS. Neither sent nor failed, deliberately not
            retried, and it needs a human. */}
        <div className="msg-row msg-row-tight">
          <div className="msg-row-body">
            <strong>Submission unknown</strong>
            <small>
              Timed out after the provider may have accepted them. NOT resent — the customer may already have them.
            </small>
          </div>
          {health.submission_unknown > 0 ? (
            <Link to="/trust?tab=delivery" className="msg-button msg-button-small">
              Investigate {formatCount(health.submission_unknown)}
            </Link>
          ) : (
            <span className="msg-count-pill">0</span>
          )}
        </div>

        {queue !== null && (
          <>
            <div className="msg-row msg-row-tight">
              <div className="msg-row-body">
                <strong>In the retry queue</strong>
                <small>Waiting for a retryable provider error or quiet hours to pass.</small>
              </div>
              <span className="msg-count-pill">{formatCount(queue.retrying ?? 0)}</span>
            </div>
            <div className="msg-row msg-row-tight">
              <div className="msg-row-body">
                <strong>Queued to send</strong>
              </div>
              <span className="msg-count-pill">{formatCount(queue.queued ?? 0)}</span>
            </div>
          </>
        )}
      </div>

      <p className="msg-muted msg-small" style={{ margin: '0.85rem 0 0', lineHeight: 1.55 }}>
        {health.basis}
      </p>
    </Panel>
  )
}

function WebhookHealthPanel({ channels }: { channels: ChannelsResponse }) {
  const { webhooks } = channels
  const counts = webhooks.counts_24h

  return (
    <Panel title="Webhook health" subtitle="Provider events received in the last 24 hours">
      <dl className="msg-definition-list">
        <dt>Processed</dt>
        <dd>{formatCount(counts.processed ?? 0)}</dd>
        <dt>Received, not yet processed</dt>
        <dd>{formatCount(counts.received ?? 0)}</dd>
        <dt>Ignored</dt>
        <dd>{formatCount(counts.ignored ?? 0)}</dd>
        <dt>Failed to process</dt>
        <dd>{formatCount(counts.failed ?? 0)}</dd>
        <dt>Last event</dt>
        <dd>{webhooks.last_event_at === null ? 'never' : timeAgo(webhooks.last_event_at)}</dd>
      </dl>

      {/* A run of rejections is either a misconfigured secret or somebody
          probing the endpoint. Both deserve attention, so it is surfaced
          separately rather than buried in a count. */}
      {webhooks.unattributed_rejected_24h > 0 && (
        <Notice tone="warning" title={`${webhooks.unattributed_rejected_24h} rejected event(s)`}>
          These arrived without a valid signature or for a connection this deployment does not have. Their bodies
          were not stored. If you have just registered a webhook, check the secret; otherwise somebody is probing
          the endpoint.
        </Notice>
      )}

      <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
        Events are acknowledged as soon as they are durably stored, then processed. The provider's own event id
        deduplicates retries, and out-of-order receipts never move a message backwards.
      </p>
    </Panel>
  )
}

/**
 * AI permissions.
 *
 * The row that matters is the last one: creating a financial record is NOT
 * Messaging's to grant, and the screen says so rather than offering a toggle
 * that would be a lie.
 */
function AiPermissionsPanel({
  channels,
  onChanged,
  onAnnounce,
}: {
  channels: ChannelsResponse
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const { ai_permissions: permissions } = channels

  const save = useMutation(async (key: string, value: boolean) =>
    api.put('v1/settings', {
      [key]: value,
      // Turning on autonomous sending needs an explicit confirmation, which
      // the backend requires separately.
      confirm_autonomous_sending: key === 'ai_autosend_allowed' && value ? true : undefined,
    }),
  )

  return (
    <Panel
      title="AI permissions"
      subtitle="What Aicountly Messaging AI may do"
      action={
        <span className="msg-source">
          <Sparkles size={13} aria-hidden />
          {permissions.editable ? 'You can change these' : 'Read only'}
        </span>
      }
    >
      {save.error && <Notice tone="danger">{save.error.message}</Notice>}

      <div className="msg-stack" style={{ gap: 0 }}>
        {permissions.rows.map((row) => (
          <div key={row.key} className="msg-row">
            <div className="msg-row-body">
              <strong>{row.label}</strong>
              <small>{row.detail}</small>
              {row.warning && (
                <small style={{ color: 'var(--warning)', fontWeight: 650 }}>{row.warning}</small>
              )}
            </div>

            <div className="msg-actions">
              <StatusPill
                tone={
                  row.state === 'allowed'
                    ? 'success'
                    : row.state === 'not_available'
                      ? 'neutral'
                      : row.state === 'approval_required'
                        ? 'warning'
                        : 'danger'
                }
                icon={row.state === 'not_available' ? Ban : undefined}
              >
                {row.state === 'allowed'
                  ? 'Allowed'
                  : row.state === 'approval_required'
                    ? 'Approval required'
                    : row.state === 'not_available'
                      ? 'Not available here'
                      : 'Disabled'}
              </StatusPill>

              {permissions.editable && row.locked !== true && (
                <Button
                  small
                  pending={save.pending}
                  onClick={async () => {
                    const next = row.state !== 'allowed'
                    const result = await save.run(row.key, next)
                    if (result !== null) {
                      onAnnounce(`${row.label} ${next ? 'allowed' : 'disabled'}.`)
                      onChanged()
                    }
                  }}
                >
                  {row.state === 'allowed' ? 'Disable' : 'Allow'}
                </Button>
              )}
            </div>
          </div>
        ))}
      </div>

      <Notice tone="info" title="The default is review, and it is deliberate">
        An AI-drafted message needs a person to read and send it. Autonomous sending is off unless somebody turns
        it on explicitly, and doing so is audited.
      </Notice>
    </Panel>
  )
}

/**
 * The integration cards.
 *
 * Connected or pending — never "connected" for something that is not. The
 * remedy names environment variables and comes from the backend, which sends
 * a generic sentence to anybody who could not act on it.
 */
function IntegrationsPanel({ channels }: { channels: ChannelsResponse }) {
  return (
    <Panel
      title="Aicountly connections"
      subtitle="Every one of these is read live. Nothing is copied into Messaging."
      action={<StatusPill tone="brand">Live APIs only</StatusPill>}
    >
      <div className="msg-stack" style={{ gap: 0 }}>
        {channels.integrations.map((integration) => (
          <div key={integration.product} className="msg-row msg-row-tight">
            <div className="msg-row-body">
              <strong>{integration.label}</strong>
              <small>{integration.purpose}</small>
              {integration.remedy && (
                <small style={{ color: 'var(--warning)' }}>{integration.remedy}</small>
              )}
            </div>
            <StatusPill
              tone={integration.state === 'connected' ? 'success' : 'warning'}
              icon={integration.state === 'connected' ? CheckCircle2 : undefined}
            >
              {integration.state_label}
            </StatusPill>
          </div>
        ))}
      </div>

      <p className="msg-muted msg-small" style={{ margin: '0.85rem 0 0', lineHeight: 1.55 }}>
        There is no cross-product database synchronisation. Customer identity comes from Contacts, balances from
        Books and payment status from Pay, read on the request that shows them — so a figure on a screen here is
        the figure that product holds now.
      </p>
    </Panel>
  )
}

/**
 * Channel onboarding.
 *
 * NOTE WHAT THIS FORM DOES NOT ACCEPT: a credential. It takes the NAME of the
 * server environment variable holding one, so a token never travels through a
 * browser, an access log or a request body. The backend refuses anything that
 * does not look like a variable name.
 */
function ChannelOnboarding({
  providers,
  onClose,
  onCreated,
}: {
  providers: ChannelsResponse['available_providers']
  onClose: () => void
  onCreated: (detail: string) => void
}) {
  const [channel, setChannel] = useState('whatsapp')
  const [provider, setProvider] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [senderAddress, setSenderAddress] = useState('')
  const [accountRef, setAccountRef] = useState('')
  const [credentialRef, setCredentialRef] = useState('')
  const [webhookSecretRef, setWebhookSecretRef] = useState('')

  const options = providers[channel] ?? []

  const create = useMutation(async () =>
    api.postRaw<{ data: { detail: string; webhook_url: string | null } }>('v1/channels', {
      channel,
      provider: provider || options[0]?.provider,
      display_name: displayName,
      sender_address: senderAddress,
      provider_account_ref: accountRef,
      credential_ref: credentialRef,
      webhook_secret_ref: webhookSecretRef,
    }),
  )

  return (
    <Drawer
      title="Connect a channel"
      subtitle="Configuration only. This form never accepts a credential."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            tone="primary"
            pending={create.pending}
            disabled={options.length === 0}
            onClick={async () => {
              const result = await create.run()
              if (result !== null) onCreated(result.data.detail)
            }}
          >
            Create connection
          </Button>
        </>
      }
    >
      {create.error && <Notice tone="danger">{create.error.message}</Notice>}

      <Notice tone="warning" title="Credentials live on the server, not here">
        Enter the NAME of the environment variable that holds the token — for example
        <code> MESSAGING_WHATSAPP_TOKEN</code>. This API refuses a credential value, so a token never travels
        through your browser or an access log.
      </Notice>

      <Field label="Channel" htmlFor="onboard-channel" required>
        <select
          id="onboard-channel"
          className="msg-select"
          value={channel}
          onChange={(event) => {
            setChannel(event.target.value)
            setProvider('')
          }}
        >
          <option value="whatsapp">WhatsApp Business</option>
          <option value="rcs">RCS</option>
          <option value="sms">SMS</option>
        </select>
      </Field>

      <Field
        label="Provider"
        htmlFor="onboard-provider"
        hint="Which provider a deployment uses is a configuration choice. Nothing in this product prefers one."
        required
      >
        <select
          id="onboard-provider"
          className="msg-select"
          value={provider || options[0]?.provider || ''}
          onChange={(event) => setProvider(event.target.value)}
          disabled={options.length === 0}
        >
          {options.length === 0 && <option value="">No adapter installed for this channel</option>}
          {options.map((entry) => (
            <option key={entry.provider} value={entry.provider}>
              {entry.display_name}
            </option>
          ))}
        </select>
      </Field>

      {channel === 'rcs' && (
        <Notice tone="warning" title="RCS needs deployment configuration first">
          RCS reaches an agent through an aggregator or Google RBM, and the request shape differs between them. Set{' '}
          <code>MESSAGING_RCS_API_BASE</code> and <code>MESSAGING_RCS_PROVIDER_STYLE</code> in the server
          environment. Until then the connection can be created but will report “Verification required” and refuse
          to send — it will not quietly fall back to SMS.
        </Notice>
      )}

      <Field label="Name for this connection" htmlFor="onboard-name" hint="What your team will see.">
        <input
          id="onboard-name"
          className="msg-input"
          value={displayName}
          onChange={(event) => setDisplayName(event.target.value)}
          placeholder="Main WhatsApp number"
        />
      </Field>

      <Field
        label="Sender"
        htmlFor="onboard-sender"
        hint={
          channel === 'sms'
            ? 'A phone number in E.164, or an alphanumeric sender id. An alphanumeric sender cannot receive replies, and the inbox will say so.'
            : 'The phone number in E.164, as the provider knows it.'
        }
        required
      >
        <input
          id="onboard-sender"
          className="msg-input"
          value={senderAddress}
          onChange={(event) => setSenderAddress(event.target.value)}
          placeholder="+919876543210"
        />
      </Field>

      <Field
        label="Provider account reference"
        htmlFor="onboard-account"
        hint={
          channel === 'whatsapp'
            ? 'The WhatsApp phone number id from the provider console.'
            : channel === 'sms'
              ? 'The provider account SID.'
              : 'The RCS agent id.'
        }
        required
      >
        <input
          id="onboard-account"
          className="msg-input"
          value={accountRef}
          onChange={(event) => setAccountRef(event.target.value)}
        />
      </Field>

      <Field
        label="Credential variable name"
        htmlFor="onboard-credential"
        hint="The NAME of the server environment variable holding the token. Not the token."
        required
      >
        <input
          id="onboard-credential"
          className="msg-input"
          value={credentialRef}
          onChange={(event) => setCredentialRef(event.target.value.toUpperCase())}
          placeholder="MESSAGING_WHATSAPP_TOKEN"
          autoComplete="off"
        />
      </Field>

      <Field
        label="Webhook secret variable name"
        htmlFor="onboard-webhook-secret"
        hint="Also a name. Without it, provider events cannot be verified and are rejected."
      >
        <input
          id="onboard-webhook-secret"
          className="msg-input"
          value={webhookSecretRef}
          onChange={(event) => setWebhookSecretRef(event.target.value.toUpperCase())}
          placeholder="MESSAGING_WHATSAPP_WEBHOOK_SECRET"
          autoComplete="off"
        />
      </Field>
    </Drawer>
  )
}

// ---------------------------------------------------------------------------
// Delivery investigation
// ---------------------------------------------------------------------------

/**
 * The delivery investigation screen.
 *
 * Two queues, kept apart: failures the provider explained, and submissions
 * nobody can explain. The second has no resend button — reconciling means
 * ASKING the provider, and where the provider cannot say, the message waits
 * for a person. That is worse than a resend button and it is the right thing.
 */
function DeliveryTab() {
  const { timezone } = useMessaging()
  const [filters, setFilters] = useUrlFilters({ status: 'failed' })
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const status = filters.status === 'submission_unknown' ? 'submission_unknown' : 'failed'

  const list = useApi(
    (signal) => api.list<Record<string, unknown>>('v1/delivery/failures', { status, limit: 50 }, signal),
    [status],
  )

  const grouped =
    (list.data?.meta.grouped as Array<{ failure_code: string | null; n: string; latest: string }> | undefined) ?? []

  return (
    <>
      <div className="msg-chips" style={{ marginBottom: '1rem' }} role="group" aria-label="Queue">
        <button
          type="button"
          className="msg-chip"
          aria-pressed={status === 'failed'}
          onClick={() => setFilters({ status: 'failed' })}
        >
          Failed
        </button>
        <button
          type="button"
          className="msg-chip"
          aria-pressed={status === 'submission_unknown'}
          onClick={() => setFilters({ status: 'submission_unknown' })}
        >
          Submission unknown
        </button>
      </div>

      <Notice tone={status === 'submission_unknown' ? 'warning' : 'info'}>
        {String(list.data?.meta.guidance ?? '')}
      </Notice>

      {grouped.length > 0 && (
        <Panel title="Grouped by reason" subtitle="Last 7 days">
          <div className="msg-stack" style={{ gap: 0 }}>
            {grouped.map((group) => (
              <div key={group.failure_code ?? 'unknown'} className="msg-row msg-row-tight">
                <div className="msg-row-body">
                  <strong>{group.failure_code ?? 'No code reported'}</strong>
                  <small>Most recent {timeAgo(group.latest)}</small>
                </div>
                <span className="msg-count-pill msg-count-pill-alert">{formatCount(Number(group.n))}</span>
              </div>
            ))}
          </div>
        </Panel>
      )}

      <Panel title={status === 'failed' ? 'Failed messages' : 'Messages needing investigation'}>
        <PanelState
          loading={list.loading}
          error={list.error}
          data={list.data}
          onRetry={list.reload}
          skeletonRows={4}
          isEmpty={(response) => response.data.length === 0}
          emptyTitle={status === 'failed' ? 'Nothing has failed' : 'Nothing is unresolved'}
          emptyBody={
            status === 'failed'
              ? 'No provider has reported a failure.'
              : 'Every send has a definite outcome. Nothing is waiting on a provider status lookup.'
          }
        >
          {(response) => (
            <div className="msg-table-wrap">
              <table className="msg-table">
                <caption className="msg-visually-hidden">
                  {status === 'failed' ? 'Failed messages' : 'Messages with an unknown submission outcome'}
                </caption>
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">To</th>
                    <th scope="col">Channel</th>
                    <th scope="col">Reason</th>
                    <th scope="col">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {response.data.map((row) => (
                    <DeliveryRow
                      key={String(row.message_uuid)}
                      row={row}
                      timezone={timezone}
                      onChanged={list.reload}
                      onAnnounce={setAnnouncement}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </PanelState>
      </Panel>

      <Announce message={announcement} />
    </>
  )
}

function DeliveryRow({
  row,
  timezone,
  onChanged,
  onAnnounce,
}: {
  row: Record<string, unknown>
  timezone: string
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const [open, setOpen] = useState(false)
  const messageUuid = String(row.message_uuid)
  const isUnknown = String(row.status) === 'submission_unknown'

  const reconcile = useMutation(async () =>
    api.postRaw<{ data: { resolved: boolean; detail: string } }>(
      `v1/delivery/messages/${messageUuid}/reconcile`,
      {},
    ),
  )

  return (
    <>
      <tr>
        <td className="msg-nowrap">
          {formatDateTime(String(row.failed_at ?? row.created_at), timezone)}
        </td>
        <td>
          <Link to={`/inbox/${String(row.conversation_uuid)}`}>{String(row.customer_address)}</Link>
        </td>
        <td>{channelLabel(String(row.channel))}</td>
        <td>
          <StatusPill tone={messageStatusTone(String(row.status))}>
            {String(row.failure_code ?? humanise(String(row.status)))}
          </StatusPill>
          {row.failure_detail !== null && row.failure_detail !== undefined && (
            <>
              <br />
              <span className="msg-muted msg-small">{String(row.failure_detail)}</span>
            </>
          )}
        </td>
        <td>
          <div className="msg-actions">
            <Button small tone="ghost" onClick={() => setOpen(true)}>
              <Search size={12} aria-hidden />
              Events
            </Button>
            {/* Reconcile ASKS the provider. There is deliberately no resend
                button here: resending an ambiguous submission is how a
                customer gets two payment reminders. */}
            {isUnknown && (
              <Button
                small
                pending={reconcile.pending}
                onClick={async () => {
                  const result = await reconcile.run()
                  if (result !== null) {
                    onAnnounce(result.data.detail)
                    onChanged()
                  }
                }}
              >
                Ask the provider
              </Button>
            )}
          </div>
          {reconcile.error && (
            <span className="msg-small" style={{ color: 'var(--danger)' }}>
              {reconcile.error.message}
            </span>
          )}
        </td>
      </tr>

      {open && (
        <tr>
          <td colSpan={5} style={{ background: 'var(--surface-2)' }}>
            <DeliveryEvents messageUuid={messageUuid} timezone={timezone} onClose={() => setOpen(false)} />
          </td>
        </tr>
      )}
    </>
  )
}

function DeliveryEvents({
  messageUuid,
  timezone,
  onClose,
}: {
  messageUuid: string
  timezone: string
  onClose: () => void
}) {
  const { data, loading, error, reload } = useApi(
    (signal) =>
      api
        .get<{
          data: {
            message: Message
            events: Array<{
              provider_event_id: string
              event_type: string
              error_code: string | null
              error_detail: string | null
              occurred_at: string | null
              received_at: string
            }>
            job: Record<string, unknown> | null
            timing_note: string
            ordering_note: string
            can_reconcile: boolean
          }
        }>(`v1/delivery/messages/${messageUuid}`, undefined, signal)
        .then((r) => r.data),
    [messageUuid],
  )

  return (
    <div style={{ padding: '0.75rem' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <strong style={{ fontSize: '0.85rem' }}>Provider events</strong>
        <Button small tone="ghost" onClick={onClose} ariaLabel="Close events">
          ✕
        </Button>
      </div>

      <PanelState loading={loading} error={error} data={data} onRetry={reload} skeletonRows={2}>
        {(detail) => (
          <>
            {detail.events.length === 0 ? (
              <p className="msg-muted msg-small" style={{ margin: '0.5rem 0 0' }}>
                No provider event has arrived for this message. For a message the provider accepted, that is what
                “awaiting confirmation” means.
              </p>
            ) : (
              <div className="msg-timeline" style={{ marginTop: '0.6rem' }}>
                {detail.events.map((event) => (
                  <div key={event.provider_event_id} className="msg-timeline-row">
                    <span
                      className={
                        event.error_code !== null
                          ? 'msg-timeline-dot msg-timeline-dot-danger'
                          : 'msg-timeline-dot'
                      }
                      aria-hidden
                    />
                    <div className="msg-timeline-body">
                      <strong>{humanise(event.event_type)}</strong>
                      <small>
                        {/* Both timestamps. The gap between them is the
                            provider's own delay, not ours. */}
                        Provider said {event.occurred_at === null ? 'no time' : formatDateTime(event.occurred_at, timezone)}
                        {' · received '}
                        {formatDateTime(event.received_at, timezone)}
                        {event.error_detail !== null && ` · ${event.error_detail}`}
                      </small>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {detail.job !== null && (
              <dl className="msg-definition-list" style={{ marginTop: '0.75rem' }}>
                <dt>Dispatch attempts</dt>
                <dd>
                  {String(detail.job.attempts)} of {String(detail.job.max_attempts)}
                </dd>
                <dt>Idempotency key</dt>
                <dd style={{ wordBreak: 'break-all' }}>{String(detail.job.idempotency_key)}</dd>
                {detail.job.last_error_detail !== null && (
                  <>
                    <dt>Last error</dt>
                    <dd style={{ textAlign: 'left' }}>{String(detail.job.last_error_detail)}</dd>
                  </>
                )}
              </dl>
            )}

            <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
              {detail.timing_note} {detail.ordering_note}
            </p>
          </>
        )}
      </PanelState>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Consent & suppression
// ---------------------------------------------------------------------------

function ConsentTab() {
  const { can, timezone } = useMessaging()
  const [view, setView] = useState<'consent' | 'suppressions'>('consent')
  const [announcement, setAnnouncement] = useState<string | null>(null)
  const [recording, setRecording] = useState(false)

  const consents = useApi(
    (signal) => api.list<Record<string, unknown>>('v1/consents', { limit: 50 }, signal),
    [view],
    view === 'consent',
  )

  const suppressions = useApi(
    (signal) => api.list<Record<string, unknown>>('v1/suppressions', { limit: 50 }, signal),
    [view],
    view === 'suppressions',
  )

  const summary =
    (consents.data?.meta.summary as
      | { opted_in_addresses: number; suppressed_addresses: number; withdrawn_30d: number }
      | undefined) ?? null

  return (
    <>
      {summary !== null && (
        <div className="msg-metric-row" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' }}>
          <div className="msg-metric">
            <div className="msg-metric-icon" aria-hidden>
              <ShieldCheck size={19} />
            </div>
            <div className="msg-metric-body">
              <span className="msg-metric-label">Addresses with consent</span>
              <span className="msg-metric-value">{formatCount(summary.opted_in_addresses)}</span>
            </div>
          </div>
          <div className="msg-metric">
            <div className="msg-metric-icon msg-metric-icon-warning" aria-hidden>
              <Ban size={19} />
            </div>
            <div className="msg-metric-body">
              <span className="msg-metric-label">Suppressed</span>
              <span className="msg-metric-value">{formatCount(summary.suppressed_addresses)}</span>
            </div>
          </div>
          <div className="msg-metric">
            <div className="msg-metric-icon msg-metric-icon-info" aria-hidden>
              <AlertTriangle size={19} />
            </div>
            <div className="msg-metric-body">
              <span className="msg-metric-label">Withdrawn, last 30 days</span>
              <span className="msg-metric-value">{formatCount(summary.withdrawn_30d)}</span>
            </div>
          </div>
        </div>
      )}

      <div className="msg-page-actions" style={{ marginBottom: '1rem' }}>
        <div className="msg-chips" role="group" aria-label="View">
          <button
            type="button"
            className="msg-chip"
            aria-pressed={view === 'consent'}
            onClick={() => setView('consent')}
          >
            Consent records
          </button>
          <button
            type="button"
            className="msg-chip"
            aria-pressed={view === 'suppressions'}
            onClick={() => setView('suppressions')}
          >
            Suppression list
          </button>
        </div>
        {can('messaging.consent.manage') && (
          <Button small tone="primary" onClick={() => setRecording(true)}>
            <Plus size={13} aria-hidden />
            Record consent
          </Button>
        )}
      </div>

      {view === 'consent' ? (
        <Panel
          title="Consent records"
          subtitle="Per channel AND per purpose. A transactional grant does not cover marketing."
        >
          <PanelState
            loading={consents.loading}
            error={consents.error}
            data={consents.data}
            onRetry={consents.reload}
            skeletonRows={4}
            isEmpty={(response) => response.data.length === 0}
            emptyTitle="No consent recorded yet"
            emptyBody="Nothing can be sent to an address with no consent for the purpose. Record what you have, with evidence."
          >
            {(response) => (
              <>
                <div className="msg-table-wrap">
                  <table className="msg-table">
                    <caption className="msg-visually-hidden">Consent records</caption>
                    <thead>
                      <tr>
                        <th scope="col">Address</th>
                        <th scope="col">Channel</th>
                        <th scope="col">Purpose</th>
                        <th scope="col">State</th>
                        <th scope="col">Evidence</th>
                        <th scope="col">Recorded</th>
                      </tr>
                    </thead>
                    <tbody>
                      {response.data.map((row) => (
                        <tr key={String(row.consent_uuid)}>
                          <td>{String(row.address)}</td>
                          <td>{channelLabel(String(row.channel))}</td>
                          <td>{humanise(String(row.purpose))}</td>
                          <td>
                            <StatusPill
                              tone={
                                row.state === 'granted' ? 'success' : row.state === 'withdrawn' ? 'danger' : 'warning'
                              }
                            >
                              {humanise(String(row.state))}
                            </StatusPill>
                          </td>
                          <td>
                            {row.evidence_source !== '' ? humanise(String(row.evidence_source)) : '—'}
                            {row.evidence_detail !== '' && (
                              <>
                                <br />
                                <span className="msg-muted msg-small">{String(row.evidence_detail)}</span>
                              </>
                            )}
                          </td>
                          <td className="msg-nowrap">{formatDateTime(String(row.recorded_at), timezone)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
                  {String(consents.data?.meta.note ?? '')}
                </p>
              </>
            )}
          </PanelState>
        </Panel>
      ) : (
        <Panel
          title="Suppression list"
          subtitle="A suppression is never deleted. Lifting one keeps the row, with who did it and why."
        >
          <PanelState
            loading={suppressions.loading}
            error={suppressions.error}
            data={suppressions.data}
            onRetry={suppressions.reload}
            skeletonRows={4}
            isEmpty={(response) => response.data.length === 0}
            emptyTitle="Nothing is suppressed"
            emptyBody="Addresses appear here after an opt-out, a hard bounce, a spam complaint or a manual suppression."
          >
            {(response) => (
              <div className="msg-table-wrap">
                <table className="msg-table">
                  <caption className="msg-visually-hidden">Suppressed addresses</caption>
                  <thead>
                    <tr>
                      <th scope="col">Address</th>
                      <th scope="col">Channel</th>
                      <th scope="col">Reason</th>
                      <th scope="col">Since</th>
                      <th scope="col">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {response.data.map((row) => (
                      <SuppressionRow
                        key={String(row.suppression_uuid)}
                        row={row}
                        timezone={timezone}
                        canOverride={Boolean(suppressions.data?.meta.can_override)}
                        onChanged={suppressions.reload}
                        onAnnounce={setAnnouncement}
                      />
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </PanelState>
        </Panel>
      )}

      {recording && (
        <RecordConsentDrawer
          onClose={() => setRecording(false)}
          onRecorded={(detail) => {
            setRecording(false)
            consents.reload()
            setAnnouncement(detail)
          }}
        />
      )}

      <Announce message={announcement} />
    </>
  )
}

function SuppressionRow({
  row,
  timezone,
  canOverride,
  onChanged,
  onAnnounce,
}: {
  row: Record<string, unknown>
  timezone: string
  canOverride: boolean
  onChanged: () => void
  onAnnounce: (message: string) => void
}) {
  const [reason, setReason] = useState('')
  const [releasing, setReleasing] = useState(false)

  const release = useMutation(async () =>
    api.postRaw<{ data: { detail: string } }>(
      `v1/suppressions/${String(row.suppression_uuid)}/release`,
      { reason },
    ),
  )

  const isCustomerOptOut = String(row.reason) === 'customer_optout'
  const released = row.released_at !== null && row.released_at !== undefined

  return (
    <tr>
      <td>{String(row.address)}</td>
      <td>{channelLabel(String(row.channel))}</td>
      <td>
        <StatusPill tone={isCustomerOptOut ? 'danger' : 'warning'}>{humanise(String(row.reason))}</StatusPill>
        {row.detail !== '' && (
          <>
            <br />
            <span className="msg-muted msg-small">{String(row.detail)}</span>
          </>
        )}
      </td>
      <td className="msg-nowrap">{formatDateTime(String(row.created_at), timezone)}</td>
      <td>
        {released ? (
          <span className="msg-muted msg-small">
            Lifted {formatDateTime(String(row.released_at), timezone)}
            {row.release_reason !== null && ` — ${String(row.release_reason)}`}
          </span>
        ) : isCustomerOptOut ? (
          // The one case that cannot be overridden by anybody. The customer has
          // to opt back in, which is recorded as their decision.
          <span className="msg-muted msg-small">
            <Lock size={11} aria-hidden style={{ verticalAlign: '-1px' }} /> The customer opted out. Record a new
            consent grant with their request as evidence.
          </span>
        ) : canOverride ? (
          releasing ? (
            <div style={{ display: 'grid', gap: '0.35rem' }}>
              <label className="msg-visually-hidden" htmlFor={`release-${String(row.suppression_uuid)}`}>
                Why are you lifting this?
              </label>
              <input
                id={`release-${String(row.suppression_uuid)}`}
                className="msg-input"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Why? This is recorded against your name."
              />
              <div className="msg-actions">
                <Button
                  small
                  tone="danger"
                  disabled={reason.trim() === ''}
                  pending={release.pending}
                  onClick={async () => {
                    const result = await release.run()
                    if (result !== null) {
                      onAnnounce(result.data.detail)
                      onChanged()
                    }
                  }}
                >
                  Lift
                </Button>
                <Button small tone="ghost" onClick={() => setReleasing(false)}>
                  Cancel
                </Button>
              </div>
              {release.error && (
                <span className="msg-small" style={{ color: 'var(--danger)' }}>
                  {release.error.message}
                </span>
              )}
            </div>
          ) : (
            <Button small onClick={() => setReleasing(true)}>
              Lift
            </Button>
          )
        ) : (
          <span className="msg-muted msg-small">Needs the override permission</span>
        )}
      </td>
    </tr>
  )
}

function RecordConsentDrawer({
  onClose,
  onRecorded,
}: {
  onClose: () => void
  onRecorded: (detail: string) => void
}) {
  const [channel, setChannel] = useState('whatsapp')
  const [address, setAddress] = useState('')
  const [purpose, setPurpose] = useState('transactional')
  const [evidenceSource, setEvidenceSource] = useState('agent_recorded')
  const [evidenceDetail, setEvidenceDetail] = useState('')

  const record = useMutation(async () =>
    api.postRaw<{ data: { detail: string } }>('v1/consents', {
      channel,
      address,
      purpose,
      state: 'granted',
      evidence_source: evidenceSource,
      evidence_detail: evidenceDetail,
    }),
  )

  return (
    <Drawer
      title="Record consent"
      subtitle="Per channel and per purpose, with evidence."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            tone="primary"
            pending={record.pending}
            disabled={address.trim() === '' || evidenceDetail.trim() === ''}
            onClick={async () => {
              const result = await record.run()
              if (result !== null) onRecorded(result.data.detail)
            }}
          >
            Record
          </Button>
        </>
      }
    >
      {record.error && <Notice tone="danger">{record.error.message}</Notice>}

      <Field label="Channel" htmlFor="consent-channel" required>
        <select
          id="consent-channel"
          className="msg-select"
          value={channel}
          onChange={(event) => setChannel(event.target.value)}
        >
          <option value="whatsapp">WhatsApp Business</option>
          <option value="rcs">RCS</option>
          <option value="sms">SMS</option>
        </select>
      </Field>

      <Field
        label="Address"
        htmlFor="consent-address"
        hint="The number or handle they consented on. Formatting does not matter — it is normalised so an opt-out on one spelling cannot be bypassed by another."
        required
      >
        <input
          id="consent-address"
          className="msg-input"
          value={address}
          onChange={(event) => setAddress(event.target.value)}
          placeholder="+919876543210"
        />
      </Field>

      <Field
        label="Purpose"
        htmlFor="consent-purpose"
        hint="“Yes, text me about my order” is not “yes, message me about your sale”. Record what they actually agreed to."
        required
      >
        <select
          id="consent-purpose"
          className="msg-select"
          value={purpose}
          onChange={(event) => setPurpose(event.target.value)}
        >
          <option value="transactional">Transactional — order and invoice updates</option>
          <option value="service">Service — replies within a conversation</option>
          <option value="promotional">Promotional — marketing</option>
          <option value="all">All purposes</option>
        </select>
      </Field>

      <Field label="Where did this come from?" htmlFor="consent-source" required>
        <select
          id="consent-source"
          className="msg-select"
          value={evidenceSource}
          onChange={(event) => setEvidenceSource(event.target.value)}
        >
          <option value="customer_message">The customer said so in a message</option>
          <option value="web_form">A form on our website</option>
          <option value="checkout">Checkout</option>
          <option value="double_optin">Double opt-in</option>
          <option value="contract">A signed contract</option>
          <option value="agent_recorded">An agent recorded it</option>
          <option value="import">Imported from another system</option>
        </select>
      </Field>

      <Field
        label="Evidence"
        htmlFor="consent-evidence"
        hint="Required. A consent record with no provenance is worth nothing six months later in front of somebody asking where it came from."
        required
      >
        <textarea
          id="consent-evidence"
          className="msg-textarea"
          value={evidenceDetail}
          onChange={(event) => setEvidenceDetail(event.target.value)}
          placeholder="Ticked the WhatsApp updates box at checkout on 12 April 2026, order SO-1082."
        />
      </Field>

      <Notice tone="info" title="This takes effect immediately">
        Consent is re-read in the moment before every send, so this applies to the next message and to anything
        already queued.
      </Notice>
    </Drawer>
  )
}
