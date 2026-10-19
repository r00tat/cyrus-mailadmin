import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useId, type ReactNode } from 'react';

import type { components } from '../../api/schema';
import { DefinitionItem, DefinitionList, Monospace } from '../../components/DefinitionList';
import { useFormatters, useTranslation } from '../../i18n';

import { AuditObject, AuditOutcomeChip, ROLE_KEYS } from './auditLabels';

/**
 * One audit entry with everything it records. There is no `GET /audit/{id}`
 * — the list carries the whole entry — so this is a dialog over the list, and
 * its links narrow that same list: by request, by object, by operation.
 *
 * `before` and `after` are redacted before they are written (S4) and are shown
 * as the JSON they are, as text. `null` means "did not exist", which is not
 * the same as an empty object and is said so.
 */

export type AuditEntry = components['schemas']['AuditEntry'];

export interface AuditNarrowing {
  readonly request_id?: string;
  readonly object_type?: string;
  readonly object_id?: string;
  readonly part_of?: string;
}

function State({ label, value }: { label: string; value: AuditEntry['before'] }): ReactNode {
  const { t } = useTranslation();

  return (
    <Box sx={{ flex: 1, minWidth: 0 }}>
      <Typography variant="subtitle2" component="h3">
        {label}
      </Typography>
      {value === null || value === undefined ? (
        <Typography variant="body2" color="text.secondary">
          {t('audit.detail.notExisting')}
        </Typography>
      ) : (
        <Box
          component="pre"
          sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, fontSize: '0.8125rem', overflowX: 'auto' }}
        >
          {JSON.stringify(value, null, 2)}
        </Box>
      )}
    </Box>
  );
}

export function AuditEntryDialog({
  entry,
  onClose,
  onNarrow,
}: {
  entry: AuditEntry | null;
  onClose: () => void;
  /** Replaces the list's filters with these. */
  onNarrow: (narrowing: AuditNarrowing) => void;
}): ReactNode {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const titleId = useId();

  if (entry === null) {
    return null;
  }

  const empty = t('audit.detail.none');
  const requestId = entry.request_id ?? null;
  const objectId = entry.object_id ?? null;
  const partOf = entry.part_of ?? null;

  return (
    <Dialog open onClose={onClose} aria-labelledby={titleId} maxWidth="md" fullWidth>
      <DialogTitle id={titleId}>{t('audit.detail.title', { id: String(entry.id) })}</DialogTitle>
      <DialogContent>
        <Stack spacing={3}>
          <DefinitionList>
            <DefinitionItem label={t('audit.detail.field.recordedAt')}>{dateTime(entry.recorded_at)}</DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.actor')}>{entry.actor}</DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.actorRole')}>
              {entry.actor_role === null || entry.actor_role === undefined ? empty : t(ROLE_KEYS[entry.actor_role])}
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.clientIp')}>
              {entry.client_ip === null || entry.client_ip === undefined ? empty : <Monospace>{entry.client_ip}</Monospace>}
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.requestId')}>
              {requestId === null ? empty : <Monospace>{requestId}</Monospace>}
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.action')}>
              <Monospace>{entry.action}</Monospace>
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.object')}>
              <AuditObject type={entry.object_type} id={objectId} />
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.domain')}>{entry.domain ?? empty}</DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.outcome')}>
              <AuditOutcomeChip outcome={entry.outcome} />
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.reason')}>
              {entry.reason === null || entry.reason === undefined ? empty : <Monospace>{entry.reason}</Monospace>}
            </DefinitionItem>
            <DefinitionItem label={t('audit.detail.field.partOf')}>{partOf === null ? empty : String(partOf)}</DefinitionItem>
          </DefinitionList>

          <Box>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
              <State label={t('audit.detail.field.before')} value={entry.before} />
              <State label={t('audit.detail.field.after')} value={entry.after} />
            </Stack>
            <Typography variant="caption" color="text.secondary">
              {t('audit.detail.redactedHint')}
            </Typography>
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        {requestId !== null && (
          <Button onClick={() => onNarrow({ request_id: requestId })}>{t('audit.detail.filter.request')}</Button>
        )}
        {objectId !== null && (
          <Button onClick={() => onNarrow({ object_type: entry.object_type, object_id: objectId })}>
            {t('audit.detail.filter.object')}
          </Button>
        )}
        {partOf !== null && (
          <Button onClick={() => onNarrow({ part_of: String(partOf) })}>{t('audit.detail.filter.operation')}</Button>
        )}
        <Button variant="contained" onClick={onClose}>
          {t('action.close')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
