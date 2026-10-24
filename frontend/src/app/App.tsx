import { useEffect } from 'react';
import { RouterProvider, createBrowserRouter } from 'react-router';
import { AppProviders } from '@/app/providers/AppProviders';
import { routes } from '@/app/router/routes';
import { setUnauthenticatedHandler } from '@/shared/api/client';

const router = createBrowserRouter(routes);

const PUBLIC_PATHS = new Set(['/', '/welcome', '/login', '/request-access', '/contact']);

export function App() {
  useEffect(() => {
    /*
     * A 401 from any request means the session ended — expired, revoked, or
     * signed out in another tab. Send the person to sign in rather than
     * leaving a shell full of failing requests.
     */
    setUnauthenticatedHandler(() => {
      /*
       * The public pages are where a signed-out person is meant to be: the home address shows them the marketing page
       * (see `RequireSession`), and sign-in, the request form and the welcome alias need no session. Sending them from
       * there to sign-in would make the home page unreachable.
       */
      if (!PUBLIC_PATHS.has(window.location.pathname)) {
        void router.navigate('/login', { replace: true });
      }
    });
  }, []);

  return (
    <AppProviders>
      <RouterProvider router={router} />
    </AppProviders>
  );
}
