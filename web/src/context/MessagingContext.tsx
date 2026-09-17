/**
 * The session, the company scope, and what this caller may do.
 *
 * Every screen needs all three before it can render anything, so they are
 * fetched once here rather than by each page. The company scope is registered
 * with the API client, which then puts it on every scoped call — so no page
 * has to remember, and a page that forgot would get a 400 rather than another
 * company's data.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { ApiError, api, setScope } from '../services/api'
import type { Company, SessionResponse } from '../services/types'

const COMPANY_STORAGE_KEY = 'messaging:cmp_id'
const BRANCH_STORAGE_KEY = 'messaging:bo_id'

interface MessagingContextValue {
  session: SessionResponse | null
  companies: Company[]
  companiesError: string | null
  cmpId: number
  boId: number
  loading: boolean
  error: ApiError | Error | null
  selectCompany: (cmpId: number, boId?: number) => void
  reload: () => void
  /** Whether this caller holds a Messaging permission. */
  can: (permission: string) => boolean
  /** Whether this deployment has an integration at all, and why not. */
  feature: (name: string) => { enabled: boolean; reason: string | null }
  timezone: string
  currency: string
}

const MessagingContext = createContext<MessagingContextValue | null>(null)

export function MessagingProvider({ children }: { children: ReactNode }) {
  const [companies, setCompanies] = useState<Company[]>([])
  const [companiesError, setCompaniesError] = useState<string | null>(null)
  const [session, setSession] = useState<SessionResponse | null>(null)
  const [cmpId, setCmpId] = useState<number>(() => readStored(COMPANY_STORAGE_KEY))
  const [boId, setBoId] = useState<number>(() => readStored(BRANCH_STORAGE_KEY))
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<ApiError | Error | null>(null)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  // Registered BEFORE anything else fetches, so the first scoped call already
  // carries it.
  useEffect(() => {
    setScope(cmpId > 0 ? { cmp_id: cmpId, bo_id: boId } : null)
  }, [cmpId, boId])

  // Step one: which companies may this user open? Manage owns that list and it
  // is read live — a remembered company the user has since lost access to must
  // not be openable.
  useEffect(() => {
    let cancelled = false
    const controller = new AbortController()

    api
      .unscoped<{ data: Company[] }>('v1/manage/companies', undefined, controller.signal)
      .then((response) => {
        if (cancelled) return

        const list = Array.isArray(response.data) ? response.data : []
        setCompanies(list)
        setCompaniesError(null)

        const ids = list.map(companyId).filter((id) => id > 0)
        if (ids.length === 0) {
          setLoading(false)
          return
        }

        // A stored company the user can still open, or the first they can.
        setCmpId((current) => (current > 0 && ids.includes(current) ? current : ids[0]))
      })
      .catch((err: Error) => {
        if (cancelled || controller.signal.aborted) return
        setCompaniesError(err.message)
        setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [token])

  // Step two: the session for the chosen company.
  useEffect(() => {
    if (cmpId <= 0) return

    let cancelled = false
    const controller = new AbortController()

    setLoading(true)
    setError(null)

    api
      .unscoped<{ data: SessionResponse }>(
        'v1/session',
        { cmp_id: cmpId, bo_id: boId },
        controller.signal,
      )
      .then((response) => {
        if (cancelled) return
        setSession(response.data)
      })
      .catch((err: Error) => {
        if (cancelled || controller.signal.aborted) return
        setError(err)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [cmpId, boId, token])

  const selectCompany = useCallback((nextCmpId: number, nextBoId = 0) => {
    setCmpId(nextCmpId)
    setBoId(nextBoId)
    writeStored(COMPANY_STORAGE_KEY, nextCmpId)
    writeStored(BRANCH_STORAGE_KEY, nextBoId)
    // The session is refetched by the effect above; the old one is dropped so
    // no screen renders one company's permissions against another's data.
    setSession(null)
  }, [])

  const can = useCallback(
    (permission: string): boolean => {
      if (session === null) return false
      if (session.user.is_owner) return true
      return session.permissions.includes(permission)
    },
    [session],
  )

  const feature = useCallback(
    (name: string) => session?.features[name.toLowerCase()] ?? { enabled: false, reason: null },
    [session],
  )

  const value = useMemo<MessagingContextValue>(
    () => ({
      session,
      companies,
      companiesError,
      cmpId,
      boId,
      loading,
      error,
      selectCompany,
      reload,
      can,
      feature,
      timezone: session?.settings.timezone ?? 'Asia/Kolkata',
      currency: session?.settings.currency ?? 'INR',
    }),
    [session, companies, companiesError, cmpId, boId, loading, error, selectCompany, reload, can, feature],
  )

  return <MessagingContext.Provider value={value}>{children}</MessagingContext.Provider>
}

export function useMessaging(): MessagingContextValue {
  const context = useContext(MessagingContext)
  if (context === null) throw new Error('useMessaging must be used inside <MessagingProvider>')
  return context
}

export function companyId(company: Company): number {
  return Number(company.cmp_id ?? company.comp_id ?? company.id ?? 0)
}

export function companyName(company: Company): string {
  return String(company.cmp_name ?? company.comp_name ?? company.name ?? `Company ${companyId(company)}`)
}

/**
 * The chosen company, remembered across page loads.
 *
 * This is a UI PREFERENCE — an integer somebody picked from a list Manage
 * gave them — and nothing more. It is not another product's business data,
 * which is why it may live here when a contact or an invoice may not. It is
 * also never trusted: the backend checks every request's company against what
 * Manage says this session can open, so a tampered value is a 403 rather than
 * a disclosure.
 */
function readStored(key: string): number {
  try {
    const raw = window.localStorage.getItem(key)
    const value = raw === null ? 0 : Number.parseInt(raw, 10)
    return Number.isFinite(value) && value > 0 ? value : 0
  } catch {
    return 0
  }
}

function writeStored(key: string, value: number): void {
  try {
    if (value > 0) window.localStorage.setItem(key, String(value))
    else window.localStorage.removeItem(key)
  } catch {
    /* private mode, or quota. The app works without it. */
  }
}
