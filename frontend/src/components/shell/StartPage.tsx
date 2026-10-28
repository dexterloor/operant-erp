'use client';

import { useEffect, useRef } from 'react';
import { START, pageTitle } from '@/copy/shell';
import type { PermissionCodes } from '@/lib/navigation';
import { documentHasArrived } from './DocumentArrival';
import styles from '@/components/states/PageStates.module.css';

/**
 * The `/` start page when no built page is open to this person (ruling Q-6; ux-copy-b3b.md C2-1). No action and no
 * link: the person still has the user menu and Sign out. The body is chosen by the served permission codes alone.
 * Focus: none on the document load (not announced, ST-2a); on a client navigation (e.g. ST-4b's "Go to your start
 * page") focus moves to the h1 after commit and the title updates.
 */
export function StartPage({ permissionCodes }: { permissionCodes: PermissionCodes }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const none = (permissionCodes instanceof Set ? permissionCodes.size : (permissionCodes as readonly string[]).length) === 0;
  useEffect(() => {
    if (!documentHasArrived()) return;
    document.title = pageTitle(START.title);
    h1.current?.focus();
  }, []);
  return (
    <section className={styles.full}>
      <h1 ref={h1} tabIndex={-1}>
        {START.heading}
      </h1>
      <p className={styles.body}>{none ? START.bodyNoAccess : START.bodyNotYet}</p>
    </section>
  );
}
