import { useTranslation } from 'react-i18next';
import { BellRing, FileCheck2, LayoutGrid, Palette, type LucideIcon } from 'lucide-react';
import { Logo } from '@/shared/ui/logo';

const BENEFITS: Array<{ key: 'platforms' | 'alerts' | 'reports' | 'creative'; icon: LucideIcon }> =
  [
    { key: 'platforms', icon: LayoutGrid },
    { key: 'alerts', icon: BellRing },
    { key: 'reports', icon: FileCheck2 },
    { key: 'creative', icon: Palette },
  ];

/** The brand half of the sign-in screen: who this is, what it does, in four short cards. */
export function BrandSide() {
  const { t } = useTranslation();

  return (
    <aside className="bg-surface border-line flex flex-col justify-center gap-[clamp(0.75rem,2.4vh,1.75rem)] border-t px-6 py-8 sm:px-12 lg:border-e lg:border-t-0 lg:py-6">
      <Logo layout="horizontal" width={150} label={t('brand.name')} className="hidden lg:block" />

      <span className="bg-accent-subtle text-content-brand w-fit rounded-full px-3 py-1 text-xs font-medium">
        {t('entry.label')}
      </span>

      <h2 className="text-content text-[clamp(1.75rem,min(3.2vw,6vh),3rem)] leading-[1.2] font-bold">
        <span className="block">{t('entry.loginHeadline.l1')}</span>
        <span className="block">{t('entry.loginHeadline.l2')}</span>
        <span className="text-content-brand block">{t('entry.loginHeadline.l3')}</span>
      </h2>

      <p className="text-content-secondary max-w-md text-sm leading-relaxed [@media(max-height:700px)]:hidden">
        {t('entry.supportLogin')}
      </p>

      <ul className="grid max-w-xl gap-3 sm:grid-cols-2">
        {BENEFITS.map(({ key, icon: Icon }) => (
          <li
            key={key}
            className="bg-surface-sunken border-line flex items-start gap-3 rounded-2xl border p-3.5"
          >
            <span className="bg-accent-subtle text-content-brand grid size-9 shrink-0 place-items-center rounded-xl">
              <Icon aria-hidden className="size-[1.125rem]" />
            </span>
            <span className="min-w-0">
              <span className="text-content block text-sm font-semibold">
                {t(`entry.promises.${key}.title`)}
              </span>
              <span className="text-content-secondary block text-xs leading-snug [@media(max-height:760px)]:hidden">
                {t(`entry.promises.${key}.line`)}
              </span>
            </span>
          </li>
        ))}
      </ul>
    </aside>
  );
}
