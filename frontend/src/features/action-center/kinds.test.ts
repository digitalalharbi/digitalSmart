import { describe, expect, it } from 'vitest';
import type { AttentionItem } from '@/features/attention';
import { itemType } from './kinds';

describe('itemType', () => {
  it('sorts an agent item by what it is about, not by who found it', () => {
    const item = (code: string): AttentionItem =>
      ({ stream: 'agents', reason: { code, params: {} } }) as unknown as AttentionItem;

    expect(itemType(item('CREATIVE_FATIGUE'))).toBe('creative');
    expect(itemType(item('LEADS_LOW_QUALITY'))).toBe('lead');
    expect(itemType(item('REPORTS_READY'))).toBe('report');
    expect(itemType(item('PROJECT_NEEDS_ACTION'))).toBe('task');
  });
});
