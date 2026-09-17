/**
 * The application frame: brand, navigation, company context, search,
 * notifications and the product area everything else renders into.
 *
 * THE LOGO IS THE SHIPPED AICOUNTLY ASSET. `public/assets/aicountly-logo.png`
 * is the official mark, used as it is — not redrawn, not recoloured, not
 * replaced with initials or an emoji, because a trademark is not a
 * placeholder. Connect's `chat.png` is deliberately NOT used: Messaging is a
 * different product and reusing another product's icon would make them
 * indistinguishable in a taskbar. The product identity here is the wordmark
 * plus the "Messaging" label beneath it.
 */

import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { Bell, LogOut, Menu, Search, ShieldAlert } from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { useMessaging } from '../context/MessagingContext'
import { NAV } from './navConfig'
import { Button, EmptyState, ErrorState, LoadingRows, Notice } from '../ui'
import { api } from '../services/api'
import { CompanyPicker } from './CompanyPicker'
import { GlobalSearch } from '../components/GlobalSearch'
import { NotificationCentre } from '../components/NotificationCentre'
import { APP_ENV } from '../config'
import './app-shell.css'
import '../ui/messaging-ui.css'

function initials(name: string | null | undefined): string {
  if (!name) return '—'

  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('') || '—'
  )
}

/**
 * The screen for somebody who is signed in but has not been given anything.
 *
 * Without this, a user with no Messaging permissions sees a sidebar with two
 * links and a page of refusals, which reads as a broken app rather than as an
 * administrative step nobody has taken. It names who can fix it, because the
 * person reading it cannot.
 */
function NoAccess() {
  return (
    <div className="msg-ui">
      <div className="msg-card" style={{ maxWidth: 560, margin: '3rem auto' }}>
        <div className="msg-card-body" style={{ textAlign: 'center' }}>
          <ShieldAlert size={28} aria-hidden style={{ color: 'var(--warning)' }} />
          <h1 style={{ fontSize: '1.15rem', margin: '0.75rem 0 0.4rem' }}>
            You have no Messaging access yet
          </h1>
          <p style={{ color: 'var(--muted)', margin: 0, lineHeight: 1.6 }}>
            Your sign-in worked and you can open this company, but nobody has given you a Messaging permission
            profile. Ask the company owner to add you under <strong>Settings → Access</strong> in this app —
            Messaging permissions are set here, not in Manage.
          </p>
        </div>
      </div>
    </div>
  )
}

