import { Ban } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { cn } from '@/shared/lib/cn';
import type { AttributionModelInfo, AttributionModelValue } from './types';

type ModelSelectorProps = {
  models: AttributionModelInfo[];
  selected: AttributionModelValue;
  onSelect: (model: AttributionModelValue) => void;
};

/**
 * The model, chosen explicitly.
 *
 * Built from the catalogue the server publishes rather than a list in this file, for the same reason
 * the provider vocabulary is: an interface that carried its own copy would eventually offer a model the
 * server does not have, or miss one it gained.
 *
 * **The unavailable models are shown**, disabled, with what is missing and what would unblock them.
 * Hiding them would be the easier interface and the worse one: the question "why can't we see
 * time-decay attribution?" then gets asked of an engineer every quarter, and the answer — that it needs
 * data nobody has paid to collect — is a decision for whoever pays rather than a gap to apologise for.
 *
 * Each option says whether it is a model or a record. Exactly one is a record.
 */
export function ModelSelector({ models, selected, onSelect }: ModelSelectorProps) {
  const { t } = useTranslation();

  return (
    <fieldset className="flex flex-col gap-2">
      <legend className="text-content text-sm font-medium">{t('attribution.model.label')}</legend>
      <p className="text-content-tertiary text-xs">{t('attribution.model.hint')}</p>

      <div className="mt-1 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        {models.map((model) => {
          const isSelected = model.model === selected;

          return (
            <Card
              key={model.model}
              className={cn(
                'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
                !model.is_available && 'opacity-70',
                isSelected && 'border-accent ring-accent/25 ring-1',
              )}
            >
              <CardContent className="p-0">
                <button
                  type="button"
                  onClick={() => onSelect(model.model)}
                  disabled={!model.is_available}
                  aria-pressed={isSelected}
                  className={cn(
                    'focus-visible:ring-accent/40 flex w-full flex-col gap-1.5 rounded-xl p-4 text-start',
                    'focus-visible:ring-2 focus-visible:outline-none',
                    model.is_available
                      ? 'hover:bg-surface-hover cursor-pointer'
                      : 'cursor-not-allowed',
                  )}
                >
                  <span className="flex flex-wrap items-center gap-1.5">
                    <span className="text-content text-sm font-medium">{model.label}</span>
                    {/*
                      The distinction the whole phase rests on. A record can be quoted as what
                      happened; a model is a claim about which campaign deserves credit.
                    */}
                    <Badge tone={model.is_modelled ? 'warning' : 'success'}>
                      {model.is_modelled
                        ? t('attribution.model.modelled')
                        : t('attribution.model.recorded')}
                    </Badge>
                    {model.is_available ? null : (
                      <Badge tone="neutral">
                        <Ban aria-hidden className="size-3" />
                        {t('attribution.model.unavailable')}
                      </Badge>
                    )}
                  </span>

                  <span className="text-content-secondary text-xs leading-relaxed">
                    {model.description}
                  </span>

                  {/*
                    Why it cannot be computed, and what would change that. Concrete, because the
                    person reading it is usually deciding whether it is worth paying for.
                  */}
                  {model.missing === null ? null : (
                    <span className="text-content-tertiary mt-1 flex flex-col gap-1 text-xs leading-relaxed">
                      <span>{model.missing}</span>
                      {model.would_unblock === null ? null : (
                        <span>
                          <strong className="font-medium">
                            {t('attribution.model.wouldUnblock')}
                          </strong>{' '}
                          {model.would_unblock}
                        </span>
                      )}
                    </span>
                  )}
                </button>
              </CardContent>
            </Card>
          );
        })}
      </div>
    </fieldset>
  );
}
