import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { RepairDetailPage } from './RepairDetailPage';
import { RepairListPage } from './RepairListPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the operations work stream: add detail and form
 * routes here, under the `/maintenance/repairs` prefix, each wrapped in `RequirePermission`.
 */
export const repairRoutes: RouteObject[] = [
  {
    path: '/maintenance/repairs',
    element: (
      <RequirePermission permission="maintenance.read">
        <RepairListPage />
      </RequirePermission>
    ),
  },
  {
    path: '/maintenance/repairs/:repair',
    element: (
      <RequirePermission permission="maintenance.read">
        <RepairDetailPage />
      </RequirePermission>
    ),
  },
];