export function AppShell() {
  const { signOut } = useAuth()
  const { session, companies, companiesError, loading, error, can, reload } = useMessaging()
  const location = useLocation()

  const [navOpen, setNavOpen] = useState(false)
  const [searching, setSearching] = useState(false)
  const [notificationsOpen, setNotificationsOpen] = useState(false)

  // Navigating closes the drawer. Leaving it open over the page somebody just
  // asked for is the classic mobile-nav bug.
  useEffect(() => {
    setNavOpen(false)
    setNotificationsOpen(false)
  }, [location.pathname])

  // ⌘K / Ctrl-K opens search. The one shortcut worth having on a screen
  // somebody works at all day.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setSearching(true)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const lockedOut = session !== null && !session.user.is_owner && session.permissions.length === 0

  const items = NAV.filter((item) => {
    if (item.permission && session !== null && !can(item.permission)) return false
    return true
  })

  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      {navOpen && (
        <button
          type="button"
          className="shell-scrim"
          aria-label="Close navigation"
          onClick={() => setNavOpen(false)}
        />
      )}

      <aside className="shell-sidebar" data-open={navOpen}>
        <div className="shell-brand">
          <img src="/assets/aicountly-logo.png" alt="" width={34} height={34} aria-hidden />
          <div style={{ minWidth: 0 }}>
            <strong>Aicountly</strong>
            <small>Messaging</small>
          </div>
        </div>

        <nav className="shell-nav" aria-label="Messaging workspaces">
          {items.map((item) => (
            <div key={item.to}>
              {item.separator && <div className="shell-nav-separator" />}
              <NavLink
                to={item.to}
                end={item.exact}
                className={({ isActive }) => (isActive ? 'shell-nav-item active' : 'shell-nav-item')}
              >
                <item.icon size={16} aria-hidden />
                <span>{item.label}</span>
              </NavLink>
            </div>
          ))}
        </nav>

        <div className="shell-sidebar-foot">
          <div className="shell-promo">
            <strong>Smarter messaging</strong>
            <span>Stronger business</span>
          </div>
          <AppLauncher />
        </div>
      </aside>

      <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <header className="shell-topbar">
          <button
            type="button"
            className="shell-sidebar-toggle"
            aria-label="Open navigation"
            aria-expanded={navOpen}
            onClick={() => setNavOpen(true)}
          >
            <Menu size={18} aria-hidden />
          </button>

          <h1 className="shell-title">Aicountly Messaging</h1>

          <button type="button" className="shell-search" onClick={() => setSearching(true)}>
            <Search size={15} aria-hidden />
            <span>Search conversations, contacts, templates…</span>
            <kbd>⌘K</kbd>
          </button>

          <div className="shell-top-actions">
            {/* Only where it means something. A "production" badge on
                production is noise; a sandbox badge on sandbox prevents
                somebody messaging a real customer from a test environment. */}
            {APP_ENV !== 'production' && (
              <span className="shell-env" title={`This is the ${APP_ENV} environment`}>
                {APP_ENV === 'local' ? 'Local' : 'Sandbox'}
              </span>
            )}

            <CompanyPicker />

            <div style={{ position: 'relative' }}>
              <button
                type="button"
                className="shell-bell"
                aria-label="Notifications"
                aria-expanded={notificationsOpen}
                onClick={() => setNotificationsOpen((open) => !open)}
              >
                <Bell size={16} aria-hidden />
                <NotificationDot />
              </button>
              {notificationsOpen && <NotificationCentre onClose={() => setNotificationsOpen(false)} />}
            </div>

            <div className="shell-user" title={session?.user.name ?? ''}>
              <span aria-hidden>{initials(session?.user.name)}</span>
              <span className="msg-visually-hidden">{session?.user.name ?? 'Signed in'}</span>
            </div>

            <Button tone="ghost" small onClick={signOut} title="Log out" ariaLabel="Log out">
              <LogOut size={15} aria-hidden />
            </Button>
          </div>
        </header>

        <main style={{ flex: 1, minWidth: 0 }}>
          {companiesError !== null && companies.length === 0 ? (
            <div className="msg-ui">
              <Notice
                tone="danger"
                title="Your companies could not be loaded"
                action={
                  <Button small onClick={reload}>
                    Retry
                  </Button>
                }
              >
                {companiesError} Aicountly Manage owns the list of companies you can open, so Messaging cannot show
                anything until it answers. Nothing has been substituted.
              </Notice>
            </div>
          ) : error !== null ? (
            <div className="msg-ui">
              <ErrorState error={error} onRetry={reload} context="This company could not be opened" />
            </div>
          ) : loading && session === null ? (
            <div className="msg-ui">
              <div className="msg-metric-row" aria-busy="true" aria-label="Loading">
                {Array.from({ length: 4 }, (_, index) => (
                  <div key={index} className="msg-skeleton" style={{ height: 104 }} />
                ))}
              </div>
              <LoadingRows rows={2} height={220} />
            </div>
          ) : companies.length === 0 && session === null ? (
            <div className="msg-ui">
              <EmptyState title="No companies yet">
                Aicountly Manage has no company you can open. Create one in Manage, then come back.
              </EmptyState>
            </div>
          ) : lockedOut ? (
            <NoAccess />
          ) : (
            <Outlet />
          )}
        </main>
      </div>

      {searching && <GlobalSearch onClose={() => setSearching(false)} />}
    </div>
  )
}

/**
 * The dot on the bell.
 *
 * Driven by real counts — drafts awaiting approval, submissions that need
 * investigating — rather than being decorative. A notification dot that is
 * always on is a dot people stop seeing.
 */
function NotificationDot() {
  const { session, can } = useMessaging()

  if (session === null) return null
  if (!can('messaging.command_centre.view')) return null

  return <NotificationDotInner />
}

function NotificationDotInner() {
  const [hasSomething, setHasSomething] = useState(false)

  useEffect(() => {
    const controller = new AbortController()

    // The same counts the Command Centre shows, so the dot and the page cannot
    // disagree.
    api
      .get<{ data: { suggestions: Array<{ weight: number }> } }>(
        'v1/overview',
        { period: 'today' },
        controller.signal,
      )
      .then((response) => {
        if (controller.signal.aborted) return
        // Only a high-weight suggestion lights it: a delivery ambiguity or a
        // missed response target, not "8 conversations are waiting", which is
        // the normal state of an inbox.
        setHasSomething((response.data.suggestions ?? []).some((item) => item.weight >= 85))
      })
      .catch(() => {
        /* The dot is not worth an error. */
      })

    return () => controller.abort()
  }, [])

  return hasSomething ? <span className="shell-bell-dot" aria-hidden /> : null
}
