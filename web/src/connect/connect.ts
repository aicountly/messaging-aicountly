/**
 * AICOUNTLY Connect in Messaging — the widget's lifecycle.
 *
 *   startConnect()   after sign-in: load loader.js, then init() with the
 *                    person's ses_key callback, product and company scope
 *   updateConnect()  on every company / branch change
 *   closeConnect()   close the panel
 *   stopConnect()    on sign-out: destroy() — widget, element and session gone
 *
 * The contract is connect-aicountly docs/EMBED_SDK.md (v1); Sales is the
 * reference consumer this mirrors (Notes for the init() limit). A Connect that
 * is offline, blocked or slow leaves every Messaging page exactly as it was:
 * the status is 'unavailable' and there is simply no launcher.
 */

import { loadConnect } from './loader'
import type { ConnectApi, ConnectInitOptions, ConnectScopePatch, ConnectUpdate } from './types'

/** Messaging's id in Manage's app catalogue (AppCatalogService::APPS). */
export const CONNECT_PRODUCT = 'messaging'

/** 'off' before sign-in and after sign-out; 'unavailable' when Connect could not be loaded or mounted. */
export type ConnectStatus = 'off' | 'loading' | 'ready' | 'unavailable'

/** How long the widget's init() may take before Messaging treats Connect as unavailable. */
export const INIT_TIMEOUT_MS = 15_000

let status: ConnectStatus = 'off'
let api: ConnectApi | null = null
let generation = 0
/** What init() will be given — patches that arrive while loader.js loads are folded in. */
let options: ConnectInitOptions | null = null

export function getConnectStatus(): ConnectStatus {
  return status
}

async function start(mine: number, origin: string | null, initial: ConnectInitOptions, timeoutMs?: number): Promise<boolean> {
  if (!origin) {
    status = 'unavailable'
    return false
  }
  status = 'loading'

  const loaded = await loadConnect(origin, timeoutMs)
  if (mine !== generation) return false
  if (!loaded) {
    status = 'unavailable'
    return false
  }

  api = loaded
  let mounted = false
  let timer: ReturnType<typeof setTimeout> | undefined
  let timedOut = false
  try {
    const gaveUp = new Promise<false>((resolve) => {
      timer = setTimeout(() => {
        timedOut = true
        resolve(false)
      }, INIT_TIMEOUT_MS)
    })
    const initialising = Promise.resolve(loaded.init({ ...(options ?? initial) }))
    // An init() that lands after Messaging gave up would leave a widget nothing
    // holds a handle to (stopConnect() could not take it down): take it down.
    initialising.then(
      () => {
        if (!timedOut) return
        try {
          loaded.destroy()
        } catch {
          /* the widget's problem, never Messaging's */
        }
      },
      () => undefined,
    )
    mounted = (await Promise.race([initialising, gaveUp])) === true
  } catch {
    mounted = false
  } finally {
    if (timer !== undefined) clearTimeout(timer)
  }
  if (mine !== generation) return false
  status = mounted ? 'ready' : 'unavailable'
  if (!mounted) api = null
  return mounted
}

/**
 * Load Connect and mount the widget. Resolves true once it is up, false when it
 * could not be (or when stopConnect() came first). Never rejects.
 */
export function startConnect(origin: string | null, initial: ConnectInitOptions, timeoutMs?: number): Promise<boolean> {
  const mine = ++generation
  options = { ...initial }
  return start(mine, origin, initial, timeoutMs)
}

/** Pass a change (company scope, above all) to the widget. Only keys present change. */
export function updateConnect(patch: ConnectUpdate): void {
  if (options) options = { ...options, ...patch }
  if (!api || (status !== 'loading' && status !== 'ready')) return
  try {
    api.update(patch)
  } catch {
    /* the widget's problem, never Messaging's */
  }
}

/** Close the panel, if the widget is up. */
export function closeConnect(): void {
  if (status !== 'ready' || !api) return
  try {
    api.close()
  } catch {
    /* the widget's problem, never Messaging's */
  }
}

/** Unmount the widget and forget the session callback — sign-out. Safe to call at any time. */
export function stopConnect(): void {
  generation++
  const current = api
  api = null
  options = null
  status = 'off'
  try {
    current?.destroy()
  } catch {
    /* already gone */
  }
}

// ---------------------------------------------------------------------------
// Scope
// ---------------------------------------------------------------------------

function positiveId(value: unknown): number | null {
  const n = typeof value === 'string' && value.trim() !== '' ? Number(value) : value
  return typeof n === 'number' && Number.isInteger(n) && n > 0 ? n : null
}

/** Branch 0 is "all locations" in Messaging, and what Connect assumes when none is given. */
function branchId(value: unknown): number {
  const n = typeof value === 'string' && value.trim() !== '' ? Number(value) : value
  return typeof n === 'number' && Number.isInteger(n) && n >= 0 ? n : 0
}

/**
 * The company Messaging is working in, as Connect takes it: the session's
 * context — the company and branch this product's API checked with Manage —
 * or no company while there is none (signing in, switching, no access).
 * Messaging has no financial year, so `fyId` is always null; every key is
 * present, so an update never leaves a previous company's branch behind.
 */
export function connectScopeFrom(context: { cmp_id?: unknown; bo_id?: unknown } | null | undefined): Required<ConnectScopePatch> {
  const companyId = positiveId(context?.cmp_id)
  return { companyId, fyId: null, boId: companyId === null ? 0 : branchId(context?.bo_id) }
}
