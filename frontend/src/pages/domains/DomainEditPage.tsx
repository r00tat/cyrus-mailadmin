import Alert from '@mui/material/Alert';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch } from '../../api/etag';
import type { components } from '../../api/schema';
import { errorAtOrBelow, FormActions, useFieldErrors, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ProblemAlert } from '../../components/ProblemAlert';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useTranslation } from '../../i18n';
import { describeUnknownError } from '../../i18n/problemText';

import { domainDetailQueryKey } from './domainCache';
import {
  DomainFields,
  domainPath,
  domainValuesFrom,
  parseDomainValues,
  type Domain,
  type DomainFormValues,
} from './domainForm';

/**
 * A1 — changing a domain's limits, folders, naming scheme and legacy flags.
 * The name is not here: a rename moves every mailbox and is its own confirmed
 * operation on the detail page.
 *
 * Only fields that changed are sent, so the audit entry says what the
 * administrator did and not what the form happened to contain. `If-Match` is
 * the version the form was filled from; after a `412` the form is rebuilt from
 * the reloaded domain (it is keyed by the `ETag`), and the user enters the
 * change again against what is there now.
 */

type DomainUpdateRequest = components['schemas']['DomainUpdateRequest'];

export function DomainEditPage(): ReactNode {
  const { t } = useTranslation();
  const { domain: name = '' } = useParams();
  const client = useApiClient();
  const [reloaded, setReloaded] = useState(false);

  const detail = useQuery({
    queryKey: domainDetailQueryKey(name),
    queryFn: ({ signal }) => client.getVersioned('/domains/{domain}', { pathParams: { domain: name }, signal }),
  });

  const breadcrumbs = [
    { label: t('domain.list.title'), to: '/domains' },
    { label: name, to: domainPath(name) },
    { label: t('domain.edit.title') },
  ];

  if (detail.isPending) {
    return (
      <>
        <PageHeader title={t('domain.edit.heading', { name })} breadcrumbs={breadcrumbs} />
        <LoadingState />
      </>
    );
  }

  if (detail.isError) {
    return (
      <>
        <PageHeader title={t('domain.edit.heading', { name })} breadcrumbs={breadcrumbs} />
        <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
      </>
    );
  }

  return (
    <>
      <PageHeader title={t('domain.edit.heading', { name })} breadcrumbs={breadcrumbs} />
      {reloaded && (
        <Alert severity="info" sx={{ mb: 2 }} onClose={() => setReloaded(false)} closeText={t('action.close')}>
          {t('domain.edit.reloaded')}
        </Alert>
      )}
      <DomainEditForm
        key={detail.data.etag ?? 'unversioned'}
        domain={detail.data.data}
        etag={detail.data.etag}
        onReload={() => {
          setReloaded(true);
          void detail.refetch();
        }}
      />
    </>
  );
}

function changedFields(domain: Domain, values: ReturnType<typeof parseDomainValues>['parsed']): DomainUpdateRequest {
  if (values === null) {
    return {};
  }

  const sameFolders =
    values.default_folders.length === domain.default_folders.length
    && values.default_folders.every((folder, index) => folder === domain.default_folders[index]);

  return {
    ...(values.max_accounts === domain.max_accounts ? {} : { max_accounts: values.max_accounts }),
    ...(values.default_account_quota_kib === domain.default_account_quota_kib
      ? {}
      : { default_account_quota_kib: values.default_account_quota_kib }),
    ...(values.domain_quota_kib === domain.domain_quota_kib ? {} : { domain_quota_kib: values.domain_quota_kib }),
    ...(sameFolders ? {} : { default_folders: values.default_folders }),
    ...(values.naming_scheme === domain.naming_scheme ? {} : { naming_scheme: values.naming_scheme }),
    ...(values.free_names === domain.free_names ? {} : { free_names: values.free_names }),
    ...(values.free_address === domain.free_address ? {} : { free_address: values.free_address }),
  };
}

function DomainEditForm({
  domain,
  etag,
  onReload,
}: {
  domain: Domain;
  etag: string | null;
  onReload: () => void;
}): ReactNode {
  const translator = useTranslation();
  const { t } = translator;
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();

  const [values, setValues] = useState<DomainFormValues>(() => domainValuesFrom(domain));
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const [unchanged, setUnchanged] = useState(false);

  const update = useMutation({
    mutationFn: (body: DomainUpdateRequest) =>
      client.patch('/domains/{domain}', { pathParams: { domain: domain.name }, body, headers: ifMatch(etag) }),
    onSuccess: async () => {
      notify(t('domain.edit.saved', { name: domain.name }));
      await queryClient.invalidateQueries({ queryKey: ['domains'] });
      void navigate(domainPath(domain.name));
    },
  });

  const fieldError = useFieldErrors(update.error, clientErrors);
  const serverFieldErrors = update.error === null ? {} : describeUnknownError(update.error, translator).fieldErrors;

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const { parsed, errors } = parseDomainValues(values, t('domain.form.error.notANumber'));

    setClientErrors(errors);

    if (parsed === null) {
      return;
    }

    const body = changedFields(domain, parsed);

    if (Object.keys(body).length === 0) {
      // `minProperties: 1` — an empty PATCH is a mistake the server reports.
      // Saying so here is kinder than a 422.
      setUnchanged(true);

      return;
    }

    setUnchanged(false);
    update.mutate(body);
  };

  return (
    <Paper variant="outlined" sx={{ p: 3 }}>
      <form onSubmit={submit} noValidate>
        <Stack spacing={3}>
          <Typography color="text.secondary">{t('domain.edit.nameHint')}</Typography>
          <DomainFields
            values={values}
            onChange={(patch) => {
              setUnchanged(false);
              setValues((current) => ({ ...current, ...patch }));
            }}
            fieldError={fieldError}
            folderError={errorAtOrBelow(serverFieldErrors, 'body.default_folders')}
            effectiveScheme={domain.effective_naming_scheme}
            disabled={update.isPending}
          />
          {unchanged && <Typography color="text.secondary">{t('domain.edit.unchanged')}</Typography>}
          <ProblemAlert error={update.error} onReload={onReload} />
          <FormActions pending={update.isPending} onCancel={() => void navigate(domainPath(domain.name))} />
        </Stack>
      </form>
    </Paper>
  );
}
