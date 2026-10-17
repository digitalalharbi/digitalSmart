import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { buttonVariants } from '@/shared/ui/button.variants';

/** A page that is not there says so — in words, not by quietly calling itself the overview. */
export function NotFoundPage() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col items-start gap-3 py-12">
      <p className="text-content-tertiary font-mono text-sm">404</p>
      <h1 className="text-content text-2xl font-semibold">{t('notFoundPage.title')}</h1>
      <p className="text-content-secondary max-w-md text-sm">{t('notFoundPage.body')}</p>
      <Link to="/" className={buttonVariants({ variant: 'secondary', size: 'md' })}>
        {t('notFoundPage.home')}
      </Link>
    </div>
  );
}
