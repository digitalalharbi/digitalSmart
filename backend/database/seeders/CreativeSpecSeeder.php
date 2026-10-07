<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\SpecRequirement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The creative specifications this product has actually read off a platform's own documentation.
 *
 * **It is a short list, and that is the finding rather than an omission.** The advertising platforms do not
 * publish their creative requirements in any form a program can read: Meta's Marketing API reference states
 * that limits exist and points at a marketing page rendered by JavaScript; TikTok's and Snapchat's main
 * numbers live in help centres behind the same; the third-party guides that aggregate them disagree with
 * each other and go stale without saying so.
 *
 * Snapchat is the exception. Its **developer** documentation states concrete numbers for several creative
 * types, so those are recorded here with the page and the date they were read. Everything else is absent,
 * and a check against an absent specification reports `no_spec` rather than passing — which tells an agency
 * exactly which placements their work goes out on unchecked.
 *
 * Adding a row means reading a platform's own page and recording the URL and the date. Not a blog, and not
 * memory: a number with no source is a number nobody can defend when a client asks why their video was
 * rejected. See docs/CREATIVE.md.
 */
final class CreativeSpecSeeder extends Seeder
{
    /**
     * Snapchat's Marketing API creative documentation, read on the date below.
     *
     * @see https://developers.snap.com/api/marketing-api/Ads-API/creatives
     */
    private const string SNAPCHAT_SOURCE = 'https://developers.snap.com/api/marketing-api/Ads-API/creatives';

    private const string SNAPCHAT_READ_ON = '2026-10-07';

    public function run(): void
    {
        $identifiers = app(IdentifierGenerator::class);

        foreach ($this->specs() as $spec) {
            DB::table('creative_specs')->updateOrInsert(
                [
                    'provider' => $spec['provider'],
                    'placement' => $spec['placement'],
                    'kind' => $spec['kind'],
                ],
                [
                    'id' => $identifiers->generate(),
                    'bounds' => json_encode($spec['bounds'], JSON_THROW_ON_ERROR),
                    'source_url' => $spec['source_url'],
                    'documented_at' => $spec['documented_at'],
                    'recorded_by' => $spec['recorded_by'],
                    'note' => $spec['note'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->command?->line(sprintf(
            '  Creative specs: %d recorded. Every other placement reports "no spec recorded", '
            .'which is honest — see docs/CREATIVE.md.',
            count($this->specs()),
        ));
    }

    /**
     * @return list<array{
     *     provider: string,
     *     placement: string,
     *     kind: string,
     *     bounds: array<string, array<string, mixed>>,
     *     source_url: string,
     *     documented_at: string,
     *     recorded_by: string,
     *     note: string|null,
     * }>
     */
    private function specs(): array
    {
        $snapchat = [
            'source_url' => self::SNAPCHAT_SOURCE,
            'documented_at' => self::SNAPCHAT_READ_ON,
            'recorded_by' => 'Read from the Snapchat Marketing API creatives documentation',
        ];

        return [
            [
                ...$snapchat,
                'provider' => 'snapchat',
                'placement' => 'story_ad_preview_image',
                'kind' => AssetKind::Image->value,
                'bounds' => [
                    SpecRequirement::Width->value => ['min' => 360],
                    SpecRequirement::Height->value => ['min' => 600],
                    // Stated as a 3:5 resolution, with the tolerance real exports need.
                    SpecRequirement::AspectRatio->value => ['allowed' => ['3:5'], 'tolerance' => 0.02],
                    SpecRequirement::FileSize->value => ['max' => 2 * 1024 * 1024],
                    SpecRequirement::Format->value => ['allowed' => ['png']],
                ],
                'note' => 'Story Ads preview image: minimum 360x600 px, 3:5, maximum 2 MB, PNG.',
            ],
            [
                ...$snapchat,
                'provider' => 'snapchat',
                'placement' => 'story_ad_logo',
                'kind' => AssetKind::Image->value,
                'bounds' => [
                    /*
                     * An exact size rather than a minimum, which is what the page states. Expressed as a
                     * min and max of the same number, because inventing a range would accept files the
                     * platform rejects.
                     */
                    SpecRequirement::Width->value => ['min' => 993, 'max' => 993],
                    SpecRequirement::Height->value => ['min' => 284, 'max' => 284],
                    SpecRequirement::FileSize->value => ['max' => 2 * 1024 * 1024],
                    SpecRequirement::Format->value => ['allowed' => ['png']],
                ],
                'note' => 'Story Ads logo: exactly 993x284 px, maximum 2 MB, PNG.',
            ],
            [
                ...$snapchat,
                'provider' => 'snapchat',
                'placement' => 'app_end_card',
                'kind' => AssetKind::Image->value,
                'bounds' => [
                    // Either orientation, so the ratio carries the requirement rather than the dimensions.
                    SpecRequirement::AspectRatio->value => ['allowed' => ['9:16', '16:9'], 'tolerance' => 0.02],
                    SpecRequirement::Width->value => ['min' => 1080],
                    SpecRequirement::FileSize->value => ['max' => 1024 * 1024],
                    SpecRequirement::Format->value => ['allowed' => ['jpg', 'jpeg', 'png']],
                ],
                'note' => 'App end card: 1080x1920 or 1920x1080 px, maximum 1 MB, JPG/JPEG/PNG.',
            ],
            [
                ...$snapchat,
                'provider' => 'snapchat',
                'placement' => 'app_icon',
                'kind' => AssetKind::Image->value,
                'bounds' => [
                    SpecRequirement::Width->value => ['min' => 200, 'max' => 2000],
                    SpecRequirement::Height->value => ['min' => 200, 'max' => 2000],
                    SpecRequirement::AspectRatio->value => ['allowed' => ['1:1'], 'tolerance' => 0.01],
                    SpecRequirement::Format->value => ['allowed' => ['png']],
                ],
                'note' => 'App icon: square PNG, minimum 200x200 px, maximum 2000x2000 px.',
            ],
            [
                ...$snapchat,
                'provider' => 'snapchat',
                'placement' => 'headline',
                'kind' => AssetKind::Copy->value,
                'bounds' => [
                    SpecRequirement::TextLength->value => ['max' => 34],
                ],
                'note' => 'Headline: maximum 34 characters.',
            ],
            [
                ...$snapchat,
                'provider' => 'snapchat',
                'placement' => 'brand_name',
                'kind' => AssetKind::Copy->value,
                'bounds' => [
                    SpecRequirement::TextLength->value => ['max' => 32],
                ],
                'note' => 'Brand name: maximum 32 characters.',
            ],
        ];
    }
}
