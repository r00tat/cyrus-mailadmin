import { act, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { renderWithRouter } from '../test/renderWithRouter';

import { FILTER_DELAY_MS, FilterField } from './FilterField';

describe('FilterField', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('hands the value on once after typing pauses, not per keystroke', async () => {
    const onChange = vi.fn();
    const { user } = renderWithRouter(<FilterField label="Name" value={undefined} onChange={onChange} />, {
      user: { advanceTimers: vi.advanceTimersByTime },
    });

    await user.type(screen.getByRole('searchbox', { name: 'Name' }), 'example');

    expect(onChange).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(FILTER_DELAY_MS);
    });

    expect(onChange).toHaveBeenCalledOnce();
    expect(onChange).toHaveBeenCalledWith('example');
  });

  it('applies at once on Enter', async () => {
    const onChange = vi.fn();
    const { user } = renderWithRouter(<FilterField label="Name" value={undefined} onChange={onChange} />, {
      user: { advanceTimers: vi.advanceTimersByTime },
    });

    await user.type(screen.getByRole('searchbox', { name: 'Name' }), 'ex{Enter}');

    expect(onChange).toHaveBeenCalledWith('ex');

    act(() => {
      vi.advanceTimersByTime(FILTER_DELAY_MS);
    });

    expect(onChange).toHaveBeenCalledOnce();
  });

  it('clears with a named button', async () => {
    const onChange = vi.fn();
    const { user } = renderWithRouter(<FilterField label="Name" value="example" onChange={onChange} />, {
      user: { advanceTimers: vi.advanceTimersByTime },
    });

    expect(screen.getByRole('searchbox', { name: 'Name' })).toHaveValue('example');

    await user.click(screen.getByRole('button', { name: 'Filter leeren' }));

    expect(onChange).toHaveBeenCalledWith('');
    expect(screen.getByRole('searchbox', { name: 'Name' })).toHaveValue('');
  });

  it('follows a value that changes from outside', () => {
    const { rerender } = renderWithRouter(<FilterField label="Name" value="a" onChange={vi.fn()} />);

    rerender(<FilterField label="Name" value="b" onChange={vi.fn()} />);

    expect(screen.getByRole('searchbox', { name: 'Name' })).toHaveValue('b');
  });
});
