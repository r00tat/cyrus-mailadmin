import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import type { ReactNode } from 'react';

import type { components } from '../../api/schema';
import { useTranslation, type TranslationKey } from '../../i18n';

/** Labels for the enumerations of an audit entry. */

type AuditOutcome = components['schemas']['AuditOutcome'];
type AuditRole = NonNullable<components['schemas']['AuditEntry']['actor_role']>;

export const OUTCOMES = ['succeeded', 'denied', 'failed'] as const satisfies readonly AuditOutcome[];

export const OUTCOME_KEYS: Record<AuditOutcome, TranslationKey> = {
  succeeded: 'audit.outcome.succeeded',
  denied: 'audit.outcome.denied',
  failed: 'audit.outcome.failed',
};

const OUTCOME_COLOURS: Record<AuditOutcome, 'success' | 'warning' | 'error'> = {
  succeeded: 'success',
  denied: 'warning',
  failed: 'error',
};

export const ROLE_KEYS: Record<AuditRole, TranslationKey> = {
  superuser: 'audit.role.superuser',
  domainmaster: 'audit.role.domainmaster',
};

export function AuditOutcomeChip({ outcome }: { outcome: AuditOutcome }): ReactNode {
  const { t } = useTranslation();

  return <Chip size="small" variant="outlined" color={OUTCOME_COLOURS[outcome]} label={t(OUTCOME_KEYS[outcome])} />;
}

/** `account` and `user@example.test`, side by side, both as stored. */
export function AuditObject({ type, id }: { type: string; id: string | null | undefined }): ReactNode {
  return (
    <Box component="span" sx={{ fontFamily: 'monospace', display: 'inline-flex', flexWrap: 'wrap', columnGap: 1 }}>
      <Box component="span" sx={{ color: 'text.secondary' }}>
        {type}
      </Box>
      {id !== null && id !== undefined && <span>{id}</span>}
    </Box>
  );
}
