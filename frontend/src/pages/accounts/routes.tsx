import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { AccountCreatePage } from './AccountCreatePage';
import { AccountDetailPage } from './AccountDetailPage';
import { AccountListPage } from './AccountListPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the accounts-addresses work stream.
 *
 * `/accounts?domain=<name>` is the list of one domain — the parameter other
 * areas link with. `/accounts/new?domain=<name>` creates; `/accounts/<id>` is
 * one account, `<id>` being the URL-encoded stored Cyrus user name. `new` can
 * never be such a name: every stored name carries its domain.
 */
export const accountRoutes: RouteObject[] = [
  {
    path: '/accounts',
    element: (
      <RequirePermission permission="account.read">
        <AccountListPage />
      </RequirePermission>
    ),
  },
  {
    path: '/accounts/new',
    element: (
      <RequirePermission permission="account.write">
        <AccountCreatePage />
      </RequirePermission>
    ),
  },
  {
    path: '/accounts/:account',
    element: (
      <RequirePermission permission="account.read">
        <AccountDetailPage />
      </RequirePermission>
    ),
  },
];
