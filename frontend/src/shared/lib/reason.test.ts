import { describe, expect, it } from 'vitest';
import { beforeAll } from 'vitest';
import { i18next as i18n, initI18n } from '@/shared/i18n';
import { reasonText } from './reason';

describe('reasonText', () => {
  beforeAll(async () => {
    await initI18n('en');
  });

  it('writes a coded finding in the reader’s language, with its values', async () => {
    await i18n.changeLanguage('ar');
    expect(
      reasonText(
        i18n.t,
        { code: 'CAMPAIGN_COST_RISE', params: { pct: 34 } },
        'Each result is costing 34% more',
      ),
    ).toBe('تكلفة النتيجة ارتفعت 34%.');

    await i18n.changeLanguage('en');
    expect(reasonText(i18n.t, { code: 'CAMPAIGN_COST_RISE', params: { pct: 34 } }, 'x')).toBe(
      'Cost per result up 34%.',
    );
  });

  it('falls back to the server’s sentence for an unknown code or none', () => {
    expect(reasonText(i18n.t, { code: 'SOMETHING_NEW', params: {} }, 'Server sentence')).toBe(
      'Server sentence',
    );
    expect(reasonText(i18n.t, null, 'Server sentence')).toBe('Server sentence');
  });
});
