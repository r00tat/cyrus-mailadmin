import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useId, useState, type ReactNode } from 'react';

import type { components } from '../../api/schema';
import { useTranslation, type TranslationKey } from '../../i18n';

/**
 * What still has to happen outside mailadmin before mail for a new domain
 * arrives — `next_steps` of `DomainCreated`, translated (E-4, E-9, E-16).
 *
 * The boxes are for the operator working down the list and are not stored:
 * mailadmin cannot perform these steps and does not check them, and a tick
 * that survived a reload would look like it had.
 */

type DomainSetupStep = components['schemas']['DomainSetupStep'];

const STEP_KEYS: Readonly<Record<DomainSetupStep, { title: TranslationKey; detail: TranslationKey }>> = {
  salt_pillar: { title: 'domain.nextSteps.step.saltPillar.title', detail: 'domain.nextSteps.step.saltPillar.detail' },
  salt_apply: { title: 'domain.nextSteps.step.saltApply.title', detail: 'domain.nextSteps.step.saltApply.detail' },
  cyrus_loginrealm: {
    title: 'domain.nextSteps.step.cyrusLoginrealm.title',
    detail: 'domain.nextSteps.step.cyrusLoginrealm.detail',
  },
  dns_mx: { title: 'domain.nextSteps.step.dnsMx.title', detail: 'domain.nextSteps.step.dnsMx.detail' },
  dns_spf: { title: 'domain.nextSteps.step.dnsSpf.title', detail: 'domain.nextSteps.step.dnsSpf.detail' },
  dns_dkim: { title: 'domain.nextSteps.step.dnsDkim.title', detail: 'domain.nextSteps.step.dnsDkim.detail' },
  dns_dmarc: { title: 'domain.nextSteps.step.dnsDmarc.title', detail: 'domain.nextSteps.step.dnsDmarc.detail' },
};

export function NextStepsChecklist({
  domain,
  steps,
  onOpen,
  onAnother,
}: {
  domain: string;
  steps: readonly DomainSetupStep[];
  onOpen: () => void;
  onAnother: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const headingId = useId();
  const [done, setDone] = useState<ReadonlySet<DomainSetupStep>>(new Set());

  const toggle = (step: DomainSetupStep): void => {
    setDone((current) => {
      const next = new Set(current);

      if (next.has(step)) {
        next.delete(step);
      } else {
        next.add(step);
      }

      return next;
    });
  };

  return (
    <Stack spacing={3}>
      <Alert severity="success">{t('domain.nextSteps.created', { name: domain })}</Alert>

      <Paper variant="outlined" sx={{ p: 3 }}>
        <Stack spacing={1}>
          <Typography variant="h6" component="h2" id={headingId}>
            {t('domain.nextSteps.title')}
          </Typography>
          <Typography color="text.secondary">{t('domain.nextSteps.intro', { name: domain })}</Typography>

          {steps.length === 0 ? (
            <Typography>{t('domain.nextSteps.none')}</Typography>
          ) : (
            <List aria-labelledby={headingId}>
              {steps.map((step) => {
                const labelId = `${headingId}-${step}`;

                return (
                  <ListItem key={step} disableGutters alignItems="flex-start">
                    <ListItemIcon sx={{ minWidth: 40 }}>
                      <Checkbox
                        edge="start"
                        checked={done.has(step)}
                        onChange={() => toggle(step)}
                        slotProps={{ input: { 'aria-labelledby': labelId } }}
                      />
                    </ListItemIcon>
                    <ListItemText
                      id={labelId}
                      primary={t(STEP_KEYS[step].title)}
                      secondary={t(STEP_KEYS[step].detail, { name: domain })}
                    />
                  </ListItem>
                );
              })}
            </List>
          )}

          <Typography variant="body2" color="text.secondary">
            {t('domain.nextSteps.notStored')}
          </Typography>
        </Stack>
      </Paper>

      <Stack direction="row" spacing={1} justifyContent="flex-end">
        <Button onClick={onAnother}>{t('domain.nextSteps.another')}</Button>
        <Button variant="contained" onClick={onOpen}>
          {t('domain.nextSteps.open')}
        </Button>
      </Stack>
    </Stack>
  );
}
