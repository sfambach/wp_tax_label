<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelCatalog;
use TaxmodLabel\Core\Structure;
use TaxmodLabel\Core\Table;
use TaxmodLabel\Core\UnitAmounts;

final class UnitAmountsTest extends TestCase
{
    private function structure(): Structure
    {
        return Structure::fromArray(['nodes' => ['label_template' => 23], 'fields' => ['label' => 1, 'template_key' => 2, 'template_width' => 354, 'template_height' => 355, 'template_layout' => 5, 'template_roll' => 356, 'printer_dpi' => 394]]);
    }

    #[Test]
    public function a_size_reads_the_same_as_plain_number_and_as_unit_value(): void
    {
        // Feldkatalog 9/10/12: label 1 still holds plain millimetres, label 2 points at unit-value parts (ids 901, 902).
        $s     = $this->structure();
        $table = new Table([
            1 => [1 => ['Alt'], 2 => ['a'], 354 => ['62'], 355 => ['30'], 5 => ['{}']],
            2 => [1 => ['Neu'], 2 => ['b'], 354 => ['901'], 355 => ['902'], 5 => ['{}']],
        ]);
        $read  = UnitAmounts::into($s, $table, [2 => [354 => 0.062, 355 => 0.0305]]);

        self::assertSame(['62', '30'], [$read->first(1, 354), $read->first(1, 355)], 'the plain number stays as it was');
        self::assertSame(['62', '30.5'], [$read->first(2, 354), $read->first(2, 355)], 'metres from the modeller become millimetres');
        self::assertSame(['Neu'], $read->all(2, 1), 'other fields untouched');
        self::assertSame([62, 30], [LabelCatalog::from($s, $read)->get(1)['format']['w'], LabelCatalog::from($s, $read)->get(1)['format']['h']]);
        self::assertSame(62, LabelCatalog::from($s, $read)->get(2)['format']['w'], 'the catalog reads both alike');
    }

    #[Test]
    public function dots_per_inch_are_taken_as_they_are_and_unknown_fields_are_left(): void
    {
        $s    = $this->structure();
        $read = UnitAmounts::into($s, new Table([7 => [394 => ['950']]]), [7 => [394 => 300.0, 999 => 1.0]]);

        self::assertSame('300', $read->first(7, 394));
        self::assertNull($read->first(7, 999), 'a field that is no size is not written in');
        self::assertSame([354, 355, 356, 394], UnitAmounts::fields($s), 'only the size fields this model has');
    }

    #[Test]
    public function without_amounts_the_table_is_the_same(): void
    {
        $table = new Table([1 => [354 => ['62']]]);

        self::assertSame($table, UnitAmounts::into($this->structure(), $table, []), 'an older modeller: nothing converted');
    }
}
