<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Pdf;

use TaxmodLabel\Core\LabelError;

/**
 * D-1513: what the postage store accepts and how long it keeps a PDF — a stamp or label carries the receiver's address, so
 * it goes 14 days after its first print (like the mail module's labels); an unprinted one stays until it is printed or removed.
 *
 * @see docs/konzept.md §7
 */
final class PostageKeep
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const KEEP_AFTER_PRINT_DAYS = 14;

    /** @throws LabelError */
    public static function check(string $pdf): void
    {
        if (! str_starts_with($pdf, '%PDF')) {
            throw LabelError::notPostage('no PDF');
        }

        if (strlen($pdf) > self::MAX_BYTES) {
            throw LabelError::notPostage('larger than 2 MB');
        }
    }

    /**
     * A key other modules choose («taxship-87301»): letters, digits, dash and underscore only.
     *
     * @throws LabelError
     */
    public static function key(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key) !== 1) {
            throw LabelError::notPostage('key ' . $key);
        }

        return $key;
    }

    public static function expired(?int $printedAt, int $now): bool
    {
        return $printedAt !== null && $printedAt < $now - self::KEEP_AFTER_PRINT_DAYS * 86400;
    }
}
