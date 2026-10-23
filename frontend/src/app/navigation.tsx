import AdminPanelSettingsIcon from '@mui/icons-material/AdminPanelSettings';
import AlternateEmailIcon from '@mui/icons-material/AlternateEmail';
import BuildIcon from '@mui/icons-material/Build';
import CompareArrowsIcon from '@mui/icons-material/CompareArrows';
import DashboardIcon from '@mui/icons-material/Dashboard';
import DnsIcon from '@mui/icons-material/Dns';
import GroupsIcon from '@mui/icons-material/Groups';
import HistoryIcon from '@mui/icons-material/History';
import InboxIcon from '@mui/icons-material/Inbox';
import MonitorHeartIcon from '@mui/icons-material/MonitorHeart';
import type { ReactNode } from 'react';

import type { TranslationKey } from '../i18n';

/**
 * The navigation drawer, as data (U-130).
 *
 * Every entry carries the permission its view needs — the same permission the
 * route checks with `RequirePermission` and, more to the point, the one the
 * backend checks on every request. Hiding an entry is courtesy, not
 * enforcement (architecture 11.2). With the role split of `RolePermissions`
 * a domain master sees the overview, domains, accounts, addresses and mailing
 * lists; the rest is the superuser's. The entry follows the permission, never
 * the role.
 *
 * The list is complete for the area views and owned by the foundation, not by
 * the work streams: a new top-level view is a change to the navigation, and
 * goes through the coordinator.
 */

export interface NavigationEntry {
  readonly to: string;
  readonly labelKey: TranslationKey;
  readonly icon: ReactNode;
  /** `undefined` means "everyone who is signed in". */
  readonly permission?: string;
}

export interface NavigationSection {
  /** `undefined` for the unlabelled first group. */
  readonly labelKey?: TranslationKey;
  readonly entries: readonly NavigationEntry[];
}

export const NAVIGATION: readonly NavigationSection[] = [
  {
    entries: [{ to: '/', labelKey: 'nav.overview', icon: <DashboardIcon /> }],
  },
  {
    labelKey: 'nav.section.management',
    entries: [
      { to: '/domains', labelKey: 'nav.domains', icon: <DnsIcon />, permission: 'domain.read' },
      { to: '/accounts', labelKey: 'nav.accounts', icon: <InboxIcon />, permission: 'account.read' },
      { to: '/addresses', labelKey: 'nav.addresses', icon: <AlternateEmailIcon />, permission: 'address.read' },
      { to: '/lists', labelKey: 'nav.lists', icon: <GroupsIcon />, permission: 'list.read' },
      { to: '/admins', labelKey: 'nav.admins', icon: <AdminPanelSettingsIcon />, permission: 'admin.read' },
    ],
  },
  {
    labelKey: 'nav.section.operations',
    entries: [
      { to: '/maintenance/repairs', labelKey: 'nav.repairs', icon: <BuildIcon />, permission: 'maintenance.read' },
      {
        to: '/maintenance/reconciliation',
        labelKey: 'nav.reconciliation',
        icon: <CompareArrowsIcon />,
        permission: 'maintenance.read',
      },
      { to: '/maintenance/health', labelKey: 'nav.health', icon: <MonitorHeartIcon />, permission: 'maintenance.read' },
      { to: '/audit', labelKey: 'nav.audit', icon: <HistoryIcon />, permission: 'audit.read' },
    ],
  },
];

/** The sections as this session sees them; a section with nothing left disappears. */
export function visibleNavigation(can: (permission: string) => boolean): readonly NavigationSection[] {
  return NAVIGATION.map((section) => ({
    ...section,
    entries: section.entries.filter((entry) => entry.permission === undefined || can(entry.permission)),
  })).filter((section) => section.entries.length > 0);
}

/**
 * Whether an entry is the current place: the overview only on `/`, every
 * other entry also on the views below it (`/domains/example.test`).
 */
export function isActive(entry: NavigationEntry, pathname: string): boolean {
  if (entry.to === '/') {
    return pathname === '/';
  }

  return pathname === entry.to || pathname.startsWith(`${entry.to}/`);
}
