<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

/**
 * Which agents a request is for.
 *
 * Deterministic and readable: words in English and Arabic mapped to the agents that own them. A question that names
 * nothing recognisable goes to the Analyst, which looks at everything a person can see — never to every agent at
 * once, because a run that does everything to answer anything is how a "quick question" opens forty tasks.
 */
final class AgentRouter
{
    public const array AGENTS = ['analyst', 'finance', 'creative', 'leads', 'reports', 'operations', 'tracking'];

    /** @var array<string, list<string>> */
    private const array WORDS = [
        'creative' => ['creative', 'ad ', 'ads', 'fatigue', 'tiring', 'design', 'hook', 'thumbnail', 'إبداع', 'تصميم', 'الإعلانات', 'إعلان', 'إرهاق', 'كريتف'],
        'leads' => ['lead', 'qualified', 'sales', 'whatsapp', 'enquir', 'محتمل', 'ليد', 'مبيعات', 'مؤهل', 'واتساب'],
        'reports' => ['report', 'weekly', 'monthly', 'summary', 'brief', 'تقرير', 'أسبوعي', 'شهري', 'ملخص'],
        'finance' => ['budget', 'spend', 'overspend', 'pacing', 'money', 'ميزانية', 'إنفاق', 'صرف'],
        'operations' => ['project', 'blocked', 'overdue', 'task', 'مشروع', 'متأخر', 'مهام', 'مهمة'],
        'tracking' => ['tracking', 'pixel', 'conversion api', 'gtm', 'tag', 'تتبع', 'التتبع', 'بكسل'],
        'analyst' => ['campaign', 'cpa', 'roas', 'cost', 'performance', 'حملة', 'حملات', 'أداء', 'تكلفة', 'عائد'],
    ];

    /** @return non-empty-list<string> */
    public static function route(string $intent): array
    {
        $text = mb_strtolower(' '.$intent.' ');
        $chosen = [];

        foreach (self::WORDS as $agent => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $chosen[] = $agent;

                    break;
                }
            }
        }

        return $chosen === [] ? ['analyst'] : $chosen;
    }
}
