import {
  Activity,
  ArrowLeft,
  ArrowRight,
  BarChart3,
  CalendarDays,
  CalendarRange,
  Check,
  LayoutDashboard,
  LineChart,
  Plus,
  ShoppingBag,
  UserCheck,
  Users,
  X,
  type LucideIcon,
  Images,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { isAvailable, PRESETS, presetSections, type Preset, type PresetId } from './presets';
import type {
  ComparisonModeValue,
  ReportAudienceValue,
  ReportVocabulary,
  ReportWindowValue,
  SectionDraft,
  SectionKindValue,
} from './types';

const STEPS = ['client', 'type', 'period', 'content', 'preview', 'share'] as const;
type Step = (typeof STEPS)[number];

const ICON: Record<PresetId, LucideIcon> = {
  executive: LayoutDashboard,
  performance: Activity,
  lead_generation: UserCheck,
  ecommerce: ShoppingBag,
  weekly: CalendarDays,
  monthly: CalendarRange,
  creative: Images,
};

/** The one-glance picture of a section kind: what it looks like, not what it is called. */
const KIND_ICON: Record<SectionKindValue, LucideIcon> = {
  scorecard: LayoutDashboard,
  trend: LineChart,
  breakdown: BarChart3,
  table: BarChart3,
  store: ShoppingBag,
  lead: Users,
  creative: Images,
};

export type WizardResult = {
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
};

type WizardProps = {
  vocabulary: ReportVocabulary | undefined;
  clients: { id: string; name: string }[];
  onSearchClients: (term: string) => void;
  isSearching?: boolean;
  isSaving: boolean;
  error: string | null;
  onSubmit: (input: WizardResult) => void;
  onCancel: () => void;
};

/**
 * Making a report as six short choices rather than one long form.
 *
 * Client, type, period, content, a look at what will be made, and who it is for. A preset is the type: it chooses the
 * period, the comparison and the sections, and every one of them can still be changed at the content step — so a
 * preset is where the work starts, not a limit on it.
 *
 * **The last choice has no default.** A report written in shorthand must not become shareable by omission, so who it is
 * for is asked at the end and the button stays off until it has been answered.
 *
 * Nothing is saved before the last step. The preview shows the *shape* the report will have — which sections, in what
 * order, for which client and period — because the figures come from the server and a report that does not exist yet
 * has none to show. The finished report opens straight onto its links.
 */
export function ReportWizard({
  vocabulary,
  clients,
  onSearchClients,
  isSearching = false,
  isSaving,
  error,
  onSubmit,
  onCancel,
}: WizardProps) {
  const { t } = useTranslation();
  const root = useRef<HTMLElement | null>(null);
  const [step, setStep] = useState<Step>('client');
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [presetId, setPresetId] = useState<PresetId | null>(null);
  const [window, setWindow] = useState<ReportWindowValue>('last_30_days');
  const [customFrom, setCustomFrom] = useState('');
  const [customUntil, setCustomUntil] = useState('');
  const [comparison, setComparison] = useState<ComparisonModeValue>('previous_period');
  const [sections, setSections] = useState<SectionDraft[]>([]);
  const [name, setName] = useState('');
  const [audience, setAudience] = useState<ReportAudienceValue | null>(null);

  /* The wizard opens below the page's own header and briefs; bring it to the top of the screen once, on opening. */
  useEffect(() => {
    root.current?.scrollIntoView({ block: 'start' });
  }, []);

  const index = STEPS.indexOf(step);
  const hasClient = client !== null;
  const windows = vocabulary?.windows ?? [];
  const kinds = vocabulary?.sections ?? [];
  const needsDates = windows.find((entry) => entry.window === window)?.needs_dates ?? false;
  const preset = PRESETS.find((entry) => entry.id === presetId) ?? null;

  const choose = (next: Preset): void => {
    if (vocabulary === undefined) {
      return;
    }

    setPresetId(next.id);
    setWindow(next.window);
    setComparison(next.comparison);
    setSections(presetSections(next, vocabulary));
    setName(
      [client?.label, t(`reports.wizard.presets.${next.id}.name`)]
        .filter((part): part is string => part !== undefined && part !== '')
        .join(' · '),
    );
  };

  const add = (kind: SectionKindValue, dimension?: string): void => {
    if (vocabulary === undefined) {
      return;
    }

    const wanted = {
      kind,
      ...(dimension === undefined ? {} : { dimension }),
      metrics: ['spend', 'clicks', 'conversions'],
    };

    setSections((current) => [
      ...current,
      ...presetSections(
        { id: 'executive', window, comparison, needsClient: false, sections: [wanted] },
        vocabulary,
      ),
    ]);
  };

  const canNext: Record<Step, boolean> = {
    client: true,
    type: preset !== null,
    period: !needsDates || (customFrom !== '' && customUntil !== ''),
    content: sections.length > 0,
    preview: name.trim() !== '',
    share: audience !== null,
  };

  const addable: {
    key: string;
    kind: SectionKindValue;
    dimension?: string;
    needsClient: boolean;
  }[] = [
    { key: 'scorecard', kind: 'scorecard', needsClient: false },
    { key: 'trend', kind: 'trend', needsClient: false },
    { key: 'platforms', kind: 'breakdown', dimension: 'provider', needsClient: false },
    { key: 'campaigns', kind: 'breakdown', dimension: 'campaign', needsClient: false },
    { key: 'store', kind: 'store', needsClient: true },
    { key: 'lead', kind: 'lead', needsClient: true },
  ];

  return (
    <section
      ref={root}
      aria-label={t('reports.wizard.label')}
      className="bg-surface flex flex-col gap-6 rounded-3xl p-5 shadow-xs"
    >
      <header className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-3">
          <p className="text-content-secondary text-sm">
            {t('reports.wizard.step', { n: index + 1, total: STEPS.length })}
            {' · '}
            <span className="text-content font-medium">{t(`reports.wizard.steps.${step}`)}</span>
          </p>
          <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
            {t('actions.cancel')}
          </Button>
        </div>
        <ol aria-hidden className="grid grid-cols-6 gap-1.5">
          {STEPS.map((entry, position) => (
            <li
              key={entry}
              className={cn(
                'h-1.5 rounded-full',
                position <= index ? 'bg-accent-strong' : 'bg-surface-sunken',
              )}
            />
          ))}
        </ol>
      </header>

      {error === null ? null : <Alert tone="danger">{error}</Alert>}

      {step === 'client' ? (
        <div className="flex flex-col gap-3">
          <EntityPicker
            label={t('reports.builder.client')}
            clearLabel={t('actions.clear')}
            placeholder={t('reports.builder.everyClient')}
            value={client}
            onChange={(option) =>
              setClient(option === null ? null : { id: option.id, label: option.label })
            }
            onSearch={onSearchClients}
            options={clients.map((entry) => ({ id: entry.id, label: entry.name }))}
            isLoading={isSearching}
          />
          <p className="text-content-tertiary text-xs">{t('reports.wizard.clientNote')}</p>
        </div>
      ) : null}

      {step === 'type' ? (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {PRESETS.map((entry) => {
            const Icon = ICON[entry.id];
            const available = isAvailable(entry, hasClient);
            const selected = presetId === entry.id;

            return (
              <li key={entry.id}>
                <button
                  type="button"
                  disabled={!available}
                  aria-pressed={selected}
                  onClick={() => choose(entry)}
                  className={cn(
                    'border-line-subtle flex h-full w-full flex-col gap-3 rounded-2xl border p-4 text-start transition',
                    selected
                      ? 'border-accent-strong bg-accent-subtle/40'
                      : 'hover:bg-surface-sunken',
                    !available && 'opacity-50',
                  )}
                >
                  <span className="bg-surface-sunken grid size-10 place-items-center rounded-xl">
                    <Icon aria-hidden className="size-5" />
                  </span>
                  <span className="text-content text-sm font-semibold">
                    {t(`reports.wizard.presets.${entry.id}.name`)}
                  </span>
                  <span className="text-content-secondary text-xs">
                    {available
                      ? t(`reports.wizard.presets.${entry.id}.tags`)
                      : t('reports.wizard.needsClient')}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}

      {step === 'period' ? (
        <div className="flex flex-col gap-5">
          <ChoiceGroup label={t('reports.builder.period')}>
            <div className="flex flex-wrap gap-2">
              {windows.map((entry) => (
                <Choice
                  key={entry.window}
                  isSelected={window === entry.window}
                  onSelect={() => setWindow(entry.window)}
                >
                  {entry.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>
          {needsDates ? (
            <div className="grid max-w-md grid-cols-2 gap-2">
              <Field label={t('sharedReport.filters.from')}>
                {(props) => (
                  <Input
                    {...props}
                    type="date"
                    value={customFrom}
                    onChange={(event) => setCustomFrom(event.target.value)}
                  />
                )}
              </Field>
              <Field label={t('sharedReport.filters.until')}>
                {(props) => (
                  <Input
                    {...props}
                    type="date"
                    value={customUntil}
                    onChange={(event) => setCustomUntil(event.target.value)}
                  />
                )}
              </Field>
            </div>
          ) : null}
          <ChoiceGroup label={t('reports.wizard.compare')}>
            <div className="flex flex-wrap gap-2">
              {(['none', 'previous_period'] as const).map((value) => (
                <Choice
                  key={value}
                  isSelected={comparison === value}
                  onSelect={() => setComparison(value)}
                >
                  {vocabulary?.comparisons.find((entry) => entry.comparison === value)?.label ??
                    value}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>
        </div>
      ) : null}

      {step === 'content' ? (
        <div className="flex flex-col gap-4">
          <ul className="grid gap-2 sm:grid-cols-2">
            {sections.map((section, position) => {
              const Icon = KIND_ICON[section.kind];

              return (
                <li
                  key={`${section.kind}-${String(position)}`}
                  className="border-line-subtle bg-surface-sunken flex min-w-0 items-center gap-3 rounded-2xl border p-3"
                >
                  <Icon aria-hidden className="size-5 shrink-0" />
                  <span className="text-content min-w-0 flex-1 truncate text-sm">
                    {kinds.find((entry) => entry.kind === section.kind)?.label ?? section.kind}
                    {section.dimension === null
                      ? ''
                      : ` · ${vocabulary?.dimensions.find((entry) => entry.dimension === section.dimension)?.label ?? section.dimension}`}
                  </span>
                  <button
                    type="button"
                    aria-label={t('reports.builder.removeSection')}
                    onClick={() =>
                      setSections((current) => current.filter((_, at) => at !== position))
                    }
                    className="hover:bg-surface grid size-7 place-items-center rounded-full"
                  >
                    <X aria-hidden className="size-3.5" />
                  </button>
                </li>
              );
            })}
          </ul>
          <div className="flex flex-wrap gap-2" role="group" aria-label={t('reports.wizard.add')}>
            {addable
              .filter((entry) => !entry.needsClient || hasClient)
              .map((entry) => (
                <Button
                  key={entry.key}
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() => add(entry.kind, entry.dimension)}
                >
                  <Plus aria-hidden className="size-3.5" />
                  {t(`reports.wizard.addable.${entry.key}`)}
                </Button>
              ))}
          </div>
        </div>
      ) : null}

      {step === 'preview' ? (
        <div className="flex flex-col gap-4">
          <Field label={t('reports.builder.name')} required>
            {(props) => (
              <Input {...props} value={name} onChange={(event) => setName(event.target.value)} />
            )}
          </Field>
          <div className="bg-surface-sunken flex flex-col gap-3 rounded-2xl p-4" aria-hidden>
            <div className="flex items-center gap-3">
              <span className="bg-surface text-content grid size-9 place-items-center rounded-full text-xs font-semibold">
                {(client?.label ?? t('reports.builder.everyClient')).slice(0, 2).toUpperCase()}
              </span>
              <div className="min-w-0">
                <p className="text-content truncate text-sm font-semibold">
                  {name === '' ? '—' : name}
                </p>
                <p className="text-content-tertiary truncate text-xs">
                  {windows.find((entry) => entry.window === window)?.label ?? window}
                </p>
              </div>
            </div>
            {sections.map((section, position) => (
              <Mock key={`${section.kind}-${String(position)}`} kind={section.kind} />
            ))}
          </div>
        </div>
      ) : null}

      {step === 'share' ? (
        <ul className="grid gap-3 sm:grid-cols-2">
          {(
            [
              ['client_safe', Users],
              ['internal', LayoutDashboard],
            ] as const
          ).map(([value, Icon]) => (
            <li key={value}>
              <button
                type="button"
                aria-pressed={audience === value}
                onClick={() => setAudience(value)}
                className={cn(
                  'border-line-subtle flex h-full w-full flex-col gap-2 rounded-2xl border p-4 text-start',
                  audience === value
                    ? 'border-accent-strong bg-accent-subtle/40'
                    : 'hover:bg-surface-sunken',
                )}
              >
                <Icon aria-hidden className="size-5" />
                <span className="text-content text-sm font-semibold">
                  {t(`reports.wizard.audience.${value}.name`)}
                </span>
                <span className="text-content-secondary text-xs">
                  {t(`reports.wizard.audience.${value}.line`)}
                </span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}

      <footer className="flex items-center justify-between gap-2">
        <Button
          type="button"
          variant="ghost"
          disabled={index === 0}
          onClick={() => setStep(STEPS[index - 1] ?? 'client')}
        >
          <ArrowLeft aria-hidden className="size-4 rtl:rotate-180" />
          {t('reports.wizard.back')}
        </Button>

        {step === 'share' ? (
          <Button
            type="button"
            disabled={!canNext.share || isSaving}
            onClick={() => {
              if (audience === null) {
                return;
              }

              onSubmit({
                name: name.trim(),
                description: null,
                clientId: client?.id ?? null,
                window,
                customFrom: needsDates && customFrom !== '' ? customFrom : null,
                customUntil: needsDates && customUntil !== '' ? customUntil : null,
                comparison,
                audience,
                internalNote: null,
                sections,
              });
            }}
          >
            <Check aria-hidden className="size-4" />
            {t('reports.wizard.create')}
          </Button>
        ) : (
          <Button
            type="button"
            disabled={!canNext[step]}
            onClick={() => setStep(STEPS[index + 1] ?? 'share')}
          >
            {t('reports.wizard.next')}
            <ArrowRight aria-hidden className="size-4 rtl:rotate-180" />
          </Button>
        )}
      </footer>
    </section>
  );
}

/** A section's shape, drawn rather than named: a row of tiles, a line, bars, a ring. No figures — there are none yet. */
function Mock({ kind }: { kind: SectionKindValue }) {
  if (kind === 'trend') {
    return (
      <svg
        viewBox="0 0 120 28"
        className="bg-surface h-10 w-full rounded-lg"
        preserveAspectRatio="none"
      >
        <path
          d="M0 22 C20 8 35 24 55 14 S95 4 120 10"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          className="text-accent-strong"
        />
      </svg>
    );
  }

  if (kind === 'breakdown' || kind === 'table') {
    return (
      <div className="bg-surface flex flex-col gap-1.5 rounded-lg p-2">
        {[88, 62, 40].map((width) => (
          <span
            key={width}
            className="bg-accent-strong/70 block h-1.5 rounded-full"
            style={{ width: `${String(width)}%` }}
          />
        ))}
      </div>
    );
  }

  if (kind === 'store' || kind === 'lead') {
    return (
      <div className="bg-surface flex items-center gap-3 rounded-lg p-2">
        <span className="border-accent-strong size-8 shrink-0 rounded-full border-4" />
        <span className="flex flex-1 flex-col gap-1.5">
          <span className="bg-accent-strong/70 block h-1.5 w-4/5 rounded-full" />
          <span className="bg-accent-strong/40 block h-1.5 w-3/5 rounded-full" />
        </span>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-3 gap-1.5">
      {[0, 1, 2].map((tile) => (
        <span key={tile} className="bg-surface block h-8 rounded-lg" />
      ))}
    </div>
  );
}
