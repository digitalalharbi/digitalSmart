import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Client } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import {
  useDeleteConversionMutation,
  useSaveConversionMutation,
  useSuggestionsQuery,
} from './useTracking';
import type { ConversionDraft, EventDefinition, MeasurementSource } from './types';

/**
 * The vocabulary, as the API publishes it.
 *
 * Kept in one place rather than fetched: it is a closed set the server validates against, and a
 * round trip to learn fourteen constants would make the form slower for no honesty gained.
 */
const NORMALIZED_EVENTS = [
  'purchase',
  'generate_lead',
  'form_submit',
  'sign_up',
  'add_to_cart',
  'begin_checkout',
  'view_item',
  'whatsapp_click',
  'phone_click',
  'form_start',
  'page_view',
  'session_start',
  'app_install',
  'custom_event',
] as const;

/** Events that are signals of intent rather than completed actions. */
const INTENT_ONLY = new Set(['whatsapp_click', 'phone_click', 'form_start']);

/**
 * Define what a client's project counts, and which raw event names mean it.
 *
 * This form is where the product's central rule is enforced in the interface: **nothing infers
 * meaning from an event name.** The raw names are typed or taken from the property's key events,
 * and the meaning is chosen by the person. The suggestion list below helps with the typing and
 * deliberately refuses to help with the meaning for any name the product cannot cite.
 */
