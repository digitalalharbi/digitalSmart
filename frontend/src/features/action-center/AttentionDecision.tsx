import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Megaphone, Radar, Wallet, ListChecks, Inbox } from 'lucide-react';
import type { AttentionItem } from '@/features/attention';
import { buttonVariants } from '@/shared/ui/button.variants';
import { DecisionCard, type Tone } from '@/shared/ui/decision-card';
import { STREAM_TYPE, type ActionType } from './kinds';

const ICON: Record<string, React.ReactNode> = {
  budget: <Wallet />,
  campaign: <Megaphone />,
  tracking: <Radar />,
  task: <ListChecks />,
  request: <Inbox />,
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
  const type: ActionType = STREAM_TYPE[item.stream] ?? 'task';

  return (
    <DecisionCard
      icon={ICON[type] ?? <Megaphone />}
      type={t(`actionCenter.type.${type}`)}
      tone={TONE[item.severity]}
      scope={[item.client_name, item.project_name].filter(Boolean).join(' · ')}
      headline={item.message}
      details={undefined}
      actions={
        <Link to={item.action.path} className={buttonVariants({ variant: 'primary' })}>
          {t('actionCenter.open')}
        </Link>
      }
    />
  );
}
