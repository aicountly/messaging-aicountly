/**
 * The signed-in person's profile photo, for the account menu.
 *
 * The photo lives on the portal (`GET my.aicountly.com/api/user/logo`, an image body behind the
 * person's ses_key). Browser code cannot ask for it directly — the portal's CORS allowlist does
 * not name product domains — so it comes through this product's own API, same-origin, over the
 * read-only leg of the `/api/global` relay (server-php/index.php, RELAYED_READ_PATHS).
 *
 * Every failure is "no photo": nobody has uploaded one, the relay is down, the portal answered
 * something that is not an image. The menu then draws initials, which is the contract's fallback
 * (AICOUNTLY_ACCOUNT_MENU.md §1, §6). Nothing here throws and nothing is reported to the person —
 * a missing avatar is not worth interrupting anyone for.
 *
 * No React here on purpose, so the test harness can load it on its own
 * (web/tests/user-logo.test.mjs). The hook that holds the object URL lives in the menu.
 */

import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'

/**
 * Fetch the photo and return an object URL for `<img src>`, or null when there is none.
 *
 * The caller owns the URL and must `URL.revokeObjectURL` it when the image goes away.
 */
export async function fetchUserLogoObjectUrl(): Promise<string | null> {
  try {
    const sesKey = await ensureSesKey()
    if (!sesKey) return null

    const response = await fetch(`${getApiBaseUrl()}/global/user/logo`, {
      headers: { Authorization: `Bearer ${sesKey}` },
    })
    // 204 / 404 is the portal saying there is no photo; anything else failing is an outage we
    // treat the same way, because the menu has initials either way.
    if (!response.ok) return null

    const contentType = response.headers.get('content-type') ?? ''
    if (!contentType.toLowerCase().startsWith('image/')) return null

    const blob = await response.blob()
    if (blob.size === 0) return null

    return URL.createObjectURL(blob)
  } catch {
    return null
  }
}
