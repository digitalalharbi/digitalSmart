import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import {
  FileChartColumn,
  Megaphone,
  Palette,
  Radar,
  Wallet,
  ListChecks,
  Inbox,
  UserRoundSearch,
} from 'lucide-react';
import type { AttentionItem } from '@/features/attention';
import { buttonVariants } from '@/shared/ui/button.variants';
import { DecisionCard, type Tone } from '@/shared/ui/decision-card';
import { reasonText } from '@/shared/lib/reason';
import { itemType, type ActionType } from './kinds';

const ICON: Record<string, React.ReactNode> = {
  budget: <Wallet />,
  campaign: <Megaphone />,
  tracking: <Radar />,
  task: <ListChecks />,
  request: <Inbox />,
  creative: <Palette />,
  lead: <UserRoundSearch />,
  report: <FileChartColumn />,
};

/* Where a grouped agent card leads: the place the things it counts are worked on. */
const AGENT_PATH: Record<ActionType, string> = {
  budget: '/budget-calculator',
  campaign: '/campaigns',
  creative: '/campaigns',
  lead: '/leads',
  report: '/reports#briefs',
  tracking: '/tracking',
  task: '/projects',
  request: '/client-requests',
};

const AGENT_OF: Record<ActionType, string> = {
  budget: 'finance',
  campaign: 'analyst',
  creative: 'creative',
  lead: 'leads',
  report: 'reports',
  tracking: 'analyst',
  task: 'operations',
  request: 'operations',
};

const TONE: Record<AttentionItem['severity'], Tone> = {
  critical: 'critical',
  needs_attention: 'attention',
  watch: 'watch',
};

/**
 * Something the system found, as a card with one way forward.
 *
 * The sentence is the module's own, written once; the card adds nothing to it. Where the finding has a place to act
 * the button goes straight there — the same page its count is taken from — so there is no second list to reconcile.
 */
export function AttentionDecision({ item }: { item: AttentionItem }) {
  const { t } = useTranslation();
  const type: ActionType = itemType(item) ?? 'task';

  return (
    <DecisionCard
      icon={ICON[type] ?? <Megaphone />}
      type={t(`actionCenter.type.${type}`)}
      tone={TONE[item.severity]}
      scope={[item.client_name, item.project_name].filter(Boolean).join(' · ')}
      headline={reasonText(t, item.reason, item.message)}
      byline={
        item.stream === 'agents'
          ? t('actionCenter.preparedBy', { agent: t(`actionCenter.agents.${AGENT_OF[type]}`) })
          : undefined
      }
      details={undefined}
      actions={
        <Link
          to={item.stream === 'agents' ? AGENT_PATH[type] : item.action.path}
          className={buttonVariants({ variant: 'primary' })}
        >
          {t('actionCenter.open')}
        </Link>
      }
    />
  );
}
