/**
 * Who somebody is, on one row of an access screen.
 *
 * The shape is Books' team list (`modules/access/TeamMembersPage.jsx`): the same
 * four labelled fields for everyone — Name, Email, Mobile and the account id —
 * drawn the same way wherever this package lists people, so a company's access
 * table and a workspace's team list cannot drift apart.
 *
 * All four are always rendered, so every row has the same lines and the one being
 * read is the one it looks like. A field the registry has not been told reads
 * "Not Available" rather than disappearing or borrowing another field's value —
 * and is never emphasised, because a name nobody has filled in should not be the
 * loudest thing on the row.
 */

import { MEMBER_NOT_AVAILABLE, formatPersonDisplay, isPersonFieldMissing } from './people.ts';

export interface PersonLike {
  uuid?: string | null;
  name?: string | null;
  email?: string | null;
  mobile?: string | null;
}

export interface PersonIdentityProps {
  person: PersonLike;
  /** Appended to whichever field identifies them, e.g. " (you)". */
  suffix?: string;
}

export function PersonDetail({
  label,
  value,
  suffix = '',
  strong = false,
}: {
  label: string;
  value: string;
  suffix?: string;
  strong?: boolean;
}) {
  const missing = isPersonFieldMissing(value);
  const className = missing
    ? 'aic-ca-detail-missing'
    : strong ? 'aic-ca-detail-value aic-ca-detail-strong' : 'aic-ca-detail-value';

  return (
    <span className="aic-ca-detail">
      <span className="aic-ca-detail-label">{label}: </span>
      <span className={className}>
        {value}
        {suffix}
      </span>
    </span>
  );
}

export function PersonIdentity({ person, suffix = '' }: PersonIdentityProps) {
  const { uuid, name, email, mobile } = formatPersonDisplay(person);
  // The suffix belongs beside whatever actually identifies them. Hung off the name
  // it would read "Not Available (you)" on the one row with no name to show.
  const nameKnown = name !== MEMBER_NOT_AVAILABLE;

  return (
    <span className="aic-ca-identity">
      <PersonDetail label="Name" value={name} suffix={nameKnown ? suffix : ''} strong />
      <PersonDetail label="Email" value={email} />
      <PersonDetail label="Mobile" value={mobile} />
      <PersonDetail label="ID" value={uuid} suffix={nameKnown ? '' : suffix} />
    </span>
  );
}
