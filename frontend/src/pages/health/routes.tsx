import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { HealthPage } from './HealthPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the operations work stream: add detail and form
 * routes here, under the `/maintenance/health` prefix, each wrapped in `RequirePermission`.
 */
export const healthRoutes: RouteObject[] = [
  {
    path: '/maintenance/health',
    element: (
      <RequirePermission permission="maintenance.read">
        <HealthPage />
      </RequirePermission>
    ),
  },
];
