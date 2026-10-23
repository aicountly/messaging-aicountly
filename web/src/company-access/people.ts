/** How a person is named across the shared screens: name, then e-mail, and only then their id. */
export function personName(m: { name?: string | null; email?: string | null; uuid?: string | null }): string {
  const name = (m.name ?? '').trim();
  if (name) return name;
  const email = (m.email ?? '').trim();
  if (email) return email;
  return m.uuid ? `User #${m.uuid}` : 'Someone';
}

/** The e-mail to show under a person's name — only when the name is what is shown above it. */
export function personSecondary(m: { name?: string | null; email?: string | null }): string | null {
  const email = (m.email ?? '').trim();
  return (m.name ?? '').trim() && email ? email : null;
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
