import type { RouteObject } from 'react-router-dom';

import { RequireSession } from '../auth/RequireSession';
import { AppLayout } from '../layout/AppLayout';
import { accountRoutes } from '../pages/accounts/routes';
import { addressRoutes } from '../pages/addresses/routes';
import { adminRoutes } from '../pages/admins/routes';
import { auditRoutes } from '../pages/audit/routes';
import { domainRoutes } from '../pages/domains/routes';
import { healthRoutes } from '../pages/health/routes';
import { mailingListRoutes } from '../pages/lists/routes';
import { NotFoundPage } from '../pages/NotFoundPage';
import { OverviewPage } from '../pages/OverviewPage';
import { reconciliationRoutes } from '../pages/reconciliation/routes';
import { repairRoutes } from '../pages/repairs/routes';
import { SignInPage } from '../pages/SignInPage';

/**
 * The route table, as data (U-17: React Router v7 in data mode).
 *
 * Exported as `RouteObject[]` rather than as JSX so that the production entry
 * point builds a browser router from it and a test builds a memory router from
 * the same list. A test that declares its own routes is testing its own routes.
 *
 * Everything except `/login` sits behind `RequireSession`. The area views
 * bring their own route lists from `pages/<area>/routes.tsx` (U-130), so that
 * the work streams building them never edit this file — see
 * `pages/README.md`.
 */
export const routes: RouteObject[] = [
  {
    path: '/login',
    element: <SignInPage />,
  },
  {
    element: <RequireSession />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <OverviewPage /> },
          ...domainRoutes,
          ...adminRoutes,
          ...mailingListRoutes,
          ...accountRoutes,
          ...addressRoutes,
          ...repairRoutes,
          ...reconciliationRoutes,
          ...healthRoutes,
          ...auditRoutes,
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
];
