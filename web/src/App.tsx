import { useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { MessagingProvider } from './context/MessagingContext'
import { AppShell } from './shell/AppShell'
import { CommandCentrePage } from './pages/CommandCentre'
import { UnifiedInboxPage } from './pages/UnifiedInbox'
import { JourneysPage } from './pages/Journeys'
import { BusinessOutcomesPage } from './pages/BusinessOutcomes'
import { ChannelsTrustPage } from './pages/ChannelsTrust'
import { ContactsPage, ContactDetailPage } from './pages/Contacts'
import { SettingsPage } from './pages/Settings'
import { JourneyRunPage } from './pages/JourneyRun'
import SignIn from './pages/SignIn'
import { initAnalytics, trackPageView } from './utils/analytics'
import './App.css'

initAnalytics()

/**
 * Routes.
 *
 * `/inbox/:conversationUuid` is a ROUTE rather than component state, for three
 * reasons that all matter in a busy inbox: a deep link to one conversation
 * works, Back returns to the list, and an agent can send a colleague a link to
 * the exact thread they are looking at. On a phone the list and the thread are
 * two screens rather than two columns, which falls out of the same routing.
 */
export default function App() {
  return (
    <BrowserRouter>
      <PageViews />
      <AuthenticatedApp />
    </BrowserRouter>
  )
}

function AuthenticatedApp() {
  const { status, message } = useAuth()

  if (status === 'signed-out') return <SignIn />

  if (status === 'loading') {
    return (
      <main className="screen">
        <div className="panel">
          <div className="panel-brand">
            <img src="/assets/aicountly-logo.png" alt="" width={40} height={40} aria-hidden />
            <div>
              <strong>AICOUNTLY</strong>
              <small>Messaging</small>
            </div>
          </div>
          <div className="spinner" aria-hidden />
          <p className="message" role="status" aria-live="polite">
            {message ?? 'Signing you in…'}
          </p>
        </div>
      </main>
    )
  }

  return (
    <MessagingProvider>
      <Routes>
        <Route element={<AppShell />}>
          <Route index element={<CommandCentrePage />} />

          <Route path="inbox" element={<UnifiedInboxPage />} />
          <Route path="inbox/:conversationUuid" element={<UnifiedInboxPage />} />

          <Route path="journeys" element={<JourneysPage />} />
          <Route path="journey-runs/:runUuid" element={<JourneyRunPage />} />

          <Route path="outcomes" element={<BusinessOutcomesPage />} />
          <Route path="trust" element={<ChannelsTrustPage />} />

          <Route path="contacts" element={<ContactsPage />} />
          <Route path="contacts/:contactUuid" element={<ContactDetailPage />} />

          <Route path="settings" element={<SettingsPage />} />

          {/* The portal lands here after sign-in; AuthProvider has already
              consumed the token by the time this renders. */}
          <Route path="auth/callback" element={<Navigate to="/" replace />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </MessagingProvider>
  )
}

/** GA4 page views, on every navigation rather than only the first. */
function PageViews() {
  const location = useLocation()

  useEffect(() => {
    trackPageView(location.pathname, document.title)
  }, [location.pathname])

  return null
}
