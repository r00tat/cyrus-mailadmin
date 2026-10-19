import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormGroup from '@mui/material/FormGroup';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict, type Versioned } from '../../api/etag';
import type { components } from '../../api/schema';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useNotify } from '../../components/Notifications';
import { useFormatters, useTranslation, type TranslationKey } from '../../i18n';

import { accountKey } from './accountQueries';
import type { Account } from './labels';

type Services = components['schemas']['AccountServices'];
type ServiceName = keyof Services;
type UpdateBody = components['schemas']['AccountUpdateRequest'];

const SERVICES: readonly { readonly name: ServiceName; readonly key: TranslationKey }[] = [
  { name: 'imap', key: 'account.access.service.imap' },
  { name: 'pop', key: 'account.access.service.pop' },
  { name: 'sieve', key: 'account.access.service.sieve' },
  { name: 'smtp_auth', key: 'account.access.service.smtpAuth' },
];

/**
 * Whether the account may log in (`enabled`, enforced through `allusers`,
 * U-127) and the four service flags (carried, **not** enforced, U-78).
 *
 * Both are `PATCH /accounts/{account}` with the `ETag` of what the user is
 * looking at; a `412` offers to reload. Disabling asks first — it locks the
 * owner out of IMAP, POP, ManageSieve and SMTP-AUTH, while mail is still
 * delivered.
 */
export function AccountAccessCard({
  account,
  etag,
  writable,
  onReload,
}: {
  account: Account;
  etag: string | null;
  writable: boolean;
  onReload: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();

  const [services, setServices] = useState<Services>(account.services);
  const [confirmDisable, setConfirmDisable] = useState(false);

  // Follow the server's state after a reload or a save.
  useEffect(() => {
    setServices(account.services);
  }, [account.services]);

  const update = useMutation({
    mutationFn: (body: UpdateBody): Promise<Versioned<Account>> =>
      client.requestVersioned('patch', '/accounts/{account}', {
        pathParams: { account: account.id },
        body,
        headers: ifMatch(etag),
      }),
    onSuccess: async (result, body) => {
      queryClient.setQueryData(accountKey(account.id), result);
      await queryClient.invalidateQueries({ queryKey: ['accounts', 'list'] });
      setConfirmDisable(false);
      notify(
        body.enabled === false
          ? t('account.access.disabled')
          : body.enabled === true
            ? t('account.access.enabled')
            : t('account.access.servicesSaved'),
      );
    },
    onError: (error) => {
      if (isVersionConflict(error)) {
        onReload();
      }
    },
  });

  const changed: Partial<Services> = {};

  for (const { name } of SERVICES) {
    if (services[name] !== account.services[name]) {
      changed[name] = services[name];
    }
  }

  const dirty = Object.keys(changed).length > 0;

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6" component="h2">
            {t('account.access.title')}
          </Typography>

          <FormControlLabel
            control={
              <Switch
                checked={account.enabled}
                disabled={!writable || update.isPending}
                onChange={(event) => {
                  if (event.target.checked) {
                    update.mutate({ enabled: true });
                  } else {
                    update.reset();
                    setConfirmDisable(true);
                  }
                }}
              />
            }
            label={account.enabled ? t('account.access.loginAllowed') : t('account.access.loginBlocked')}
          />

          {!account.enabled && (
            <Alert severity="warning">
              {account.disabled_at === null
                ? t('account.access.disabledInfo')
                : account.disabled_by === null
                  ? t('account.access.disabledSince', { when: dateTime(account.disabled_at) })
                  : t('account.access.disabledSinceBy', { when: dateTime(account.disabled_at), who: account.disabled_by })}
            </Alert>
          )}

          <Typography variant="subtitle2" component="h3">
            {t('account.access.servicesTitle')}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {t('account.access.servicesHint')}
          </Typography>
          <FormGroup row>
            {SERVICES.map(({ name, key }) => (
              <FormControlLabel
                key={name}
                control={
                  <Checkbox
                    checked={services[name]}
                    disabled={!writable || update.isPending}
                    onChange={(event) => setServices({ ...services, [name]: event.target.checked })}
                  />
                }
                label={t(key)}
              />
            ))}
          </FormGroup>

          {!confirmDisable && <ProblemAlert error={update.error} onReload={onReload} />}

          {writable && (
            <Stack direction="row" justifyContent="flex-end">
              <Button
                variant="contained"
                disabled={!dirty || update.isPending}
                onClick={() => update.mutate({ services: changed })}
              >
                {update.isPending ? t('action.saving') : t('account.access.saveServices')}
              </Button>
            </Stack>
          )}
        </Stack>
      </CardContent>

      <ConfirmDialog
        open={confirmDisable}
        title={t('account.access.confirmDisable.title')}
        message={t('account.access.confirmDisable.message', { address: account.address ?? account.id })}
        confirmLabel={t('account.access.confirmDisable.confirm')}
        destructive
        pending={update.isPending}
        error={update.error}
        onConfirm={() => update.mutate({ enabled: false })}
        onClose={() => {
          setConfirmDisable(false);
          update.reset();
        }}
      />
    </Card>
  );
}
