import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import type { TaskPriorityInfo, TaskPriorityValue } from './types';

type TaskComposerProps = {
  priorities: TaskPriorityInfo[];
  clients: { id: string; name: string }[];
  isSaving: boolean;
  error: string | null;
  onSubmit: (input: {
    title: string;
    priority: TaskPriorityValue;
    description?: string;
    clientId?: string | null;
    dueOn?: string | null;
  }) => void;
};

/**
 * Putting work on the board.
 *
 * Two deliberate absences.
 *
 * **No client is pre-selected.** An agency has internal work, and a form that filled in the first client
 * would quietly file a hiring task against whoever happens to sort first.
 *
 * **No due date is pre-filled.** Inventing one — today, next Friday — would turn every card into a deadline
 * nobody agreed to, and the board's lateness figures would be measuring the form rather than the work. The
 * hint says what leaving it empty means, so it is a choice rather than an omission.
 */
export function TaskComposer({
  priorities,
  clients,
  isSaving,
  error,
  onSubmit,
}: TaskComposerProps) {
  const { t } = useTranslation();
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [priority, setPriority] = useState<TaskPriorityValue>('normal');
  const [clientId, setClientId] = useState<string | null>(null);
  const [dueOn, setDueOn] = useState('');

  const ordered = [...priorities].sort((a, b) => a.rank - b.rank);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('operations.composer.title')}</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault();

            if (title.trim() === '') {
              return;
            }

            onSubmit({
              title: title.trim(),
              priority,
              ...(description.trim() === '' ? {} : { description: description.trim() }),
              clientId,
              dueOn: dueOn === '' ? null : dueOn,
            });

            setTitle('');
            setDescription('');
            setDueOn('');
          }}
        >
          {error === null ? null : <Alert tone="danger">{error}</Alert>}

          <Field label={t('operations.composer.titleField')} required>
            {(props) => (
              <Input
                {...props}
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                required
              />
            )}
          </Field>

          <Field label={t('operations.composer.description')}>
            {(props) => (
              <Textarea
                {...props}
                rows={2}
                value={description}
                onChange={(event) => setDescription(event.target.value)}
              />
            )}
          </Field>

          <ChoiceGroup label={t('operations.composer.priority')}>
            <div className="flex flex-wrap gap-2">
              {ordered.map((option) => (
                <Choice
                  key={option.priority}
                  isSelected={priority === option.priority}
                  onSelect={() => setPriority(option.priority)}
                >
                  {option.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <ChoiceGroup
            label={t('operations.composer.client')}
            hint={t('operations.composer.clientHint')}
          >
            <div className="flex flex-wrap gap-2">
              <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                {t('operations.composer.internal')}
              </Choice>
              {clients.map((client) => (
                <Choice
                  key={client.id}
                  isSelected={clientId === client.id}
                  onSelect={() => setClientId(client.id)}
                >
                  {client.name}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <Field label={t('operations.composer.due')} hint={t('operations.composer.dueHint')}>
            {(props) => (
              <Input
                {...props}
                type="date"
                value={dueOn}
                onChange={(event) => setDueOn(event.target.value)}
              />
            )}
          </Field>

          <div>
            <Button type="submit" disabled={isSaving || title.trim() === ''}>
              {t('operations.composer.submit')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
