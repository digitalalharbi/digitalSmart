import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { FileChartColumn, FolderKanban, Palette, UserRoundSearch } from 'lucide-react';
import { buttonVariants } from '@/shared/ui/button.variants';
import { BeforeAfter, DecisionCard, type Tone } from '@/shared/ui/decision-card';
import { formatNumber } from '@/shared/lib/format';
import { reasonText } from '@/shared/lib/reason';
import type { AgentFinding } from './api';
import { num, preview, record, str } from './evidence';

const TONE: Record<AgentFinding['severity'], Tone> = {
  critical: 'critical',
  needs_attention: 'attention',
  watch: 'watch',
};

const pct = (value: number | null, locale: string) =>
  value === null ? '—' : `${formatNumber(Math.round(value * 10000) / 100, locale)}%`;

/**
 * One agent finding as a decision card: the picture first, the agent that saw it, and one way forward.
 *
 * Tiring creative shows the ad itself beside the strongest ad in the same campaign; a lead decline shows the two
 * fortnights; a project pile lists its three most pressing reasons. Nothing here acts — the buttons open the task,
 * the campaign or the project where a person does.
 */
export function AgentFindingDecision({ finding }: { finding: AgentFinding }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const e = finding.evidence;
  const scope = [finding.client?.name, finding.project?.name].filter(Boolean).join(' · ');
  const byline = t('actionCenter.preparedBy', { agent: t(`actionCenter.agents.${finding.agent}`) });
  const headline = reasonText(t, finding.reason, finding.message);
  const code = finding.reason.code;

  if (code === 'CREATIVE_FATIGUE') {
    const winner = record(e['winner']);
    const now = num(e['ctr_now']);
    const before = num(e['ctr_before']);
    const campaign = str(e['campaign_id']);

    return (
      <DecisionCard
        icon={<Palette />}
        type={t('actionCenter.type.creative')}
        tone={TONE[finding.severity]}
        scope={scope}
        headline={headline}
        byline={byline}
        visual={
          <div className="flex flex-col gap-3">
            <div className="flex items-center gap-3">
              <Thumb src={preview(e['thumbnail_url'])} label={finding.subject.label} />
              <span className="text-content min-w-0 flex-1 truncate text-sm" dir="auto">
                {finding.subject.label}
              </span>
              {winner === null ? null : (
                <span className="flex items-center gap-2">
                  <span className="text-content-tertiary text-xs">{t('agentFindings.winner')}</span>
                  <Thumb src={preview(winner['thumbnail_url'])} label={str(winner['name']) ?? ''} />
                </span>
              )}
            </div>
            <BeforeAfter
              before={pct(before, locale)}
              after={pct(now, locale)}
              difference={`−${formatNumber(Number(finding.reason.params['drop'] ?? 0), locale)}%`}
              beforeLabel={t('agentFindings.ctrBefore')}
              afterLabel={t('agentFindings.ctrNow')}
              positive={false}
            />
          </div>
        }
        actions={
          <>
            <Link to="/tasks" className={buttonVariants({ variant: 'primary' })}>
              {t('agentFindings.openBrief')}
            </Link>
            {campaign === null ? null : (
              <Link
                to={`/campaigns/${campaign}`}
                className={buttonVariants({ variant: 'secondary' })}
              >
                {t('agentFindings.openCampaign')}
              </Link>
            )}
          </>
        }
      />
    );
  }

  if (code === 'LEADS_DECLINE' || code === 'LEADS_LOW_QUALITY') {
    const leads = num(e['leads']);
    const qualified = num(e['qualified']);
    const won = num(e['won']);
    const before = num(e['leads_before']);

    return (
      <DecisionCard
        icon={<UserRoundSearch />}
        type={t('actionCenter.type.lead')}
        tone={TONE[finding.severity]}
        scope={scope}
        headline={headline}
        byline={byline}
        visual={
          <div className="flex flex-col gap-3">
            {code === 'LEADS_DECLINE' && before !== null && leads !== null ? (
              <BeforeAfter
                before={formatNumber(before, locale)}
                after={formatNumber(leads, locale)}
                difference={`−${formatNumber(Number(finding.reason.params['drop'] ?? 0), locale)}%`}
                beforeLabel={t('agentFindings.fortnightBefore')}
                afterLabel={t('agentFindings.lastFortnight')}
                positive={false}
              />
            ) : null}
            {/* Three counts, never added: a lead, a qualified lead and a sale are different things. */}
            <dl className="grid grid-cols-3 gap-2 text-center">
              {(
                [
                  ['leads', leads],
                  ['qualified', qualified],
                  ['won', won],
                ] as const
              ).map(([key, value]) => (
                <div key={key} className="bg-surface-sunken rounded-xl p-2">
                  <dt className="text-content-tertiary text-xs">{t(`agentFindings.${key}`)}</dt>
                  <dd className="ds-numeric text-content text-lg font-semibold">
                    {value === null ? '—' : formatNumber(value, locale)}
                  </dd>
                </div>
              ))}
            </dl>
          </div>
        }
        actions={
          <Link to="/leads" className={buttonVariants({ variant: 'primary' })}>
            {t('agentFindings.openLeads')}
          </Link>
        }
      />
    );
  }

  if (code === 'PROJECT_NEEDS_ACTION') {
    const top = Array.isArray(e['top'])
      ? (e['top'] as unknown[]).map(record).filter((r) => r !== null)
      : [];

    return (
      <DecisionCard
        icon={<FolderKanban />}
        type={t('actionCenter.type.task')}
        tone={TONE[finding.severity]}
        scope={finding.subject.label}
        headline={headline}
        byline={byline}
        visual={
          <ol className="flex flex-col gap-1.5">
            {top.map((item, index) => {
              const code = str(item['reason_code']);
              const params = record(item['reason_params']) as Record<
                string,
                string | number
              > | null;
              const reason = code === null ? null : { code, params: params ?? {} };

              return (
                <li
                  key={index}
                  className="bg-surface-sunken flex gap-2 rounded-xl px-3 py-2 text-sm"
                >
                  <span className="ds-numeric text-content-tertiary">
                    {formatNumber(index + 1, locale)}
                  </span>
                  <span className="text-content min-w-0 flex-1" dir="auto">
                    {reasonText(t, reason, str(item['message']) ?? '')}
                  </span>
                </li>
              );
            })}
          </ol>
        }
        actions={
          <Link
            to={`/projects/${finding.subject.id}`}
            className={buttonVariants({ variant: 'primary' })}
          >
            {t('agentFindings.openProject')}
          </Link>
        }
      />
    );
  }

  return (
    <DecisionCard
      icon={<FileChartColumn />}
      type={t('actionCenter.type.report')}
      tone={TONE[finding.severity]}
      scope={scope}
      headline={headline}
      byline={byline}
      actions={
        <Link to="/reports#briefs" className={buttonVariants({ variant: 'primary' })}>
          {t('actionCenter.open')}
        </Link>
      }
    />
  );
}

function Thumb({ src, label }: { src: string | null; label: string }) {
  return (
    <span className="bg-surface-active grid size-14 shrink-0 place-items-center overflow-hidden rounded-xl">
      {src === null ? (
        <Palette aria-hidden className="text-content-tertiary size-5" />
      ) : (
        <img src={src} alt={label} className="size-full object-cover" loading="lazy" />
      )}
    </span>
  );
}
