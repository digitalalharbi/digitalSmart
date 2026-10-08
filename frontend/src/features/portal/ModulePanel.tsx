import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Ban, Check, Lock, TriangleAlert } from 'lucide-react';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import type { PortalDetail, PortalModuleValue, PortalVocabulary } from './types';

type ModulePanelProps = {
  detail: PortalDetail;
  /** The saved set, as a stable string. The parent keys this component on it — see the note below. */
  exposed: string;
  vocabulary: PortalVocabulary | undefined;
  canManage: boolean;
  isSaving: boolean;
  onSave: (modules: PortalModuleValue[]) => void;
};

/**
 * The three lists, side by side.
 *
 * What **can** be exposed, what is **always** available, and what will **never** be — each with its reason. A
 * screen showing only the first would make every refusal look like a feature nobody had got round to, and that
 * question then gets asked once a quarter forever.
 *
 * The chosen set is local state until it is saved, because the request is a whole set rather than a toggle: the
 * question being answered is "what can this client see", and a form that submitted one change at a time can leave
 * that answer half-applied.
 */
export function ModulePanel({
  detail,
  exposed,
  vocabulary,
  canManage,
  isSaving,
  onSave,
}: ModulePanelProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  /*
   * Seeded once from the saved set, and re-seeded by remounting rather than by an effect: the parent keys this
   * component on the client and the set, so a successful save leaves the form agreeing with what was saved, and
   * switching clients cannot carry one client's unsaved choices onto another. An effect calling `setState` would
   * reach the same place one render later, and that render would be spent asserting something untrue.
   */
  const [chosen, setChosen] = useState<PortalModuleValue[]>(
    exposed === '' ? [] : (exposed.split(',') as PortalModuleValue[]),
  );

  const isDirty = [...chosen].sort().join(',') !== exposed.split(',').sort().join(',');

  return (
    <div className="flex flex-col gap-5">
      <section className="flex flex-col gap-3" aria-label={t('portal.modules.title')}>
        <h3 className="text-content text-sm font-semibold">{t('portal.modules.title')}</h3>

        <ChoiceGroup label={t('portal.modules.choose')}>
          <div className="flex flex-wrap gap-2">
            {detail.modules.map((entry) => (
              <Choice
                key={entry.module}
                isSelected={chosen.includes(entry.module)}
                disabled={!canManage}
                onSelect={() =>
                  setChosen((current) =>
                    current.includes(entry.module)
                      ? current.filter((value) => value !== entry.module)
                      : [...current, entry.module],
                  )
                }
              >
                {entry.label}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        <ul className="flex flex-col gap-3">
          {detail.modules.map((entry) => (
            <li key={entry.module} className="border-line-subtle rounded-lg border p-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-content text-sm font-medium">{entry.label}</span>
                {entry.is_exposed ? (
                  <Badge tone="accent">
                    <Check aria-hidden className="me-1 size-3" />
                    {t('portal.modules.exposedBadge')}
                  </Badge>
                ) : null}
                {/*
                  Said before the switch is thrown, not after the client asks. An empty surface exposed to a
                  client reads as the agency having done nothing.
                */}
                {entry.is_prerequisite_met ? null : (
                  <Badge tone="warning">
                    <TriangleAlert aria-hidden className="me-1 size-3" />
                    {t('portal.modules.needs', { prerequisite: entry.prerequisite ?? '' })}
                  </Badge>
                )}
              </div>

              <p className="text-content-secondary mt-1 text-sm">{entry.description}</p>

              {entry.is_prerequisite_met ? null : (
                <p className="text-content-tertiary mt-1 text-xs">
                  {t('portal.modules.empty', { prerequisite: entry.prerequisite ?? '' })}
                </p>
              )}

              {!entry.is_exposed || entry.exposed_at === null ? null : (
                <p className="text-content-tertiary mt-1 text-xs">
                  {entry.exposed_by_name === null
                    ? t('portal.modules.exposedAt', {
                        at: new Date(entry.exposed_at).toLocaleString(locale),
                      })
                    : t('portal.modules.exposedBy', {
                        at: new Date(entry.exposed_at).toLocaleString(locale),
                        who: entry.exposed_by_name,
                      })}
                </p>
              )}
            </li>
          ))}
        </ul>

        {!canManage ? null : (
          <span>
            <Button onClick={() => onSave(chosen)} disabled={isSaving || !isDirty}>
              {t('portal.modules.save')}
            </Button>
          </span>
        )}
      </section>

      {vocabulary === undefined ? null : (
        <>
          <section className="flex flex-col gap-2" aria-label={t('portal.always.title')}>
            <h3 className="text-content flex items-center gap-1.5 text-sm font-semibold">
              <Lock aria-hidden className="size-3.5" />
              {t('portal.always.title')}
            </h3>
            <p className="text-content-tertiary text-sm">{t('portal.always.body')}</p>
            <ul className="flex flex-col gap-2">
              {vocabulary.always_available.map((entry) => (
                <li key={entry.module} className="text-sm">
                  <span className="text-content font-medium">{entry.label}</span>
                  <span className="text-content-secondary"> — {entry.because}</span>
                </li>
              ))}
            </ul>
          </section>

          <section className="flex flex-col gap-2" aria-label={t('portal.withheld.title')}>
            <h3 className="text-content flex items-center gap-1.5 text-sm font-semibold">
              <Ban aria-hidden className="size-3.5" />
              {t('portal.withheld.title')}
            </h3>
            <p className="text-content-tertiary text-sm">{t('portal.withheld.body')}</p>
            <ul className="flex flex-col gap-2">
              {vocabulary.withheld.map((entry) => (
                <li key={entry.module} className="text-sm">
                  <span className="text-content font-medium">{entry.label}</span>
                  <span className="text-content-secondary"> — {entry.because}</span>
                </li>
              ))}
            </ul>
          </section>
        </>
      )}
    </div>
  );
}
