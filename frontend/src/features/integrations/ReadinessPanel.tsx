import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { Alert } from '@/shared/ui/alert';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';

type Level = 'campaign' | 'ad_group' | 'ad';
type Change = 'pause' | 'resume' | 'budget' | 'bid';
type Cell = {
  support: 'documented' | 'not_offered' | 'not_wired';
  state?: string;
  reached?: string[];
  next?: string;
  reference?: string | null;
  because?: string | null;
  field?: string;
};
type Readiness = {
  provider: string;
  label: string;
  credentials: 'none' | 'sandbox' | 'real';
  levels: Record<Level, { read: Cell; changes: Record<Change, Cell> }>;
  mcp: { official: boolean; reads: boolean; writes: string; source: string | null };
  checked_on: string;
};

const LEVELS: Level[] = ['campaign', 'ad_group', 'ad'];
const CHANGES: Change[] = ['pause', 'resume', 'budget', 'bid'];

const TONE: Record<Cell['support'], string> = {
  documented: 'bg-success-surface text-success',
  not_offered: 'bg-surface-sunken text-content-tertiary',
  not_wired: 'bg-warning-surface text-warning',
};

/**
 * What each platform's own documentation lets this product read and change, at each level — and how far each has
 * been proven. A documented change has run against sandbox credentials; the rungs above it need a real connection,
 * and none is claimed until a real round trip has been seen. A change not documented at a level is shown with the
 * platform's reason, never inferred from the level above.
 */
export function ReadinessPanel() {
  const { t } = useTranslation();
  const query = useQuery({
    queryKey: ['integrations', 'readiness'] as const,
    queryFn: async (): Promise<Readiness[]> =>
      (await api.get<{ data: Readiness[] }>('/integrations/readiness')).data.data,
    staleTime: 300_000,
  });

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h2 className="text-content text-base font-semibold">{t('readiness.title')}</h2>
        <p className="text-content-secondary text-sm">{t('readiness.lead')}</p>
      </div>
      {query.isError ? <Alert tone="danger">{t('readiness.failed')}</Alert> : null}
      {query.isPending ? (
        <Skeleton className="h-48 rounded-2xl" />
      ) : (
        <DataTable label={t('readiness.title')} minWidth="52rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('readiness.platform')}</TableHeader>
              <TableHeader>{t('readiness.level')}</TableHeader>
              {CHANGES.map((c) => (
                <TableHeader key={c}>{t(`readiness.change.${c}`)}</TableHeader>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {(query.data ?? []).flatMap((p) =>
              LEVELS.map((level, index) => (
                <TableRow key={`${p.provider}-${level}`}>
                  <TableCell label={t('readiness.platform')}>
                    {index === 0 ? (
                      <span className="flex flex-col">
                        <span className="text-content text-sm font-semibold">{p.label}</span>
                        <span className="text-content-tertiary text-xs">
                          {t(`readiness.credentials.${p.credentials}`)}
                          {p.mcp.official ? ` · ${t(`readiness.mcp.${p.mcp.writes}`)}` : ''}
                        </span>
                      </span>
                    ) : null}
                  </TableCell>
                  <TableCell label={t('readiness.level')}>
                    <span className="text-content-secondary text-xs">
                      {t(`automation.level.${level}_plural`)}
                    </span>
                  </TableCell>
                  {CHANGES.map((c) => {
                    const cell = p.levels[level].changes[c];
                    const detail =
                      cell.support === 'documented'
                        ? (cell.reference ?? '')
                        : cell.support === 'not_wired'
                          ? `${cell.field ?? ''} — ${cell.because ?? ''}`
                          : (cell.because ?? '');

                    return (
                      <TableCell key={c} label={t(`readiness.change.${c}`)}>
                        <span
                          title={detail}
                          className={cn(
                            'w-fit rounded-full px-2 py-0.5 text-xs font-semibold',
                            TONE[cell.support],
                          )}
                        >
                          {cell.support === 'documented'
                            ? t(`readiness.state.${cell.state ?? 'code_verified'}`)
                            : t(`readiness.support.${cell.support}`)}
                        </span>
                        <span className="sr-only">{detail}</span>
                      </TableCell>
                    );
                  })}
                </TableRow>
              )),
            )}
          </TableBody>
        </DataTable>
      )}
      <p className="text-content-tertiary text-xs">
        {t('readiness.ladder', { date: query.data?.[0]?.checked_on ?? '' })}
      </p>
    </div>
  );
}
