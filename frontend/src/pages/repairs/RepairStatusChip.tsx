import Chip from '@mui/material/Chip';
import type { ReactNode } from 'react';

import { useTranslation } from '../../i18n';

import type { RepairEntry } from './repairActions';

/**
 * `open`, or how the entry was closed: `retried` by the application or
 * `dismissed` by an administrator. "Resolved" alone would hide the difference
 * that matters later — whether mailadmin finished the step or someone said it
 * no longer needed finishing.
 */
export function RepairStatusChip({ entry }: { entry: RepairEntry }): ReactNode {
  const { t } = useTranslation();

  if (entry.status === 'open') {
    return <Chip size="small" color="warning" label={t('maintenance.repairs.status.open')} />;
  }

  const label = entry.resolution === 'retried'
    ? t('maintenance.repairs.status.retried')
    : entry.resolution === 'dismissed'
      ? t('maintenance.repairs.status.dismissed')
      : t('maintenance.repairs.status.resolved');

  return <Chip size="small" color="success" variant="outlined" label={label} />;
}
