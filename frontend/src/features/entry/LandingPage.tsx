import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import {
  ArrowRight,
  BellRing,
  FileCheck2,
  LayoutGrid,
  Palette,
  type LucideIcon,
} from 'lucide-react';
import { Logo } from '@/shared/ui/logo';
import { buttonVariants } from '@/shared/ui/button.variants';
import { cn } from '@/shared/lib/cn';
import { useAccessOpenQuery } from './api';
import { PlatformPills } from './PlatformPills';
import { PreferenceSwitches } from './PreferenceSwitches';
import { SamplePreview } from './SamplePreview';

const CARDS: Array<{ key: 'platforms' | 'alerts' | 'reports' | 'creative'; icon: LucideIcon }> = [
  { key: 'platforms', icon: LayoutGrid },
  { key: 'alerts', icon: BellRing },
  { key: 'reports', icon: FileCheck2 },
  { key: 'creative', icon: Palette },
];

/*
 * Heights below which supporting detail steps aside, in order: the supporting sentence and the cards' body text, then
 * the sentence under the headline. The headline, the platforms, the preview and the way in never do.
 */
const SHORT = '[@media(max-height:760px)]:hidden';
const VERY_SHORT = '[@media(max-height:680px)]:hidden';

/**
 * The one-screen way in: what digitalSmart is, which platforms it supports, a picture of the product and where to sign
 * in. Somebody without an account can ask for one where this installation takes requests — a request, not a
 * registration — so the page offers exactly two actions: sign in, and ask for an account.
 *
 * On a desktop it is one screen. On a phone it reads top to bottom in the order a visitor needs: the headline, the
 * platforms, the way in centred under them, then the picture of the product and what it does.
 */
export function LandingPage() {
  const { t } = useTranslation();
  const open = useAccessOpenQuery();
  const canAsk = open.data === true;

  return (
    <div className="bg-canvas text-content ds-safe-area flex min-h-dvh flex-col lg:h-dvh lg:overflow-hidden">
      <header className="border-line flex h-16 shrink-0 items-center justify-between gap-4 border-b px-4 sm:px-8">
        <div className="flex items-center gap-8">
          <Link
            to="/"
            aria-label={t('brand.name')}
            className="flex items-center pointer-coarse:min-h-11 pointer-coarse:min-w-11"
          >
            {/* The mark alone on the narrowest phones: the full lockup, two switches and the way in do not fit in 320px. */}
            <Logo layout="mark" width={32} label={null} className="min-[420px]:hidden" />
            <Logo layout="horizontal" width={128} label={null} className="max-[419px]:hidden" />
          </Link>
          <nav aria-label={t('entry.nav.label')} className="hidden items-center gap-6 md:flex">
            {(['idea', 'platforms', 'why'] as const).map((key) => (
              <a
                key={key}
                href={`#${key}`}
                className="text-content-secondary hover:text-content rounded-full text-sm font-medium"
              >
                {t(`entry.nav.${key}`)}
              </a>
            ))}
          </nav>
        </div>
        <div className="flex items-center gap-2">
          <PreferenceSwitches />
          {canAsk ? (
            <Link
              to="/request-access"
              className={cn(
                buttonVariants({ variant: 'secondary', size: 'sm' }),
                'max-sm:hidden pointer-coarse:h-11',
              )}
            >
              {t('entry.ask')}
            </Link>
          ) : null}
          <Link
            to="/login"
            className={cn(
              buttonVariants({ variant: 'primary', size: 'sm' }),
              'pointer-coarse:h-11',
            )}
          >
            {t('auth.signIn')}
          </Link>
        </div>
      </header>

      <main className="grid min-h-0 flex-1 items-center gap-6 px-4 py-6 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:gap-10 lg:py-3">
        {/* Centred on a phone, where the column is the whole screen; set to the start beside the preview. */}
        <div
          id="idea"
          className="flex min-w-0 flex-col items-center gap-4 text-center lg:items-start lg:gap-[clamp(0.75rem,2.2vh,1.5rem)] lg:text-start"
        >
          <span className="bg-accent-subtle text-content-brand w-fit rounded-full px-3 py-1 text-xs font-medium">
            {t('entry.label')}
          </span>
          <h1 className="text-content text-[clamp(1.75rem,min(3vw,5.6vh),2.75rem)] leading-[1.2] font-bold">
            <span className="block">{t('entry.hero.line1')}</span>
            <span className="text-content-brand block">{t('entry.hero.line2')}</span>
          </h1>
          <p className={cn('text-content-secondary max-w-xl text-base font-medium', VERY_SHORT)}>
            {t('entry.hero.sub')}
          </p>
          <p className={cn('text-content-secondary max-w-xl text-sm leading-relaxed', SHORT)}>
            {t('entry.hero.support')}
          </p>
          <PlatformPills centered="max-lg" />
          <div className="flex w-full flex-wrap justify-center gap-2.5 lg:w-auto lg:justify-start">
            <Link
              to="/login"
              className={cn(buttonVariants({ variant: 'primary', size: 'lg' }), 'max-sm:w-full')}
            >
              {t('auth.signIn')}
              <ArrowRight aria-hidden className="rtl:-scale-x-100" />
            </Link>
            {canAsk ? (
              <Link
                to="/request-access"
                className={cn(
                  buttonVariants({ variant: 'secondary', size: 'lg' }),
                  'max-sm:w-full',
                )}
              >
                {t('entry.ask')}
              </Link>
            ) : null}
          </div>
          {canAsk ? (
            <p className={cn('text-content-tertiary text-xs', SHORT)}>
              {t('entry.start.reviewed')}
            </p>
          ) : null}
        </div>

        <SamplePreview compact />
      </main>

      <section
        id="why"
        aria-label={t('entry.nav.why')}
        className="grid shrink-0 gap-3 px-4 pt-2 pb-5 sm:grid-cols-2 sm:px-8 lg:grid-cols-4"
      >
        {CARDS.map(({ key, icon: Icon }) => (
          <article
            key={key}
            className="bg-surface border-line flex items-start gap-3 rounded-2xl border p-3.5"
          >
            <span className="bg-accent-subtle text-content-brand grid size-9 shrink-0 place-items-center rounded-xl">
              <Icon aria-hidden className="size-[1.125rem]" />
            </span>
            <div className="min-w-0">
              <h2 className="text-content text-sm font-semibold">
                {t(`entry.promises.${key}.title`)}
              </h2>
              <p className={cn('text-content-secondary mt-0.5 text-xs leading-snug', SHORT)}>
                {t(`entry.promises.${key}.line`)}
              </p>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}
