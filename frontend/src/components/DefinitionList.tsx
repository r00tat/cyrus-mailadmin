import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

/**
 * Label–value pairs of one object, as a real `<dl>`: the stored facts of a
 * repair entry, an audit entry, an account. Two columns from `sm` up, stacked
 * below it; long values (addresses, request ids) wrap anywhere instead of
 * pushing the page sideways.
 *
 * ```tsx
 * <DefinitionList>
 *   <DefinitionItem label={t('…field.actor')}>{entry.actor}</DefinitionItem>
 *   <DefinitionItem label={t('…field.action')}><Monospace>{entry.action}</Monospace></DefinitionItem>
 * </DefinitionList>
 * ```
 */
export function DefinitionList({ children }: { children: ReactNode }): ReactNode {
  return (
    <Box
      component="dl"
      sx={{
        display: 'grid',
        gridTemplateColumns: { xs: '1fr', sm: 'minmax(9rem, max-content) 1fr' },
        columnGap: 3,
        rowGap: 1,
        m: 0,
      }}
    >
      {children}
    </Box>
  );
}

export function DefinitionItem({ label, children }: { label: string; children: ReactNode }): ReactNode {
  return (
    <Box sx={{ display: 'contents' }}>
      <Typography component="dt" variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography component="dd" variant="body2" sx={{ m: 0, overflowWrap: 'anywhere' }}>
        {children}
      </Typography>
    </Box>
  );
}

/**
 * A value shown exactly as stored — an identifier, a code, an operator note —
 * and set apart from the interface's own text (E-16).
 */
export function Monospace({ children }: { children: ReactNode }): ReactNode {
  return (
    <Box component="span" sx={{ fontFamily: 'monospace' }}>
      {children}
    </Box>
  );
}
