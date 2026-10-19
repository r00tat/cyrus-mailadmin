import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import { errorAtOrBelow, fieldProps, FormActions, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useTranslation } from '../../i18n';
import { describeUnknownError } from '../../i18n/problemText';

import {
  DomainFields,
  EMPTY_DOMAIN_VALUES,
  parseDomainValues,
  domainPath,
  type DomainFormValues,
} from './domainForm';
import { NextStepsChecklist } from './NextStepsChecklist';

/**
 * A1 — creating a domain. Only the name is required; what is left empty takes
 * the legacy column default, so a bare name creates the domain webcyradm would
 * have created.
 *
 * After the `201` the form gives way to `next_steps`: what has to happen
 * outside mailadmin before mail arrives (E-4, E-9). The answer carries them,
 * the page does not invent them.
 */

type DomainCreateRequest = components['schemas']['DomainCreateRequest'];
type DomainCreated = components['schemas']['DomainCreated'];

export function DomainCreatePage(): ReactNode {
  const translator = useTranslation();
  const { t } = translator;
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();

  const [name, setName] = useState('');
  const [values, setValues] = useState<DomainFormValues>(EMPTY_DOMAIN_VALUES);
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const [created, setCreated] = useState<DomainCreated | null>(null);
  const required = useRequiredCheck();

  const create = useMutation({
    mutationFn: (body: DomainCreateRequest) => client.post('/domains', { body }),
    onSuccess: async (result) => {
      setCreated(result);
      notify(t('domain.create.saved', { name: result.domain.name }));
      await queryClient.invalidateQueries({ queryKey: ['domains'] });
    },
  });

  const fieldError = useFieldErrors(create.error, clientErrors);
  const serverFieldErrors = create.error === null ? {} : describeUnknownError(create.error, translator).fieldErrors;

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.name': name });
    const { parsed, errors } = parseDomainValues(values, t('domain.form.error.notANumber'));

    setClientErrors({ ...errors, ...missing });

    if (parsed === null || Object.keys(missing).length > 0) {
      return;
    }

    // Only what the user said something about: an omitted field takes the
    // column default (contract, `DomainCreateRequest`), an explicit value
    // would not.
    const body: DomainCreateRequest = {
      name: name.trim(),
      ...(parsed.max_accounts === null ? {} : { max_accounts: parsed.max_accounts }),
      ...(parsed.default_account_quota_kib === null ? {} : { default_account_quota_kib: parsed.default_account_quota_kib }),
      ...(parsed.domain_quota_kib === null ? {} : { domain_quota_kib: parsed.domain_quota_kib }),
      ...(parsed.default_folders.length === 0 ? {} : { default_folders: parsed.default_folders }),
      ...(parsed.naming_scheme === 'inherited' ? {} : { naming_scheme: parsed.naming_scheme }),
      ...(parsed.free_names ? { free_names: true } : {}),
      ...(parsed.free_address ? { free_address: true } : {}),
    };

    create.mutate(body);
  };

  const breadcrumbs = [{ label: t('domain.list.title'), to: '/domains' }, { label: t('domain.create.title') }];

  if (created !== null) {
    return (
      <>
        <PageHeader title={t('domain.create.doneTitle', { name: created.domain.name })} breadcrumbs={breadcrumbs} />
        <NextStepsChecklist
          domain={created.domain.name}
          steps={created.next_steps}
          onOpen={() => void navigate(domainPath(created.domain.name))}
          onAnother={() => {
            setCreated(null);
            setName('');
            setValues(EMPTY_DOMAIN_VALUES);
            setClientErrors({});
            create.reset();
          }}
        />
      </>
    );
  }

  return (
    <>
      <PageHeader title={t('domain.create.title')} breadcrumbs={breadcrumbs} description={t('domain.create.description')} />
      <Paper variant="outlined" sx={{ p: 3 }}>
        <form onSubmit={submit} noValidate>
          <Stack spacing={3}>
            <TextField
              label={t('domain.field.name')}
              required
              value={name}
              onChange={(event) => setName(event.target.value)}
              autoComplete="off"
              slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
              sx={{ maxWidth: 420 }}
              {...fieldProps(fieldError, 'body.name', t('domain.create.hint.name'))}
            />
            <DomainFields
              values={values}
              onChange={(patch) => setValues((current) => ({ ...current, ...patch }))}
              fieldError={fieldError}
              folderError={errorAtOrBelow(serverFieldErrors, 'body.default_folders')}
              disabled={create.isPending}
            />
            <ProblemAlert error={create.error} />
            <FormActions
              pending={create.isPending}
              submitLabel={t('domain.create.submit')}
              onCancel={() => void navigate('/domains')}
            />
          </Stack>
        </form>
      </Paper>
    </>
  );
}
