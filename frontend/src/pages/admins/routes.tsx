import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { AdminDetailPage } from './AdminDetailPage';
import { AdminListPage } from './AdminListPage';
import { OwnPasswordPage } from './OwnPasswordPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the domains-admins-lists work stream.
 *
 * One segment below `/admins/` is an administrator's name, and a legacy name
 * may be anything without a slash — so nothing else lives on that level.
 * Creating is a dialog on the list, and the own password sits two levels down,
 * where no name can reach (`/admins/me/password`, as in the contract). That
 * page is the one route here a domain master may open: it needs
 * `profile.password.write`, not `admin.read`.
 */
export const adminRoutes: RouteObject[] = [
  {
    path: '/admins',
    element: (
      <RequirePermission permission="admin.read">
        <AdminListPage />
      </RequirePermission>
    ),
  },
  {
    path: '/admins/me/password',
    element: (
      <RequirePermission permission="profile.password.write">
        <OwnPasswordPage />
      </RequirePermission>
    ),
  },
  {
    path: '/admins/:username',
    element: (
      <RequirePermission permission="admin.read">
        <AdminDetailPage />
      </RequirePermission>
    ),
  },
];
