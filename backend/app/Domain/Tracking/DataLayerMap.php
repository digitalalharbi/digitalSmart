<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use App\Domain\Tracking\Exception\InvalidDataLayerMap;

/**
 * Which `dataLayer` event name each digitalSmart event is pushed as, for Tag Manager to listen to.
 *
 * Only events a visitor's page can know about are pushed from the browser; the ones this product writes itself
 * (`link_click`, `lead_created`, and everything after) happen on the server and reach Tag Manager only through a server
 * container, which is a later destination. The default is a prefix, so the names this product emits cannot collide with a
 * client's own (`form_submit` is already something GTM's built-in triggers use).
 */
final class DataLayerMap
{
    public const array BROWSER_EVENTS = ['landing_view', 'form_view', 'form_start', 'form_step_completed', 'form_validation_error', 'form_submit', 'whatsapp_click'];

    /** @return array<string, string> */
    public static function defaults(): array
    {
        $out = [];

        foreach (self::BROWSER_EVENTS as $event) {
            $out[$event] = 'ds_'.$event;
        }

        return $out;
    }

    /**
     * Defaults overlaid with a project's own names, refusing anything that could break a data layer.
     *
     * @param  array<string, mixed>  $custom
     * @return array<string, string>
     */
    public static function resolve(array $custom): array
    {
        $map = self::defaults();

        foreach ($custom as $event => $name) {
            if (! in_array($event, self::BROWSER_EVENTS, true)) {
                throw new InvalidDataLayerMap(sprintf('"%s" is not an event a page reports.', (string) $event));
            }

            $name = is_string($name) ? trim($name) : '';

            if ($name === '') {
                continue;
            }

            /* gtm.* is Tag Manager's own namespace; a name there would overwrite its internal events. */
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{1,59}$/', $name) !== 1 || str_starts_with(strtolower($name), 'gtm')) {
                throw new InvalidDataLayerMap(sprintf('"%s" is not a usable data layer event name.', $name));
            }

            $map[$event] = $name;
        }

        return $map;
    }

    /**
     * The snippet a landing page carries: it reads the touchpoint the tracking link handed over, reports the landing view,
     * and exposes `dsTrack(event, meta)` for the page's own forms. It pushes the mapped event to `dataLayer` as well.
     *
     * Nothing in it reads or sends what a visitor types.
     *
     * @param  array<string, string>  $map
     */
    public static function snippet(string $apiBase, array $map): string
    {
        $json = json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $endpoint = rtrim($apiBase, '/').'/public/track';

        return <<<JS
        <script>
        (function () {
          var map = {$json};
          var key = 'ds_tp';
          var tp = new URLSearchParams(location.search).get(key);
          try { if (tp) { sessionStorage.setItem(key, tp); } else { tp = sessionStorage.getItem(key); } } catch (e) {}
          window.dataLayer = window.dataLayer || [];
          function id() { var a = new Uint8Array(12); crypto.getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
          window.dsTrack = function (event, meta) {
            var eventId = id();
            if (map[event]) { window.dataLayer.push({ event: map[event], ds_event_id: eventId }); }
            if (!tp) { return; }
            try { navigator.sendBeacon('{$endpoint}', JSON.stringify({ touchpoint: tp, event: event, event_id: eventId, path: location.pathname, meta: meta || {} })); } catch (e) {}
          };
          window.dsTrack('landing_view');
        })();
        </script>
        JS;
    }
}
