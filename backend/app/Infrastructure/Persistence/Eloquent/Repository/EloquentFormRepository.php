<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\FormRepository;
use App\Application\Tracking\DTO\FormInput;
use App\Application\Tracking\DTO\FormView;
use App\Domain\Tracking\Form\FormField;
use App\Domain\Tracking\Form\FormFieldType;
use App\Domain\Tracking\Form\FormStatus;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

final readonly class EloquentFormRepository implements FormRepository
{
    public function create(string $workspaceId, string $clientId, string $token, string $createdBy, FormInput $input, array $fields): FormView
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::transaction(function () use ($id, $workspaceId, $clientId, $token, $createdBy, $input, $fields, $now): void {
            DB::table('forms')->insert([
                ...$this->columns($input),
                'id' => $id, 'workspace_id' => $workspaceId, 'client_id' => $clientId, 'public_token' => $token,
                'status' => FormStatus::Draft->value, 'created_by' => $createdBy, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->writeFields($id, $fields);
        });

        return $this->find($workspaceId, null, $id) ?? throw new LogicException('A form just written could not be read.');
    }

    public function update(string $workspaceId, string $id, FormInput $input, array $fields): FormView
    {
        DB::transaction(function () use ($workspaceId, $id, $input, $fields): void {
            DB::table('forms')->where('workspace_id', $workspaceId)->where('id', $id)
                ->update([...$this->columns($input), 'updated_at' => now()]);
            DB::table('form_fields')->where('form_id', $id)->delete();
            $this->writeFields($id, $fields);
        });

        return $this->find($workspaceId, null, $id) ?? throw new LogicException('A form just written could not be read.');
    }

    public function setStatus(string $workspaceId, string $id, FormStatus $status): void
    {
        DB::table('forms')->where('workspace_id', $workspaceId)->where('id', $id)
            ->update(['status' => $status->value, 'updated_at' => now()]);
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?FormView
    {
        $query = $this->base()->where('f.workspace_id', $workspaceId)->where('f.id', $id);

        if ($visibleProjectIds !== null) {
            $query->whereIn('f.project_id', $visibleProjectIds);
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->view($row, $this->fieldsOf([$id])[$id] ?? []) : null;
    }

    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $projectId): array
    {
        $query = $this->base()->where('f.workspace_id', $workspaceId)->where('f.status', '!=', FormStatus::Archived->value);

        if ($visibleProjectIds !== null) {
            $query->whereIn('f.project_id', $visibleProjectIds);
        }

        if ($projectId !== null) {
            $query->where('f.project_id', $projectId);
        }

        $rows = $query->orderByDesc('f.created_at')->orderByDesc('f.id')->limit(300)->get()->all();
        $fields = $this->fieldsOf(array_values(array_map(static fn (object $r): string => (string) $r->id, $rows)));

        return array_values(array_map(fn (object $r): FormView => $this->view($r, $fields[(string) $r->id] ?? []), $rows));
    }

    public function findByToken(string $token): ?FormView
    {
        $row = $this->base()->where('f.public_token', $token)->first();

        return $row instanceof stdClass ? $this->view($row, $this->fieldsOf([(string) $row->id])[(string) $row->id] ?? []) : null;
    }

    public function tenantOf(string $formId): ?array
    {
        $row = DB::table('forms')->where('id', $formId)->first(['workspace_id', 'client_id']);

        return $row instanceof stdClass ? ['workspace_id' => (string) $row->workspace_id, 'client_id' => (string) $row->client_id] : null;
    }

    public function tokenExists(string $token): bool
    {
        return DB::table('forms')->where('public_token', $token)->exists();
    }

    public function submissionCounts(array $formIds): array
    {
        if ($formIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('form_submissions')->whereIn('form_id', $formIds)->where('traffic_class', 'valid')
            ->selectRaw('form_id, count(*) as n')->groupBy('form_id')->get() as $row) {
            $out[(string) $row->form_id] = (int) $row->n;
        }

        return $out;
    }

    private function base(): Builder
    {
        return DB::table('forms as f')
            ->join('clients as c', 'c.id', '=', 'f.client_id')
            ->join('projects as p', 'p.id', '=', 'f.project_id')
            ->select('f.*', 'c.name as client_name', 'p.name as project_name');
    }

    /** @return array<string, mixed> */
    private function columns(FormInput $i): array
    {
        return [
            'project_id' => $i->projectId, 'name' => $i->name, 'default_locale' => $i->defaultLocale, 'creates_lead' => $i->createsLead,
            'submit_label_ar' => $i->submitLabelAr, 'submit_label_en' => $i->submitLabelEn,
            'thank_you_ar' => $i->thankYouAr, 'thank_you_en' => $i->thankYouEn,
            'redirect_url' => $i->redirectUrl, 'theme' => $i->theme, 'accent' => $i->accent, 'logo_url' => $i->logoUrl,
        ];
    }

    /** @param list<FormField> $fields */
    private function writeFields(string $formId, array $fields): void
    {
        $now = now();

        foreach ($fields as $f) {
            DB::table('form_fields')->insert([
                'id' => (string) Str::uuid7(), 'form_id' => $formId, 'field_key' => $f->key, 'type' => $f->type->value, 'position' => $f->position,
                'label_ar' => $f->labelAr, 'label_en' => $f->labelEn, 'placeholder_ar' => $f->placeholderAr, 'placeholder_en' => $f->placeholderEn,
                'help_ar' => $f->helpAr, 'help_en' => $f->helpEn, 'required' => $f->required, 'default_value' => $f->default,
                'options' => json_encode($f->options, JSON_THROW_ON_ERROR),
                'validation' => json_encode($f->validation, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                'visibility' => $f->visibility === null ? null : json_encode($f->visibility, JSON_THROW_ON_ERROR),
                'maps_to' => $f->mapsTo, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  list<string>  $formIds
     * @return array<string, list<FormField>>
     */
    private function fieldsOf(array $formIds): array
    {
        if ($formIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('form_fields')->whereIn('form_id', $formIds)->orderBy('position')->get() as $r) {
            $out[(string) $r->form_id][] = new FormField(
                (string) $r->field_key, FormFieldType::from((string) $r->type), (int) $r->position,
                $this->s($r->label_ar), $this->s($r->label_en), $this->s($r->placeholder_ar), $this->s($r->placeholder_en),
                $this->s($r->help_ar), $this->s($r->help_en), (bool) $r->required, $this->s($r->default_value),
                $this->decode($r->options, []), $this->decode($r->validation, []), $this->decode($r->visibility, null),
                $this->s($r->maps_to),
            );
        }

        return $out;
    }

    private function s(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function decode(mixed $json, mixed $fallback): mixed
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : $fallback;
    }

    /** @param list<FormField> $fields */
    private function view(stdClass $r, array $fields): FormView
    {
        return new FormView(
            (string) $r->id, (string) $r->workspace_id, (string) $r->client_id, (string) $r->client_name,
            (string) $r->project_id, (string) $r->project_name, (string) $r->public_token, (string) $r->name,
            FormStatus::from((string) $r->status), (string) $r->default_locale, (bool) $r->creates_lead,
            $this->s($r->submit_label_ar), $this->s($r->submit_label_en), $this->s($r->thank_you_ar), $this->s($r->thank_you_en),
            $this->s($r->redirect_url), (string) $r->theme, $this->s($r->accent), $this->s($r->logo_url),
            $fields, new DateTimeImmutable((string) $r->created_at),
        );
    }
}
