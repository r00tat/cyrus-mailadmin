import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DefinitionItem, DefinitionList, Monospace } from './DefinitionList';

describe('DefinitionList', () => {
  it('renders label–value pairs as a real description list', () => {
    const { container } = render(
      <DefinitionList>
        <DefinitionItem label="Akteur">root</DefinitionItem>
        <DefinitionItem label="Aktion">
          <Monospace>domain.create</Monospace>
        </DefinitionItem>
      </DefinitionList>,
    );

    const list = container.querySelector('dl');

    expect(list).not.toBeNull();
    expect([...list!.querySelectorAll('dt')].map((term) => term.textContent)).toEqual(['Akteur', 'Aktion']);
    expect([...list!.querySelectorAll('dd')].map((value) => value.textContent)).toEqual(['root', 'domain.create']);
  });
});
