import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { AuditListPage } from './AuditListPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the operations work stream: add detail and form
 * routes here, under the `/audit` prefix, each wrapped in `RequirePermission`.
 */
export const auditRoutes: RouteObject[] = [
  {
    path: '/audit',
    element: (
      <RequirePermission permission="audit.read">
        <AuditListPage />
      </RequirePermission>
    ),
  },
];
