/**
 * The contacts browser.
 *
 * A LIVE WINDOW onto Aicountly Contacts, not a copy of it. Every row is
 * fetched on the request that draws it, and when Contacts cannot be reached
 * this screen says so and shows nothing — it does not fall back to a stale
 * local list, because there is no local list and a stale address book is how a
 * business messages the wrong number.
 *
 * What Messaging adds beside each contact is the messaging-shaped part of
 * knowing somebody: how many conversations, when we last spoke, whether they
 * are suppressed. That part IS ours, and it survives Contacts being down.
 */

import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Ban, MessageSquare } from 'lucide-react'
import { useApi } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import { useMessaging } from '../context/MessagingContext'
import {
  EmptyState,
  Notice,
  Panel,
  PanelState,
  PendingIntegrationState,
  PermissionState,
  SourceBadge,
  StatusPill,
  formatCount,
  formatDateTime,
  timeAgo,
} from '../ui'
import { channelLabel, humanise } from './CommandCentre'

interface ContactRow {
  contact_uuid: string
  name: string
  mobile: string
  email: string
  language: string
  messaging: {
    conversations: number
    open_conversations: number
    last_heard_from: string | null
    last_contacted: string | null
    suppressed: { channel: string; reason: string } | null
  } | null
}

export function ContactsPage() {
  const { can, feature } = useMessaging()
  const [query, setQuery] = useUrlState('q', '')
  const [search, setSearch] = useState(query)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (search !== query) setQuery(search)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search, query, setQuery])

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api
        .get<{
          data: {
            contacts: ContactRow[]
            state: string
            message?: string
            source: string
            fetched_at?: string
            note?: string
          }
        }>('v1/contacts', { q: query, limit: 50 }, signal)
        .then((r) => r.data),
    [query],
    can('messaging.conversations.view'),
  )

  if (!can('messaging.conversations.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="contacts" />
      </div>
    )
  }

  const contactsFeature = feature('contacts')

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Contacts</h1>
          <p>Read live from Aicountly Contacts. Messaging keeps no address book of its own.</p>
        </div>
      </div>

      {!contactsFeature.enabled && (
        <Panel>
          <PendingIntegrationState product="Aicountly Contacts" remedy={contactsFeature.reason}>
            This screen is a live view of Aicountly Contacts. There is no local copy to show instead, which is
            deliberate — a second address book is how a business ends up with two spellings of the same mobile
            number and no way to say which is right.
          </PendingIntegrationState>
        </Panel>
      )}

      {contactsFeature.enabled && (
        <Panel
          title="Contacts"
          subtitle="Search Aicountly Contacts"
          action={data?.fetched_at ? <SourceBadge source="contacts" fetchedAt={data.fetched_at} /> : undefined}
        >
          <label className="msg-visually-hidden" htmlFor="contacts-search">
            Search contacts
          </label>
          <input
            id="contacts-search"
            className="msg-input"
            style={{ marginBottom: '1rem', maxWidth: '24rem' }}
            placeholder="Name, number or email"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />

          <PanelState
            loading={loading}
            error={error}
            data={data}
            onRetry={reload}
            skeletonRows={5}
            isEmpty={(response) => response.contacts.length === 0 && response.state === 'ready'}
            emptyTitle={query === '' ? 'Search for a contact' : `Nothing matched “${query}”`}
            emptyBody={
              query === ''
                ? 'Type a name, a number or an email. Results come from Aicountly Contacts.'
                : 'Create the contact in Aicountly Contacts, and it will appear here.'
            }
          >
            {(response) =>
              response.state !== 'ready' ? (
                <Notice tone="warning" title="Aicountly Contacts could not be read">
                  {response.message} Nothing has been substituted from a local copy, because there is not one.
                </Notice>
              ) : (
                <>
                  <div className="msg-table-wrap">
                    <table className="msg-table">
                      <caption className="msg-visually-hidden">Contacts, with their messaging history</caption>
                      <thead>
                        <tr>
                          <th scope="col">Name</th>
                          <th scope="col">Mobile</th>
                          <th scope="col">Conversations</th>
                          <th scope="col">Last heard from</th>
                          <th scope="col">Messaging</th>
                        </tr>
                      </thead>
                      <tbody>
                        {response.contacts.map((contact) => (
                          <tr key={contact.contact_uuid}>
                            <td>
                              <Link to={`/contacts/${contact.contact_uuid}`}>{contact.name || '(no name)'}</Link>
                            </td>
                            <td>{contact.mobile || <span className="msg-muted">—</span>}</td>
                            <td className="num">
                              {formatCount(contact.messaging?.conversations ?? 0)}
                              {(contact.messaging?.open_conversations ?? 0) > 0 && (
                                <>
                                  <br />
                                  <span className="msg-muted msg-small">
                                    {formatCount(contact.messaging?.open_conversations ?? 0)} open
                                  </span>
                                </>
                              )}
                            </td>
                            <td className="msg-nowrap">
                              {contact.messaging?.last_heard_from === null ||
                              contact.messaging?.last_heard_from === undefined ? (
                                <span className="msg-muted">never</span>
                              ) : (
                                timeAgo(contact.messaging.last_heard_from)
                              )}
                            </td>
                            <td>
                              {contact.messaging?.suppressed ? (
                                <StatusPill tone="danger" icon={Ban}>
                                  Suppressed on {channelLabel(contact.messaging.suppressed.channel)}
                                </StatusPill>
                              ) : (
                                <span className="msg-muted msg-small">—</span>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  <p className="msg-muted msg-small" style={{ margin: '0.75rem 0 0', lineHeight: 1.55 }}>
                    {response.note}
                  </p>
                </>
              )
            }
          </PanelState>
        </Panel>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------

export function ContactDetailPage() {
  const { contactUuid } = useParams<{ contactUuid: string }>()
  const { can, timezone } = useMessaging()

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api
        .get<{
          data: {
            contact: { contact_uuid: string; name: string; mobile: string; email: string; language: string } | null
            contact_state: string
            contact_message: string | null
            contact_source: string
            contact_fetched_at: string
            conversations: Array<Record<string, unknown>>
            messaging: ContactRow['messaging']
            consent: Array<Record<string, unknown>> | null
          }
        }>(`v1/contacts/${contactUuid}`, undefined, signal)
        .then((r) => r.data),
    [contactUuid],
    can('messaging.conversations.view'),
  )

  if (!can('messaging.conversations.view')) {
    return (
      <div className="msg-ui">
        <PermissionState what="contacts" />
      </div>
    )
  }

  return (
    <div className="msg-ui">
      <Link to="/contacts" className="msg-button msg-button-small msg-button-ghost" style={{ marginBottom: '1rem' }}>
        <ArrowLeft size={13} aria-hidden />
        All contacts
      </Link>

      <PanelState loading={loading} error={error} data={data} onRetry={reload} skeletonRows={4}>
        {(detail) => (
          <>
            <div className="msg-page-header">
              <div>
                <h1>{detail.contact?.name || detail.contact?.mobile || 'Contact'}</h1>
                <p>
                  {detail.contact_state === 'ready'
                    ? 'Identity read live from Aicountly Contacts.'
                    : 'Identity unavailable. The messaging history below is Messaging’s own.'}
                </p>
              </div>
              <SourceBadge source="contacts" fetchedAt={detail.contact_fetched_at} />
            </div>

            {/* Contacts being down does NOT take this screen down: the
                messaging history is ours and renders regardless. */}
            {detail.contact === null && (
              <Notice tone="warning" title="Aicountly Contacts could not be read">
                {detail.contact_message} Their conversations with you are shown below — those are Messaging's own
                records.
              </Notice>
            )}

            <div className="msg-grid msg-split">
              <Panel title="Conversations" subtitle="Messaging's own records">
                {detail.conversations.length === 0 ? (
                  <EmptyState title="No conversations" icon={MessageSquare}>
                    Nothing has been sent to or received from this contact.
                  </EmptyState>
                ) : (
                  <div className="msg-table-wrap">
                    <table className="msg-table">
                      <caption className="msg-visually-hidden">Conversations with this contact</caption>
                      <thead>
                        <tr>
                          <th scope="col">Channel</th>
                          <th scope="col">Address</th>
                          <th scope="col">Status</th>
                          <th scope="col">Last heard</th>
                        </tr>
                      </thead>
                      <tbody>
                        {detail.conversations.map((conversation) => (
                          <tr key={String(conversation.conversation_uuid)}>
                            <td>{channelLabel(String(conversation.channel))}</td>
                            <td>
                              <Link to={`/inbox/${String(conversation.conversation_uuid)}`}>
                                {String(conversation.customer_address)}
                              </Link>
                            </td>
                            <td>
                              <StatusPill tone={conversation.status === 'resolved' ? 'success' : 'neutral'}>
                                {humanise(String(conversation.status))}
                              </StatusPill>
                            </td>
                            <td className="msg-nowrap">
                              {conversation.last_inbound_at === null ? (
                                <span className="msg-muted">—</span>
                              ) : (
                                formatDateTime(String(conversation.last_inbound_at), timezone)
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Panel>

              <div className="msg-stack">
                {detail.contact !== null && (
                  <Panel title="Identity" subtitle="Aicountly Contacts" headingLevel={3}>
                    <dl className="msg-definition-list">
                      <dt>Mobile</dt>
                      <dd>{detail.contact.mobile || '—'}</dd>
                      <dt>Email</dt>
                      <dd className="msg-truncate">{detail.contact.email || '—'}</dd>
                      <dt>Prefers</dt>
                      <dd>{detail.contact.language ? detail.contact.language.toUpperCase() : '—'}</dd>
                    </dl>
                    <p className="msg-muted msg-small" style={{ margin: '0.6rem 0 0', lineHeight: 1.5 }}>
                      To change any of this, open the contact in Aicountly Contacts. Messaging does not edit contact
                      details.
                    </p>
                  </Panel>
                )}

                <Panel title="Messaging history" subtitle="Ours" headingLevel={3}>
                  <dl className="msg-definition-list">
                    <dt>Conversations</dt>
                    <dd>{formatCount(detail.messaging?.conversations ?? 0)}</dd>
                    <dt>Open now</dt>
                    <dd>{formatCount(detail.messaging?.open_conversations ?? 0)}</dd>
                    <dt>Last heard from</dt>
                    <dd>
                      {detail.messaging?.last_heard_from === null || detail.messaging?.last_heard_from === undefined
                        ? 'never'
                        : timeAgo(detail.messaging.last_heard_from)}
                    </dd>
                    <dt>Last contacted</dt>
                    <dd>
                      {detail.messaging?.last_contacted === null || detail.messaging?.last_contacted === undefined
                        ? 'never'
                        : timeAgo(detail.messaging.last_contacted)}
                    </dd>
                  </dl>

                  {detail.messaging?.suppressed && (
                    <Notice tone="danger" title="This contact is suppressed">
                      {humanise(detail.messaging.suppressed.reason)} on{' '}
                      {channelLabel(detail.messaging.suppressed.channel)}. Nothing will be sent to them on that
                      channel.
                    </Notice>
                  )}
                </Panel>

                {detail.consent !== null && (
                  <Panel title="Consent" subtitle="Per channel and purpose" headingLevel={3}>
                    {detail.consent.length === 0 ? (
                      <p className="msg-muted msg-small" style={{ margin: 0 }}>
                        No consent is recorded for this contact.
                      </p>
                    ) : (
                      <div className="msg-stack" style={{ gap: 0 }}>
                        {detail.consent.map((record) => (
                          <div key={String(record.consent_uuid)} className="msg-row msg-row-tight">
                            <div className="msg-row-body">
                              <strong>
                                {channelLabel(String(record.channel))} · {humanise(String(record.purpose))}
                              </strong>
                              <small>
                                {record.evidence_source !== ''
                                  ? humanise(String(record.evidence_source))
                                  : 'source not recorded'}
                                {' · '}
                                {formatDateTime(String(record.recorded_at), timezone)}
                              </small>
                            </div>
                            <StatusPill tone={record.state === 'granted' ? 'success' : 'danger'}>
                              {humanise(String(record.state))}
                            </StatusPill>
                          </div>
                        ))}
                      </div>
                    )}
                  </Panel>
                )}
              </div>
            </div>
          </>
        )}
      </PanelState>
    </div>
  )
}
