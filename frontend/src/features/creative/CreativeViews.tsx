import { useState } from 'react';
import { Film, GalleryHorizontal, Image as ImageIcon, Type } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { Badge } from '@/shared/ui/badge';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import type { AssetKindValue, CreativeCard, CreativeStateValue } from './types';

const ICON = { image: ImageIcon, video: Film, carousel: GalleryHorizontal, copy: Type } as const;

/** The shape a kind usually has when nobody has measured the file: tall for video, square for stills. */
const DEFAULT_RATIO: Record<AssetKindValue, number> = {
  image: 1,
  video: 9 / 16,
  carousel: 4 / 5,
  copy: 4 / 3,
};

const TONE: Record<CreativeStateValue, string> = {
  draft: 'bg-surface-sunken',
  briefed: 'bg-surface-sunken',
  in_production: 'bg-info-surface',
  in_review: 'bg-warning-surface',
  changes_requested: 'bg-danger-surface',
  approved: 'bg-success-surface',
  cancelled: 'bg-surface-sunken opacity-60',
};

const BADGE: Record<CreativeStateValue, 'neutral' | 'info' | 'warning' | 'danger' | 'success'> = {
  draft: 'neutral',
  briefed: 'neutral',
  in_production: 'info',
  in_review: 'warning',
  changes_requested: 'danger',
  approved: 'success',
  cancelled: 'neutral',
};

/**
 * A preview of the work, drawn at the shape of the file.
 *
 * Not the file itself: this product stores uploads as downloads and never serves them inline, which is a
 * deliberate security rule, so a picture here would be a pretence. What is real is the file's measured
 * shape — a 9:16 video reads as tall, a square post as square — plus its kind, platform and state. Where
 * nobody measured the file the shape falls back to what that kind usually is, and says nothing more.
 */
export function CreativeThumb({ card, className }: { card: CreativeCard; className?: string }) {
  const Icon = ICON[card.kind];
  const measured =
    card.preview === null ? null : card.preview.width_px / Math.max(1, card.preview.height_px);
  const ratio = Math.min(1.8, Math.max(0.5, measured ?? DEFAULT_RATIO[card.kind]));

  return (
    <div
      aria-hidden
      className={cn(
        'border-line-subtle flex h-36 items-center justify-center rounded-xl border',
        TONE[card.state],
        className,
      )}
    >
      <div
        className="bg-surface border-line text-content-tertiary relative flex max-h-28 max-w-[85%] items-center justify-center rounded-lg border shadow-xs"
        style={{
          aspectRatio: String(ratio),
          height: ratio >= 1 ? undefined : '7rem',
          width: ratio >= 1 ? '7rem' : undefined,
        }}
      >
        <Icon className="size-6" />
        {card.provider === null ? null : (
          <span className="bg-surface-sunken text-content-secondary absolute start-1 top-1 rounded px-1 text-[10px] font-medium uppercase">
            {card.provider}
          </span>
        )}
      </div>
    </div>
  );
}

function Due({ card }: { card: CreativeCard }) {
  const { t } = useTranslation();

  if (card.is_overdue) {
    return <Badge tone="danger">{t('creative.card.overdue')}</Badge>;
  }

  return card.days_until_due === null ? null : (
    <Badge tone="neutral">
      {card.days_until_due === 0
        ? t('creative.card.dueToday')
        : t('creative.card.due', { days: card.days_until_due })}
    </Badge>
  );
}

const GALLERY_PAGE = 12;

export function CreativeGallery({
  cards,
  selected,
  onSelect,
}: {
  cards: CreativeCard[];
  selected: string | null;
  onSelect: (id: string) => void;
}) {
  const { t } = useTranslation();
  const [visible, setVisible] = useState(GALLERY_PAGE);
  /* Twelve at a time, and always the chosen one: a selected card never folds away under the reader. */
  const shown = cards.filter((card, index) => index < visible || card.id === selected);

  return (
    <>
      <ul
        aria-label={t('creative.gallery')}
        className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
      >
        {shown.map((card) => (
          <li key={card.id}>
            <button
              type="button"
              onClick={() => onSelect(card.id)}
              aria-pressed={selected === card.id}
              className={cn(
                'bg-surface border-line-subtle flex w-full flex-col gap-3 rounded-2xl border p-3 text-start shadow-xs',
                'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                selected === card.id && 'border-accent ring-accent/25 ring-1',
              )}
            >
              <CreativeThumb card={card} />
              <span className="flex flex-col gap-1">
                <span className="text-content text-sm font-medium" dir="auto">
                  {card.title}
                </span>
                <span className="text-content-tertiary text-xs" dir="auto">
                  {[card.client_name, card.kind_label].join(' · ')}
                </span>
              </span>
              <span className="flex flex-wrap items-center gap-1.5">
                <Badge tone={BADGE[card.state]}>{card.state_label}</Badge>
                <Due card={card} />
                {card.placement === null ? (
                  <Badge tone="warning">{t('creative.card.noPlacement')}</Badge>
                ) : null}
              </span>
            </button>
          </li>
        ))}
      </ul>
      {cards.length > shown.length ? (
        <button
          type="button"
          onClick={() => setVisible((current) => current + GALLERY_PAGE)}
          className="bg-surface text-content-secondary hover:bg-surface-hover mt-4 rounded-full px-5 py-2 text-sm font-semibold"
        >
          {t('actionCenter.showMore', { count: cards.length - shown.length })}
        </button>
      ) : null}
    </>
  );
}

export function CreativeTable({
  cards,
  selected,
  onSelect,
}: {
  cards: CreativeCard[];
  selected: string | null;
  onSelect: (id: string) => void;
}) {
  const { t } = useTranslation();

  return (
    <DataTable label={t('creative.title')} minWidth="48rem">
      <TableHead>
        <TableRow>
          <TableHeader>{t('creative.col.request')}</TableHeader>
          <TableHeader>{t('creative.col.state')}</TableHeader>
          <TableHeader>{t('creative.col.platform')}</TableHeader>
          <TableHeader>{t('creative.col.owner')}</TableHeader>
          <TableHeader>{t('creative.col.due')}</TableHeader>
        </TableRow>
      </TableHead>
      <TableBody>
        {cards.map((card) => (
          <TableRow key={card.id}>
            <TableCell>
              <button
                type="button"
                onClick={() => onSelect(card.id)}
                aria-pressed={selected === card.id}
                className="text-start hover:underline"
                dir="auto"
              >
                <span className="text-content block text-sm font-medium">{card.title}</span>
                <span className="text-content-tertiary block text-xs">{card.client_name}</span>
              </button>
            </TableCell>
            <TableCell label={t('creative.col.state')}>
              <Badge tone={BADGE[card.state]}>{card.state_label}</Badge>
            </TableCell>
            <TableCell label={t('creative.col.platform')}>
              {[card.provider, card.placement].filter(Boolean).join(' · ') || '—'}
            </TableCell>
            <TableCell label={t('creative.col.owner')}>
              {card.owner_name ?? t('attention.unassigned')}
            </TableCell>
            <TableCell label={t('creative.col.due')}>
              <Due card={card} />
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </DataTable>
  );
}