export function ConversionEditor({
  definition,
  clientId,
  clients,
  sources,
  onClose,
}: {
  definition: EventDefinition | null;
  clientId: string | null;
  clients: Client[];
  sources: MeasurementSource[];
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const headingId = useId();
  const save = useSaveConversionMutation();
  const remove = useDeleteConversionMutation();

  const [draft, setDraft] = useState<ConversionDraft>(() => ({
    /*
     * No fallback to the first client in the list — deliberately, and it used to have one.
     *
     * Two reasons. A conversion definition decides what a client's reports count, so attaching one
     * to whichever client happened to sort first is a consequential choice made on somebody's
     * behalf. And the fallback did not work reliably anyway: the draft is initialised once, so if
     * the client list arrived after the form opened the preselection silently did not happen and
     * the save button stayed disabled with nothing on screen explaining why. An explicit "choose a
     * client" says what is needed.
     */
    client_id: definition?.client_id ?? clientId ?? '',
    project_id: definition?.project_id ?? null,
    name: definition?.name ?? '',
    normalized_event: definition?.normalized_event ?? 'generate_lead',
    is_conversion: definition?.is_conversion ?? true,
    is_primary: definition?.is_primary ?? false,
    expects_value: definition?.expects_value ?? false,
    mappings: definition?.mappings.map((mapping) => ({
      raw_event_name: mapping.raw_event_name,
      measurement_source_id: mapping.measurement_source_id,
    })) ?? [{ raw_event_name: '', measurement_source_id: null }],
  }));

  /*
   * Suggestions come from the first analytics source in scope. One source rather than all of them:
   * a person is configuring one client's measurement, and merging key events from several
   * properties would offer names that mean different things.
   */
  const analyticsSource = sources.find((source) => source.kind === 'analytics') ?? null;
  const suggestions = useSuggestionsQuery(analyticsSource?.id ?? null);

  const isIntentOnly = INTENT_ONLY.has(draft.normalized_event);
  const canSave =
    draft.client_id !== '' &&
    draft.name.trim() !== '' &&
    draft.mappings.some((mapping) => mapping.raw_event_name.trim() !== '');

  function update(patch: Partial<ConversionDraft>) {
    setDraft((current) => ({ ...current, ...patch }));
  }

  function setMapping(index: number, rawName: string) {
    setDraft((current) => ({
      ...current,
      mappings: current.mappings.map((mapping, position) =>
        position === index ? { ...mapping, raw_event_name: rawName } : mapping,
      ),
    }));
  }

  function addMapping(rawName = '') {
    setDraft((current) => ({
      ...current,
      mappings: [...current.mappings, { raw_event_name: rawName, measurement_source_id: null }],
    }));
  }

  function removeMapping(index: number) {
    setDraft((current) => ({
      ...current,
      mappings: current.mappings.filter((_, position) => position !== index),
    }));
  }

  function submit() {
    save.mutate(
      {
        id: definition?.id ?? null,
        draft: {
          ...draft,
          name: draft.name.trim(),
          mappings: draft.mappings
            .map((mapping) => ({
              ...mapping,
              raw_event_name: mapping.raw_event_name.trim(),
            }))
            .filter((mapping) => mapping.raw_event_name !== ''),
        },
      },
      { onSuccess: onClose },
    );
  }

  return (
    <Card className="p-5" role="dialog" aria-labelledby={headingId} aria-modal="false">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <h3 id={headingId} className="text-content text-lg font-semibold">
          {definition === null
            ? t('tracking.editor.createTitle')
            : t('tracking.editor.editTitle', { name: definition.name })}
        </h3>
        <Button variant="ghost" size="sm" onClick={onClose}>
          {t('common.cancel')}
        </Button>
      </div>

      {save.isError ? (
        <Alert tone="danger" className="mt-4">
          {/*
           * The server's own message, not a generic one. Every refusal here names the thing to
           * change — *which* raw name is already taken, that a primary must also be a conversion —
           * and collapsing that into "could not save" would throw away the only useful part. The
           * fallback is for a failure with no message at all, such as a dropped connection.
           */}
          {save.error instanceof ApiError && save.error.message !== ''
            ? save.error.message
            : t('tracking.editor.saveFailed')}
        </Alert>
      ) : null}

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <Field label={t('tracking.editor.name')}>
          {(props) => (
            <Input
              {...props}
              value={draft.name}
              onChange={(event) => update({ name: event.target.value })}
            />
          )}
        </Field>

        <Field label={t('tracking.editor.meaning')} hint={t('tracking.editor.meaningHint')}>
          {(props) => (
            <select
              {...props}
              className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
              value={draft.normalized_event}
              onChange={(event) => update({ normalized_event: event.target.value })}
            >
              {NORMALIZED_EVENTS.map((value) => (
                <option key={value} value={value}>
                  {t(`tracking.events.${value}`, { defaultValue: value })}
                </option>
              ))}
            </select>
          )}
        </Field>

        {clientId === null ? (
          <Field label={t('tracking.register.client')}>
            {(props) => (
              <select
                {...props}
                className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
                value={draft.client_id}
                onChange={(event) => update({ client_id: event.target.value })}
              >
                <option value="">{t('tracking.register.chooseClient')}</option>
                {clients.map((client) => (
                  <option key={client.id} value={client.id}>
                    {client.name}
                  </option>
                ))}
              </select>
            )}
          </Field>
        ) : null}
      </div>

      {isIntentOnly ? (
        <Alert tone="warning" className="mt-4">
          {/*
           * Said here, at the moment somebody chooses it, rather than only in the issue list later.
           * A click is not a conversation, and reporting a cost per click as a cost per lead is the
           * commonest way an agency overstates its results.
           */}
          {t('tracking.editor.intentWarning')}
        </Alert>
      ) : null}

      <div className="mt-4 flex flex-wrap gap-4">
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={draft.is_conversion}
            onChange={(event) =>
              update({
                is_conversion: event.target.checked,
                // Only a conversion can be primary; the server refuses the pair outright.
                is_primary: event.target.checked ? draft.is_primary : false,
              })
            }
          />
          <span className="text-content">{t('tracking.editor.isConversion')}</span>
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={draft.is_primary}
            disabled={!draft.is_conversion}
            onChange={(event) => update({ is_primary: event.target.checked })}
          />
          <span className="text-content">{t('tracking.editor.isPrimary')}</span>
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={draft.expects_value}
            onChange={(event) => update({ expects_value: event.target.checked })}
          />
          <span className="text-content">{t('tracking.editor.expectsValue')}</span>
        </label>
      </div>

      <section className="mt-5" aria-labelledby={`${headingId}-mappings`}>
        <h4 id={`${headingId}-mappings`} className="text-content text-sm font-medium">
          {t('tracking.editor.mappings')}
        </h4>
        <p className="text-content-secondary mt-1 text-sm">{t('tracking.editor.mappingsHint')}</p>

        <ul className="mt-3 flex flex-col gap-2">
          {draft.mappings.map((mapping, index) => (
            <li key={index} className="flex items-center gap-2">
              <Input
                aria-label={t('tracking.editor.rawName')}
                value={mapping.raw_event_name}
                placeholder="purchase"
                onChange={(event) => setMapping(index, event.target.value)}
              />
              <Button
                variant="ghost"
                size="sm"
                onClick={() => removeMapping(index)}
                disabled={draft.mappings.length === 1}
              >
                {t('common.remove')}
              </Button>
            </li>
          ))}
        </ul>

        <Button variant="ghost" size="sm" className="mt-2" onClick={() => addMapping()}>
          {t('tracking.editor.addMapping')}
        </Button>
      </section>

      {(suggestions.data ?? []).length === 0 ? null : (
        <section className="mt-5" aria-labelledby={`${headingId}-suggestions`}>
          <h4 id={`${headingId}-suggestions`} className="text-content text-sm font-medium">
            {t('tracking.editor.suggestionsTitle')}
          </h4>
          <p className="text-content-secondary mt-1 text-sm">
            {t('tracking.editor.suggestionsHint')}
          </p>

          <ul className="mt-3 flex flex-wrap gap-2">
            {(suggestions.data ?? []).map((suggestion) => (
              <li key={suggestion.raw_event_name}>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={suggestion.already_mapped}
                  onClick={() => {
                    addMapping(suggestion.raw_event_name);

                    /*
                     * The meaning is applied only when the product can cite it. For every other
                     * name — `generate_lead` included — the choice above is left exactly as the
                     * person set it, because a suggestion this product cannot cite is a guess.
                     */
                    if (suggestion.normalized_event !== null) {
                      update({ normalized_event: suggestion.normalized_event });
                    }
                  }}
                >
                  {suggestion.raw_event_name}
                  {suggestion.already_mapped ? ` · ${t('tracking.editor.alreadyMapped')}` : ''}
                </Button>
              </li>
            ))}
          </ul>

          {(suggestions.data ?? []).some((suggestion) => suggestion.counted_once_per_session) ? (
            <p className="text-content-tertiary mt-2 text-xs">
              {t('tracking.editor.oncePerSessionNote')}
            </p>
          ) : null}
        </section>
      )}

      <div className="mt-6 flex flex-wrap items-center justify-between gap-2">
        {definition === null ? (
          <span />
        ) : (
          <Button
            variant="danger"
            size="sm"
            disabled={remove.isPending}
            onClick={() => remove.mutate(definition.id, { onSuccess: onClose })}
          >
            {t('tracking.editor.delete')}
          </Button>
        )}

        <div className="flex items-center gap-2">
          {draft.is_primary ? (
            <Badge tone="accent">{t('tracking.conversions.primary')}</Badge>
          ) : null}
          <Button onClick={submit} disabled={!canSave || save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </div>
    </Card>
  );
}
