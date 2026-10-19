import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Sparkles } from 'lucide-react';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { AgentFindingDecision } from './AgentFindingDecision';
import { useAskAgents } from './api';

/**
 * A question to the agents, answered with cards.
 *
 * The question picks which agents look; it is never carried out. What comes back is the same decision cards Today
 * shows, scoped to what the asker may see — not a paragraph.
 */
export function AskAgents() {
  const { t } = useTranslation();
  const [question, setQuestion] = useState('');
  const ask = useAskAgents();
  const answer = ask.data;

  return (
    <section
      aria-labelledby="ask-title"
      className="bg-surface flex flex-col gap-4 rounded-3xl p-5 shadow-xs"
    >
      <h2 id="ask-title" className="text-content flex items-center gap-2 text-base font-semibold">
        <Sparkles aria-hidden className="text-content-brand size-4" />
        {t('agentFindings.ask')}
      </h2>
      <form
        className="flex flex-col gap-2 sm:flex-row"
        onSubmit={(event) => {
          event.preventDefault();

          if (question.trim() !== '') {
            ask.mutate(question.trim());
          }
        }}
      >
        <Input
          value={question}
          maxLength={500}
          aria-label={t('agentFindings.ask')}
          placeholder={t('agentFindings.askPlaceholder')}
          onChange={(event) => setQuestion(event.target.value)}
          className="flex-1"
          dir="auto"
        />
        <Button type="submit" disabled={ask.isPending || question.trim() === ''}>
          {t('agentFindings.askButton')}
        </Button>
      </form>

      {ask.isError ? <Alert tone="danger">{t('agentFindings.askFailed')}</Alert> : null}

      {answer === undefined ? null : (
        <div className="flex flex-col gap-3">
          <p className="text-content-tertiary text-xs">
            {t('agentFindings.askedAgents', {
              agents: answer.agents.map((agent) => t(`aiCenter.agent.${agent}`)).join(' · '),
            })}
          </p>
          {answer.findings.length === 0 ? (
            <p className="text-content-secondary text-sm">{t('agentFindings.askNothing')}</p>
          ) : (
            <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {answer.findings.slice(0, 9).map((finding) => (
                <li key={finding.id} className="flex">
                  <AgentFindingDecision finding={finding} />
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </section>
  );
}
