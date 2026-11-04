<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use App\Application\Notifications\Mail\EmailKind;

/**
 * The wording of every message, in both languages, written as Arabic and English rather than translated one from the other.
 *
 * `{subject}` is the thing the message is about (a workspace, a report, a connection); `{name}` the recipient. Both are
 * escaped by the renderer. No wording here states a figure, a client's money or a credential: a mail is read in places the
 * product does not control, so it points at the product instead of repeating it.
 */
final class EmailCatalogue
{
    /**
     * @return array{subject: string, heading: string, lines: list<string>, cta: string}
     */
    public static function for(EmailKind $kind, string $locale): array
    {
        $arabic = $locale === 'ar';

        return match ($kind) {
            EmailKind::Invitation => $arabic
                ? ['subject' => 'دعوة إلى مساحة عمل', 'heading' => 'دُعيت إلى {subject}', 'lines' => ['تمت دعوتك للانضمام إلى {subject} على digitalSmart.', 'الرابط يعمل مرة واحدة وتنتهي صلاحيته.'], 'cta' => 'قبول الدعوة']
                : ['subject' => 'You are invited to a workspace', 'heading' => 'You are invited to {subject}', 'lines' => ['You have been invited to join {subject} on digitalSmart.', 'The link works once and expires.'], 'cta' => 'Accept the invitation'],
            EmailKind::WeeklyReport => $arabic
                ? ['subject' => 'تقريرك الأسبوعي', 'heading' => 'تقريرك الأسبوعي جاهز', 'lines' => ['التقرير «{subject}» جاهز للاطلاع.'], 'cta' => 'فتح التقرير']
                : ['subject' => 'Your weekly report', 'heading' => 'Your weekly report is ready', 'lines' => ['The report “{subject}” is ready to read.'], 'cta' => 'Open the report'],
            EmailKind::NeedsAttention => $arabic
                ? ['subject' => 'تنبيه يحتاج متابعة', 'heading' => 'هناك ما يحتاج متابعتك', 'lines' => ['{subject}'], 'cta' => 'عرض التفاصيل']
                : ['subject' => 'Something needs your attention', 'heading' => 'Something needs your attention', 'lines' => ['{subject}'], 'cta' => 'See the details'],
            EmailKind::ReportShared => $arabic
                ? ['subject' => 'تمت مشاركة تقرير معك', 'heading' => 'تمت مشاركة تقرير معك', 'lines' => ['شُورك معك التقرير «{subject}».', 'الرابط شخصي، فلا تُعد توجيهه.'], 'cta' => 'فتح التقرير']
                : ['subject' => 'A report was shared with you', 'heading' => 'A report was shared with you', 'lines' => ['The report “{subject}” was shared with you.', 'The link is personal — please do not forward it.'], 'cta' => 'Open the report'],
            EmailKind::ApprovalNeeded => $arabic
                ? ['subject' => 'بانتظار موافقتك', 'heading' => 'طلب بانتظار موافقتك', 'lines' => ['{subject}'], 'cta' => 'مراجعة الطلب']
                : ['subject' => 'An approval is waiting for you', 'heading' => 'An approval is waiting for you', 'lines' => ['{subject}'], 'cta' => 'Review the request'],
            EmailKind::IntegrationAlert => $arabic
                ? ['subject' => 'ربط يحتاج متابعة', 'heading' => 'ربط يحتاج متابعتك', 'lines' => ['الربط «{subject}» يحتاج إلى انتباهك.'], 'cta' => 'فتح الربط']
                : ['subject' => 'A connection needs attention', 'heading' => 'A connection needs your attention', 'lines' => ['The connection “{subject}” needs your attention.'], 'cta' => 'Open the connection'],
            EmailKind::SecurityNotice => $arabic
                ? ['subject' => 'تنبيه أمني', 'heading' => 'تنبيه أمني على حسابك', 'lines' => ['{subject}', 'إن لم يكن هذا من فعلك فغيّر كلمة مرورك الآن.'], 'cta' => 'مراجعة الحساب']
                : ['subject' => 'Security notice', 'heading' => 'A security notice for your account', 'lines' => ['{subject}', 'If this was not you, change your password now.'], 'cta' => 'Review the account'],
        };
    }

    /** What every message says at the bottom, so a stranger who received one by mistake knows what it is and that nothing is needed. */
    public static function footer(string $locale): string
    {
        return $locale === 'ar'
            ? 'أُرسلت هذه الرسالة من digitalSmart. إن لم تكن تتوقعها فتجاهلها، ولا يُطلب منك شيء.'
            : 'This message was sent by digitalSmart. If you were not expecting it, ignore it — nothing is asked of you.';
    }

    public static function greeting(string $locale, ?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return $locale === 'ar' ? 'مرحبًا {name}،' : 'Hello {name},';
    }
}
