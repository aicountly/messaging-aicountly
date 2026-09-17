/**
 * A piece of state that lives in the query string.
 *
 * Filters, the selected conversation, the chosen period. In the URL so that a
 * deep link works, Back behaves, and an agent can send a colleague a link to
 * the exact conversation they are looking at — which is the whole reason the
 * inbox's selection is a route parameter rather than component state.
 */

import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'

export function useUrlState(
  key: string,
  fallback = '',
): [string, (next: string) => void] {
  const [params, setParams] = useSearchParams()
  const value = params.get(key) ?? fallback

  const set = useCallback(
    (next: string) => {
      setParams(
        (current) => {
          const updated = new URLSearchParams(current)
          if (next === '' || next === fallback) updated.delete(key)
          else updated.set(key, next)
          return updated
        },
        // replace, so changing a filter does not add a history entry per
        // keystroke and Back does not walk through every intermediate state.
        { replace: true },
      )
    },
    [key, fallback, setParams],
  )

  return [value, set]
}

/** Several filters at once, so one change is one navigation. */
export function useUrlFilters<T extends Record<string, string>>(
  defaults: T,
): [T, (next: Partial<T>) => void, () => void] {
  const [params, setParams] = useSearchParams()

  const values = { ...defaults }
  for (const key of Object.keys(defaults) as Array<keyof T>) {
    const fromUrl = params.get(String(key))
    if (fromUrl !== null) values[key] = fromUrl as T[keyof T]
  }

  const update = useCallback(
    (next: Partial<T>) => {
      setParams(
        (current) => {
          const updated = new URLSearchParams(current)
          for (const [key, value] of Object.entries(next)) {
            if (value === undefined || value === '' || value === defaults[key as keyof T]) {
              updated.delete(key)
            } else {
              updated.set(key, String(value))
            }
          }
          return updated
        },
        { replace: true },
      )
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [setParams],
  )

  const clear = useCallback(() => setParams(new URLSearchParams(), { replace: true }), [setParams])

  return [values, update, clear]
}
