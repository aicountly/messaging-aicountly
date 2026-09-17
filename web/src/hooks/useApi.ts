/**
 * Fetch-on-mount with loading, error and reload, cancelled cleanly on unmount.
 *
 * The abort matters here more than usual: an agent clicking through
 * conversations faster than the network answers would otherwise get the FIRST
 * response painted last, and the composer would be sitting under somebody
 * else's thread. Sending a reply to the wrong customer is the failure this
 * prevents.
 */

import { useCallback, useEffect, useState } from 'react'
import { ApiError } from '../services/api'

export interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: ApiError | Error | null
  reload: () => void
}

export function useApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  enabled = true,
): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<ApiError | Error | null>(null)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    let cancelled = false

    setLoading(true)
    setError(null)

    fetcher(controller.signal)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch((err: Error) => {
        // An abort is this component going away, not a failure to report.
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, token, enabled])

  return { data, loading, error, reload }
}

/**
 * The same thing, refreshed on a timer.
 *
 * For the Command Centre and the inbox list, which sit open on a screen all
 * day. Polling PAUSES while the tab is hidden — an inbox left open overnight
 * should not spend the night asking — and refreshes immediately on return, so
 * somebody coming back to their desk sees current figures rather than
 * yesterday's.
 *
 * The interval is a caller's decision rather than a constant in here, because
 * the right cadence for a delivery dashboard is not the right cadence for an
 * outcome report.
 */
export function usePolledApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  intervalSeconds: number,
  enabled = true,
): AsyncState<T> & { paused: boolean } {
  const state = useApi(fetcher, deps, enabled)
  const [paused, setPaused] = useState(() => typeof document !== 'undefined' && document.hidden)

  useEffect(() => {
    const onVisibility = () => setPaused(document.hidden)
    document.addEventListener('visibilitychange', onVisibility)
    return () => document.removeEventListener('visibilitychange', onVisibility)
  }, [])

  const { reload } = state

  useEffect(() => {
    if (!enabled || paused || intervalSeconds <= 0) return

    const timer = window.setInterval(reload, intervalSeconds * 1000)
    return () => window.clearInterval(timer)
  }, [enabled, paused, intervalSeconds, reload])

  useEffect(() => {
    if (!paused && enabled) reload()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paused])

  return { ...state, paused }
}

/**
 * A mutation with its own pending and error state.
 *
 * Every write in this product can be refused for a reason the user needs to
 * read — no consent, a stale approval, a version conflict — so the error is
 * kept rather than thrown into a void, and `reset` clears it when they change
 * something and try again.
 */
export function useMutation<TArgs extends unknown[], TResult>(
  action: (...args: TArgs) => Promise<TResult>,
): {
  run: (...args: TArgs) => Promise<TResult | null>
  /**
   * The last successful result, retained.
   *
   * Needed by the actions whose ANSWER is the point rather than the side
   * effect: a journey proposal to review, a simulation's counts. Those are
   * mutations by HTTP verb — they are POSTs because they are expensive and
   * take a body — but nothing is written, and the caller has to render what
   * came back.
   */
  data: TResult | null
  pending: boolean
  error: ApiError | Error | null
  reset: () => void
} {
  const [pending, setPending] = useState(false)
  const [data, setData] = useState<TResult | null>(null)
  const [error, setError] = useState<ApiError | Error | null>(null)

  const run = useCallback(
    async (...args: TArgs): Promise<TResult | null> => {
      setPending(true)
      setError(null)
      try {
        const result = await action(...args)
        setData(result)
        return result
      } catch (err) {
        setError(err as Error)
        return null
      } finally {
        setPending(false)
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  )

  const reset = useCallback(() => {
    setError(null)
    setData(null)
  }, [])

  return { run, data, pending, error, reset }
}
