import { useEffect } from 'react';
import { RouterProvider, createBrowserRouter } from 'react-router';
import { AppProviders } from '@/app/providers/AppProviders';
import { routes } from '@/app/router/routes';
import { setUnauthenticatedHandler } from '@/shared/api/client';

const router = createBrowserRouter(routes);

export function App() {
  useEffect(() => {
    /*
     * A 401 from any request means the session ended — expired, revoked, or
     * signed out in another tab. Send the person to sign in rather than
     * leaving a shell full of failing requests.
     */
    setUnauthenticatedHandler(() => {
      if (window.location.pathname !== '/login') {
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
