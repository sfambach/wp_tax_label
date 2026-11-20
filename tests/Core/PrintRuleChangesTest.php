<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\PrintRule;
use TaxmodLabel\Core\PrintRuleChanges;
use TaxmodLabel\Core\PrintRules;
use TaxmodLabel\Core\Structure;

/** D-1519 R2: today's preselections become rules once, after the rules there are, in one act. */
final class PrintRuleChangesTest extends TestCase
{
    private static function structure(): Structure
    {
        return Structure::fromArray(['nodes' => ['label_template' => 1, 'label_rule' => 9], 'fields' => [
            'label' => 100, 'template_key' => 101, 'template_width' => 102, 'template_height' => 103, 'template_layout' => 104,
            'position' => 110, 'rule_for' => 111, 'rule_form' => 112, 'rule_group' => 113, 'rule_place_type' => 114,
            'rule_workplace' => 115, 'rule_label' => 116, 'rule_printer' => 117, 'rule_copies' => 118,
        ]]);
    }

    #[Test]
    public function a_preselection_becomes_a_rule_after_the_last_and_only_once(): void
    {
        $rules     = new PrintRules([new PrintRule(1, 'Schon da', 900, position: 30, forNode: 12, placeType: 48122)]);
        $proposals = [
            ['name' => 'Platzart Schublade', 'for' => 12, 'place_type' => 48122, 'label' => 48435, 'copies' => null],
            ['name' => 'Platzart Kiste', 'for' => 12, 'place_type' => 48236, 'label' => 48435, 'copies' => 2],
            ['name' => 'Bauform SMD', 'for' => 10, 'form' => 20, 'label' => 49068],
            ['name' => 'ohne Etikett', 'for' => 12, 'place_type' => 48407, 'label' => 0, 'copies' => 3],
        ];

        $changes = (new PrintRuleChanges(self::structure()))->takeOver($proposals, $rules);

        self::assertCount(4, $changes, 'two rules: the place type already ruled and the one without a label are left out');
        self::assertSame(['op' => 'create_record', 'ref' => 'regel0', 'node' => '9'], $changes[0]);
        self::assertSame(['100' => 'Platzart Kiste', '110' => '40', '111' => '12', '114' => '48236', '116' => '48435', '118' => '2'], $changes[1]['values']);
        self::assertSame(['100' => 'Bauform SMD', '110' => '50', '111' => '10', '112' => '20', '116' => '49068'], $changes[3]['values']);
        self::assertSame([], (new PrintRuleChanges(self::structure()))->takeOver([$proposals[0]], $rules));
    }

    #[Test]
    public function a_new_rule_needs_a_name_and_comes_last(): void
    {
        $changes = (new PrintRuleChanges(self::structure()))->create(' Neue ', new PrintRules([new PrintRule(1, 'A', 900, position: 70)]));

        self::assertSame(['100' => 'Neue', '110' => '80'], $changes[1]['values']);
        $this->expectException(LabelError::class);
        (new PrintRuleChanges(self::structure()))->create('  ', new PrintRules([]));
    }
}
