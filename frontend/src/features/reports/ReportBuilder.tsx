import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import type {
  ComparisonModeValue,
  ReportAudienceValue,
  ReportDraft,
  ReportVocabulary,
  ReportWindowValue,
  SectionDraft,
} from './types';

type ReportBuilderProps = {
  vocabulary: ReportVocabulary | undefined;
  clients: { id: string; name: string }[];
  /** The report being edited, or undefined for a new one. */
  draft: ReportDraft | undefined;
  isSaving: boolean;
  error: string | null;
  onSubmit: (input: {
    name: string;
    description: string | null;
    clientId: string | null;
    window: ReportWindowValue;
    customFrom: string | null;
    customUntil: string | null;
    comparison: ComparisonModeValue;
    audience: ReportAudienceValue;
    internalNote: string | null;
    sections: SectionDraft[];
  }) => void;
  onCancel: () => void;
};

/**
 * Building a report.
 *
 * The builder's job is to make an unreadable report hard to construct, which it does by **offering only what each
 * shape accepts**. The server publishes, per section kind, which metrics it will take and how many — so reach never
 * appears as an option in a ranking, rather than being offered and then refused. A form that offers something the
 * server rejects teaches people to distrust the form.
 *
 * Two defaults are deliberate. A new report is **internal**, because a default of client-safe would mean a report
 * written in shorthand becoming shareable by omission. And the comparison defaults to **none**, because the
 * comparison is the part most likely to be wrong and should be a choice somebody made.
 */
