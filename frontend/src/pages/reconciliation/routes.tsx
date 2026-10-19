import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { ReconciliationPage } from './ReconciliationPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the operations work stream: add detail and form
 * routes here, under the `/maintenance/reconciliation` prefix, each wrapped in `RequirePermission`.
 */
export const reconciliationRoutes: RouteObject[] = [
  {
    path: '/maintenance/reconciliation',
    element: (
      <RequirePermission permission="maintenance.read">
        <ReconciliationPage />
      </RequirePermission>
    ),
  },
];
