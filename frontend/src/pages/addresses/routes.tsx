import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { AddressListPage } from './AddressListPage';
import { AliasDetailPage } from './AliasDetailPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the accounts-addresses work stream.
 *
 * `/addresses?domain=<name>` is the address list of one domain, with its
 * catchall; `/addresses/aliases/<address>` is one alias, the address
 * URL-encoded. Forwards and vacation notices belong to an account and are
 * shown on `/accounts/<id>`.
 */
export const addressRoutes: RouteObject[] = [
  {
    path: '/addresses',
    element: (
      <RequirePermission permission="address.read">
        <AddressListPage />
      </RequirePermission>
    ),
  },
  {
    path: '/addresses/aliases/:alias',
    element: (
      <RequirePermission permission="address.read">
        <AliasDetailPage />
      </RequirePermission>
    ),
  },
];
