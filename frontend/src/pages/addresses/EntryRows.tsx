import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import type { ReactNode } from 'react';

import { useTranslation } from '../../i18n';

import { ENTRY_TYPE_KEYS, type AddressEntry } from './labels';

/**
 * The raw rows of `virtual` behind a resource that cannot be written back as
 * one (U-105, U-111). Shown so that nobody has to guess what is stored; they
 * are removed one by one from the domain's address list.
 */
export function EntryRows({ entries }: { entries: readonly AddressEntry[] }): ReactNode {
  const { t } = useTranslation();

  return (
    <Table size="small" aria-label={t('address.entries.label')}>
      <TableHead>
        <TableRow>
          <TableCell>{t('address.list.column.alias')}</TableCell>
          <TableCell>{t('address.list.column.type')}</TableCell>
          <TableCell>{t('address.list.column.destinations')}</TableCell>
          <TableCell>{t('address.entries.username')}</TableCell>
          <TableCell>{t('address.list.column.status')}</TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {entries.map((entry) => (
          <TableRow key={entry.id}>
            <TableCell sx={{ overflowWrap: 'anywhere' }}>{entry.alias}</TableCell>
            <TableCell>{t(ENTRY_TYPE_KEYS[entry.type])}</TableCell>
            <TableCell sx={{ overflowWrap: 'anywhere' }}>{entry.destinations.join(', ')}</TableCell>
            <TableCell sx={{ overflowWrap: 'anywhere' }}>{entry.username}</TableCell>
            <TableCell>{entry.enabled ? t('address.list.status.enabled') : t('address.list.status.disabled')}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
