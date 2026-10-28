/**
 * The AICOUNTLY Connect embed contract (v1), as Messaging uses it.
 *
 * The source of truth is connect-aicountly's docs/EMBED_SDK.md and
 * web/public/embed/v1/loader.js. Only what Messaging calls is typed here.
 */

export type ConnectEventHandler = (name: string, detail: unknown) => void

/**
 * Company scope: the company and branch Messaging is working in. Messaging has
 * no financial year, so `fyId` is always null.
 */
export interface ConnectScopePatch {
  companyId?: number | null
  fyId?: number | null
  boId?: number | null
}

/** Opens the pinned AI Pulse thread, optionally with a question (sent only with `send: true`). */
export interface ConnectPulseRequest {
  prompt?: string
  send?: boolean
}

export interface ConnectOpenRequest {
  conversationId?: string
  pulse?: ConnectPulseRequest
}

export interface ConnectInitOptions extends ConnectScopePatch {
  /** The host's ses_key: cached normally, re-minted when `forceRefresh`, null when signed out. */
  getSesKey: (forceRefresh: boolean) => Promise<string | null>
  apiOrigin?: string
  product?: string
  position?: 'bottom-right' | 'bottom-left'
  zIndex?: number
  advisorUrl?: string
  labels?: { button?: string; openAria?: string; closeAria?: string; advisor?: string }
  calling?: boolean
  launcher?: boolean
  pulse?: { disclaimer?: string; enabled?: boolean } | false | null
  onEvent?: ConnectEventHandler
}

export type ConnectUpdate = Partial<Omit<ConnectInitOptions, 'apiOrigin' | 'calling'>>

/** `window.AICountlyChat` / `window.AICountlyConnect` — the same object. */
export interface ConnectApi {
  /** Resolves false when the widget could not load. Never rejects. */
  init(options: ConnectInitOptions): Promise<boolean>
  update(patch: ConnectUpdate): void
  open(request?: ConnectOpenRequest): void
  close(): void
  destroy(): void
  isReady(): boolean
  version?: string
}

/** Outbound events (dispatched on window and passed to `onEvent`). */
export const CONNECT_EVENTS = {
  READY: 'aicountly:connect:ready',
  OPENED: 'aicountly:connect:opened',
  CLOSED: 'aicountly:connect:closed',
  UNREAD: 'aicountly:connect:unread',
  AUTH_REQUIRED: 'aicountly:connect:auth-required',
  CONTEXT_OPEN: 'aicountly:connect:context-open',
  SHARE_RESULT: 'aicountly:connect:share-result',
} as const

/** The widget's host element (`<aicountly-connect>`), appended to <body>. */
export const CONNECT_HOST_ID = 'aicountly-chat-root'

declare global {
  interface Window {
    AICountlyChat?: ConnectApi
    AICountlyConnect?: ConnectApi
  }
}
