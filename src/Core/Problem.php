<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * One thing wrong with a label, said as a code with its details so the boundary words it in the user's language (AR-2).
 *
 * `element` counts from 1; 0 means the label as a whole. An error stops saving and printing, a warning only shows.
 *
 * @see docs/konzept.md §6
 */
final class Problem
{
    public const JSON         = 'json';
    public const SHAPE        = 'shape';
    public const UNKNOWN_TYPE = 'unknown_type';
    public const NEEDS_FIELD  = 'needs_field';
    public const NEEDS_MEDIA  = 'needs_media';
    public const NEEDS_BOX    = 'needs_box';
    public const OUTSIDE      = 'outside';
    public const GREW_OUTSIDE = 'grew_outside';
    public const TOO_SMALL    = 'too_small';
    public const TOO_FLAT     = 'too_flat';
    public const BAD_VALUE    = 'bad_value';
    public const NO_PICTURE   = 'no_picture';
    public const BAD_SIZE     = 'bad_size';
    public const CUT          = 'cut';

    /** @param array<string, string|int|float> $details */
    public function __construct(
        public readonly string $code,
        public readonly int $element = 0,
        public readonly array $details = [],
        public readonly bool $error = true,
    ) {
    }

    public static function warning(string $code, int $element = 0, array $details = []): self
    {
        return new self($code, $element, $details, false);
    }

    /** @return array{code: string, element: int, details: array<string, string|int|float>, error: bool} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'element' => $this->element, 'details' => $this->details, 'error' => $this->error];
    }

    /**
     * @param list<self> $problems
     */
    public static function hasError(array $problems): bool
    {
        foreach ($problems as $problem) {
            if ($problem->error) {
                return true;
            }
        }

        return false;
    }
}
