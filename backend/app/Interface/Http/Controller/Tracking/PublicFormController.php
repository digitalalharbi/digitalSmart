<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\SubmitForm;
use App\Application\Tracking\Contract\TrackingSettingsRepository;
use App\Application\Tracking\Query\GetPublicForm;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\DataLayerMap;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The form a visitor sees and the submission they make. Public, so it shows and says only what the visitor needs:
 * no workspace, client or project identifier, no lead mapping, no permissions, no internal configuration.
 */
final class PublicFormController extends Controller
{
    public function show(string $token, GetPublicForm $get, TrackingSettingsRepository $settings): JsonResponse
    {
        $form = $get($token);

        if ($form === null) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => [
            'token' => $form->publicToken,
            'name' => $form->name,
            'default_locale' => $form->defaultLocale,
            'submit_label' => ['ar' => $form->submitLabelAr, 'en' => $form->submitLabelEn],
            'theme' => $form->theme,
            'accent' => $form->accent,
            'logo_url' => $form->logoUrl,
            /* Names only: what the page pushes to the visitor's own data layer. No project is named. */
            'data_layer' => DataLayerMap::resolve($settings->dataLayerOverrides($form->projectId)),
            'fields' => array_map(static function ($f): array {
                /* The public gets what it needs to draw and validate: never `maps_to`, which names the lead's fields. */
                $field = FormController::field($f);
                unset($field['maps_to']);

                return $field;
            }, $form->fields),
        ]]);
    }

    public function submit(Request $request, string $token, SubmitForm $submit): JsonResponse
    {
        $body = strlen($request->getContent()) <= 16384 ? $request->json()->all() : [];
        $values = is_array($body['values'] ?? null) ? $body['values'] : [];

        /* A field no person sees. Anything in it is a script filling every box it finds. */
        if (($body['website'] ?? '') !== '') {
            return response()->json(['data' => ['received' => true]], Response::HTTP_ACCEPTED);
        }

        $touchpoint = $body['touchpoint'] ?? null;
        $eventId = $body['event_id'] ?? null;

        if (! is_string($eventId) || preg_match('/^[A-Za-z0-9_\-]{8,40}$/', $eventId) !== 1) {
            return response()->json(['message' => 'Invalid submission.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $submit(
            $token,
            $values,
            is_string($touchpoint) && preg_match('/^[A-Za-z0-9]{20}$/', $touchpoint) === 1 ? $touchpoint : null,
            $eventId,
            is_string($body['consent'] ?? null) ? ConsentState::tryFrom($body['consent']) : null,
            is_string($body['locale'] ?? null) ? $body['locale'] : 'ar',
        );

        if ($result === null) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($result['errors'] !== []) {
            return response()->json(['message' => 'Please check the highlighted fields.', 'errors' => $result['errors']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ['received' => true, 'thank_you' => $result['thank_you'], 'redirect_url' => $result['redirect_url']]], Response::HTTP_CREATED);
    }
}
