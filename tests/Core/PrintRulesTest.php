<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Tests\Support\RuleBookStandIn;
use TaxmodLabel\Core\PrintContext;
use TaxmodLabel\Core\PrintRule;
use TaxmodLabel\Core\PrintRules;

/** Druckregeln (D-1519, D-1541): the first rule that holds decides, in the order of their position. */
final class PrintRulesTest extends TestCase
{
    private const MATERIAL = 10, LOT = 11, PLACE = 12, SMD = 20, SMD0603 = 21, THT = 22, RESISTORS = 30, PARTS = 31;

    #[Test]
    public function the_first_rule_that_holds_decides_in_order_of_position(): void
    {
        $rules = new PrintRules([
            new PrintRule(3, 'Rest', 900, position: 30, forNode: self::MATERIAL, node: self::PARTS),
            new PrintRule(1, 'SMD', 901, position: 10, forNode: self::MATERIAL, form: self::SMD, node: self::PARTS),
            new PrintRule(2, 'Widerstände', 902, position: 20, forNode: self::MATERIAL, group: self::RESISTORS, node: self::PARTS),
        ]);
        $smdResistor = new PrintContext([self::MATERIAL], [self::SMD0603, self::SMD], [self::RESISTORS, self::PARTS]);

        self::assertSame(1, self::first($rules, $smdResistor)?->id, '«SMD» at the Bauform\'s ancestor holds for 0603, and comes first');
        self::assertSame(2, self::first($rules, new PrintContext([self::MATERIAL], [self::THT], [self::RESISTORS, self::PARTS]))?->id);
        self::assertSame(3, self::first($rules, new PrintContext([self::MATERIAL], [self::THT], [self::PARTS]))?->id);
        self::assertNull(self::first($rules, new PrintContext([self::LOT])), 'no rule for lots: the way of today');
    }

    #[Test]
    public function every_set_condition_must_hold(): void
    {
        $rules = new PrintRules([new PrintRule(1, 'Keller Platz', 903, forNode: self::PLACE, placeType: 40, workplace: 500, copies: 2, printer: 71, node: self::PLACE)]);

        self::assertSame(2, self::first($rules, new PrintContext([self::PLACE], groupPath: [self::PLACE], placeType: 40, workplace: 500))?->copies);
        self::assertNull(self::first($rules, new PrintContext([self::PLACE], groupPath: [self::PLACE], placeType: 40, workplace: 501)), 'another workplace');
        self::assertNull(self::first($rules, new PrintContext([self::PLACE], groupPath: [self::PLACE], placeType: 41, workplace: 500)), 'another place type');
        self::assertNull(self::first(new PrintRules([new PrintRule(2, 'ohne Etikett', 0)]), new PrintContext([self::PLACE])), 'a rule without a label never holds');
    }

    #[Test]
    public function equal_positions_keep_the_order_of_creation(): void
    {
        $rules = new PrintRules([new PrintRule(8, 'b', 905, forNode: self::MATERIAL, node: self::PARTS), new PrintRule(7, 'a', 904, forNode: self::MATERIAL, node: self::PARTS)]);

        self::assertSame(7, self::first($rules, new PrintContext([self::MATERIAL], groupPath: [self::PARTS]))?->id);
    }

    #[Test]
    public function the_rule_book_gets_each_condition_under_its_key_and_the_context_as_lists(): void
    {
        $rules = new PrintRules([new PrintRule(1, 'Keller', 903, position: 5, forNode: self::PLACE, placeType: 40, workplace: 500, node: self::PLACE), new PrintRule(2, 'leer', 0)]);

        self::assertSame([['id' => 1, 'position' => 5, 'conditions' => [PrintRules::FOR => self::PLACE, PrintRules::FORM => 0, PrintRules::GROUP => 0, PrintRules::PLACE_TYPE => 40, PrintRules::WORKPLACE => 500, PrintRules::NODE => self::PLACE], 'filters' => []]], $rules->book(), 'a rule without a label is not handed in');
        self::assertSame([PrintRules::FOR => [self::PLACE], PrintRules::FORM => [], PrintRules::GROUP => [], PrintRules::PLACE_TYPE => [40], PrintRules::WORKPLACE => [], PrintRules::NODE => []], PrintRules::context(new PrintContext([self::PLACE], placeType: 40)));
    }

    #[Test]
    public function a_rule_without_a_node_holds_nothing_even_where_its_place_type_fits(): void
    {
        // D-1563(3), Live-Check 11/12: the old place rule 87590 (label and place type, no node) held for place 47821.
        $rules = new PrintRules([new PrintRule(1, 'Platzart ohne Knoten', 903, forNode: self::PLACE, placeType: 40)]);

        self::assertSame([], $rules->book(), 'not handed in');
        self::assertNull(self::first($rules, new PrintContext([self::PLACE], groupPath: [self::PLACE], placeType: 40)));
    }

    /** D-1541: the module translates, the rule book decides. */
    private static function first(PrintRules $rules, PrintContext $context): ?PrintRule
    {
        $id = RuleBookStandIn::first($rules->book(), PrintRules::context($context));

        return $id === null ? null : $rules->get($id);
    }
}
