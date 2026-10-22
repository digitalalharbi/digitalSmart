import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ArrowRight, ChartColumn, Link2, Megaphone, Palette, type LucideIcon } from 'lucide-react';
import { Logo } from '@/shared/ui/logo';
import { buttonVariants } from '@/shared/ui/button.variants';
import { cn } from '@/shared/lib/cn';
import { PlatformPills } from './PlatformPills';
import { PreferenceSwitches } from './PreferenceSwitches';
import { SamplePreview } from './SamplePreview';

const CARDS: Array<{ key: 'campaigns' | 'analytics' | 'reports' | 'creative'; icon: LucideIcon }> =
  [
    { key: 'campaigns', icon: Megaphone },
    { key: 'analytics', icon: ChartColumn },
    { key: 'reports', icon: Link2 },
    { key: 'creative', icon: Palette },
  ];

/*
 * Heights below which supporting detail steps aside, in order: the long sentence, then the cards' body text. The
 * headline, the platforms, the preview and the way in never do.
 */
const SHORT = '[@media(max-height:760px)]:hidden';
const VERY_SHORT = '[@media(max-height:680px)]:hidden';

/**
 * The one-screen way in: what digitalSmart is, which platforms it works with, a picture of the product, and where
 * to sign in. A visitor can only sign in — there is no self-service sign-up — so that is the only action offered.
 */
export function LandingPage() {
  const { t } = useTranslation();

  return (
    <div className="bg-canvas text-content flex min-h-dvh flex-col lg:h-dvh lg:overflow-hidden">
      <header className="border-line flex h-16 shrink-0 items-center justify-between gap-4 border-b px-4 sm:px-8">
        <div className="flex items-center gap-8">
          <Link to="/welcome" aria-label={t('brand.name')}>
            <Logo layout="horizontal" width={128} label={null} />
          </Link>
          <nav aria-label={t('entry.nav.label')} className="hidden items-center gap-6 md:flex">
            {(['features', 'platforms', 'reports'] as const).map((key) => (
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
          <Link to="/login" className={buttonVariants({ variant: 'primary', size: 'sm' })}>
            {t('auth.signIn')}
          </Link>
        </div>
      </header>

      <main className="grid min-h-0 flex-1 items-center gap-6 px-4 py-6 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:gap-10 lg:py-3">
        <div className="flex min-w-0 flex-col gap-4 lg:gap-[clamp(0.75rem,2.2vh,1.5rem)]">
          <span className="bg-accent-subtle text-content-brand w-fit rounded-full px-3 py-1 text-xs font-medium">
            {t('entry.label')}
          </span>
          <h1 className="text-content text-[clamp(1.875rem,min(3.4vw,6.4vh),3.25rem)] leading-[1.18] font-bold">
            <span className="block">{t('entry.hero.line1')}</span>
            <span className="block">{t('entry.hero.line2')}</span>
            <span className="text-content-brand block">{t('entry.hero.line3')}</span>
          </h1>
          <p className={cn('text-content-secondary max-w-xl text-base font-medium', VERY_SHORT)}>
            {t('entry.hero.tag')}
          </p>
          <p className={cn('text-content-secondary max-w-xl text-sm leading-relaxed', SHORT)}>
            {t('entry.hero.support')}
          </p>
          <PlatformPills />
          <div>
            <Link to="/login" className={buttonVariants({ variant: 'primary', size: 'lg' })}>
              {t('auth.signIn')}
              <ArrowRight aria-hidden className="rtl:-scale-x-100" />
            </Link>
          </div>
        </div>

        <SamplePreview compact />
      </main>

      <section
        id="features"
        aria-label={t('entry.nav.features')}
        className="grid shrink-0 gap-3 px-4 pt-2 pb-5 sm:grid-cols-2 sm:px-8 lg:grid-cols-4"
      >
        {CARDS.map(({ key, icon: Icon }) => (
          <article
            key={key}
            id={key === 'reports' ? 'reports' : undefined}
            className="bg-surface border-line flex items-start gap-3 rounded-2xl border p-3.5"
          >
            <span className="bg-accent-subtle text-content-brand grid size-9 shrink-0 place-items-center rounded-xl">
              <Icon aria-hidden className="size-[1.125rem]" />
            </span>
            <div className="min-w-0">
              <h2 className="text-content text-sm font-semibold">
                {t(`entry.cards.${key}.title`)}
              </h2>
              <p className={cn('text-content-secondary mt-0.5 text-xs leading-snug', SHORT)}>
                {t(`entry.cards.${key}.body`)}
              </p>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}
