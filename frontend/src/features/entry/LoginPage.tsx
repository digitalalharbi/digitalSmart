import { useTranslation } from 'react-i18next';
import { ShieldCheck } from 'lucide-react';
import { SignInForm } from '@/features/auth';
import { Logo } from '@/shared/ui/logo';
import { BrandSide } from './BrandSide';
import { PreferenceSwitches } from './PreferenceSwitches';

/** The sign-in screen: the form first (on a phone, before anything else), the brand beside it. */
export function LoginPage() {
  const { t } = useTranslation();

  return (
    <div className="bg-canvas text-content ds-safe-area grid min-h-dvh lg:h-dvh lg:grid-cols-2 lg:overflow-hidden">
      {/* On a phone the form comes first; the brand half follows it. */}
      <div className="order-first flex min-h-0 flex-col lg:order-last">
        <div className="flex justify-end px-4 pt-3 sm:px-8">
          <PreferenceSwitches />
        </div>
        <div className="flex flex-1 items-center justify-center px-4 py-6 sm:px-8">
          <div className="w-full max-w-sm">
            <div className="mb-6 flex flex-col gap-2">
              <Logo layout="horizontal" width={132} label={t('brand.name')} className="lg:hidden" />
              <p className="text-content-secondary text-sm">{t('entry.welcome')}</p>
              <h1 className="text-content text-xl font-semibold">{t('auth.signInTitle')}</h1>
            </div>

            <SignInForm autoFocus />

            <p className="text-content-tertiary mt-4 flex items-start gap-2 text-xs">
              <ShieldCheck aria-hidden className="mt-px size-3.5 shrink-0" />
              {t('entry.secure')}
            </p>
          </div>
        </div>
      </div>

      <BrandSide />
    </div>
  );
}
