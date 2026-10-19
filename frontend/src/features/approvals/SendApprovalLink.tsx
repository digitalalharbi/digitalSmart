import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { MessageCircle, Mail } from 'lucide-react';
import { api } from '@/shared/api/client';
import { Button } from '@/shared/ui/button';

/**
 * Send yourself a one-time link to decide this approval away from the desk.
 *
 * Only to yourself, and only if you could decide it here. The link is never shown on this screen — it goes to the
 * channel — and where the channel is not connected yet the page says so instead of pretending.
 */
export function SendApprovalLink({ approvalId }: { approvalId: string }) {
  const { t } = useTranslation();
  const send = useMutation({
    mutationFn: async (channel: 'whatsapp' | 'email') => {
      const { data } = await api.post<{ data: { delivery: string } }>(
        `/approvals/${approvalId}/links`,
        { channel },
      );

      return data.data.delivery;
    },
  });

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-content-tertiary text-xs">{t('approvalLink.send')}</span>
      <Button
        variant="ghost"
        size="sm"
        disabled={send.isPending}
        onClick={() => send.mutate('whatsapp')}
      >
        <MessageCircle />
        {t('approvalLink.sendWhatsApp')}
      </Button>
      <Button
        variant="ghost"
        size="sm"
        disabled={send.isPending}
        onClick={() => send.mutate('email')}
      >
        <Mail />
        {t('approvalLink.sendEmail')}
      </Button>
      {send.isSuccess ? (
        <span role="status" className="text-content-secondary text-xs">
          {send.data === 'awaiting_credentials'
            ? t('approvalLink.awaiting')
            : t('approvalLink.sent')}
        </span>
      ) : null}
    </div>
  );
}
