import qrcode from 'qrcode-generator';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { buttonVariants } from '@/shared/ui/button.variants';

/**
 * The link's short address as a QR code, drawn here and never sent anywhere.
 *
 * It encodes the short address and nothing else, so a scan is an ordinary click of the link and is counted as one. Error
 * correction is medium: a printed code survives a crease, and a higher level would make the modules too small to scan at
 * the size a poster usually prints it. The quiet zone is four modules, as the standard asks. The image is black on white
 * whatever the theme, because a scanner reads contrast and not a design system.
 */
const QUIET = 4;

function modules(address: string): boolean[][] {
  const code = qrcode(0, 'M');

  code.addData(address);
  code.make();

  const count = code.getModuleCount();

  return Array.from({ length: count }, (_, row) =>
    Array.from({ length: count }, (_, column) => code.isDark(row, column)),
  );
}

function qrSvg(address: string): string {
  const grid = modules(address);
  const size = grid.length + QUIET * 2;
  const path = grid
    .flatMap((row, y) =>
      row.map((dark, x) => (dark ? `M${String(x + QUIET)} ${String(y + QUIET)}h1v1h-1z` : '')),
    )
    .join('');

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${String(size)} ${String(size)}" shape-rendering="crispEdges"><rect width="${String(size)}" height="${String(size)}" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
}

export function QrCode({ address, name }: { address: string; name: string }) {
  const { t } = useTranslation();
  const svg = useMemo(() => qrSvg(address), [address]);
  const url = useMemo(() => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`, [svg]);

  return (
    <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center">
      <img
        src={url}
        alt={t('tracking.links.qrAlt', { name })}
        width={160}
        height={160}
        className="size-40 shrink-0 rounded-lg border border-black/10"
      />
      <div className="flex flex-col gap-2">
        <p className="text-content-tertiary text-xs">{t('tracking.links.qrNote')}</p>
        <a
          href={url}
          download={`${name.replace(/[^\p{L}\p{N}]+/gu, '-')}-qr.svg`}
          className={cn(buttonVariants({ variant: 'secondary', size: 'sm' }), 'w-fit max-md:h-11')}
        >
          {t('tracking.links.qrDownload')}
        </a>
      </div>
    </div>
  );
}
