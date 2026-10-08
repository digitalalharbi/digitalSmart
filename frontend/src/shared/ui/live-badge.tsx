import { RadioTower, WifiOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Whether this page is being pushed to.
 *
 * Said plainly, either way. Realtime being off is not a failure — every move is written to the database and
 * arrives on the next read — but a reader who believes the page is live and sees nothing move concludes the
 * product is broken, and then stops trusting the figures on it.
 *
 * The icon is never the only channel: the words beside it say the same thing.
 */
export function LiveBadge({ isLive }: { isLive: boolean }) {
  const { t } = useTranslation();

  return (
    <p className="text-content-tertiary flex items-center gap-1.5 text-xs">
      {isLive ? (
        <>
          <RadioTower aria-hidden className="size-3.5" />
          {t('realtime.live')}
        </>
      ) : (
        <>
          <WifiOff aria-hidden className="size-3.5" />
          {t('realtime.off')}
        </>
      )}
    </p>
  );
}
