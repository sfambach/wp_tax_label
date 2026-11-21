<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\PrintRule;
use TaxmodLabel\Core\PrintRules;
use TaxmodLabel\Core\PrintRuleTakeover;
use TaxmodLabel\Core\Structure;

/** Prüftor 21.11., D-1552: a preselection becomes node + filters; one naming «für» stays open (INF-142). */
final class PrintRuleTakeoverTest extends TestCase
{
    private static function structure(): Structure
    {
        return Structure::fromArray([
            'nodes'  => ['label_template' => 1, 'label_rule' => 2, 'filter_kind_field' => 3, 'filter_kind_stock' => 4],
            'fields' => ['label' => 10, 'template_key' => 11, 'template_width' => 12, 'template_height' => 13, 'template_layout' => 14,
                'position' => 15, 'rule_node' => 16, 'rule_label' => 17, 'rule_copies' => 18, 'rule_filters' => 19,
                'filter_field' => 20, 'filter_value' => 21, 'filter_kind' => 22, 'material_form' => 23, 'location_place_type' => 24],
        ]);
    }

    #[Test]
    public function group_becomes_the_node_and_form_and_place_type_become_filters(): void
    {
        $answer = (new PrintRuleTakeover(self::structure()))->of([
            ['name' => 'Gruppe Widerstände', 'group' => 300, 'form' => 400, 'label' => 900],
            ['name' => 'Platzart Box', 'place_type' => 500, 'label' => 901, 'copies' => 2],
        ], new PrintRules([new PrintRule(1, 'alt', 899, position: 40)]));

        self::assertSame([], $answer['open']);
        self::assertCount(2, $answer['changes']);
        self::assertSame(['10' => 'Gruppe Widerstände', '17' => '900', '16' => '300', '15' => '50', '19' => ['f0' => ['22' => '3', '20' => '23', '21' => '400']]], $answer['changes'][0]['values']);
        self::assertSame(['10' => 'Platzart Box', '17' => '901', '15' => '60', '18' => '2', '19' => ['f0' => ['22' => '4', '20' => '24', '21' => '500']]], $answer['changes'][1]['values']);
        self::assertSame(2, $answer['changes'][0]['node']);
    }

    #[Test]
    public function a_proposal_naming_for_is_only_listed_and_a_standing_rule_is_not_repeated(): void
    {
        $standing = new PrintRule(1, 'steht', 900, position: 10, node: 300, filters: [['field' => 23, 'value' => '400', 'operator' => PrintRules::EQUALS]]);
        $answer   = (new PrintRuleTakeover(self::structure()))->of([
            ['name' => 'Material · Gruppe X', 'for' => 77, 'group' => 300, 'label' => 900],
            ['name' => 'schon Regel', 'group' => 300, 'form' => 400, 'label' => 900],
        ], new PrintRules([$standing]));

        self::assertSame([['proposal' => 0, 'reason' => 'for']], $answer['open']);
        self::assertSame([], $answer['changes']);
    }
}
