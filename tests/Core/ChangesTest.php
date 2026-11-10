<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelChanges;
use TaxmodLabel\Core\Structure;
use TaxmodLabel\Core\Table;

/** A label joins and leaves rolls in one change list (his word 2026-11-10 «was hin kann sollte auch weg können»). */
final class ChangesTest extends TestCase
{
    private function structure(): Structure
    {
        $bundled = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/structure-live.json'), true);

        return Structure::fromArray($bundled);
    }

    public function testTheRollsNamedAreExactlyTheOnesThatAllowIt(): void
    {
        $s     = $this->structure();
        $field = $s->field('roll_labels');
        $rolls = new Table([1 => [$field => ['7', '8']], 2 => [$field => ['8']], 3 => [$field => []]]);

        $changes = (new LabelChanges($s))->update(7, [], [3], $rolls);

        self::assertSame([
            ['op' => 'set_values', 'record' => '1', 'values' => [(string) $field => ['values' => ['8']]]],
            ['op' => 'set_values', 'record' => '3', 'values' => [(string) $field => ['values' => ['7']]]],
        ], $changes);
    }

    public function testWithoutRollsTheyStayAsTheyAre(): void
    {
        $s     = $this->structure();
        $rolls = new Table([1 => [$s->field('roll_labels') => ['7']]]);

        self::assertSame([], (new LabelChanges($s))->update(7, [], null, $rolls));
        self::assertCount(1, (new LabelChanges($s))->update(7, [], [], $rolls));
    }
}
