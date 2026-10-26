/**
 * The reference a failed request is logged under on the server (X-Request-Id), and the sentence a
 * failure shows. Every API call sends a fresh id; on a 5xx the message ends " (Ref: <id>)" so a
 * person can quote it and support can find the log line (the find-ref workflow). No imports, so
 * the node tests load it as it is (tests/request-ref.test.mjs).
 */

/** A fresh random 32-hex id per request, sent as X-Request-Id: the server logs its failures under it. */
export function newRequestId(): string {
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

/**
 * The id the server logged this request under: the one it answered with (X-Request-Id header, or
 * request_id in the body), else the one this request sent — the server keeps a plain token as sent.
 */
export function requestIdOf(response: Response, parsed: unknown, sent: string): string {
  let fromHeader = ''
  try {
    fromHeader = String(response.headers?.get?.('X-Request-Id') ?? '').trim()
  } catch {
    fromHeader = ''
  }
  const body = parsed as { request_id?: unknown; error?: { request_id?: unknown } } | null
  const fromBody = typeof body?.request_id === 'string' ? body.request_id : typeof body?.error?.request_id === 'string' ? body.error.request_id : ''
  return fromHeader || fromBody.trim() || sent
}

/**
 * The sentence an error shows. A 5xx always says something (never a bare "Request failed (500)")
 * and quotes the reference, so support can find the failure in the server log.
 */
export function errorMessage(status: number, serverMessage: string | undefined, refId: string): string {
  let message =
    serverMessage ||
    (status >= 500
      ? 'Messaging hit an unexpected error and gave no details. Please retry; if it keeps failing, contact support with this reference.'
      : `Request failed (${status})`)
  if (status >= 500 && refId && !message.includes(refId)) {
    message = `${message} (Ref: ${refId})`
  }
  return message
}
