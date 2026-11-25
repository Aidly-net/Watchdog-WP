<?php

namespace WebsiteWatchdog\Agent\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WebsiteWatchdog\Agent\Local\Store;

class IncidentEndpoint
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('website-watchdog/' . WEBSITE_WATCHDOG_API_VERSION, '/incidents/(?P<id>[0-9a-f-]{36})/status', [
                'methods' => 'POST',
                'callback' => [$this, 'update'],
                'permission_callback' => [new HealthEndpoint(), 'authorize'],
            ]);
        });
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body) || array_diff(array_keys($body), ['expected_revision', 'status', 'mute_future'])
            || !isset($body['expected_revision'], $body['status'], $body['mute_future'])
            || !is_int($body['expected_revision']) || $body['expected_revision'] < 1
            || !is_string($body['status']) || !is_bool($body['mute_future'])) {
            return new WP_REST_Response(['error' => 'Invalid status request.'], 400);
        }
        $store = new Store();
        if (!$store->install()) {
            return new WP_REST_Response(['error' => 'Local incident storage unavailable.'], 500);
        }
        $id = (string) $request['id'];
        $result = $store->update_status($id, $body['expected_revision'], $body['status'], $body['mute_future']);
        $row = $store->get_incident($id);
        if (false === $row) {
            return new WP_REST_Response(['error' => 'Local incident storage unavailable.'], 500);
        }
        $codes = ['ok' => 200, 'conflict' => 409, 'not_found' => 404, 'invalid' => 400, 'error' => 500];
        $data = ['result' => $result];
        if ($row) {
            $data['revision'] = (int) $row['revision'];
            $data['status'] = $row['status'];
            $data['mute_future'] = (bool) $row['mute_future'];
        }
        $response = new WP_REST_Response($data, $codes[$result]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
