/**
 * The account menu, behind the avatar in the top right.
 *
 * The layout is the suite-wide one — the same blocks in the same order in every
 * AICOUNTLY product, so the menu does not rearrange itself as people move
 * between them (see `AICOUNTLY_ACCOUNT_MENU.md` in the Saas Common Handler):
 *
 *   1. who you are      photo, name, e-mail, masked mobile, Cust ID
 *   2. your accounts    the others signed in on this browser, and "Use another"
 *   3. company          Messaging is company-scoped: switch / edit / branches
 *   4. appearance       Messaging has no in-menu theme switch
 *   5. links            Manage profile, Manage Account
 *   6. sign out
 *
 * The identity block takes the name and Cust ID from `v1/session`, which is
 * what this product knows about the signed-in person, and the masked mobile and
 * masked e-mail from the roster cookie. The photo comes from the portal through
 * this product's own relay (`services/userLogo.ts` → `/api/global/user/logo`),
 * with initials as the fallback whenever there is none to be had.
 *
 * The Company block is here even though CompanyPicker sits two controls to the
 * left. A picker in the bar and an entry in the menu are not two sources of
 * truth — they are two doors onto the same action, and the menu is the one that
 * sits in the same place in every AICOUNTLY product. "Recent" is the one line
 * left out: Messaging remembers the company last opened, not an ordered list of
 * them, and the contract leaves out a line the product cannot perform rather
 * than inventing one.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { Building2, ChevronDown, GitBranch, LogOut, Pencil, User, UserCog, UserPlus, Users } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { canAddAccount, buildChooseAccountUrl, performAccountSwitch, readAccountRoster } from '../auth/accounts'
import type { RosterAccount } from '../auth/accounts'
import { stopConnect } from '../connect/connect'
import { useMessaging } from '../context/MessagingContext'
import { getManageApiOrigin } from '../services/appLauncher'
import { fetchUserLogoObjectUrl } from '../services/userLogo'
import { OPEN_COMPANY_SWITCHER_EVENT } from './CompanyDiscovery'

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

/**
 * The profile photo as an object URL, or null while there is none.
 *
 * Fetched once per mount and revoked on unmount, so a blob never outlives the menu that showed
 * it. It never throws and never reports a failure: the caller draws initials when this is null.
 */
function useUserLogo(): string | null {
  const [src, setSrc] = useState<string | null>(null)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    void fetchUserLogoObjectUrl().then((url) => {
      if (url === null) return
      if (cancelled) {
        // Unmounted while the photo was in flight: nothing will show it, so let it go.
        URL.revokeObjectURL(url)
        return
      }
      objectUrl = url
      setSrc(url)
    })

    return () => {
      cancelled = true
      if (objectUrl !== null) URL.revokeObjectURL(objectUrl)
      setSrc(null)
    }
  }, [])

  return src
}

export function AccountMenu() {
  const { signOut } = useAuth()
  const { session, cmpId } = useMessaging()
  const [open, setOpen] = useState(false)
  // Null until the photo arrives, and null for good when there is none: the menu draws initials.
  const photo = useUserLogo()
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
          {photo !== null
            ? <img src={photo} alt="" className="shell-user-photo" />
            : initials || <User size={15} />}
        </span>
        <ChevronDown size={13} aria-hidden />
      </button>

      {open && (
        <div className="shell-account-menu" role="menu" aria-label="Account menu">
          {name || activeEntry ? (
            <div className="shell-account-who">
              {/* 1. Who you are — with the photo, which this menu used to leave out. */}
              <span className="shell-user shell-user-lg" aria-hidden>
                {photo !== null
                  ? <img src={photo} alt="" className="shell-user-photo" />
                  : initials || <User size={18} />}
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

          {/* 3. Company — Messaging is company-scoped: every conversation hangs
              off cmp_id. Switching opens the workspace-companies drawer beside
              this menu, which is the one door that exists whether or not the
              picker renders its select (it hides it for a single company).
              Editing the company and its branches belong to Aicountly Manage,
              which is where Messaging sends people for both. */}
          <div className="shell-account-section">
            <p className="shell-account-section-title">Company</p>
            <button
              type="button"
              role="menuitem"
              className="shell-account-item"
              title="Choose another company"
              onClick={() => {
                setOpen(false)
                window.dispatchEvent(new CustomEvent(OPEN_COMPANY_SWITCHER_EVENT))
              }}
            >
              <Building2 size={16} aria-hidden /> Switch company
            </button>
            {cmpId > 0 && (
              <>
                <a
                  role="menuitem"
                  className="shell-account-item"
                  href={manageUrl(`/company/${encodeURIComponent(String(cmpId))}/edit`)}
                  target="_blank"
                  rel="noopener noreferrer"
                  title="Opens this company in Manage in a new tab"
                  onClick={() => setOpen(false)}
                >
                  <Pencil size={16} aria-hidden />
                  <span className="shell-account-item-text">Edit company</span>
                  <span className="msg-visually-hidden"> (opens in a new tab)</span>
                </a>
                <a
                  role="menuitem"
                  className="shell-account-item"
                  href={manageUrl(`/branch/${encodeURIComponent(String(cmpId))}`)}
                  target="_blank"
                  rel="noopener noreferrer"
                  title="Opens this company's branches in Manage in a new tab"
                  onClick={() => setOpen(false)}
                >
                  <GitBranch size={16} aria-hidden />
                  <span className="shell-account-item-text">Manage branches</span>
                  <span className="msg-visually-hidden"> (opens in a new tab)</span>
                </a>
              </>
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
