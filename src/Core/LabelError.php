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

    public static function notAPrinter(string $which): self
    {
        return new self('not_a_printer', $which);
    }
}
