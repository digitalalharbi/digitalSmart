import { Image as ImageIcon, Play, Video } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import type { CreativeRow } from './types';

/**
 * What a creative looks like — or an honest statement that it cannot be shown.
 *
 * An image is drawn only when the server vouches for the address (a real https or app-hosted file). Otherwise the tile
 * is a neutral placeholder that says why: a sandbox fixture or a platform that gave no preview. Artwork is never
 * invented to fill the space. Provider images are fetched with no referrer, and one that fails to load falls back to
 * the same placeholder rather than a broken-image glyph.
 */
export function CreativeMediaTile({
  media,
  format,
  label,
  className,
}: {
  media: CreativeRow['media'];
  format: string;
  label: string;
  className?: string | undefined;
}) {
  const { t } = useTranslation();
  const [failed, setFailed] = useState(false);
  const showImage = media.state === 'available' && media.url !== null && !failed;
  const isVideo = media.kind === 'video' || format === 'video';
  const Placeholder = isVideo ? Video : ImageIcon;

  return (
    <div
      className={cn(
        'bg-surface-sunken relative grid aspect-video place-items-center overflow-hidden',
        className,
      )}
    >
      {showImage ? (
        <>
          <img
            src={media.url ?? undefined}
            alt={label}
            className="size-full object-cover"
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => setFailed(true)}
          />
          {isVideo ? (
            <span
              aria-hidden
              className="bg-surface/90 text-content absolute inset-0 m-auto grid size-9 place-items-center rounded-full"
            >
              <Play className="size-4" />
            </span>
          ) : null}
        </>
      ) : (
        <div className="text-content-tertiary flex flex-col items-center gap-1 px-3 text-center">
          <Placeholder aria-hidden className="size-7" />
          <span className="text-xs">
            {t(media.state === 'fixture' ? 'creatives.media.fixture' : 'creatives.media.none')}
          </span>
        </div>
      )}
    </div>
  );
}
