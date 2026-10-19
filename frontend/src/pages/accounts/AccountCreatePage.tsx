import Alert from '@mui/material/Alert';
import InputAdornment from '@mui/material/InputAdornment';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { FormActions, fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useFormatters, useTranslation } from '../../i18n';

import { QuotaInput, quotaKibOf, type QuotaChoice } from './QuotaInput';
import { accountListPath, accountPath } from './labels';
import { useDomainParam } from './useDomainParam';

/**
 * A2 — create an account in a domain (`?domain=`).
 *
 * The form shows what the account will be called before it exists: the Cyrus
 * user name is **built** from the local part and the domain's naming scheme
 * (architecture 3.3), never taken apart, and it is what the owner logs in
 * with. The quota defaults to the domain's default limit; "no limit" and an
 * own limit are explicit choices (U-79).
 *
 * The password is held in component state only and never stored (U-74).
 */
export function AccountCreatePage(): ReactNode {
  const { t } = useTranslation();
  const { kibibytes } = useFormatters();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const [domain] = useDomainParam();

  const [localPart, setLocalPart] = useState('');
  const [password, setPassword] = useState('');
  const [quota, setQuota] = useState<QuotaChoice>({ mode: 'default', amount: '', unit: 'gibibyte' });
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const required = useRequiredCheck();

  const domainQuery = useQuery({
    queryKey: ['accounts', 'domain', domain],
    queryFn: ({ signal }) => client.get('/domains/{domain}', { pathParams: { domain: domain ?? '' }, signal }),
    enabled: domain !== undefined,
  });

  const create = useMutation({
    mutationFn: (body: { local_part: string; password: string; quota_kib?: number | null }) =>
      client.post('/domains/{domain}/accounts', { pathParams: { domain: domain ?? '' }, body }),
    onSuccess: async (created) => {
      setPassword('');
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['addresses'] }),
        queryClient.invalidateQueries({ queryKey: ['domains'] }),
      ]);
      notify(
        created.incomplete_steps.length === 0
          ? t('account.create.created', { address: created.account.address ?? created.account.id })
          : t('account.create.createdIncomplete', { address: created.account.address ?? created.account.id }),
      );
      void navigate(accountPath(created.account.id));
    },
  });

  const fieldError = useFieldErrors(create.error, clientErrors);

  const breadcrumbs = [
    { label: t('account.list.title'), to: accountListPath(domain) },
    { label: t('account.create.title') },
  ];

  if (domain === undefined) {
    return (
      <>
        <PageHeader title={t('account.create.title')} breadcrumbs={breadcrumbs} />
        <Alert severity="info">{t('account.list.chooseDomain')}</Alert>
      </>
    );
  }

  if (domainQuery.isPending) {
    return (
      <>
        <PageHeader title={t('account.create.title')} breadcrumbs={breadcrumbs} />
        <LoadingState />
      </>
    );
  }

  if (domainQuery.isError) {
    return (
      <>
        <PageHeader title={t('account.create.title')} breadcrumbs={breadcrumbs} />
        <ErrorState error={domainQuery.error} onRetry={() => void domainQuery.refetch()} />
      </>
    );
  }

  const settings = domainQuery.data;
  const scheme = settings.effective_naming_scheme;
  const typed = localPart.trim().toLowerCase();
  const username = typed === '' ? null : scheme === 'fqun' ? `${typed}@${domain}` : `${typed}.${domain}`;

  const defaultLabel = settings.default_account_quota_kib === null
    ? t('account.create.quotaDefaultUnlimited')
    : t('account.create.quotaDefault', { quota: kibibytes(settings.default_account_quota_kib) });

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({
      'body.local_part': localPart,
      'body.password': password,
      ...(quota.mode === 'limit' ? { 'body.quota_kib': quota.amount } : {}),
    });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    const quotaKib = quotaKibOf(quota);

    create.mutate({
      local_part: localPart.trim(),
      password,
      ...(quotaKib === undefined ? {} : { quota_kib: quotaKib }),
    });
  };

  return (
    <>
      <PageHeader
        title={t('account.create.title')}
        breadcrumbs={breadcrumbs}
        description={t('account.create.description', { domain })}
      />

      <Paper variant="outlined" sx={{ p: 3, maxWidth: 640 }}>
        <form onSubmit={submit} noValidate>
          <Stack spacing={3}>
            <Alert severity="info">
              {scheme === 'fqun'
                ? t('account.create.namingScheme.fqun', { domain })
                : t('account.create.namingScheme.dotted', { domain })}
            </Alert>

            <TextField
              label={t('account.create.field.localPart')}
              required
              value={localPart}
              onChange={(event) => setLocalPart(event.target.value)}
              autoComplete="off"
              slotProps={{
                htmlInput: { maxLength: 64, spellCheck: false, autoCapitalize: 'off' },
                input: { endAdornment: <InputAdornment position="end">{t('account.create.field.domainSuffix', { domain })}</InputAdornment> },
              }}
              {...fieldProps(
                fieldError,
                'body.local_part',
                username === null ? undefined : t('account.create.field.username', { username }),
              )}
            />

            <TextField
              label={t('account.create.field.password')}
              type="password"
              required
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="new-password"
              {...fieldProps(fieldError, 'body.password', t('account.create.field.passwordHint'))}
            />

            <QuotaInput
              value={quota}
              onChange={setQuota}
              defaultLabel={defaultLabel}
              {...fieldProps(fieldError, 'body.quota_kib')}
            />

            <ProblemAlert error={create.error} />

            <FormActions
              pending={create.isPending}
              submitLabel={t('account.create.submit')}
              onCancel={() => void navigate(accountListPath(domain))}
            />
          </Stack>
        </form>
      </Paper>
    </>
  );
}
