/**
 * Loading AICOUNTLY Connect's embed loader — once, asynchronously, and never at
 * Messaging's expense.
 *
 * `${connectOrigin}/embed/v1/loader.js` defines `window.AICountlyChat` (alias
 * `AICountlyConnect`); the widget itself is fetched by that loader, from its own
 * directory, and renders in a shadow root on its own element under <body>. What
 * this file guarantees on the Messaging side:
 *
 *  - ONE script tag per page, however many times it is asked for;
 *  - a hard timeout, so a Connect that hangs is a Connect that is not there;
 *  - every failure (offline, blocked, a 404, a loader that did not register)
 *    resolves `null` — nothing throws into Messaging, and at most one warning is
 *    written to the console, however often it is asked afterwards.
 *
 * The contract is connect-aicountly docs/EMBED_SDK.md (v1).
 */

import { isSandboxHost } from '../auth/hostnames'
import { getAppById, resolveAppOrigin } from '../services/appLauncher'
import type { ConnectApi } from './types'

export const LOADER_PATH = '/embed/v1/loader.js'
export const LOAD_TIMEOUT_MS = 10_000

/** Values of VITE_CONNECT_ORIGIN that switch the embed off for a build. */
const DISABLED = new Set(['off', 'none', 'false', '0', 'disabled'])

/** An http(s) origin from an origin or URL, or null — never a javascript: or data: source. */
export function originOf(value: unknown): string | null {
  if (typeof value !== 'string' || !value.trim()) return null
  try {
    const url = new URL(value.trim())
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.origin : null
  } catch {
    return null
  }
}

/**
 * Where Connect is served from, like every other sibling product: the launcher
 * catalog's Connect entry for this host (production → https://connect.aicountly.com,
 * the *.gh sandbox and localhost → https://connect.gh.aicountly.com), overridable
 * with VITE_CONNECT_ORIGIN at build time. `VITE_CONNECT_ORIGIN=off` loads nothing.
 */
export function resolveConnectOrigin(
  hostname: string = typeof window === 'undefined' ? '' : window.location.hostname,
  configured: string | undefined = import.meta.env.VITE_CONNECT_ORIGIN,
): string | null {
  const raw = (configured ?? '').trim()
  if (DISABLED.has(raw.toLowerCase())) return null

  const explicit = originOf(raw)
  if (explicit) return explicit

  const app = getAppById('chat')
  return app ? resolveAppOrigin(app, isSandboxHost(hostname)) : null
}

/** The loader's API, once it has registered itself. */
export function readConnectGlobal(win: Window | undefined = typeof window === 'undefined' ? undefined : window): ConnectApi | null {
  const candidate = win?.AICountlyConnect ?? win?.AICountlyChat
  return candidate && typeof candidate.init === 'function' && typeof candidate.open === 'function' ? candidate : null
}

let pending: Promise<ConnectApi | null> | null = null
let warned = false

function warnOnce(message: string): void {
  if (warned) return
  warned = true
  try {
    console.warn(`[Messaging] ${message}`)
  } catch {
    /* no console — nothing to say it on */
  }
}

/**
 * Load Connect's loader.js from `origin`, once per page.
 *
 * @returns the API, or null when Connect could not be loaded. Never rejects.
 */
export function loadConnect(origin: string | null, timeoutMs: number = LOAD_TIMEOUT_MS): Promise<ConnectApi | null> {
  if (pending) return pending

  pending = new Promise<ConnectApi | null>((resolve) => {
    const already = readConnectGlobal()
    if (already) {
      resolve(already)
      return
    }
    if (!origin || typeof document === 'undefined') {
      resolve(null)
      return
    }

    let settled = false
    let timer: ReturnType<typeof setTimeout> | undefined
    const finish = (api: ConnectApi | null, why: string) => {
      if (settled) return
      settled = true
      if (timer !== undefined) clearTimeout(timer)
      if (!api) warnOnce(why)
      resolve(api)
    }

    try {
      const script = document.createElement('script')
      script.src = `${origin}${LOADER_PATH}`
      script.async = true
      script.setAttribute('data-aicountly-connect', 'loader')
      script.onload = () => finish(readConnectGlobal(), 'Connect loaded but did not register itself; Messaging carries on without it.')
      script.onerror = () => finish(null, `Connect could not be loaded from ${origin}; Messaging carries on without it.`)
      timer = setTimeout(
        () => finish(null, `Connect did not load within ${Math.round(timeoutMs / 1000)}s; Messaging carries on without it.`),
        timeoutMs,
      )
      ;(document.head ?? document.documentElement).appendChild(script)
    } catch {
      finish(null, 'Connect could not be loaded; Messaging carries on without it.')
    }
  })

  return pending
}

/** Forget the load, for tests. A page loads Connect once; a failure stays a failure until reload. */
export function resetConnectLoader(): void {
  pending = null
  warned = false
}
