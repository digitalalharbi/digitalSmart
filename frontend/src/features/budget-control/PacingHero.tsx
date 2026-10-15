import { useTranslation } from 'react-i18next';
import { formatMinor } from '@/shared/lib/format';

export type PacedRow = {
  currency: string;
  approved_minor: number;
  spent_minor: number | null;
  pacing: { expected_minor?: number; projected_minor: number | null } | null;
};

/**
 * Plan, pace and forecast on one scale, per currency.
 *
 * Over the budgets whose spend could be totalled — a budget with unreported spend has no pace to compare, and
 * counting it as zero would make the whole agency look under-spent. Four bars: what was approved, what should
 * have been spent by now, what has been, and where it ends if nothing changes.
 */
export function PacingHero({ rows, locale }: { rows: PacedRow[]; locale: string }) {
  const { t } = useTranslation();
  const byCurrency = new Map<
    string,
    { approved: number; expected: number; spent: number; projected: number; budgets: number }
  >();

  for (const row of rows) {
    if (row.spent_minor === null || row.pacing === null) {
      continue;
    }

    const entry = byCurrency.get(row.currency) ?? {
      approved: 0,
      expected: 0,
      spent: 0,
      projected: 0,
      budgets: 0,
    };
    entry.approved += row.approved_minor;
    entry.expected += row.pacing.expected_minor ?? 0;
    entry.spent += row.spent_minor;
    entry.projected += row.pacing.projected_minor ?? row.spent_minor;
    entry.budgets += 1;
    byCurrency.set(row.currency, entry);
  }

  if (byCurrency.size === 0) {
    return null;
  }

  return (
    <section aria-label={t('budgetControl.hero.title')} className="grid gap-3 lg:grid-cols-2">
      {[...byCurrency.entries()].map(([currency, v]) => {
        const scale = Math.max(1, v.approved, v.expected, v.spent, v.projected);
        const bars: Array<{ key: string; value: number; tone: string }> = [
          { key: 'planned', value: v.approved, tone: 'bg-line' },
          { key: 'expected', value: v.expected, tone: 'bg-content-tertiary' },
          { key: 'actual', value: v.spent, tone: 'bg-accent' },
          {
            key: 'forecast',
            value: v.projected,
            tone: v.projected > v.approved ? 'bg-danger' : 'bg-success',
          },
        ];
        const variance = v.spent - v.expected;

        return (
          <div
            key={currency}
            className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
          >
            <div className="flex items-baseline justify-between">
              <span className="ds-overline text-content-tertiary">
                {t('budgetControl.hero.title')} · {currency}
              </span>
              <span
                className={`ds-numeric text-sm ${variance > 0 ? 'text-warning' : 'text-content-secondary'}`}
              >
                {t('budgetControl.hero.variance')} {variance > 0 ? '+' : ''}
                {formatMinor(variance, currency, locale)}
              </span>
            </div>
            <ul className="flex flex-col gap-2.5">
              {bars.map((bar) => (
                <li key={bar.key} className="flex flex-col gap-1">
                  <div className="flex justify-between text-xs">
                    <span className="text-content-secondary">
                      {t(`budgetControl.hero.${bar.key}`)}
                    </span>
                    <span className="ds-numeric text-content">
                      {formatMinor(bar.value, currency, locale)}
                    </span>
                  </div>
                  <span
                    aria-hidden
                    className="bg-surface-sunken block h-2.5 overflow-hidden rounded-full"
                  >
                    <span
                      className={`block h-full rounded-full ${bar.tone}`}
                      style={{ width: `${String(Math.max(1, (bar.value / scale) * 100))}%` }}
                    />
                  </span>
                </li>
              ))}
            </ul>
          </div>
        );
      })}
    </section>
  );
}
