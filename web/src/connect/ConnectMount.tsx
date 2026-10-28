import { useEffect, useRef } from 'react'
import { sesKeyForEmbed } from '../auth/portal'
import { useMessaging } from '../context/MessagingContext'
import { buildAppJumpUrl } from '../services/appLauncher'
import { CONNECT_PRODUCT, closeConnect, connectScopeFrom, startConnect, stopConnect, updateConnect } from './connect'
import { resolveConnectOrigin } from './loader'
import { CONNECT_EVENTS, CONNECT_HOST_ID } from './types'

/**
 * Stacking level of the whole widget (launcher, panel, its dialogs).
 *
 * Above the sticky top bar (40), whose company and notification menus live in
 * its layer, so none of them cuts into the open panel; below Messaging's own
 * layers — the mobile navigation and its scrim (55–60), drawers (70–71), the
 * search dialog (80), and the toasts and app launcher (1000) — so a Messaging
 * dialog is never covered by the Connect launcher and a toast is never hidden
 * behind it.
 */
export const CONNECT_Z_INDEX = 50

/**
 * AICOUNTLY Connect, mounted for the signed-in person.
 *
 * Rendered by the app shell, which only exists while somebody is signed in —
 * so mounting here IS "after sign-in", and unmounting (sign-out, or the
 * session ending) destroys the widget. Sign-out also stops it first, before
 * the portal logout (AuthProvider). It draws nothing itself: the widget lives
 * in a shadow root on its own element under <body>, outside `.msg-ui`, where
 * Messaging's styles cannot reach it and it cannot reach them.
 *
 * The company it is given is the one the session says Messaging is working in
 * (checked with Manage by this product's API), with its branch; none while
 * there is none. Nothing here blocks or breaks Messaging: if Connect cannot be
 * loaded the status is 'unavailable', and that is all.
 */
export function ConnectMount() {
  const { session } = useMessaging()
  const scope = connectScopeFrom(session?.context)

  // The widget is started once; it must start with the scope of the render it
  // is started from, not the first one ever seen.
  const latest = useRef(scope)
  useEffect(() => {
    latest.current = scope
  })

  useEffect(() => {
    void startConnect(resolveConnectOrigin(), {
      getSesKey: sesKeyForEmbed,
      product: CONNECT_PRODUCT,
      ...latest.current,
      advisorUrl: buildAppJumpUrl('advisor') ?? undefined,
      position: 'bottom-right',
      zIndex: CONNECT_Z_INDEX,
    })

    return () => stopConnect()
  }, [])

  useEffect(() => {
    updateConnect({ companyId: scope.companyId, fyId: scope.fyId, boId: scope.boId })
  }, [scope.companyId, scope.fyId, scope.boId])

  // Going back to Messaging — pressing anywhere outside the widget — closes the
  // panel. It also keeps the top bar usable: its company and notification menus
  // live in the bar's own layer (40), below Connect's, and would otherwise open
  // underneath a panel left open.
  useEffect(() => closeConnectOnOutsidePress(), [])

  return null
}

/**
 * Close the Connect panel on a press outside it. Presses inside the widget reach
 * the document retargeted to its host element, so they are told apart by that.
 */
function closeConnectOnOutsidePress(): () => void {
  let open = false
  const onOpened = () => {
    open = true
  }
  const onClosed = () => {
    open = false
  }
  const onPointerDown = (event: Event) => {
    if (!open) return
    const host = document.getElementById(CONNECT_HOST_ID)
    if (host && event.target instanceof Node && host.contains(event.target)) return
    closeConnect()
  }
  window.addEventListener(CONNECT_EVENTS.OPENED, onOpened)
  window.addEventListener(CONNECT_EVENTS.CLOSED, onClosed)
  document.addEventListener('pointerdown', onPointerDown, true)
  return () => {
    window.removeEventListener(CONNECT_EVENTS.OPENED, onOpened)
    window.removeEventListener(CONNECT_EVENTS.CLOSED, onClosed)
    document.removeEventListener('pointerdown', onPointerDown, true)
  }
}
