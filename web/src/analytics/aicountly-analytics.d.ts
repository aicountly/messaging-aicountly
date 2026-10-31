/**
 * Types for the estate analytics browser SDK (aicountly-analytics.js).
 *
 * Copy it beside the SDK, unchanged. It exists so a TypeScript product can import the vendored
 * .js without turning on `allowJs` for its whole source tree — most of the estate builds with
 * `tsc -b && vite build` under `strict`, where an untyped .js import fails the build.
 */

/** Extra fields on an event. Counts and coarse shapes only — never content, never an identifier. */
export interface AnalyticsProperties {
  [key: string]: string | number | boolean | null | undefined
}

/** Fields the SDK sets itself, overridable at the call site where that makes sense. */
export interface AnalyticsExtra {
  page_path?: string
  duration_ms?: number
  outcome?: 'success' | 'failure' | string
}

export interface AnalyticsInitOptions {
  /** The product's catalog id, e.g. 'books'. Must be a registered source. */
  app: string
  /** Collect path. Absolute when the API is a different origin from the SPA. */
  endpoint?: string
  /** Defaults to on outside localhost. */
  enabled?: boolean
  /** Gate for surfaces that need consent (the public site). Signed-in product telemetry does not. */
  consent?: () => boolean
  /**
   * Request headers carrying the caller's identity, for a backend that authenticates by header
   * rather than cookie. Read at every flush, so a refreshed token is picked up. Return null when
   * there is no session and the batch is dropped rather than sent unattributable.
   */
  auth?: () => Record<string, string> | null
  /**
   * Extra fields for the POST envelope, beside `events` — claims the backend verifies, such as
   * the company the request is for. Read at every flush. `events` cannot be overridden here.
   */
  context?: () => Record<string, unknown> | null
}

export declare class AicountlyAnalytics {
  init(options: AnalyticsInitOptions): void
  /** Record a route change. Masks identifiers out of the path and times the previous route. */
  page(path?: string): void
  track(event: string, properties?: AnalyticsProperties, extra?: AnalyticsExtra): void
  error(code: string | number): void
  emptyState(where: string): void
  searched(resultCount: number): void
  exported(format: string): void
  /** Send what is queued. `isUnload` picks the transport that survives the page going away. */
  flush(isUnload?: boolean): void
}

/** True for a key that looks like it carries content or an identifier; such keys are dropped. */
export declare function isSensitiveKey(key: string): boolean

/** The masked form of a route: every identifier-looking segment becomes ':id'. */
export declare function maskPath(path: string): string

export declare const analytics: AicountlyAnalytics
export default analytics
