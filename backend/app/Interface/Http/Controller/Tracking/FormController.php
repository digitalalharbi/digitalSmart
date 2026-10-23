<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\SaveForm;
use App\Application\Tracking\Command\SetFormStatus;
use App\Application\Tracking\DTO\FormInput;
use App\Application\Tracking\DTO\FormView;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Query\ListForms;
use App\Domain\Tracking\Form\FormField;
use App\Domain\Tracking\Form\FormStatus;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The agency's side of forms: build, read, publish. */
final class FormController extends Controller
{
    public function index(Request $request, ListForms $list): JsonResponse
    {
        $project = $request->query('project_id');

        return response()->json(['data' => array_map(
            self::resource(...),
            $list(is_string($project) && preg_match('/^[0-9a-f-]{36}$/i', $project) === 1 ? $project : null),
        )]);
    }

    public function show(string $form, ListForms $list): JsonResponse
    {
        return response()->json(['data' => self::resource($list->one($form))]);
    }

    public function store(Request $request, SaveForm $save): JsonResponse
    {
        return response()->json(['data' => self::resource($this->save($request, $save, null))], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $form, SaveForm $save): JsonResponse
    {
        return response()->json(['data' => self::resource($this->save($request, $save, $form))]);
    }

    public function status(Request $request, string $form, SetFormStatus $set): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:'.implode(',', FormStatus::values())]]);

        try {
            $set($form, FormStatus::from((string) $data['status']));
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        return response()->json(['data' => ['status' => $data['status']]]);
    }

    /** @return array<string, mixed> */
    public static function resource(FormView $f): array
    {
        return [
            'id' => $f->id,
            'client' => ['id' => $f->clientId, 'name' => $f->clientName],
            'project' => ['id' => $f->projectId, 'name' => $f->projectName],
            'name' => $f->name,
            'status' => $f->status->value,
            'public_url_token' => $f->publicToken,
            'default_locale' => $f->defaultLocale,
            'creates_lead' => $f->createsLead,
            'submit_label_ar' => $f->submitLabelAr,
            'submit_label_en' => $f->submitLabelEn,
            'thank_you_ar' => $f->thankYouAr,
            'thank_you_en' => $f->thankYouEn,
            'redirect_url' => $f->redirectUrl,
            'theme' => $f->theme,
            'accent' => $f->accent,
            'logo_url' => $f->logoUrl,
            'fields' => array_map(self::field(...), $f->fields),
            'submissions' => $f->submissions === null ? null : ['value' => $f->submissions, 'state' => 'live'],
            'created_at' => $f->createdAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function field(FormField $x): array
    {
        return [
            'key' => $x->key, 'type' => $x->type->value, 'position' => $x->position,
            'label_ar' => $x->labelAr, 'label_en' => $x->labelEn,
            'placeholder_ar' => $x->placeholderAr, 'placeholder_en' => $x->placeholderEn,
            'help_ar' => $x->helpAr, 'help_en' => $x->helpEn,
            'required' => $x->required, 'default' => $x->default,
            'options' => $x->options, 'validation' => (object) $x->validation,
            'visibility' => $x->visibility, 'maps_to' => $x->mapsTo,
        ];
    }

    private function save(Request $request, SaveForm $save, ?string $id): FormView
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'default_locale' => ['nullable', 'string', 'in:ar,en'],
            'creates_lead' => ['nullable', 'boolean'],
            'submit_label_ar' => ['nullable', 'string', 'max:80'],
            'submit_label_en' => ['nullable', 'string', 'max:80'],
            'thank_you_ar' => ['nullable', 'string', 'max:600'],
            'thank_you_en' => ['nullable', 'string', 'max:600'],
            'redirect_url' => ['nullable', 'string', 'max:2048'],
            'theme' => ['nullable', 'string', 'in:auto,light,dark'],
            'accent' => ['nullable', 'string', 'max:7'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'fields' => ['present', 'array', 'max:40'],
            'fields.*' => ['array'],
        ]);

        $text = static fn (string $k): ?string => isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '' ? $data[$k] : null;

        try {
            return $save(new FormInput(
                projectId: (string) $data['project_id'],
                name: (string) $data['name'],
                defaultLocale: $text('default_locale') ?? 'ar',
                createsLead: (bool) ($data['creates_lead'] ?? false),
                fields: array_values($data['fields']),
                submitLabelAr: $text('submit_label_ar'),
                submitLabelEn: $text('submit_label_en'),
                thankYouAr: $text('thank_you_ar'),
                thankYouEn: $text('thank_you_en'),
                redirectUrl: $text('redirect_url'),
                theme: $text('theme') ?? 'auto',
                accent: $text('accent'),
                logoUrl: $text('logo_url'),
            ), $id);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
    }
}
