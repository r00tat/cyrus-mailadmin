import DeleteIcon from '@mui/icons-material/Delete';
import DriveFileRenameOutlineIcon from '@mui/icons-material/DriveFileRenameOutline';
import EditIcon from '@mui/icons-material/Edit';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { Link as RouterLink, useNavigate, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict } from '../../api/etag';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useFormatters, useTranslation } from '../../i18n';
import { useProblemText } from '../../i18n/problemText';

import { domainDetailQueryKey, forgetDomainName } from './domainCache';
import { EFFECTIVE_SCHEME_KEYS, NAMING_SCHEME_KEYS, domainPath, type Domain } from './domainForm';
import { RenameDomainDialog } from './RenameDomainDialog';

/**
 * A1 — one domain. Reading is open to both roles; the actions are the
 * superuser's (`domain.write`, `domain.delete`) and hidden from a domain
 * master. A domain outside the actor's scope is a `404` like one that does not
 * exist (U-44) and is shown as such.
 *
 * The version the user looks at is the one every write sends as `If-Match`
 * (U-19). A `412` reloads it, so that the next confirmation is a decision
 * about the current state.
 */

export function DomainDetailPage(): ReactNode {
  const { t } = useTranslation();
  const { domain: name = '' } = useParams();
  const client = useApiClient();
  const { can } = useSession();

  const detail = useQuery({
    queryKey: domainDetailQueryKey(name),
    queryFn: ({ signal }) => client.getVersioned('/domains/{domain}', { pathParams: { domain: name }, signal }),
  });

  const breadcrumbs = [{ label: t('domain.list.title'), to: '/domains' }, { label: name }];

  if (detail.isPending) {
    return (
      <>
        <PageHeader title={name} breadcrumbs={breadcrumbs} />
        <LoadingState />
      </>
    );
  }

  if (detail.isError) {
    return (
      <>
        <PageHeader title={name} breadcrumbs={breadcrumbs} />
        <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
      </>
    );
  }

  return (
    <DomainDetail
      domain={detail.data.data}
      etag={detail.data.etag}
      breadcrumbs={breadcrumbs}
      canWrite={can('domain.write')}
      canDelete={can('domain.delete')}
      canSeeRepairs={can('maintenance.read')}
      onReload={() => void detail.refetch()}
    />
  );
}

