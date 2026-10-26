import { redirect } from 'next/navigation';

// IA §3: `/` redirects to /overview. B-3a builds no screens, so until B-3b the only page is the component preview.
export default function Home() {
  redirect(process.env.NODE_ENV === 'production' ? '/overview' : '/component-preview');
}
