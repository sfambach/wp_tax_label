<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\Workplace;
use TaxmodLabel\Core\WorkplaceMenu;

/** D-1502 Stufe 3: the switch in the admin bar and the reminder while no workplace is chosen. */
final class WorkplaceMenuTest extends TestCase
{
    #[Test]
    public function the_switch_names_the_current_workplace_and_offers_every_one_and_none(): void
    {
        $menu = new WorkplaceMenu([500 => 'EG', 501 => 'OG'], new Workplace(501, 'OG', [71]));

        self::assertSame('OG', $menu->currentName());
        self::assertSame([['id' => 500, 'name' => 'EG', 'current' => false], ['id' => 501, 'name' => 'OG', 'current' => true], ['id' => 0, 'name' => '', 'current' => false]], $menu->choices());
        self::assertTrue($menu->allows(500) && $menu->allows(0));
        self::assertFalse($menu->allows(77), 'a record that is no workplace is never stored');
        self::assertFalse($menu->remindsToChoose());
    }

    #[Test]
    public function it_reminds_only_where_workplaces_exist_and_none_is_chosen(): void
    {
        self::assertTrue((new WorkplaceMenu([500 => 'EG'], null))->remindsToChoose());
        self::assertFalse((new WorkplaceMenu([], null))->remindsToChoose(), 'no workplaces yet: nothing to choose, no reminder');
        self::assertSame('', (new WorkplaceMenu([500 => 'EG'], null))->currentName());
        self::assertTrue((new WorkplaceMenu([500 => 'EG'], null))->choices()[1]['current'], '«kein Arbeitsplatz» is the current choice');
    }
}