export function ReportBuilder({
  vocabulary,
  clients,
  draft,
  isSaving,
  error,
  onSubmit,
  onCancel,
}: ReportBuilderProps) {
  const { t } = useTranslation();

  const [name, setName] = useState(draft?.name ?? '');
  const [description, setDescription] = useState(draft?.description ?? '');
  const [clientId, setClientId] = useState<string | null>(draft?.client_id ?? null);
  const [window, setWindow] = useState<ReportWindowValue>(draft?.window ?? 'last_30_days');
  const [customFrom, setCustomFrom] = useState(draft?.custom_from ?? '');
  const [customUntil, setCustomUntil] = useState(draft?.custom_until ?? '');
  const [comparison, setComparison] = useState<ComparisonModeValue>(draft?.comparison ?? 'none');
  const [audience, setAudience] = useState<ReportAudienceValue>(draft?.audience ?? 'internal');
  const [internalNote, setInternalNote] = useState(draft?.internal_note ?? '');
  const [sections, setSections] = useState<SectionDraft[]>(
    draft?.sections ?? [
      { kind: 'scorecard', title: null, metrics: ['spend'], dimension: null, row_limit: null },
    ],
  );

  const kinds = vocabulary?.sections ?? [];
  const metricsByValue = new Map(
    (vocabulary?.metrics ?? []).map((metric) => [metric.metric, metric]),
  );
  const needsDates =
    vocabulary?.windows.find((entry) => entry.window === window)?.needs_dates ?? false;
  const chosenComparison = vocabulary?.comparisons.find((entry) => entry.comparison === comparison);

  function updateSection(index: number, change: Partial<SectionDraft>): void {
    setSections((current) =>
      current.map((section, position) =>
        position === index ? { ...section, ...change } : section,
      ),
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {draft === undefined ? t('reports.builder.new') : t('reports.builder.edit')}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-5"
          onSubmit={(event) => {
            event.preventDefault();

            if (name.trim() === '') {
              return;
            }

            onSubmit({
              name: name.trim(),
              description: description.trim() === '' ? null : description.trim(),
              clientId,
              window,
              customFrom: needsDates && customFrom !== '' ? customFrom : null,
              customUntil: needsDates && customUntil !== '' ? customUntil : null,
              comparison,
              audience,
              internalNote: internalNote.trim() === '' ? null : internalNote.trim(),
              sections,
            });
          }}
        >
          {error === null ? null : <Alert tone="danger">{error}</Alert>}

          <Field label={t('reports.builder.name')} required>
            {(props) => (
              <Input
                {...props}
                value={name}
                onChange={(event) => setName(event.target.value)}
                required
              />
            )}
          </Field>

          <Field label={t('reports.builder.description')}>
            {(props) => (
              <Textarea
                {...props}
                rows={2}
                value={description}
                onChange={(event) => setDescription(event.target.value)}
              />
            )}
          </Field>

          <ChoiceGroup label={t('reports.builder.client')} hint={t('reports.builder.clientHint')}>
            <div className="flex flex-wrap gap-2">
              <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                {t('reports.builder.everyClient')}
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

          <ChoiceGroup label={t('reports.builder.period')} hint={t('reports.builder.periodHint')}>
            <div className="flex flex-wrap gap-2">
              {(vocabulary?.windows ?? []).map((entry) => (
                <Choice
                  key={entry.window}
                  isSelected={window === entry.window}
                  onSelect={() => setWindow(entry.window)}
                >
                  {entry.label}
                  {entry.is_incomplete ? ` · ${t('reports.builder.stillRunning')}` : ''}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          {!needsDates ? null : (
            <div className="flex flex-wrap gap-3">
              <Field label={t('reports.builder.from')} className="min-w-40">
                {(props) => (
                  <Input
                    {...props}
                    type="date"
                    value={customFrom}
                    onChange={(event) => setCustomFrom(event.target.value)}
                    required
                  />
                )}
              </Field>
              <Field label={t('reports.builder.until')} className="min-w-40">
                {(props) => (
                  <Input
                    {...props}
                    type="date"
                    value={customUntil}
                    onChange={(event) => setCustomUntil(event.target.value)}
                    required
                  />
                )}
              </Field>
            </div>
          )}

          <ChoiceGroup label={t('reports.builder.comparison')}>
            <div className="flex flex-wrap gap-2">
              {(vocabulary?.comparisons ?? []).map((entry) => (
                <Choice
                  key={entry.comparison}
                  isSelected={comparison === entry.comparison}
                  onSelect={() => setComparison(entry.comparison)}
                >
                  {entry.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          {/*
            The caveat shown while choosing, not after the report is read. What a comparison cannot account for is
            part of deciding whether to ask for one.
          */}
          {chosenComparison?.caveat === null || chosenComparison === undefined ? null : (
            <p className="text-content-tertiary text-sm">{chosenComparison.caveat}</p>
          )}

          <ChoiceGroup
            label={t('reports.builder.audience')}
            hint={t('reports.builder.audienceHint')}
          >
            <div className="flex flex-wrap gap-2">
              {(vocabulary?.audiences ?? []).map((entry) => (
                <Choice
                  key={entry.audience}
                  isSelected={audience === entry.audience}
                  onSelect={() => setAudience(entry.audience)}
                >
                  {entry.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          {audience !== 'internal' ? null : (
            <Field
              label={t('reports.builder.internalNote')}
              hint={t('reports.builder.internalNoteHint')}
            >
              {(props) => (
                <Textarea
                  {...props}
                  rows={2}
                  value={internalNote}
                  onChange={(event) => setInternalNote(event.target.value)}
                />
              )}
            </Field>
          )}

          <section className="flex flex-col gap-4" aria-label={t('reports.builder.sections')}>
            <div className="flex items-center justify-between">
              <h3 className="text-content text-sm font-semibold">
                {t('reports.builder.sections')}
              </h3>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() =>
                  setSections((current) => [
                    ...current,
                    {
                      kind: 'scorecard',
                      title: null,
                      metrics: ['spend'],
                      dimension: null,
                      row_limit: null,
                    },
                  ])
                }
              >
                <Plus aria-hidden className="me-1 size-3.5" />
                {t('reports.builder.addSection')}
              </Button>
            </div>

            {sections.map((section, index) => {
              const kind = kinds.find((entry) => entry.kind === section.kind);
              const allowed = kind?.metrics ?? [];
              const atCap = kind !== undefined && section.metrics.length >= kind.metric_cap;

              return (
                <div
                  key={index}
                  className="border-line-subtle flex flex-col gap-3 rounded-2xl border p-4"
                >
                  <div className="flex items-start justify-between gap-2">
                    <ChoiceGroup label={t('reports.builder.shape')}>
                      <div className="flex flex-wrap gap-2">
                        {kinds.map((entry) => (
                          <Choice
                            key={entry.kind}
                            isSelected={section.kind === entry.kind}
                            onSelect={() => {
                              /*
                               * Changing the shape drops metrics the new shape will not accept, rather than carrying
                               * them over to be refused on save. Reach in a scorecard is fine; the same reach in a
                               * ranking is not, and the form should not have to be told twice.
                               */
                              const keep = section.metrics.filter((metric) =>
                                entry.metrics.includes(metric),
                              );

                              updateSection(index, {
                                kind: entry.kind,
                                metrics: keep.slice(0, entry.metric_cap),
                                dimension: entry.needs_dimension
                                  ? (section.dimension ?? 'provider')
                                  : null,
                              });
                            }}
                          >
                            {entry.label}
                          </Choice>
                        ))}
                      </div>
                    </ChoiceGroup>

                    {sections.length === 1 ? null : (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                          setSections((current) =>
                            current.filter((_, position) => position !== index),
                          )
                        }
                        aria-label={t('reports.builder.removeSection')}
                      >
                        <Trash2 aria-hidden className="size-3.5" />
                      </Button>
                    )}
                  </div>

                  <ChoiceGroup
                    label={t('reports.builder.metrics')}
                    /* Spread rather than passed as undefined: `exactOptionalPropertyTypes` treats the two differently. */
                    {...(kind === undefined
                      ? {}
                      : { hint: t('reports.builder.metricsHint', { cap: kind.metric_cap }) })}
                  >
                    <div className="flex flex-wrap gap-2">
                      {allowed.map((metric) => {
                        const isSelected = section.metrics.includes(metric);

                        return (
                          <Choice
                            key={metric}
                            isSelected={isSelected}
                            /*
                             * Disabled at the cap rather than silently dropping a choice. The cap is the shape's own,
                             * published by the server: four lines on a trend is the measured limit where series
                             * cross, and a table can carry more because position does the work.
                             */
                            disabled={!isSelected && atCap}
                            onSelect={() =>
                              updateSection(index, {
                                metrics: isSelected
                                  ? section.metrics.filter((entry) => entry !== metric)
                                  : [...section.metrics, metric],
                              })
                            }
                          >
                            {metricsByValue.get(metric)?.label ?? metric}
                          </Choice>
                        );
                      })}
                    </div>
                  </ChoiceGroup>

                  {kind?.needs_dimension !== true ? null : (
                    <ChoiceGroup label={t('reports.builder.groupBy')}>
                      <div className="flex flex-wrap gap-2">
                        {(vocabulary?.dimensions ?? []).map((entry) => (
                          <Choice
                            key={entry.dimension}
                            isSelected={section.dimension === entry.dimension}
                            onSelect={() => updateSection(index, { dimension: entry.dimension })}
                          >
                            {entry.label}
                          </Choice>
                        ))}
                      </div>
                    </ChoiceGroup>
                  )}

                  {kind?.supports_comparison !== true || comparison === 'none' ? null : (
                    <Badge tone="neutral">{t('reports.builder.carriesComparison')}</Badge>
                  )}
                </div>
              );
            })}
          </section>

          <div className="flex gap-2">
            <Button type="submit" disabled={isSaving || name.trim() === ''}>
              {t('reports.builder.save')}
            </Button>
            <Button type="button" variant="ghost" onClick={onCancel}>
              {t('actions.cancel')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
