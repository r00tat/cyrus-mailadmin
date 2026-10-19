import { screen, within } from '@testing-library/react';
import Button from '@mui/material/Button';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '../test/renderWithRouter';

import { PageHeader } from './PageHeader';

describe('PageHeader', () => {
  it('renders the one heading and sets the document title from it', () => {
    renderWithRouter(<PageHeader title="Domains" />);

    expect(screen.getByRole('heading', { level: 1, name: 'Domains' })).toBeInTheDocument();
    expect(document.title).toBe('Domains — mailadmin');
    expect(screen.queryByRole('navigation')).toBeNull();
  });

  it('links every breadcrumb but the last, which is the current page', async () => {
    const { user, router } = renderWithRouter(
      <PageHeader
        title="example.test"
        breadcrumbs={[{ label: 'Domains', to: '/domains' }, { label: 'example.test' }]}
      />,
      { initialEntry: '/domains/example.test' },
    );

    const trail = screen.getByRole('navigation', { name: 'Pfad' });

    expect(within(trail).getByText('example.test')).toHaveAttribute('aria-current', 'page');

    await user.click(within(trail).getByRole('link', { name: 'Domains' }));

    expect(router.state.location.pathname).toBe('/domains');
  });

  it('places the page actions and the description', () => {
    renderWithRouter(
      <PageHeader title="Domains" description="Alle Domains." actions={<Button>Anlegen</Button>} />,
    );

    expect(screen.getByRole('button', { name: 'Anlegen' })).toBeInTheDocument();
    expect(screen.getByText('Alle Domains.')).toBeInTheDocument();
  });
});
