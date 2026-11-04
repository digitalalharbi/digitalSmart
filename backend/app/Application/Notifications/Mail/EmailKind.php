<?php

declare(strict_types=1);

namespace App\Application\Notifications\Mail;

/** The messages this product can write. Each has a fixed wording in both languages; nothing here carries a figure. */
enum EmailKind: string
{
    case Invitation = 'invitation';
    case WeeklyReport = 'weekly_report';
    case NeedsAttention = 'needs_attention';
    case ReportShared = 'report_shared';
    case ApprovalNeeded = 'approval_needed';
    case IntegrationAlert = 'integration_alert';
    case SecurityNotice = 'security_notice';
}
