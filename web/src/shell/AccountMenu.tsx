/**
 * The account menu, behind the avatar in the top right.
 *
 * The layout is the suite-wide one — the same blocks in the same order in every
 * AICOUNTLY product, so the menu does not rearrange itself as people move
 * between them (see `AICOUNTLY_ACCOUNT_MENU.md` in the Saas Common Handler):
 *
 *   1. who you are      photo, name, e-mail, masked mobile, Cust ID
 *   2. your accounts    the others signed in on this browser, and "Use another"
 *   3. company          the shell's own CompanyPicker, two controls to the left
 *   4. appearance       Messaging has no in-menu theme switch
 *   5. links            Manage profile, Manage Account
 *   6. sign out
 *
 * The identity block takes the name and Cust ID from `v1/session`, which is
 * what this product knows about the signed-in person, and the masked mobile and
 * masked e-mail from the roster cookie. There is no photo: this API has no
 * authenticated user-logo route to fetch one from, and initials are what the
 * contract allows instead.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, LogOut, User, UserCog, UserPlus, Users } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { canAddAccount, buildChooseAccountUrl, performAccountSwitch, readAccountRoster } from '../auth/accounts'
import type { RosterAccount } from '../auth/accounts'
import { stopConnect } from '../connect/connect'
import { useMessaging } from '../context/MessagingContext'
import { getManageApiOrigin } from '../services/appLauncher'

/**
 * Manage's own pages for this environment (Manage is hash-routed). Plain
 * addresses — Manage signs the person in through the portal itself, so no token
 * is ever put in one.
 */
function manageUrl(path: string): string {
  return `${getManageApiOrigin()}/#${path}`
}

/**
 * First letters of up to two words — the avatar when there is no photo.
 *
 * Splits on `@`, `.`, `_` and `-` as well as whitespace, so an account with no
 * name on file still gets something out of its e-mail.
 */
export function initialsOf(source: string | null | undefined): string {
  return String(source ?? '')
    .split(/[\s@._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

function accountLabel(account: RosterAccount): string {
  return account.name || account.maskedEmail || `Account ${account.uuid}`
}

export function AccountMenu() {
  const { signOut } = useAuth()
  const { session } = useMessaging()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)

  // Read when the menu opens, not on every render: only a full page load can
  // change the cookie, because the portal is what writes it.
  const [roster, setRoster] = useState<RosterAccount[]>([])
  useEffect(() => {
    if (open) setRoster(readAccountRoster())
  }, [open])

  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: MouseEvent) => {
      if (rootRef.current && event.target instanceof Node && !rootRef.current.contains(event.target)) {
        setOpen(false)
      }
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const custId = session?.user.uuid ?? ''
  const name = session?.user.name ?? ''
  // The active account's own roster entry carries the masked mobile. Matched on
  // the subscriber id where both are known, so a stale cookie cannot label this
  // session with somebody else's number.
  const activeEntry = useMemo(
    () =>
      roster.find((account) => account.active && String(account.uuid) === String(custId)) ??
      roster.find((account) => account.active) ??
      null,
    [roster, custId],
  )
  const others = useMemo(() => roster.filter((account) => !account.active), [roster])
  const initials = initialsOf(name || activeEntry?.name || activeEntry?.maskedEmail)

  function switchTo(uuid?: number) {
    // Connect goes first: its widget and session callback belong to the account
    // being left, exactly as at sign-out.
    stopConnect()
    setOpen(false)
    performAccountSwitch(uuid)
  }

  return (
    <div className="shell-account" ref={rootRef}>
      <button
        ref={triggerRef}
        type="button"
        className="shell-account-trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        // Includes the name shown on the button (WCAG 2.5.3, label in name).
        aria-label={name ? `Account menu for ${name}` : 'Account menu'}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="shell-user" aria-hidden>
          {initials || <User size={15} />}
        </span>
        <ChevronDown size={13} aria-hidden />
      </button>

      {open && (
        <div className="shell-account-menu" role="menu" aria-label="Account menu">
          {name || activeEntry ? (
            <div className="shell-account-who">
              <span className="shell-user shell-user-lg" aria-hidden>
                {initials || <User size={18} />}
              </span>
              <div className="shell-account-who-text">
                <p className="shell-account-name">{name || activeEntry?.name || 'Signed in'}</p>
                {activeEntry?.maskedEmail && <p className="shell-account-meta">{activeEntry.maskedEmail}</p>}
                {activeEntry?.maskedMobile && (
                  <p className="shell-account-meta shell-account-numeric" title="Your registered mobile number">
                    {activeEntry.maskedMobile}
                  </p>
                )}
                {custId !== '' && (
                  <p className="shell-account-meta" title="Your AICOUNTLY customer ID">
                    Cust ID: <span className="shell-account-numeric">{custId}</span>
                  </p>
                )}
              </div>
            </div>
          ) : null}

          <div className="shell-account-section">
            <p className="shell-account-section-title">Accounts</p>

            {others.map((account) => (
              <button
                key={account.uuid}
                type="button"
                role="menuitem"
                className="shell-account-item"
                onClick={() => switchTo(account.uuid)}
              >
                <span className="shell-user shell-user-sm" aria-hidden>
                  {initialsOf(account.name || account.maskedEmail) || <User size={13} />}
                </span>
                <span className="shell-account-item-text">
                  <span className="shell-account-item-name">{accountLabel(account)}</span>
                  {account.maskedEmail && <span className="shell-account-item-meta">{account.maskedEmail}</span>}
                </span>
              </button>
            ))}

            {/* No readable roster — local development, or a browser signed in
                before the roster shipped. The portal chooser always knows. */}
            {roster.length === 0 && (
              <a role="menuitem" className="shell-account-item" href={buildChooseAccountUrl()}>
                <Users size={16} aria-hidden /> Switch account
              </a>
            )}

            {/* Hidden once this browser holds five — the portal refuses a sixth. */}
            {canAddAccount(roster) && (
              <button type="button" role="menuitem" className="shell-account-item" onClick={() => switchTo(undefined)}>
                <UserPlus size={16} aria-hidden /> Use another account
              </button>
            )}
          </div>

          <div className="shell-account-section">
            <a
              role="menuitem"
              className="shell-account-item"
              href={manageUrl('/profile')}
              target="_blank"
              rel="noopener noreferrer"
              title="Opens your profile in Manage in a new tab"
              onClick={() => setOpen(false)}
            >
              <UserCog size={16} aria-hidden />
              <span className="shell-account-item-text">Manage profile</span>
              <span className="msg-visually-hidden"> (opens in a new tab)</span>
            </a>
            <a
              role="menuitem"
              className="shell-account-item"
              href={manageUrl('/account')}
              target="_blank"
              rel="noopener noreferrer"
              title="Opens your account settings in Manage in a new tab"
              onClick={() => setOpen(false)}
            >
              <Users size={16} aria-hidden />
              <span className="shell-account-item-text">Manage Account</span>
              <span className="msg-visually-hidden"> (opens in a new tab)</span>
            </a>
          </div>

          {/* "Sign out", not "Log out": one wording across the suite, and it
              ends every account signed in on this browser, as the portal does. */}
          <div className="shell-account-section">
            <button
              type="button"
              role="menuitem"
              className="shell-account-item shell-account-item-danger"
              onClick={signOut}
            >
              <LogOut size={16} aria-hidden /> Sign out
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
