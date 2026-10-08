import { useTranslation } from 'react-i18next';
import { ScrollRegion } from '@/shared/ui/scroll-region';

type ProposalTableProps = {
  proposal: Record<string, unknown>;
  /** The first twelve characters of the hash, shown so a decision can be quoted in a message. */
  fingerprint: string;
};

/**
 * Exactly what is being proposed, as a table.
 *
 * A table rather than prose, and **never a summary**. Whatever a requester wrote is what a decider has to read:
 * this product deliberately does not model a platform's targeting schema in order to describe a change to it,
 * because a half-modelled schema quietly drops the field that mattered and the approval would then be of
 * something nobody stated.
 *
 * The fingerprint is shown beside it. It is the short form of the hash a decision sends back, and it is here so
 * that "I approved the version ending 4f2a" is something a person can say.
 */
export function ProposalTable({ proposal, fingerprint }: ProposalTableProps) {
  const { t } = useTranslation();
  const entries = Object.entries(proposal);

  return (
    <section className="flex flex-col gap-2" aria-label={t('approvals.proposal.title')}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-content text-sm font-semibold">{t('approvals.proposal.title')}</h3>
        <span className="text-content-tertiary font-mono text-xs">
          {t('approvals.proposal.fingerprint', { hash: fingerprint })}
        </span>
      </div>

      {entries.length === 0 ? (
        <p className="text-content-tertiary text-sm">{t('approvals.proposal.empty')}</p>
      ) : (
        <ScrollRegion label={t('approvals.proposal.caption')}>
          <table className="w-full border-collapse text-sm">
            <caption className="sr-only">{t('approvals.proposal.caption')}</caption>
            <thead>
              <tr className="border-line-subtle border-b">
                <th
                  scope="col"
                  className="text-content-tertiary py-2 text-start text-xs font-medium"
                >
                  {t('approvals.proposal.field')}
                </th>
                <th
                  scope="col"
                  className="text-content-tertiary py-2 text-start text-xs font-medium"
                >
                  {t('approvals.proposal.value')}
                </th>
              </tr>
            </thead>
            <tbody>
              {entries.map(([key, value]) => (
                <tr key={key} className="border-line-subtle border-b last:border-0">
                  <th
                    scope="row"
                    className="text-content-secondary py-2 pe-4 text-start font-normal"
                  >
                    {key}
                  </th>
                  <td className="text-content py-2 font-mono text-xs break-all">
                    {/*
                      Rendered as JSON rather than coerced to a string. A nested object printed as
                      "[object Object]" is a field a decider cannot read, which makes the approval worthless
                      for exactly the proposals that needed the most care.
                    */}
                    {typeof value === 'string' ? value : JSON.stringify(value)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ScrollRegion>
      )}
    </section>
  );
}
