import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { DomainCreatePage } from './DomainCreatePage';
import { DomainDetailPage } from './DomainDetailPage';
import { DomainEditPage } from './DomainEditPage';
import { DomainListPage } from './DomainListPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the domains-admins-lists work stream.
 *
 * `/domains/new` does not collide with a domain mailadmin creates: those have
 * at least two labels (`DomainCreateRequest.name`). Only a legacy single-label
 * domain literally called `new` would be shadowed — accepted.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: '/domains',
    element: (
      <RequirePermission permission="domain.read">
        <DomainListPage />
      </RequirePermission>
    ),
  },
  {
    path: '/domains/new',
    element: (
      <RequirePermission permission="domain.write">
        <DomainCreatePage />
      </RequirePermission>
    ),
  },
  {
    path: '/domains/:domain',
    element: (
      <RequirePermission permission="domain.read">
        <DomainDetailPage />
      </RequirePermission>
    ),
  },
  {
    path: '/domains/:domain/edit',
    element: (
      <RequirePermission permission="domain.write">
        <DomainEditPage />
      </RequirePermission>
    ),
  },
];
