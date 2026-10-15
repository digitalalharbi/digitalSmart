import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { EntityPicker } from '@/shared/ui/entity-picker';
import type { ClientRequestKindValue, RequestVocabulary, StatedUrgencyValue } from './types';

type RequestComposerProps = {
  vocabulary: RequestVocabulary | undefined;
  clients: { id: string; name: string }[];
  onSearchClients: (term: string) => void;
  isSearching?: boolean;
  /** Pre-selected and locked when a client is raising their own request: they have exactly one. */
  lockedClientId: string | null;
  isSaving: boolean;
  error: string | null;
  onSubmit: (input: {
    clientId: string;
    kind: ClientRequestKindValue;
    title: string;
    detail?: string;
    urgency?: StatedUrgencyValue;
    wantedBy?: string | null;
  }) => void;
};

/**
 * Raising a request.
 *
 * Two deliberate choices about the urgency field.
 *
 * **It defaults to the mildest value, never to urgent.** Defaulting to urgent would make every request urgent,
 * after which none of them is — and it would be putting words in a client's mouth.
 *
 * **Its hint says what it is and what it is not.** This is the client's own statement of how soon they need
 * something; it is not the agency's priority, and nothing on the board is ordered by it. Saying so on the form is
 * the honest version of a field a client would otherwise expect to act as a queue-jump.
 *
 * The date field is a **wish**, labelled as one, and separate from the date the agency later promises.
 */
export function RequestComposer({
  vocabulary,
  clients,
  onSearchClients,
  isSearching = false,
  lockedClientId,
  isSaving,
  error,
  onSubmit,
}: RequestComposerProps) {
  const { t } = useTranslation();
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const clientId = client?.id ?? lockedClientId;
  const [kind, setKind] = useState<ClientRequestKindValue>('change');
  const [title, setTitle] = useState('');
  const [detail, setDetail] = useState('');
  const [urgency, setUrgency] = useState<StatedUrgencyValue>('whenever');
  const [wantedBy, setWantedBy] = useState('');

  const resolvedClient = lockedClientId ?? clientId;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('requests.composer.title')}</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault();

            if (title.trim() === '' || resolvedClient === null) {
              return;
            }

            onSubmit({
              clientId: resolvedClient,
              kind,
              title: title.trim(),
              ...(detail.trim() === '' ? {} : { detail: detail.trim() }),
              urgency,
              wantedBy: wantedBy === '' ? null : wantedBy,
            });

            setTitle('');
            setDetail('');
            setWantedBy('');
          }}
        >
          {error === null ? null : <Alert tone="danger">{error}</Alert>}

          {lockedClientId !== null ? null : (
            <EntityPicker
              label={t('requests.composer.client')}
              clearLabel={t('actions.clear')}
              value={client}
              onChange={(option) =>
                setClient(option === null ? null : { id: option.id, label: option.label })
              }
              onSearch={onSearchClients}
              options={clients.map((c) => ({ id: c.id, label: c.name }))}
              isLoading={isSearching}
            />
          )}

          <ChoiceGroup label={t('requests.composer.kind')}>
            <div className="flex flex-wrap gap-2">
              {(vocabulary?.kinds ?? []).map((option) => (
                <Choice
                  key={option.kind}
                  isSelected={kind === option.kind}
                  onSelect={() => setKind(option.kind)}
                >
                  {option.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <Field label={t('requests.composer.titleField')} required>
            {(props) => (
              <Input
                {...props}
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                required
              />
            )}
          </Field>

          <Field label={t('requests.composer.detail')}>
            {(props) => (
              <Textarea
                {...props}
                rows={3}
                value={detail}
                onChange={(event) => setDetail(event.target.value)}
              />
            )}
          </Field>

          <ChoiceGroup
            label={t('requests.composer.urgency')}
            hint={t('requests.composer.urgencyHint')}
          >
            <div className="flex flex-wrap gap-2">
              {(vocabulary?.urgencies ?? []).map((option) => (
                <Choice
                  key={option.urgency}
                  isSelected={urgency === option.urgency}
                  onSelect={() => setUrgency(option.urgency)}
                >
                  {option.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <Field label={t('requests.composer.wantedBy')} hint={t('requests.composer.wantedByHint')}>
            {(props) => (
              <Input
                {...props}
                type="date"
                value={wantedBy}
                onChange={(event) => setWantedBy(event.target.value)}
              />
            )}
          </Field>

          <div>
            <Button
              type="submit"
              disabled={isSaving || title.trim() === '' || resolvedClient === null}
            >
              {t('requests.composer.submit')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