function DomainDetail({
  domain,
  etag,
  breadcrumbs,
  canWrite,
  canDelete,
  canSeeRepairs,
  onReload,
}: {
  domain: Domain;
  etag: string | null;
  breadcrumbs: readonly { label: string; to?: string }[];
  canWrite: boolean;
  canDelete: boolean;
  canSeeRepairs: boolean;
  onReload: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const { kibibytes } = useFormatters();
  const describe = useProblemText();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();

  const [deleting, setDeleting] = useState(false);
  const [renaming, setRenaming] = useState(false);
  const [renameIncomplete, setRenameIncomplete] = useState<unknown>(null);

  const remove = useMutation({
    mutationFn: () => client.delete('/domains/{domain}', { pathParams: { domain: domain.name }, headers: ifMatch(etag) }),
    onSuccess: () => {
      setDeleting(false);
      notify(t('domain.detail.deleted', { name: domain.name }));
      forgetDomainName(queryClient, domain.name);
      void navigate('/domains', { replace: true });
    },
    onError: (error) => {
      // Someone else changed the domain: show the new state before asking again.
      if (isVersionConflict(error)) {
        onReload();
      }
    },
  });

  const quota = (value: number | null): string => (value === null ? t('domain.value.unlimited') : kibibytes(value));
  const encoded = encodeURIComponent(domain.name);

  return (
    <>
      <PageHeader
        title={domain.name}
        breadcrumbs={breadcrumbs}
        actions={
          <>
            {canWrite && (
              <Button component={RouterLink} to={`${domainPath(domain.name)}/edit`} startIcon={<EditIcon />}>
                {t('domain.detail.action.edit')}
              </Button>
            )}
            {canWrite && (
              <Button onClick={() => setRenaming(true)} startIcon={<DriveFileRenameOutlineIcon />}>
                {t('domain.detail.action.rename')}
              </Button>
            )}
            {canDelete && (
              <Button color="error" onClick={() => setDeleting(true)} startIcon={<DeleteIcon />}>
                {t('domain.detail.action.delete')}
              </Button>
            )}
          </>
        }
      />

      <Stack spacing={3}>
        {renameIncomplete !== null && (
          <Alert severity="error" role="alert" onClose={() => setRenameIncomplete(null)} closeText={t('action.close')}>
            <AlertTitle>{t('domain.rename.incomplete.title')}</AlertTitle>
            <Stack spacing={1}>
              <Typography variant="body2">{describe(renameIncomplete).message}</Typography>
              <Typography variant="body2">{t('domain.rename.incomplete.detail', { name: domain.name })}</Typography>
              {describe(renameIncomplete).requestId !== undefined && (
                <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>
                  {t('state.error.requestId', { requestId: describe(renameIncomplete).requestId ?? '' })}
                </Typography>
              )}
              {canSeeRepairs && (
                <Link component={RouterLink} to="/maintenance/repairs">
                  {t('domain.rename.incomplete.repairs')}
                </Link>
              )}
            </Stack>
          </Alert>
        )}

        {!domain.conventional_name && <Alert severity="warning">{t('domain.detail.unconventionalName')}</Alert>}

        <Paper variant="outlined" sx={{ p: 3 }}>
          <Stack spacing={2}>
            <Typography variant="h6" component="h2">
              {t('domain.detail.section.limits')}
            </Typography>
            <Property label={t('domain.field.accounts')}>
              {domain.max_accounts === null
                ? t('domain.value.accountsUnlimited', { count: domain.account_count })
                : t('domain.value.accountsOfLimit', { count: domain.account_count, limit: domain.max_accounts })}
            </Property>
            <Property label={t('domain.field.defaultQuota')}>{quota(domain.default_account_quota_kib)}</Property>
            <Property label={t('domain.field.domainQuota')}>{quota(domain.domain_quota_kib)}</Property>
            <Typography variant="body2" color="text.secondary">
              {t('domain.detail.domainQuotaNote')}
            </Typography>
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 3 }}>
          <Stack spacing={2}>
            <Typography variant="h6" component="h2">
              {t('domain.detail.section.mailboxes')}
            </Typography>
            <Property label={t('domain.field.namingScheme')}>
              {domain.naming_scheme === 'inherited'
                ? t('domain.value.schemeInherited', { scheme: t(EFFECTIVE_SCHEME_KEYS[domain.effective_naming_scheme]) })
                : t(NAMING_SCHEME_KEYS[domain.naming_scheme])}
            </Property>
            <Property label={t('domain.field.defaultFolders')}>
              {domain.default_folders.length === 0 ? (
                t('domain.value.noFolders')
              ) : (
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                  {domain.default_folders.map((folder, index) => (
                    <Chip key={`${folder}-${String(index)}`} label={folder} size="small" />
                  ))}
                </Stack>
              )}
            </Property>
            <Stack direction="row" spacing={2} flexWrap="wrap" useFlexGap>
              <Link component={RouterLink} to={`/accounts?domain=${encoded}`}>
                {t('domain.detail.link.accounts', { count: domain.account_count })}
              </Link>
              <Link component={RouterLink} to={`/addresses?domain=${encoded}`}>
                {t('domain.detail.link.addresses')}
              </Link>
            </Stack>
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 3 }}>
          <Stack spacing={2}>
            <Typography variant="h6" component="h2">
              {t('domain.detail.section.legacy')}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {t('domain.detail.legacyNote')}
            </Typography>
            <Property label={t('domain.field.legacyPrefix')}>{domain.legacy_prefix}</Property>
            <Property label={t('domain.field.transport')}>{domain.transport}</Property>
            <Property label={t('domain.field.freeNames')}>
              {domain.free_names ? t('domain.value.yes') : t('domain.value.no')}
            </Property>
            <Property label={t('domain.field.freeAddress')}>
              {domain.free_address ? t('domain.value.yes') : t('domain.value.no')}
            </Property>
          </Stack>
        </Paper>
      </Stack>

      <ConfirmDialog
        open={deleting}
        destructive
        title={t('domain.delete.title', { name: domain.name })}
        message={
          <Stack spacing={1}>
            <span>{t('domain.delete.message', { name: domain.name })}</span>
            {domain.account_count > 0 && (
              <span>{t('domain.delete.hasAccounts', { count: domain.account_count })}</span>
            )}
          </Stack>
        }
        typedConfirmation={domain.name}
        pending={remove.isPending}
        error={remove.error}
        onConfirm={() => remove.mutate()}
        onClose={() => {
          setDeleting(false);
          remove.reset();
        }}
      />

      <RenameDomainDialog
        open={renaming}
        domain={domain}
        etag={etag}
        onClose={() => setRenaming(false)}
        onReload={onReload}
        onIncomplete={(error) => {
          setRenaming(false);
          setRenameIncomplete(error);
          onReload();
        }}
        onRenamed={(newName) => {
          setRenaming(false);
          notify(t('domain.rename.done', { from: domain.name, to: newName }));
          void navigate(domainPath(newName), { replace: true });
        }}
      />
    </>
  );
}

/** One labelled value. */
function Property({ label, children }: { label: string; children: ReactNode }): ReactNode {
  return (
    <Stack spacing={0.25}>
      <Typography variant="overline" color="text.secondary" component="div">
        {label}
      </Typography>
      <Typography component="div">{children}</Typography>
    </Stack>
  );
}
