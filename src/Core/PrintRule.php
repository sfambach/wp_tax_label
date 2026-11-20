<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * One print rule (Druckregeln): what it is for and when it holds, and the label it chooses. An empty condition (0) always holds;
 * all set conditions must hold.
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class PrintRule
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $label,
        public readonly int $position = 0,
        public readonly int $forNode = 0,
        public readonly int $form = 0,
        public readonly int $group = 0,
        public readonly int $placeType = 0,
        public readonly int $workplace = 0,
        public readonly ?int $copies = null,
        public readonly int $printer = 0,
    ) {
    }

    public function holdsFor(PrintContext $context): bool
    {
        return $this->label > 0
            && ($this->forNode === 0 || in_array($this->forNode, $context->nodePath, true))
            && ($this->form === 0 || in_array($this->form, $context->formPath, true))
            && ($this->group === 0 || in_array($this->group, $context->groupPath, true))
            && ($this->placeType === 0 || $this->placeType === $context->placeType)
            && ($this->workplace === 0 || $this->workplace === $context->workplace);
    }
}
