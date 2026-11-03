import { PersonNotFound } from '../PeopleStates';

// ST-5 for `/admin/users/[id]` (ux-copy-b3b.md C5-2): RULED texts, `Back to the list` → `/admin/users`.
export default function NotFoundPage() {
  return <PersonNotFound />;
}
