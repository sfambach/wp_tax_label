<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\WordPress\Plugin as Modeller;

/**
 * The editor's two doors to the server — draw and save — the same work the abilities do, behind the REST nonce of the admin screen.
 *
 * ```mermaid
 * flowchart LR
 *   E["editor.js"] -- "X-WP-Nonce" --> P["POST taxmod-label/v1/preview"] --> A["LabelAbilities::preview"]
 *   E -- "X-WP-Nonce" --> S["POST taxmod-label/v1/save"] --> B["LabelAbilities::save"]
 * ```
 *
 * @see docs/konzept.md §5
 */
final class EditorRoutes
{
    public const NAMESPACE = 'taxmod-label/v1';

    public function register(): void
    {
        $allowed = static fn (): bool => current_user_can(Modeller::CAPABILITY);
        register_rest_route(self::NAMESPACE, '/preview', [
            'methods'             => 'POST',
            'permission_callback' => $allowed,
            'callback'            => fn (\WP_REST_Request $request): \WP_REST_Response|\WP_Error => $this->answer(fn (): array => (new LabelAbilities())->preview(self::input($request, ['id', 'layout', 'width', 'height', 'values', 'dpi', 'as']))),
        ]);
        register_rest_route(self::NAMESPACE, '/save', [
            'methods'             => 'POST',
            'permission_callback' => $allowed,
            'callback'            => fn (\WP_REST_Request $request): \WP_REST_Response|\WP_Error => $this->answer(fn (): array => (new LabelAbilities())->save(self::input($request, ['id', 'category', 'name', 'key', 'width', 'height', 'roll', 'copies', 'layout', 'rolls', 'dry_run']))),
        ]);
    }

    /**
     * Only the keys the ability knows, each sanitised for its kind; the layout stays raw JSON because the core parses and judges it.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function input(\WP_REST_Request $request, array $keys): array
    {
        $raw   = $request->get_json_params() ?: $request->get_params();
        $input = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $value       = $raw[$key];
            $input[$key] = match ($key) {
                'id', 'category', 'width', 'height', 'roll', 'copies' => absint($value),
                'dpi'                                                 => (float) $value,
                'name', 'key', 'as'                                   => sanitize_text_field((string) $value),
                'rolls'                                               => array_map('absint', (array) $value),
                'dry_run'                                             => (bool) $value,
                'values'                                              => array_map(static fn (mixed $v): string => sanitize_text_field(is_scalar($v) ? (string) $v : ''), (array) $value),
                default                                               => $value,
            };
        }

        return $input;
    }

    /** @param \Closure(): array<string, mixed> $act */
    private function answer(\Closure $act): \WP_REST_Response|\WP_Error
    {
        $answer = LabelAbilities::guarded($act);

        return $answer instanceof \WP_Error ? $answer : new \WP_REST_Response($answer);
    }
}
