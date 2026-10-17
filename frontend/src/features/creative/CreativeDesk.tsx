import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import type { CreativeBoard, CreativeCard } from './types';

/**
 * The creative lead's morning, read off the board the page already holds.
 *
 * Nothing here is a second query: workload per person, what is late, what waits on review, what is ready to
 * publish, the deadlines of the week and the latest approvals are all facts about the cards in hand, so they
 * cannot disagree with the gallery beneath them.
 */
export function CreativeDesk({ board }: { board: CreativeBoard }) {
  const { t, i18n } = useTranslation();
  const cards = board.columns.flatMap((column) => column.requests);
  const open = cards.filter((card) => card.state !== 'approved' && card.state !== 'cancelled');
  const ready = cards.filter((card) => card.state === 'approved');
  const dueSoon = open
    .filter(
      (card) =>
        card.days_until_due !== null && card.days_until_due >= 0 && card.days_until_due <= 7,
    )
    .sort((a, b) => (a.days_until_due ?? 0) - (b.days_until_due ?? 0))
    .slice(0, 5);
  const recent = [...ready]
    .sort((a, b) => (b.state_changed_at ?? '').localeCompare(a.state_changed_at ?? ''))
    .slice(0, 5);

  const byOwner = new Map<string, { open: number; overdue: number }>();

  for (const card of open) {
    const key = card.owner_name ?? '';
    const entry = byOwner.get(key) ?? { open: 0, overdue: 0 };
    entry.open += 1;
    entry.overdue += card.is_overdue ? 1 : 0;
    byOwner.set(key, entry);
  }

  const people = [...byOwner.entries()].sort((a, b) => b[1].open - a[1].open).slice(0, 5);
  const number = new Intl.NumberFormat(i18n.language);
  const date = new Intl.DateTimeFormat(i18n.language, { day: 'numeric', month: 'short' });

  return (
    <section aria-label={t('creative.desk.label')} className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {(
          [
            ['open', board.open],
            ['overdue', board.overdue],
            ['review', board.waiting_on_review],
            ['changes', cards.filter((card) => card.state === 'changes_requested').length],
            ['ready', ready.length],
          ] as const
        ).map(([key, value]) => (
          <div
            key={key}
            className="bg-surface border-line-subtle flex flex-col gap-1 rounded-2xl border p-4 shadow-xs"
          >
            <span className="ds-overline text-content-tertiary">{t(`creative.desk.${key}`)}</span>
            <span className="ds-figure text-content text-3xl">{number.format(value)}</span>
          </div>
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <DeskList title={t('creative.desk.workload')} empty={t('creative.desk.noWork')}>
          {people.map(([name, load]) => (
            <li key={name} className="flex items-center justify-between gap-3 py-1.5 text-sm">
              <span className="min-w-0 truncate" dir="auto">
                {name === '' ? t('attention.unassigned') : name}
              </span>
              <span className="text-content-secondary ds-numeric shrink-0">
                <span>{number.format(load.open)}</span>
                {load.overdue > 0 ? (
                  <span className="text-danger ms-2">
                    · {t('creative.desk.late', { count: load.overdue })}
                  </span>
                ) : null}
              </span>
            </li>
          ))}
        </DeskList>
        <DeskList title={t('creative.desk.deadlines')} empty={t('creative.desk.noDeadlines')}>
          {dueSoon.map((card) => (
            <DeskRow key={card.id} card={card} note={deadline(card, t, date)} />
          ))}
        </DeskList>
        <DeskList title={t('creative.desk.approved')} empty={t('creative.desk.noApproved')}>
          {recent.map((card) => (
            <DeskRow
              key={card.id}
              card={card}
              note={
                card.state_changed_at === null ? '' : date.format(new Date(card.state_changed_at))
              }
            />
          ))}
        </DeskList>
      </div>
    </section>
  );
}

function deadline(card: CreativeCard, t: TFunction, date: Intl.DateTimeFormat) {
  return card.due_at === null
    ? ''
    : `${date.format(new Date(card.due_at))} · ${t('creative.card.due', { days: card.days_until_due ?? 0 })}`;
}

function DeskList({
  title,
  empty,
  children,
}: {
  title: string;
  empty: string;
  children: React.ReactNode;
}) {
  const items = Array.isArray(children) ? children : [children];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <p className="text-content-tertiary text-sm">{empty}</p>
        ) : (
          <ul className="divide-line-subtle flex flex-col divide-y">{children}</ul>
        )}
      </CardContent>
    </Card>
  );
}

function DeskRow({ card, note }: { card: CreativeCard; note: string }) {
  return (
    <li className="flex items-center justify-between gap-3 py-1.5 text-sm">
      <span className="min-w-0 truncate" dir="auto">
        {card.title}
      </span>
      <span className="text-content-tertiary shrink-0 text-xs">{note}</span>
    </li>
  );
}
