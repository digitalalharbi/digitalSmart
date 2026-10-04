import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { buttonVariants } from '@/shared/ui/button.variants';

export function NotFoundPage() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col items-start gap-4 py-12">
      <p className="text-content-tertiary font-mono text-sm">404</p>
      <h1 className="text-content text-2xl font-semibold tracking-[-0.02em]">
        {t('nav.overview')}
      </h1>
      <Link to="/" className={buttonVariants({ variant: 'secondary', size: 'md' })}>
        {t('nav.overview')}
      </Link>
    </div>
  );
}
