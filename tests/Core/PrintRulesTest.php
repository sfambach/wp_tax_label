<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\PrintContext;
use TaxmodLabel\Core\PrintRule;
use TaxmodLabel\Core\PrintRules;

/** Druckregeln (D-1519): the first rule that holds decides, in the order of their position. */
final class PrintRulesTest extends TestCase
{
    private const MATERIAL = 10, LOT = 11, PLACE = 12, SMD = 20, SMD0603 = 21, THT = 22, RESISTORS = 30, PARTS = 31;

    #[Test]
    public function the_first_rule_that_holds_decides_in_order_of_position(): void
    {
        $rules = new PrintRules([
            new PrintRule(3, 'Rest', 900, position: 30, forNode: self::MATERIAL),
            new PrintRule(1, 'SMD', 901, position: 10, forNode: self::MATERIAL, form: self::SMD),
            new PrintRule(2, 'Widerstände', 902, position: 20, forNode: self::MATERIAL, group: self::RESISTORS),
        ]);
        $smdResistor = new PrintContext([self::MATERIAL], [self::SMD0603, self::SMD], [self::RESISTORS, self::PARTS]);

        self::assertSame(1, $rules->first($smdResistor)?->id, '«SMD» at the Bauform\'s ancestor holds for 0603, and comes first');
        self::assertSame(2, $rules->first(new PrintContext([self::MATERIAL], [self::THT], [self::RESISTORS, self::PARTS]))?->id);
        self::assertSame(3, $rules->first(new PrintContext([self::MATERIAL], [self::THT], [self::PARTS]))?->id);
        self::assertNull($rules->first(new PrintContext([self::LOT])), 'no rule for lots: the way of today');
    }

    #[Test]
    public function every_set_condition_must_hold(): void
    {
        $rules = new PrintRules([new PrintRule(1, 'Keller Platz', 903, forNode: self::PLACE, placeType: 40, workplace: 500, copies: 2, printer: 71)]);

        self::assertSame(2, $rules->first(new PrintContext([self::PLACE], placeType: 40, workplace: 500))?->copies);
        self::assertNull($rules->first(new PrintContext([self::PLACE], placeType: 40, workplace: 501)), 'another workplace');
        self::assertNull($rules->first(new PrintContext([self::PLACE], placeType: 41, workplace: 500)), 'another place type');
        self::assertNull((new PrintRules([new PrintRule(2, 'ohne Etikett', 0)]))->first(new PrintContext([self::PLACE])), 'a rule without a label never holds');
    }

    #[Test]
    public function equal_positions_keep_the_order_of_creation(): void
    {
        $rules = new PrintRules([new PrintRule(8, 'b', 905), new PrintRule(7, 'a', 904)]);

        self::assertSame(7, $rules->first(new PrintContext([self::MATERIAL]))?->id);
    }
}
