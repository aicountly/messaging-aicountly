/**
 * How a person is named in a SENTENCE: a toast, a confirmation, an aria-label.
 *
 * Still a fallback chain, and deliberately so — "Not Available was invited to your
 * workspace" is not a sentence anybody should read. For a LIST row, where each
 * field gets a line of its own, use {@link formatPersonDisplay} instead:
 * substituting one field for another is precisely what must not happen there.
 */
export function personName(m: { name?: string | null; email?: string | null; uuid?: string | null }): string {
  const name = (m.name ?? '').trim();
  if (name) return name;
  const email = (m.email ?? '').trim();
  if (email) return email;
  return m.uuid ? `User #${m.uuid}` : 'Someone';
}

/**
 * The e-mail to show under a person's name — only when the name is what is shown
 * above it.
 *
 * @deprecated v1.5.0. This is the rule that hid an address on exactly the rows
 * that needed it: with no name, the address was promoted to being the name and
 * then suppressed here, so the row showed it once and called it something it is
 * not. Draw rows with `<PersonIdentity>` instead, which labels all four fields.
 */
export function personSecondary(m: { name?: string | null; email?: string | null }): string | null {
  const email = (m.email ?? '').trim();
  return (m.name ?? '').trim() && email ? email : null;
}

/** What Books prints for a field nobody has filled in. Same words, deliberately. */
export const MEMBER_NOT_AVAILABLE = 'Not Available';

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : value == null ? '' : String(value).trim();
}

export function looksLikeEmail(value: unknown): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text(value));
}

/** Whether a formatted field is really a value, or the stand-in for one. */
export function isPersonFieldMissing(value: string): boolean {
  return value === '' || value === MEMBER_NOT_AVAILABLE;
}

export interface PersonDisplay {
  uuid: string;
  name: string;
  email: string;
  mobile: string;
}

/**
 * The four things that say who somebody is: name, e-mail, mobile and their id.
 *
 * Ported from Books' `utils/teamMemberDisplay.js`, field for field, so the
 * estate's access screens answer "who is this?" the same way. The rule it brings
 * with it, and the whole reason this is not a few `??` chains at the call site:
 *
 *   **A field that is missing says so.** It is never quietly replaced by another
 *   field, and it is never dropped from the row.
 *
 * Substituting is what left these screens showing "User #4243" where a name
 * belonged — which reads like an account called that, rather than like nobody has
 * told the registry a name.
 *
 * An address that arrived in the name column is read back onto the e-mail line,
 * as Books does: a share made by e-mail before the directory knew a name carries
 * the address as its `name`, and left there the row claims both that the address
 * is their name and that no address is known.
 */
export function formatPersonDisplay(
  m: { name?: string | null; email?: string | null; mobile?: string | null; uuid?: string | null },
): PersonDisplay {
  let name = text(m.name);
  let email = text(m.email);
  const mobile = text(m.mobile);

  if (looksLikeEmail(name) && isPersonFieldMissing(email)) {
    email = name;
    name = '';
  }

  return {
    uuid: text(m.uuid) || MEMBER_NOT_AVAILABLE,
    name: name || MEMBER_NOT_AVAILABLE,
    email: email || MEMBER_NOT_AVAILABLE,
    mobile: mobile || MEMBER_NOT_AVAILABLE,
  };
}

/**
 * A registry date for display, or '' — never 1970 (`new Date(null)`) or "Invalid Date" (`new
 * Date('')`, or a field an older registry leaves out).
 */
export function shortDate(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString();
}

/** As `shortDate`, with the time. */
export function shortDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString();
}
