<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * Why a label action was refused, as a reason the boundary words in the user's language (AR-2).
 *
 * @see docs/konzept.md §7
 */
final class LabelError extends \RuntimeException
{
    private function __construct(public readonly string $reason, public readonly string $detail = '')
    {
        parent::__construct($reason . ($detail === '' ? '' : ': ' . $detail));
    }

    /** D-1513: what the postage store refuses — not a PDF, too large, or a key it does not take. */
    public static function notPostage(string $why): self
    {
        return new self('not_postage', $why);
    }

    /** D-1517: what the postage print takes — a PNG of the cropped label, not too large, of a plausible size. */
    public static function notAPostagePicture(string $why): self
    {
        return new self('not_postage_picture', $why);
    }

    /** D-1517: the person has no workplace, or its default printer is missing or switched off. */
    public static function noWorkplacePrinter(): self
    {
        return new self('no_workplace_printer');
    }

    public static function notConfigured(string $key): self
    {
        return new self('not_configured', $key);
    }

    public static function notALabel(string $which): self
    {
        return new self('not_a_label', $which);
    }

    public static function notALabelSize(string $which): self
    {
        return new self('not_a_size', $which);
    }

    public static function withoutName(): self
    {
        return new self('without_name');
    }

    public static function inUse(string $users): self
    {
        return new self('in_use', $users);
    }

    public static function notARoll(string $which): self
    {
        return new self('not_a_roll', $which);
    }

    /** D-1441: the printer lists its media; this one is not among them. */
    public static function notAMediumOf(string $printer): self
    {
        return new self('not_a_medium', $printer);
    }

    public static function notAPrinter(string $which): self
    {
        return new self('not_a_printer', $which);
    }
}
