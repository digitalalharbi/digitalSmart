import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { AttentionList } from '@/shared/ui/attention-list';
import { Choice } from '@/shared/ui/choice';
import type { AttentionItem as ListItem } from '@/shared/ui/attention.model';
import { Badge } from '@/shared/ui/badge';
import { PageSection } from '@/shared/ui/page-header';
import { useAttentionQuery } from './useAttention';
import { reasonText } from '@/shared/lib/reason';
import type {
  AttentionAudience,
  AttentionItem,
  AttentionQueue,
  AttentionStreamValue,
} from './types';

const AUDIENCES: AttentionAudience[] = ['all', 'my_clients', 'my_projects'];

/** The queue's three severities onto the shared health words. Same colour, same glyph, same meaning. */
const SEVERITY = {
  critical: 'critical',
  needs_attention: 'attention',
  watch: 'watch',
} as const;

/**
 * The first thing on a home screen: what is waiting on you.
 *
 * The list itself is the server's. This component's job is the fine print around it, and two lines of it
 * hold up everything above them.
 *
 * **It names what was looked at.** "Nothing needs attention" means something different to a finance
 * manager who can see budgets and approvals than to a creative lead who can see neither, and an empty
 * list without its scope is a reassurance the product has not earned. The server publishes the scope; this
 * prints it.
 *
 * **A stream that failed to load is said out loud.** Otherwise a broken request and a quiet week render
 * identically, and the broken one renders as the reassuring one.
 */
export function AttentionPanel({
  limit = 6,
  compact = false,
}: {
  limit?: number | undefined;
  /** Just the list and its grouping: no heading, scope sentence or audience switch — for a card that already has a title. */
  compact?: boolean | undefined;
}) {
  const { t, i18n } = useTranslation();
  const [audience, setAudience] = useState<AttentionAudience>('all');
  const [stream, setStream] = useState<AttentionStreamValue | null>(null);
  const { data, isPending, isError, error } = useAttentionQuery([], 50, audience);

  /*
   * `Intl.ListFormat` rather than `join(', ')`: English wants "budgets, tracking and approvals" and
   * Arabic wants its own conjunction and its own comma. Hand-joining gets one of the two languages wrong.
   */
  const list = new Intl.ListFormat(i18n.language, { style: 'long', type: 'conjunction' });

  if (isError) {
    /* A 403 is not an error here: the endpoint has no permission of its own, so this is a real failure. */
    const message = error instanceof ApiError ? error.message : '';

    return <Alert tone="danger">{message === '' ? t('attention.loadFailed') : message}</Alert>;
  }

  if (!isPending && data !== undefined && data.meta.covered.length === 0) {
    /*
     * A role that can see none of the streams has no attention centre, and an empty panel saying
     * "nothing needs attention" would be a claim about a product this person cannot see.
     */
    return null;
  }

  /* Counted from the one response: grouping is a way of reading the list, not another request. */
  const groups = new Map<AttentionStreamValue, number>();

  for (const item of data?.data ?? []) {
    groups.set(item.stream, (groups.get(item.stream) ?? 0) + 1);
  }

  const visible = (data?.data ?? []).filter((item) => stream === null || item.stream === stream);
  const items = visible.map((item) => toListItem(item, t));
  const criticals = data?.meta.severity_counts.critical ?? 0;

  if (compact) {
    return (
      <div className="flex flex-col gap-3">
        {groups.size < 2 ? null : (
          <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label={t('attention.group.label')}
          >
            <Choice isSelected={stream === null} onSelect={() => setStream(null)}>
              {t('attention.group.all', { count: data?.data.length ?? 0 })}
            </Choice>
            {[...groups.entries()].map(([key, count]) => (
              <Choice key={key} isSelected={stream === key} onSelect={() => setStream(key)}>
                {t(`attention.group.${key}`, { count })}
              </Choice>
            ))}
          </div>
        )}
        {failedNotice(data, list, t)}
        <AttentionList items={items} isPending={isPending} limit={limit} />
      </div>
    );
  }

  return (
    <PageSection
      title={t('attention.title')}
      description={
        data === undefined
          ? t('attention.subtitle')
          : t('attention.scope', {
              streams: list.format(
                data.meta.covered.map((stream) =>
                  t(`attention.streams.${stream.stream}`, { defaultValue: stream.label }),
                ),
              ),
            })
      }
      actions={
        data === undefined || data.meta.total === 0 ? null : (
          <Badge tone={criticals > 0 ? 'danger' : 'warning'}>
            {t('attention.count', { count: data.meta.total })}
          </Badge>
        )
      }
    >
      <div className="flex flex-wrap gap-2" role="group" aria-label={t('attention.audience.label')}>
        {AUDIENCES.map((value) => (
          <Choice key={value} isSelected={audience === value} onSelect={() => setAudience(value)}>
            {t(`attention.audience.${value}`)}
          </Choice>
        ))}
      </div>

      {groups.size < 2 ? null : (
        <div className="flex flex-wrap gap-2" role="group" aria-label={t('attention.group.label')}>
          <Choice isSelected={stream === null} onSelect={() => setStream(null)}>
            {t('attention.group.all', { count: data?.data.length ?? 0 })}
          </Choice>
          {[...groups.entries()].map(([key, count]) => (
            <Choice key={key} isSelected={stream === key} onSelect={() => setStream(key)}>
              {t(`attention.group.${key}`, { count })}
            </Choice>
          ))}
        </div>
      )}

      {failedNotice(data, list, t)}

      <AttentionList items={items} isPending={isPending} limit={limit} />
    </PageSection>
  );
}

function failedNotice(data: AttentionQueue | undefined, list: Intl.ListFormat, t: TFunction) {
  if (data === undefined || data.meta.failed.length === 0) {
    return null;
  }

  const labels = data.meta.failed.map((stream) =>
    t(`attention.streams.${stream}`, {
      defaultValue: data.meta.streams.find((known) => known.stream === stream)?.label ?? stream,
    }),
  );

  return (
    <Alert tone="warning">
      <AlertTriangle aria-hidden className="size-4" />
      {t('attention.partial', { streams: list.format(labels) })}
    </Alert>
  );
}

/**
 * The wire shape onto the shared list's.
 *
 * `owner` passes through by presence, not by value: the server omits the key for a kind that has no
 * owner, and `'owner' in item` is what keeps that distinct from a `null` meaning nobody holds it.
 */
function toListItem(item: AttentionItem, t: TFunction): ListItem {
  return {
    id: item.id,
    severity: SEVERITY[item.severity],
    message: reasonText(t, item.reason, item.message),
    entity: { kind: item.entity.kind, label: item.entity.label },
    ...('owner' in item ? { owner: item.owner ?? null } : {}),
    since: item.since,
    action: {
      label: t(`attention.streams.${item.stream}`, { defaultValue: item.stream_label }),
      to: item.action.path,
    },
  };
}
