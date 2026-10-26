import type { RouteObject } from 'react-router-dom';

import { RequirePermission } from '../../auth/RequirePermission';

import { MailingListDetailPage } from './MailingListDetailPage';
import { MailingListListPage } from './MailingListListPage';

/**
 * The routes of this page directory, mounted under `AppLayout` by
 * `app/routes.tsx`. Owned by the domains-admins-lists work stream.
 *
 * The segment below `/lists/` is the list address, URL-encoded. Creating is a
 * dialog on the list page, and nothing else lives on that level.
 */
export const mailingListRoutes: RouteObject[] = [
  {
    path: '/lists',
    element: (
      <RequirePermission permission="list.read">
        <MailingListListPage />
      </RequirePermission>
    ),
  },
  {
    path: '/lists/:address',
    element: (
      <RequirePermission permission="list.read">
        <MailingListDetailPage />
      </RequirePermission>
    ),
  },
];
