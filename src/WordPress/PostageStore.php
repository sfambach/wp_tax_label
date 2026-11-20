<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\WordPress\Import\Vault;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\Pdf\PostageKeep;

/**
 * D-1513: the one store of postage PDFs (a stamp from the Post shop, a label from a mail), so a stamp is printed again without
 * choosing the file. Each PDF is sealed with the site's vault (D-951) in wp-content/taxmod-postage/, closed to the web; which keys
 * exist and when each was first printed is one option. Other modules reach it only through the filters and actions below,
 * under a key of their own; a PDF goes 14 days after its first print (PostageKeep).
 *
 * @see docs/konzept.md §7
 */
final class PostageStore
{
    /** apply_filters(PUT, false, key, pdf) → true once stored, else the reason in words */
    public const PUT = 'taxmod_postage_put';

    /** apply_filters(GET, null, key) → ?string */
    public const GET = 'taxmod_postage_get';

    /** apply_filters(KEYS, []) → list of stored keys */
    public const KEYS = 'taxmod_postage_keys';

    /** do_action(PRINTED, key): from now on the PDF is kept 14 days */
    public const PRINTED = 'taxmod_postage_printed';

    /** do_action(DELETE, key): when the thing it belongs to is gone for good */
    public const DELETE = 'taxmod_postage_delete';

    public const PURGE = 'taxmod_postage_purge';

    private const FOLDER = 'taxmod-postage';

    private const INDEX = 'taxmod_postage_index';

    public static function boot(): void
    {
        // `mixed`: a filter carries anything; the answer is true or the reason it was not stored.
        add_filter(self::PUT, static function (mixed $stored, mixed $key = '', mixed $pdf = ''): bool|string {
            if (! is_string($key) || ! is_string($pdf)) {
                return is_string($stored) ? $stored : $stored === true;
            }

            try {
                self::put($key, $pdf);
            } catch (LabelError $refused) {
                return ProblemWords::ofError($refused);
            }

            return true;
        }, 10, 3);
        add_filter(self::GET, static fn (mixed $pdf, mixed $key = ''): ?string => is_string($key) ? self::get($key) : (is_string($pdf) ? $pdf : null), 10, 2);
        add_filter(self::KEYS, static fn (mixed $keys): array => [...(is_array($keys) ? $keys : []), ...array_keys(self::index())]);
        add_action(self::PRINTED, static fn (mixed $key): bool => is_string($key) && self::printed($key));
        add_action(self::DELETE, static fn (mixed $key): bool => is_string($key) && self::delete($key));
        add_action(self::PURGE, self::purge(...));
        add_action('admin_init', static function (): void {
            if (wp_next_scheduled(self::PURGE) === false) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE);
            }
        });
    }

    /** @throws LabelError when it is no PDF, too large, the key is refused or the folder cannot be written */
    public static function put(string $key, string $pdf): void
    {
        $key = PostageKeep::key($key);
        PostageKeep::check($pdf);
        $directory = self::directory();

        if (! wp_mkdir_p($directory)) {
            throw LabelError::notPostage('folder');
        }

        file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($directory . '/index.php', "<?php declare(strict_types=1);\n// Silence.\n");

        if (file_put_contents(self::path($key), Vault::for(self::FOLDER)->seal($pdf)) === false) {
            throw LabelError::notPostage('write');
        }

        $index = self::index();
        // Stored again (the same stamp chosen twice): its first print still counts.
        $index[$key] = ['printed' => $index[$key]['printed'] ?? null];
        update_option(self::INDEX, $index, false);
    }

    public static function get(string $key): ?string
    {
        if (! isset(self::index()[$key]) || ! is_readable(self::path($key))) {
            return null;
        }

        try {
            return Vault::for(self::FOLDER)->open((string) file_get_contents(self::path($key)));
        } catch (SecretDoesNotOpen) {
            return null; // the salts changed; the stamp is chosen again once
        }
    }

    public static function printed(string $key): bool
    {
        $index = self::index();

        if (! isset($index[$key]) || $index[$key]['printed'] !== null) {
            return false;
        }

        $index[$key]['printed'] = time();
        update_option(self::INDEX, $index, false);

        return true;
    }

    public static function delete(string $key): bool
    {
        $index = self::index();
        unset($index[$key]);
        update_option(self::INDEX, $index, false);

        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key) === 1 && is_file(self::path($key)) && unlink(self::path($key));
    }

    /** Daily (WP-Cron): what was printed more than 14 days ago goes. */
    public static function purge(): void
    {
        foreach (self::index() as $key => $row) {
            if (PostageKeep::expired($row['printed'], time())) {
                self::delete($key);
            }
        }
    }

    /** @return array<string, array{printed: ?int}> */
    private static function index(): array
    {
        $index = get_option(self::INDEX, []);
        $rows = [];

        foreach (is_array($index) ? $index : [] as $key => $row) {
            $printed = is_array($row) && is_int($row['printed'] ?? null) ? $row['printed'] : null;
            $rows[(string) $key] = ['printed' => $printed];
        }

        return $rows;
    }

    private static function directory(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . self::FOLDER;
    }

    private static function path(string $key): string
    {
        return self::directory() . '/' . hash('sha256', $key) . '.pdf.sealed';
    }
}
