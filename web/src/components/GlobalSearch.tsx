/**
 * Global search.
 *
 * Searches conversations, templates and journeys through the API — never a
 * local index, because there is no local copy of anything to index. Contacts
 * are searched through Aicountly Contacts and are labelled with their source,
 * so it is clear which results come from another product.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Search } from 'lucide-react'
import { api } from '../services/api'
import type { Conversation, Journey, Template } from '../services/types'
import { useMessaging } from '../context/MessagingContext'

interface Result {
  group: string
  label: string
  detail: string
  to: string
}

export function GlobalSearch({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const { can, feature } = useMessaging()
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<Result[]>([])
  const [searching, setSearching] = useState(false)
  const [selected, setSelected] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  // Debounced, so typing does not fire a request per keystroke.
  useEffect(() => {
    const term = query.trim()
    if (term.length < 2) {
      setResults([])
      return
    }

    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setSearching(true)

      const requests: Array<Promise<Result[]>> = []

      if (can('messaging.conversations.view')) {
        requests.push(
          api
            .list<Conversation>('v1/conversations', { q: term, limit: 5 }, controller.signal)
            .then((response) =>
              response.data.map((conversation) => ({
                group: 'Conversations',
                label: conversation.contact_name || conversation.customer_address,
                detail: `${conversation.channel} · ${conversation.status}`,
                to: `/inbox/${conversation.conversation_uuid}`,
              })),
            )
            .catch(() => []),
        )
      }

      if (can('messaging.templates.view')) {
        requests.push(
          api
            .list<Template>('v1/templates', { q: term, limit: 5 }, controller.signal)
            .then((response) =>
              response.data.map((template) => ({
                group: 'Templates',
                label: template.name,
                detail: `${template.channel} · ${template.sendable ? 'approved' : 'not approved'}`,
                to: `/journeys?tab=templates&template=${template.template_uuid}`,
              })),
            )
            .catch(() => []),
        )
      }

      if (can('messaging.journeys.view')) {
        requests.push(
          api
            .list<Journey>('v1/journeys', { limit: 20 }, controller.signal)
            .then((response) =>
              response.data
                .filter((journey) => journey.name.toLowerCase().includes(term.toLowerCase()))
                .slice(0, 5)
                .map((journey) => ({
                  group: 'Journeys',
                  label: journey.name,
                  detail: journey.runnable ? 'published' : journey.status,
                  to: `/journeys?journey=${journey.journey_uuid}`,
                })),
            )
            .catch(() => []),
        )
      }

      if (feature('contacts').enabled) {
        requests.push(
          api
            .get<{
              data: { contacts: Array<{ contact_uuid: string; name: string; mobile: string }>; state: string }
            }>('v1/contacts', { q: term, limit: 5 }, controller.signal)
            .then((response) =>
              (response.data.contacts ?? []).map((contact) => ({
                group: 'Contacts · Aicountly Contacts',
                label: contact.name || contact.mobile,
                detail: contact.mobile,
                to: `/contacts/${contact.contact_uuid}`,
              })),
            )
            .catch(() => []),
        )
      }

      Promise.all(requests)
        .then((groups) => {
          if (controller.signal.aborted) return
          setResults(groups.flat())
          setSelected(0)
        })
        .finally(() => {
          if (!controller.signal.aborted) setSearching(false)
        })
    }, 220)

    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [query, can, feature])

  const grouped = useMemo(() => {
    const out = new Map<string, Result[]>()
    for (const result of results) {
      const list = out.get(result.group) ?? []
      list.push(result)
      out.set(result.group, list)
    }
    return [...out.entries()]
  }, [results])

  const flat = grouped.flatMap(([, items]) => items)

  return (
    <div
      className="shell-search-overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Search"
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          onClose()
          return
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault()
          setSelected((index) => Math.min(index + 1, Math.max(0, flat.length - 1)))
          return
        }
        if (event.key === 'ArrowUp') {
          event.preventDefault()
          setSelected((index) => Math.max(0, index - 1))
          return
        }
        if (event.key === 'Enter' && flat[selected]) {
          navigate(flat[selected].to)
          onClose()
        }
      }}
    >
      {/* Clicking the backdrop closes. A button rather than a div so it is
          reachable by keyboard and announced. */}
      <button
        type="button"
        className="msg-visually-hidden"
        onClick={onClose}
        aria-label="Close search"
      />
      <div className="shell-search-dialog">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', padding: '0 1.15rem' }}>
          <Search size={16} aria-hidden style={{ color: 'var(--muted)', flex: 'none' }} />
          <label className="msg-visually-hidden" htmlFor="global-search-input">
            Search conversations, contacts and templates
          </label>
          <input
            ref={inputRef}
            id="global-search-input"
            className="shell-search-input"
            style={{ padding: '1rem 0', borderBottom: 0 }}
            placeholder="Search conversations, contacts, templates…"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            autoComplete="off"
          />
        </div>

        <div style={{ borderTop: '1px solid var(--border)' }} />

        <div className="shell-search-results" role="listbox" aria-label="Search results">
          {query.trim().length < 2 && (
            <p className="msg-muted msg-small" style={{ padding: '1rem 0.7rem', margin: 0 }}>
              Type at least two characters. Results come from this product and, for contacts, live from Aicountly
              Contacts.
            </p>
          )}

          {query.trim().length >= 2 && searching && results.length === 0 && (
            <p className="msg-muted msg-small" style={{ padding: '1rem 0.7rem', margin: 0 }}>
              Searching…
            </p>
          )}

          {query.trim().length >= 2 && !searching && results.length === 0 && (
            <p className="msg-muted msg-small" style={{ padding: '1rem 0.7rem', margin: 0 }}>
              Nothing matched “{query.trim()}”.
            </p>
          )}

          {grouped.map(([group, items]) => (
            <div key={group}>
              <div className="shell-search-group">{group}</div>
              {items.map((result) => {
                const index = flat.indexOf(result)
                return (
                  <button
                    key={`${result.group}-${result.to}`}
                    type="button"
                    className="shell-search-result"
                    role="option"
                    aria-selected={index === selected}
                    onClick={() => {
                      navigate(result.to)
                      onClose()
                    }}
                  >
                    {result.label}
                    <small>{result.detail}</small>
                  </button>
                )
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
