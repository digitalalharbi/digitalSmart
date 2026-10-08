import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProviderLogo } from './ProviderLogo';
import type { ProviderSlug } from './types';

/*
|--------------------------------------------------------------------------
| The provider chip
|--------------------------------------------------------------------------
|
| This file exists because of one defect, and the defect is worth recording.
|
| The **server** owns the provider list. Adding one to it put a slug on the integrations page that this
| component's colour map had no entry for, so the lookup was `undefined`, reading a property off it threw, and the
| whole page became an error boundary. The slug union is typed — which is exactly why it was trusted: a server
| string cast into a union is not checked by anything at runtime.
|
| A new provider missing its colour should be a cosmetic gap somebody notices. It should never be an outage.
|
*/

describe('a provider chip', function () {
  it('draws the mark a known provider was given', () => {
    render(<ProviderLogo provider="meta" />);

    expect(screen.getByText('M')).toBeInTheDocument();
  });

  it('draws a slug it has never seen rather than throwing', () => {
    /*
     * Cast deliberately, because that is exactly what happens in production: the API's string arrives typed as
     * this union whether or not the build has heard of it.
     */
    render(<ProviderLogo provider={'pipedrive' as ProviderSlug} />);

    expect(screen.getByText('P')).toBeInTheDocument();
  });

  it('builds initials from a multi-part slug', () => {
    render(<ProviderLogo provider={'zoho_crm' as ProviderSlug} />);

    // `zoho_crm` reads as "ZC" — at least the right provider, which a blank chip would not be.
    expect(screen.getByText('ZC')).toBeInTheDocument();
  });

  it('is hidden from a screen reader, because the name is beside it', () => {
    const { container } = render(<ProviderLogo provider="hubspot" />);

    /*
     * Decoration, and that is why white initials on a brand hue are acceptable here: the provider's name is in
     * text next to the chip, so the chip repeats rather than carries the information.
     */
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
  });
});
