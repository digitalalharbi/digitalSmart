import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { useLinkAuditQuery } from './api';

/**
 * What is wrong with the links' campaign parameters, from the parameters alone.
 *
 * Silent while it loads, when it cannot be read and when nothing is wrong: this is a warning beside the list, and an
 * empty warning is furniture. A finding names the parameter it is about; a shared set names how many other live links
 * carry it, because that is the one an analytics property cannot undo — their visits arrive as one.
 */
export function UtmAuditPanel() {
  const { t } = useTranslation();
  const audit = useLinkAuditQuery(null);

  if (audit.data === undefined || audit.data.with_findings === 0) {
    return null;
  }

  return (
    <Alert tone="warning">
      <section aria-label={t('tracking.links.audit.title')} className="flex flex-col gap-2">
        <p className="font-medium">
          {t('tracking.links.audit.summary', {
            count: audit.data.with_findings,
            audited: audit.data.audited,
          })}
        </p>
        <ul className="flex flex-col gap-1.5 text-sm">
          {audit.data.links.map((link) => (
            <li key={link.id}>
              <span className="font-medium">{link.name}</span>
              {' — '}
              {link.findings
                .map((finding) =>
                  t(`tracking.links.audit.codes.${finding.code}`, {
                    parameters: finding.parameters.join(', '),
                    count: finding.shared_with.length,
                  }),
                )
                .join(' · ')}
            </li>
          ))}
        </ul>
      </section>
    </Alert>
  );
}
