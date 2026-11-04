<?php

declare(strict_types=1);

use App\Domain\Tracking\UtmAudit;

/**
 * @param  array<string, string>  $utm
 * @return array{id: string, project_id: string, live: bool, destination_url: string, utm: array<string, string>}
 */
function utmAuditLink(string $id, array $utm, bool $live = true, string $project = 'p1', string $url = 'https://shop.example.com/offer'): array
{
    return ['id' => $id, 'project_id' => $project, 'live' => $live, 'destination_url' => $url, 'utm' => $utm];
}

const CLEAN_UTM = ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring'];

/**
 * @param  list<array{code: string, parameters: list<string>, shared_with: list<string>}>  $findings
 * @return list<string>
 */
function utmAuditCodes(array $findings): array
{
    return array_column($findings, 'code');
}

it('says nothing about a link whose parameters are complete and consistent', function (): void {
    expect(UtmAudit::judge([utmAuditLink('a', CLEAN_UTM)]))->toBe([]);
});

it('names each required parameter that is missing', function (): void {
    $findings = UtmAudit::judge([utmAuditLink('a', ['utm_source' => 'snapchat'])]);

    expect(utmAuditCodes($findings['a']))->toBe([UtmAudit::MISSING_MEDIUM, UtmAudit::MISSING_CAMPAIGN]);
});

it('reports letter case and spaces with the parameters that carry them', function (): void {
    $findings = UtmAudit::judge([utmAuditLink('a', [...CLEAN_UTM, 'utm_campaign' => 'Spring Offer', 'utm_content' => 'Video'])]);

    expect($findings['a'][0])->toBe(['code' => UtmAudit::MIXED_CASE, 'parameters' => ['utm_campaign', 'utm_content'], 'shared_with' => []])
        ->and($findings['a'][1])->toBe(['code' => UtmAudit::HAS_SPACES, 'parameters' => ['utm_campaign'], 'shared_with' => []]);
});

it('reports a destination that already carries a campaign parameter', function (): void {
    $findings = UtmAudit::judge([utmAuditLink('a', CLEAN_UTM, url: 'https://shop.example.com/offer?utm_source=newsletter&ref=1')]);

    expect($findings['a'])->toBe([['code' => UtmAudit::DESTINATION_HAS_PARAMETER, 'parameters' => ['utm_source'], 'shared_with' => []]]);
});

it('reports two live links in one project that carry the identical set, each naming the other', function (): void {
    $findings = UtmAudit::judge([utmAuditLink('a', CLEAN_UTM), utmAuditLink('b', CLEAN_UTM)]);

    expect($findings['a'])->toBe([['code' => UtmAudit::DUPLICATE_SET, 'parameters' => [], 'shared_with' => ['b']]])
        ->and($findings['b'][0]['shared_with'])->toBe(['a']);
});

it('does not call it a duplicate across projects, for a link that is not live, or when content tells them apart', function (): void {
    expect(UtmAudit::judge([
        utmAuditLink('a', CLEAN_UTM),
        utmAuditLink('b', CLEAN_UTM, project: 'p2'),
        utmAuditLink('c', CLEAN_UTM, live: false),
        utmAuditLink('d', [...CLEAN_UTM, 'utm_content' => 'video_2']),
    ]))->toBe([]);
});

it('does not treat two links with no parameters at all as one set', function (): void {
    $findings = UtmAudit::judge([utmAuditLink('a', []), utmAuditLink('b', [])]);

    expect(utmAuditCodes($findings['a']))->not->toContain(UtmAudit::DUPLICATE_SET);
});
