<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Checks;

/**
 * Routes used by Panopticon around a manual update. They answer to any user with manage_options,
 * which includes the Panopticon connector's token, so the panel needs no second secret.
 * Responses carry states and counts only: no paths, no messages.
 */
final class ChecksController
{
    public function registerRoutes(): void
    {
        foreach (['/checks/logs' => 'baseline', '/checks/logs/compare' => 'compare', '/checks/cache/purge' => 'purge'] as $route => $method) {
            register_rest_route('mirasai/v1', $route, [
                'methods' => 'POST',
                'callback' => [$this, $method],
                'permission_callback' => [$this, 'authorize'],
            ]);
        }
    }

    public function authorize(): bool
    {
        return current_user_can('manage_options');
    }

    public function baseline(): \WP_REST_Response
    {
        return new \WP_REST_Response(LogReader::fromWordPress()->baseline());
    }

    /** @param \WP_REST_Request $request */
    public function compare($request): \WP_REST_Response
    {
        try {
            return new \WP_REST_Response(LogReader::fromWordPress()->compare((string) ($request->get_json_params()['cursor'] ?? '')));
        } catch (\Throwable) {
            return new \WP_REST_Response(['version' => 1, 'state' => 'unknown', 'error' => 'invalid_cursor'], 400);
        }
    }

    public function purge(): \WP_REST_Response
    {
        return new \WP_REST_Response(CachePurger::purge());
    }
}
