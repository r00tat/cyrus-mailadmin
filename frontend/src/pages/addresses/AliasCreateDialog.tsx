import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import { ProblemAlert } from '../../components/ProblemAlert';
import { fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { useTranslation } from '../../i18n';

import { DestinationsField, SenderField, destinationsError, senderValueOf, type SenderChoice } from './fields';
import { aliasPath, parseDestinations } from './labels';

type AliasCreateRequest = components['schemas']['AliasCreateRequest'];

/**
 * Creates an alias in a domain (`POST /domains/{domain}/aliases`).
 *
 * Destinations are addresses or account user names (U-104). Who may send as
 * the alias defaults to "automatic" — the server grants it to the only
 * destination when that is an account, and to nobody otherwise (U-106).
 */
export function AliasCreateDialog({ domain, onClose }: { domain: string; onClose: () => void }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const titleId = useId();
  const required = useRequiredCheck();

  const [localPart, setLocalPart] = useState('');
  const [destinations, setDestinations] = useState('');
  const [sender, setSender] = useState<SenderChoice>({ mode: 'auto' });
  const [enabled, setEnabled] = useState(true);
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const create = useMutation({
    mutationFn: (body: AliasCreateRequest) =>
      client.post('/domains/{domain}/aliases', { pathParams: { domain }, body }),
    onSuccess: async (alias) => {
      await queryClient.invalidateQueries({ queryKey: ['addresses'] });
      notify(t('address.alias.created', { address: alias.address }));
      onClose();
      void navigate(aliasPath(alias.address));
    },
  });

  const fieldError = useFieldErrors(create.error, clientErrors);

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const list = parseDestinations(destinations);
    const missing = required({
      'body.local_part': localPart,
      'body.destinations': list.length === 0 ? '' : 'given',
    });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    const senderAccount = senderValueOf(sender);

    create.mutate({
      local_part: localPart.trim(),
      destinations: list,
      enabled,
      ...(senderAccount === undefined ? {} : { sender_account: senderAccount }),
    });
  };

  return (
    <Dialog open onClose={() => !create.isPending && onClose()} aria-labelledby={titleId} maxWidth="sm" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('address.alias.createTitle')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField
              label={t('address.alias.field.localPart')}
              required
              autoFocus
              value={localPart}
              onChange={(event) => setLocalPart(event.target.value)}
              disabled={create.isPending}
              autoComplete="off"
              slotProps={{
                htmlInput: { maxLength: 64, spellCheck: false, autoCapitalize: 'off' },
                input: {
                  endAdornment: <InputAdornment position="end">{t('address.alias.field.domainSuffix', { domain })}</InputAdornment>,
                },
              }}
              {...fieldProps(fieldError, 'body.local_part')}
            />
            <DestinationsField
              value={destinations}
              onChange={setDestinations}
              error={destinationsError(fieldError)}
              disabled={create.isPending}
            />
            <SenderField
              value={sender}
              onChange={setSender}
              destinations={destinations}
              offerAuto
              error={fieldError('body.sender_account')}
              disabled={create.isPending}
            />
            <FormControlLabel
              control={<Switch checked={enabled} onChange={(event) => setEnabled(event.target.checked)} />}
              label={t('address.alias.field.enabled')}
            />
            <ProblemAlert error={create.error} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={create.isPending}>
            {t('action.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={create.isPending}>
            {create.isPending ? t('action.saving') : t('address.alias.action.create')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
