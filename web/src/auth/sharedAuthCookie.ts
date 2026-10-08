/**
 * RETIRED: the shared `.aicountly.com` `auth_token` cookie.
 *
 * It held the long-lived portal auth token where any script on any
 * *.aicountly.com page could read it (30 days, not HttpOnly), so it was retired
 * for security. Cross-product sign-in is the portal hand-off
 * (my.aicountly.com /login/authentication_jump/<product_key>, backed by the
 * portal's httpOnly cookie). This module is kept for one release only so callers
 * keep compiling and cookies already in browsers are purged; delete it next release.
 */

const AUTH_TOKEN_COOKIE = 'auth_token'

/**
 * The cookie was only ever written on the shared parent domain. Returning null
 * for anything else keeps it off unrelated origins (localhost included).
 */
function getSharedCookieDomain(): string | null {
  const host = window.location.hostname
  if (host === 'localhost' || host.endsWith('.localhost')) return null
  if (host.endsWith('.aicountly.com')) return '.aicountly.com'
  return null
}

/** Retired: the token is never read from a cookie any more. */
export function readSharedAuthToken(): string | null {
  return null
}

/** Retired: a JavaScript-readable cookie must never carry the token again. */
export function writeSharedAuthToken(_token: string): void {}

/** Expire `auth_token` on the shared parent domain (if any) and host-only. */
export function clearSharedAuthToken(): void {
  if (typeof document === 'undefined') return

  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  const expiry = `${AUTH_TOKEN_COOKIE}=; path=/; max-age=0; SameSite=Lax${secure}`
  const domain = getSharedCookieDomain()
  if (domain) document.cookie = `${expiry}; domain=${domain}`
  document.cookie = expiry
}

/** Remove a legacy `auth_token` cookie still sitting in this browser. Never throws. */
export function purgeLegacySharedAuthToken(): void {
  try {
    if (typeof document === 'undefined') return
    const prefix = `${AUTH_TOKEN_COOKIE}=`
    const present = document.cookie.split(';').some((part) => part.trim().startsWith(prefix))
    if (present) clearSharedAuthToken()
  } catch {
    /* cookies unavailable: nothing to purge */
  }
}
