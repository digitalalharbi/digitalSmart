import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useSaveFunnelMutation } from './api';
import { FUNNEL_SOURCES, type FunnelSource, type FunnelStageDef, type StoredFunnel } from './types';

/**
 * Choose a funnel's stages from the list of things the product records.
 *
 * Stages can only be switched on and off, never reordered: they follow the journey, from the click to the sale, because
 * that order is what makes every rate divide by the stage before it.
 */
export function FunnelBuilder({
  projectId,
  funnel,
  onClose,
}: {
  projectId: string;
  funnel: StoredFunnel | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const save = useSaveFunnelMutation();
  const [name, setName] = useState(funnel?.name ?? '');
  const [chosen, setChosen] = useState<Map<FunnelSource, FunnelStageDef>>(
    () => new Map((funnel?.stages ?? []).map((s) => [s.source, s])),
  );

  const toggle = (source: FunnelSource) =>
    setChosen((current) => {
      const next = new Map(current);

      if (next.has(source)) {
        next.delete(source);
      } else {
        next.set(source, { source, label_ar: null, label_en: null, required: true });
      }

      return next;
    });

  const stages = FUNNEL_SOURCES.flatMap((s) => {
    const stage = chosen.get(s);

    return stage === undefined ? [] : [stage];
  });

  return (
    <form
      className="border-line flex flex-col gap-4 rounded-3xl border p-4 sm:p-5"
      onSubmit={(event) => {
        event.preventDefault();
        save.mutate(
          { ...(funnel === null ? {} : { id: funnel.id }), projectId, name, stages },
          { onSuccess: onClose },
        );
      }}
    >
      <h3 className="text-content text-base font-semibold">
        {funnel === null
          ? t('tracking.funnels.builder.new')
          : t('tracking.funnels.builder.edit', { name: funnel.name })}
      </h3>
      <Field label={t('tracking.funnels.builder.name')} required>
        {(f) => <Input {...f} value={name} onChange={(e) => setName(e.target.value)} required />}
      </Field>

      <fieldset className="flex flex-col gap-1">
        <legend className="text-content mb-1 text-sm font-medium">
          {t('tracking.funnels.builder.stages')}
        </legend>
        <p className="text-content-tertiary mb-1 text-xs">{t('tracking.funnels.builder.order')}</p>
        {FUNNEL_SOURCES.map((source) => (
          <label key={source} className="flex min-h-11 items-center gap-3 text-sm">
            <input
              type="checkbox"
              className="size-5"
              checked={chosen.has(source)}
              onChange={() => toggle(source)}
            />
            {t(`tracking.funnels.sources.${source}`)}
            <span className="text-content-tertiary text-xs">
              ({t(`tracking.funnels.units.${unitOf(source)}`)})
            </span>
          </label>
        ))}
      </fieldset>

      {save.isError ? (
        <Alert tone="danger">
          {save.error instanceof ApiError && save.error.message !== ''
            ? save.error.message
            : t('tracking.funnels.builder.failed')}
        </Alert>
      ) : null}

      <div className="flex justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onClose}>
          {t('actions.cancel')}
        </Button>
        <Button
          type="submit"
          disabled={save.isPending || stages.length < 2 || name.trim().length < 2}
        >
          {t('tracking.funnels.builder.save')}
        </Button>
      </div>
    </form>
  );
}

function unitOf(source: FunnelSource): 'session' | 'submission' | 'lead' {
  return source === 'form_submit'
    ? 'submission'
    : ['link_click', 'landing_view', 'form_start'].includes(source)
      ? 'session'
      : 'lead';
}
