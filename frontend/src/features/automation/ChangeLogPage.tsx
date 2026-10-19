import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { useChangeLog } from './api';
import { when } from './ruleText';

/** Change Log — who changed which rule, what, and when. Words, not payloads. */
export function ChangeLogPage() {
  const { t, i18n } = useTranslation();
  const [days, setDays] = useState(30);
  const changes = useChangeLog(days);

  return (
    <div className="ds-page">
      <PageHeader title={t('automation.changeLog')} />
      <select
        aria-label={t('automation.filter.period')}
        value={days}
        onChange={(e) => setDays(Number(e.target.value))}
        className="bg-surface text-content-secondary h-10 w-fit rounded-full px-3 text-sm shadow-xs"
      >
        {[7, 30, 90].map((d) => (
          <option key={d} value={d}>
            {t('automation.lastDays', { count: d })}
          </option>
        ))}
      </select>
      {changes.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}
      {changes.isPending ? (
        <Skeleton className="h-48 rounded-3xl" />
      ) : (changes.data ?? []).length === 0 ? (
        <p className="text-content-tertiary bg-surface rounded-2xl p-6 text-sm">
          {t('automation.noChanges')}
        </p>
      ) : (
        <DataTable label={t('automation.changeLog')} minWidth="40rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('automation.ccol.when')}</TableHeader>
              <TableHeader>{t('automation.ccol.who')}</TableHeader>
              <TableHeader>{t('automation.ccol.rule')}</TableHeader>
              <TableHeader>{t('automation.ccol.what')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {(changes.data ?? []).map((c, i) => (
              <TableRow key={`${c.rule_id}-${c.at}-${String(i)}`}>
                <TableCell>
                  <span className="text-content-tertiary text-xs">{when(c.at, i18n.language)}</span>
                </TableCell>
                <TableCell>
                  <span className="text-content text-sm" dir="auto">
                    {c.who ?? t('automation.someone')}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="text-content text-sm" dir="auto">
                    {c.rule ?? t('automation.archivedRule')}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="flex flex-wrap gap-1">
                    {c.kinds.map((k) => (
                      <span
                        key={k}
                        className="bg-surface-sunken text-content-secondary rounded-full px-2.5 py-0.5 text-xs font-medium"
                      >
                        {t(`automation.change.${k}`, { defaultValue: k })}
                      </span>
                    ))}
                  </span>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </div>
  );
}
