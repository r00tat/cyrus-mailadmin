import Autocomplete from '@mui/material/Autocomplete';
import TextField from '@mui/material/TextField';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { FILTER_DELAY_MS } from '../../components/FilterField';
import { useTranslation } from '../../i18n';

/**
 * Chooses the domain a list of this area shows. Local to the
 * accounts-addresses stream, shared with the addresses views (U-132).
 *
 * A domain master picks from the domains of their session — no request. A
 * superuser types and the server filters (`GET /domains?name=…`), so the
 * picker works the same with five domains and with five hundred.
 *
 * The query key starts with the area of the page using it (`queryRoot`),
 * never with `domains` — the domains stream owns that root (pages/README.md
 * section 5).
 */
export function DomainPicker({
  value,
  onChange,
  queryRoot,
}: {
  value: string | undefined;
  onChange: (domain: string | null) => void;
  queryRoot: 'accounts' | 'addresses';
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const { session } = useSession();
  const scoped = session?.role === 'domainmaster';

  const [input, setInput] = useState(value ?? '');
  const [search, setSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setSearch(input.trim()), FILTER_DELAY_MS);

    return () => clearTimeout(timer);
  }, [input]);

  // When a domain is chosen, the input shows its name; searching for exactly
  // that name would hide every other domain from the list. Search only while
  // the user is typing something else.
  const term = search === (value ?? '') ? '' : search;

  const domains = useQuery({
    queryKey: [queryRoot, 'domainOptions', term],
    queryFn: ({ signal }) =>
      client.get('/domains', {
        query: { per_page: 25, sort: 'name', order: 'asc', ...(term === '' ? {} : { name: term }) },
        signal,
      }),
    enabled: !scoped,
    placeholderData: keepPreviousData,
  });

  const options = useMemo(() => {
    const names = scoped ? [...(session?.domains ?? [])] : (domains.data?.items.map((domain) => domain.name) ?? []);

    // The current value is always an option, even when the page of results
    // does not hold it — otherwise the field would show it as invalid.
    if (value !== undefined && !names.includes(value)) {
      names.unshift(value);
    }

    return names;
  }, [scoped, session?.domains, domains.data, value]);

  return (
    <Autocomplete
      options={options}
      value={value ?? null}
      onChange={(_event, next) => onChange(next)}
      inputValue={input}
      onInputChange={(_event, next) => setInput(next)}
      // The server already filtered a superuser's options.
      {...(scoped ? {} : { filterOptions: (found: string[]) => found })}
      loading={domains.isFetching}
      noOptionsText={t('account.domainPicker.noOptions')}
      loadingText={t('account.domainPicker.loading')}
      openText={t('account.domainPicker.open')}
      closeText={t('account.domainPicker.close')}
      clearText={t('account.domainPicker.clear')}
      sx={{ minWidth: 260 }}
      renderInput={(params) => <TextField {...params} label={t('account.domainPicker.label')} />}
    />
  );
}
