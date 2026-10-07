import { Copy, Link2, Snowflake, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import type { ReportShare, ReportSnapshot } from './types';

type SharePanelProps = {
  shares: ReportShare[];
  snapshots: ReportSnapshot[];
  maxDays: number;
  /** False for an internal report, which cannot be shared at all. */
  canShare: boolean;
  isInternal: boolean;
  /** The token of the link just created, shown once. */
  freshToken: string | null;
  isWorking: boolean;
  error: string | null;
  onShare: (input: { days: number; snapshotId: string | null; recipientLabel: string }) => void;
  onRevoke: (shareId: string, reason: string) => void;
  onTakeSnapshot: (label: string) => void;
};

/**
 * Links out, and the frozen copies behind them.
 *
 * Three things here are deliberate.
 *
 * **The token is shown once and said to be shown once.** There is no endpoint that returns it again — a link
 * somebody lost is a link they should replace rather than recover — and a panel that implied otherwise would have
 * people closing it expecting to come back.
 *
 * **An internal report says why it cannot be shared**, rather than hiding the control. Hiding it produces a support
 * question; the sentence produces an edit.
 *
 * **Every link shows how many times it was opened.** Not who, and not when each time: an agency wants to know
 * whether the client read the report, and a log of a client's reading habits is data this product has no reason to
 * hold.
 */
export function SharePanel({
  shares,
  snapshots,
  maxDays,
  canShare,
  isInternal,
  freshToken,
  isWorking,
  error,
  onShare,
  onRevoke,
  onTakeSnapshot,
}: SharePanelProps) {
  const { t } = useTranslation();
  const [days, setDays] = useState(7);
  const [snapshotId, setSnapshotId] = useState<string | null>(null);
  const [recipient, setRecipient] = useState('');
  const [revoking, setRevoking] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [snapshotLabel, setSnapshotLabel] = useState('');

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('reports.share.title')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {freshToken === null ? null : (
          <Alert tone="info" className="flex flex-col gap-2">
            <span className="flex items-start gap-2">
              <Link2 aria-hidden className="mt-0.5 size-4 shrink-0" />
              {/*
                Said plainly. There is no way back to this value, which is deliberate rather than an omission, and a
                panel that did not say so would have people closing it expecting to return.
              */}
              <span>{t('reports.share.tokenOnce')}</span>
            </span>
            <code className="bg-surface-sunken text-content block overflow-x-auto rounded-md p-2 font-mono text-xs break-all">
              {`${window.location.origin}/shared-reports/${freshToken}`}
            </code>
            <span>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => {
                  void navigator.clipboard?.writeText(
                    `${window.location.origin}/shared-reports/${freshToken}`,
                  );
                }}
              >
                <Copy aria-hidden className="me-1 size-3.5" />
                {t('reports.share.copy')}
              </Button>
            </span>
          </Alert>
        )}

        <section className="flex flex-col gap-3" aria-label={t('reports.share.snapshots')}>
          <h3 className="text-content text-sm font-semibold">{t('reports.share.snapshots')}</h3>
          <p className="text-content-tertiary text-sm">{t('reports.share.snapshotsHint')}</p>

          {snapshots.length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('reports.share.noSnapshots')}</p>
          ) : (
            <ul className="flex flex-col gap-1.5">
              {snapshots.map((snapshot) => (
                <li key={snapshot.id} className="flex flex-wrap items-center gap-2 text-sm">
                  <Snowflake aria-hidden className="size-3.5" />
                  <span className="text-content">
                    {snapshot.label ?? `${snapshot.period_from} – ${snapshot.period_until}`}
                  </span>
                  <span className="text-content-tertiary text-xs">
                    {new Date(snapshot.taken_at).toLocaleString()}
                  </span>
                  <Badge tone="neutral">{snapshot.source_label}</Badge>
                </li>
              ))}
            </ul>
          )}

          <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
              event.preventDefault();
              onTakeSnapshot(snapshotLabel.trim());
              setSnapshotLabel('');
            }}
          >
            <Field label={t('reports.share.snapshotLabel')} className="min-w-48 flex-1">
              {(props) => (
                <Input
                  {...props}
                  value={snapshotLabel}
                  onChange={(event) => setSnapshotLabel(event.target.value)}
                />
              )}
            </Field>
            <Button type="submit" variant="secondary" size="sm" disabled={isWorking}>
              {t('reports.share.takeSnapshot')}
            </Button>
          </form>
        </section>

        <section className="flex flex-col gap-3" aria-label={t('reports.share.links')}>
          <h3 className="text-content text-sm font-semibold">{t('reports.share.links')}</h3>

          {isInternal ? (
            <Alert tone="warning" className="flex items-start gap-2">
              <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
              {/*
                Said rather than hidden. A report written with internal commentary in mind has it throughout — in its
                section titles, in which metrics were chosen, in what was left out — so this is an edit, not a toggle.
              */}
              <span>{t('reports.share.internalCannotBeShared')}</span>
            </Alert>
          ) : null}

          {shares.length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('reports.share.noLinks')}</p>
          ) : (
            <ul className="flex flex-col gap-2">
              {shares.map((share) => (
                <li
                  key={share.id}
                  className="border-line-subtle flex flex-col gap-2 rounded-lg border p-3"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <code className="text-content-tertiary font-mono text-xs">…{share.hint}</code>
                    <Badge
                      tone={
                        share.state === 'active'
                          ? 'accent'
                          : share.state === 'expired'
                            ? 'warning'
                            : 'danger'
                      }
                    >
                      {share.state_label}
                    </Badge>
                    {share.is_frozen ? (
                      <Badge tone="neutral">
                        <Snowflake aria-hidden className="me-1 size-3" />
                        {t('reports.share.frozen')}
                      </Badge>
                    ) : null}
                    {share.recipient_label === null ? null : (
                      <span className="text-content-secondary text-sm">
                        {share.recipient_label}
                      </span>
                    )}
                  </div>

                  <p className="text-content-tertiary text-xs">
                    {t('reports.share.meta', {
                      expires: new Date(share.expires_at).toLocaleDateString(),
                      views: share.view_count,
                    })}
                  </p>

                  {share.revoked_reason === null ? null : (
                    <p className="text-content-tertiary text-xs">
                      {t('reports.share.revokedBecause', { reason: share.revoked_reason })}
                    </p>
                  )}

                  {share.state !== 'active' || !canShare ? null : revoking === share.id ? (
                    <form
                      className="flex flex-wrap items-end gap-2"
                      onSubmit={(event) => {
                        event.preventDefault();

                        if (reason.trim() === '') {
                          return;
                        }

                        onRevoke(share.id, reason.trim());
                        setRevoking(null);
                        setReason('');
                      }}
                    >
                      <Field
                        label={t('reports.share.revokeReason')}
                        className="min-w-56 flex-1"
                        required
                      >
                        {(props) => (
                          <Input
                            {...props}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            required
                          />
                        )}
                      </Field>
                      <Button type="submit" size="sm" disabled={reason.trim() === ''}>
                        {t('reports.share.confirmRevoke')}
                      </Button>
                    </form>
                  ) : (
                    <span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setRevoking(share.id)}
                      >
                        {t('reports.share.revoke')}
                      </Button>
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}

          {!canShare || isInternal ? null : (
            <form
              className="flex flex-col gap-3"
              onSubmit={(event) => {
                event.preventDefault();
                onShare({
                  days,
                  snapshotId,
                  recipientLabel: recipient.trim(),
                });
                setRecipient('');
              }}
            >
              <ChoiceGroup label={t('reports.share.showing')} hint={t('reports.share.showingHint')}>
                <div className="flex flex-wrap gap-2">
                  <Choice isSelected={snapshotId === null} onSelect={() => setSnapshotId(null)}>
                    {t('reports.share.live')}
                  </Choice>
                  {snapshots.map((snapshot) => (
                    <Choice
                      key={snapshot.id}
                      isSelected={snapshotId === snapshot.id}
                      onSelect={() => setSnapshotId(snapshot.id)}
                    >
                      {snapshot.label ?? new Date(snapshot.taken_at).toLocaleDateString()}
                    </Choice>
                  ))}
                </div>
              </ChoiceGroup>

              <div className="flex flex-wrap items-end gap-2">
                <Field
                  label={t('reports.share.days')}
                  hint={t('reports.share.daysHint', { max: maxDays })}
                  className="min-w-32"
                >
                  {(props) => (
                    <Input
                      {...props}
                      type="number"
                      min={1}
                      max={maxDays}
                      value={days}
                      onChange={(event) => setDays(Number.parseInt(event.target.value, 10) || 1)}
                    />
                  )}
                </Field>
                <Field label={t('reports.share.recipient')} className="min-w-48 flex-1">
                  {(props) => (
                    <Input
                      {...props}
                      value={recipient}
                      onChange={(event) => setRecipient(event.target.value)}
                    />
                  )}
                </Field>
                <Button type="submit" disabled={isWorking}>
                  {t('reports.share.create')}
                </Button>
              </div>
            </form>
          )}
        </section>
      </CardContent>
    </Card>
  );
}
