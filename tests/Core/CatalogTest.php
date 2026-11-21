<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodLabel\Tests\Support\RuleBookStandIn;
use TaxmodLabel\Core\LabelCatalog;
use TaxmodLabel\Core\LabelContents;
use TaxmodLabel\Core\PrintRule;
use TaxmodLabel\Core\PrintContext;
use TaxmodLabel\Core\PrintRules;
use TaxmodLabel\Core\Structure;
use TaxmodLabel\Core\Table;
use TaxmodLabel\Core\Workplace;

/** D-1153, D-1159: labels as records, with the printer whose roll allows them (moved from the stock with D-1296). */
final class CatalogTest extends TestCase
{
    private const LAYOUT = '[{"type":"qr","field":"code","x":2,"y":2,"w":20}]';

    #[Test]
    public function a_complete_template_is_read_with_its_format(): void
    {
        $templates = $this->templates();

        self::assertSame(['name' => 'Platz', 'format' => ['key' => 'holder62', 'w' => 62, 'h' => 50, 'roll' => 62], 'copies' => 1, 'layout' => self::LAYOUT, 'category' => '', 'node' => 0, 'rolls' => []], $templates->get(90));
        self::assertSame(2, $templates->get(91)['copies']);
        self::assertFalse($templates->has(92), 'a template without a layout cannot print');
        self::assertFalse($templates->has(93), 'a label without a size cannot print');
    }

    #[Test]
    public function the_first_label_of_a_category_is_its_default(): void
    {
        $s      = $this->structure();
        $label  = $s->field('label');
        $row    = static fn (string $name): array => [$label => [$name], 400 => ['material62'], 401 => ['62'], 402 => ['30'], 405 => ['1'], 406 => [self::LAYOUT]];
        $labels = LabelCatalog::from($s, new Table([1 => $row('62×50'), 2 => $row('62×30'), 3 => $row('100×62')]), [], [1 => 40, 2 => 40, 3 => 41]);

        self::assertSame(2, $labels->firstIn(40));
        self::assertSame(3, $labels->firstIn(41));
        self::assertSame(0, $labels->firstIn(42));
    }

    #[Test]
    public function a_label_starts_on_the_first_printer_whose_loaded_roll_allows_it(): void
    {
        $labels = $this->withPrinters();

        self::assertSame(['queue' => 'Phomemo_M220L', 'roll' => 40, 'turn' => false, 'options' => '', 'turn_options' => '', 'sizes' => '*=w{B}h{H}', 'offset' => 21, 'line' => 38, 'retry' => true, 'dpi' => 0, 'pause' => 20, 'name' => 'Phomemo M22L'], $labels->get(1)['printer']);
        self::assertSame('QL-810W_take', $labels->get(2)['printer']['queue'], 'the first by name that has a queue and a roll');
        self::assertTrue($labels->get(2)['printer']['turn']);
        self::assertSame(0, $labels->get(2)['printer']['pause'], 'D-1333: no pause set is no pause');
        self::assertArrayNotHasKey('printer', $labels->get(3), 'no loaded roll allows it: the print service chooses as before');
        self::assertSame(['name' => '40×30', 'format' => $labels->get(1)['format'], 'layout' => self::LAYOUT, 'printer' => $labels->get(1)['printer'], 'rolls' => ['40×30 gestanzt'], 'record' => 5], LabelCatalog::forButton($labels->get(1), ['record' => 5]));
    }

    #[Test]
    public function a_group_label_is_chosen_only_when_it_is_of_the_category_and_can_print(): void
    {
        $labels = $this->withPrinters();

        // D-1266: 2 is a printable label of the category; 3 no roll allows; 9 is none; 0 asks for nothing.
        self::assertSame(2, $labels->preferredIn(40, 2));
        self::assertSame($labels->firstIn(40), $labels->preferredIn(40, 3));
        self::assertSame($labels->firstIn(40), $labels->preferredIn(40, 9));
        self::assertSame($labels->firstIn(40), $labels->preferredIn(40, 0));
        self::assertSame($labels->firstIn(41), $labels->preferredIn(41, 2), 'a label of another category is not chosen');
    }

    #[Test]
    public function the_dialog_offers_the_labels_of_the_category_and_every_printer_with_what_its_roll_allows(): void
    {
        $labels = $this->withPrinters();
        $dialog = $labels->dialog(2, ['record' => 5]);

        self::assertSame(2, $dialog['chosen']);
        self::assertSame([1, 2, 3], array_keys($dialog['choices']), 'the whole category, by name');
        self::assertSame(5, $dialog['choices'][3]['record']);
        self::assertArrayNotHasKey('printer', $dialog['choices'][1], 'the printer is chosen in the dialog, not carried by a choice');
        self::assertSame([70, 71], array_keys($dialog['printers']));
        self::assertSame([1], $dialog['printers'][70]['allows']);
        self::assertSame('40×30 gestanzt', $dialog['printers'][70]['roll_name']);
        self::assertSame(1, $labels->firstIn(40), 'a label a printer can print goes first');
        self::assertSame([], $labels->dialog(99));
    }

    #[Test]
    public function a_label_with_a_content_field_offers_nothing_categories_or_materials_shortened_to_its_lines(): void
    {
        $s      = $this->structure();
        $label  = $s->field('label');
        $with   = '[{"type":"text","field":"inhalt","x":2,"y":40,"w":58,"h":20,"lines":3}]';
        $row    = static fn (string $name, string $layout): array => [$label => [$name], 400 => ['x'], 401 => ['62'], 402 => ['62'], 405 => ['1'], 406 => [$layout]];
        $labels = LabelCatalog::from($s, new Table([1 => $row('62×62', $with), 2 => $row('62×50', self::LAYOUT)]), [], [1 => 40, 2 => 40]);
        $dialog = $labels->dialog(1, ['values' => ['ort' => 'A1']], ['categories' => ['Widerstände', 'Kondensatoren'], 'materials' => ['R 10k', 'R 1k', 'C 100n', 'C 10µ'], 'words' => ['more' => '+%d weitere']]);

        self::assertSame(['ort' => 'A1'], $dialog['values'], 'the values stay the caller\'s');
        self::assertSame('categories', $dialog['variants']['chosen']);
        self::assertSame('Kondensatoren · Widerstände', $dialog['variants']['options']['categories']['text']);
        self::assertSame('C 10µ · C 100n · +2 weitere', $dialog['variants']['options']['materials']['text'], 'three lines: two names and the rest');
        self::assertSame('', $dialog['variants']['options']['']['text']);
        self::assertArrayNotHasKey('variants', $dialog['choices'][2], 'a label without a place for it offers nothing');
    }

    #[Test]
    public function a_content_line_says_how_many_it_leaves_out(): void
    {
        self::assertSame('A · B', LabelContents::line(['B', 'A', 'A', ' '], 2));
        self::assertSame('A · +2', LabelContents::line(['A', 'B', 'C'], 2));
        self::assertSame(6, LabelContents::maxOf('[{"field":"inhalt"}]'));
        self::assertSame(4, LabelContents::maxOf('[{"field":"inhalt","lines":2,"max":4}]'));
        self::assertFalse(LabelContents::named('[{"field":"code"}]'));
    }

    #[Test]
    public function at_a_workplace_the_dialog_offers_only_its_printers_and_without_one_all(): void
    {
        $labels = $this->withPrinters(true);

        // D-1502: no workplace, all printers as before.
        self::assertSame($labels, $labels->at(null));
        self::assertSame([70, 71, 74], array_keys($labels->dialog(2, [])['printers']));

        $place = $labels->at(new Workplace(500, 'Werkbank', [71]));
        self::assertSame([71], array_keys($place->dialog(2, [])['printers']));
        self::assertSame([71], array_keys($place->printerList()));
        self::assertSame('QL-810W_take', $place->get(2)['printer']['queue']);
        self::assertArrayNotHasKey('printer', $place->get(1), 'no printer of the place allows it: none is carried');
        self::assertSame('Phomemo_M220L', $labels->get(1)['printer']['queue'], 'the full catalog stays as it was');
    }

    #[Test]
    public function at_a_workplace_a_label_starts_on_its_default_printer_where_that_ones_roll_allows_it(): void
    {
        $labels = $this->withPrinters(true);

        self::assertSame('QL-810W_take', $labels->get(2)['printer']['queue'], 'by name without a workplace');
        $place = $labels->at(new Workplace(500, 'Werkbank', [70, 71, 74], 74));
        self::assertSame('Zweiter_QL', $place->get(2)['printer']['queue']);
        self::assertSame([74, 70, 71], array_keys($place->printerList()), 'the default printer first');
        self::assertSame('Phomemo_M220L', $place->get(1)['printer']['queue'], 'the default cannot print it: the next that can');
        self::assertSame([70, 71], array_keys($labels->at(new Workplace(501, 'Ohne', [70, 71, 99], 99))->printerList()), 'a printer that is gone is left out');
    }

    #[Test]
    public function a_print_rule_chooses_label_and_printer_only_where_its_printer_can_print_it(): void
    {
        $rules  = new PrintRules([
            new PrintRule(1, 'Falscher Drucker', 2, position: 10, forNode: 10, printer: 70),
            new PrintRule(2, 'Zweiter QL', 2, position: 20, forNode: 10, copies: 3, printer: 74),
            new PrintRule(3, 'Ohne Drucker', 2, position: 30, forNode: 10),
        ]);
        $labels = $this->withPrinters(true)->withRules($rules, RuleBookStandIn::closure());

        // D-1519: 70's roll does not allow label 2 — that rule cannot print here and the next one decides.
        self::assertSame(['id' => 2, 'name' => 'Zweiter QL', 'label' => 2, 'copies' => 3, 'printer' => 74], $labels->ruleFor(['nodes' => [10]]));
        self::assertSame('Zweiter_QL', $labels->preferring(74)->get(2)['printer']['queue'], 'the rule\'s printer starts the dialog');
        self::assertSame([74, 70, 71], array_keys($labels->preferring(74)->printerList()));
        self::assertSame($labels, $labels->preferring(99), 'a printer that is not here changes nothing');
        self::assertNull($labels->ruleFor(['nodes' => [11]]), 'no rule for it: the way of today');

        $place = $labels->at(new Workplace(500, 'EG', [71], 71));
        self::assertSame(3, $place->ruleFor(['nodes' => [10]])['id'] ?? 0, 'at a workplace without printer 74 the rule naming it does not hold; one without a printer does');
        self::assertNull($this->withPrinters(true)->ruleFor(['nodes' => [10]]), 'without rules there is none');
    }

    #[Test]
    public function a_rule_without_printer_holds_only_where_some_printer_here_holds_a_roll_for_its_label(): void
    {
        // D-1565: label 3 fits no loaded roll, label 2 only roll 81 (printers 71, 74); the catch-all (D-1563) takes what is left.
        $rules  = new PrintRules([
            new PrintRule(1, 'Groß', 3, position: 10, forNode: 10),
            new PrintRule(2, '62 mm', 2, position: 20, forNode: 10),
            new PrintRule(9, 'Auffang', 1, position: 9990, forNode: 10),
        ]);
        $labels = $this->withPrinters(true)->withRules($rules, RuleBookStandIn::closure());

        self::assertSame(2, $labels->ruleFor(['nodes' => [10]])['id'] ?? 0, 'no roll for label 3 anywhere: its rule does not hold');
        self::assertSame(9, $labels->at(new Workplace(500, 'Nur Phomemo', [70], 70))->ruleFor(['nodes' => [10]])['id'] ?? 0, 'only roll 80 here: the catch-all');
        self::assertTrue($labels->printable(2));
        self::assertFalse($labels->printable(3));
    }

    #[Test]
    public function a_rule_without_its_own_label_reads_label_and_count_from_the_field_it_names(): void
    {
        // D-1570: «Lagerorte · Etikett der Platzart» — label and count are the Platzart's (fields 350/351), handed in by the stock.
        $rules  = new PrintRules([
            new PrintRule(1, 'Fest', 2, position: 10, forNode: 10, copies: 5),
            new PrintRule(2, 'Platzart', 0, position: 20, forNode: 20, labelFrom: 350, copiesFrom: 351),
        ]);
        $labels = $this->withPrinters(true)->withRules($rules, RuleBookStandIn::closure());

        self::assertSame(['id' => 2, 'name' => 'Platzart', 'label' => 2, 'copies' => 2, 'printer' => 0], $labels->ruleFor(['nodes' => [20], 'from' => [350 => '2', 351 => '2']]));
        self::assertNull($labels->ruleFor(['nodes' => [20], 'from' => [350 => '2']])['copies'] ?? null, 'no count handed in: the label\'s own');
        self::assertNull($labels->ruleFor(['nodes' => [20], 'from' => [350 => '3']]), 'the Platzart\'s label fits no loaded roll (D-1565)');
        self::assertNull($labels->ruleFor(['nodes' => [20]]), 'nothing handed in: no label, no rule');
        self::assertSame(5, $labels->ruleFor(['nodes' => [10], 'from' => [350 => '3']])['copies'] ?? 0, 'a fixed label and count go before the field');
    }

    #[Test]
    public function a_print_rule_for_a_workplace_holds_only_there(): void
    {
        $labels = $this->withPrinters(true)->withRules(new PrintRules([new PrintRule(1, 'Nur EG', 2, workplace: 500)]), RuleBookStandIn::closure());

        self::assertNull($labels->ruleFor([]), 'no workplace chosen');
        self::assertSame(1, $labels->at(new Workplace(500, 'EG', [71]))->ruleFor([])['id'] ?? 0);
        self::assertNull($labels->at(new Workplace(501, 'OG', [71]))->ruleFor([]));
    }

    #[Test]
    public function the_print_rules_are_read_from_their_records(): void
    {
        $s     = Structure::fromArray(['nodes' => ['label_template' => 23, 'label_rule' => 27], 'fields' => ['label' => 1, 'position' => 2, 'rule_for' => 460, 'rule_form' => 461, 'rule_label' => 465, 'rule_copies' => 466, 'rule_printer' => 467]]);
        $rules = PrintRules::fromTable($s, new Table([
            600 => [1 => ['SMD'], 2 => ['20'], 460 => ['10'], 461 => ['21'], 465 => ['2'], 466 => ['2']],
            601 => [1 => ['Alles'], 2 => ['10'], 465 => ['1'], 467 => ['70']],
        ]));

        self::assertSame([601, 600], array_map(static fn (PrintRule $r): int => $r->id, $rules->all()), 'in order of position');
        self::assertSame([10, 21, 2, 2, 0], [$rules->all()[1]->forNode, $rules->all()[1]->form, $rules->all()[1]->label, $rules->all()[1]->copies, $rules->all()[1]->printer]);
        self::assertNull($rules->all()[0]->copies, 'no number: the label\'s own');
    }

    #[Test]
    public function a_print_rule_holds_its_node_and_its_filters_from_the_shared_rule_filter(): void
    {
        // D-1548: the same form as the test rules — a node, plus filters «Feld · Operator · Wert» of the shared «Regel-Filter».
        $s       = Structure::fromArray(['nodes' => ['label_template' => 23, 'label_rule' => 27, 'label_rule_filter' => 28, 'operator_equals' => 90], 'fields' => ['label' => 1, 'rule_label' => 465, 'rule_node' => 470, 'rule_filters' => 471, 'filter_field' => 472, 'filter_value' => 473, 'filter_operator' => 474]]);
        $rules   = PrintRules::fromTable($s, new Table([600 => [1 => ['SMD-Widerstände'], 465 => ['2'], 470 => ['30'], 471 => ['80', '81']]]), new Table([
            80 => [472 => ['555'], 473 => [' SMD '], 474 => ['90']],
            81 => [472 => ['556'], 473 => ['5'], 474 => ['91']],
        ]));
        $book    = $rules->book();

        self::assertSame(30, $book[0]['conditions'][PrintRules::NODE]);
        self::assertSame([['field' => 555, 'value' => 'SMD', 'operator' => PrintRules::EQUALS], ['field' => 556, 'value' => '5', 'operator' => '91']], $book[0]['filters'], 'an operator not built is passed on and never holds');
        self::assertSame([555, 556], $rules->fields());
        self::assertSame([30, 31], PrintRules::context(PrintContext::fromArray(['groups' => [30, 31], 'record' => 4711], 0))[PrintRules::NODE], 'the node holds along the material\'s group path');
        self::assertSame(4711, PrintContext::fromArray(['record' => 4711], 0)->record);
    }

    #[Test]
    public function a_workplace_is_read_from_its_record_and_a_deleted_one_is_none(): void
    {
        $s      = Structure::fromArray(['nodes' => ['label_template' => 23, 'label_workplace' => 26], 'fields' => ['label' => 1, 'workplace_printers' => 450, 'workplace_default_printer' => 451, 'workplace_location' => 452]]);
        $places = new Table([500 => [1 => [' Werkbank '], 450 => ['71', '70'], 451 => ['70'], 452 => ['3000']]]);

        $place = Workplace::from($s, $places, 500);
        self::assertNotNull($place);
        self::assertSame(['Werkbank', [71, 70], 70, 3000], [$place->name, $place->printers, $place->defaultPrinter, $place->location]);
        self::assertNull(Workplace::from($s, $places, 501), 'a deleted record is no workplace, without an error');
        self::assertNull(Workplace::from($s, $places, 0));
    }

    private function withPrinters(bool $second = false): LabelCatalog
    {
        $s      = $this->structure();
        $label  = $s->field('label');
        $row    = static fn (string $name, int $w, int $h, int $roll): array => [$label => [$name], 400 => ['k'], 401 => [(string) $w], 402 => [(string) $h], 403 => [(string) $roll], 405 => ['1'], 406 => [self::LAYOUT]];
        $rolls  = new Table([
            80 => [$label => ['40×30 gestanzt'], 440 => ['40'], 441 => ['1']],
            81 => [$label => ['62 mm endlos'], 440 => ['62'], 441 => ['2']],
        ]);
        $printers = new Table([
            70 => [$label => ['Phomemo M22L'], 430 => ['Phomemo_M220L'], 443 => ['1'], 444 => ['90'], 438 => ['80'], 431 => ['0'], 437 => ['*=w{B}h{H}'], 432 => ['21'], 433 => ['38']],
            71 => [$label => ['QL-810W'], 430 => ['QL-810W_take'], 438 => ['81'], 431 => ['1'], 436 => ['orientation-requested=4'], 437 => ['62x30=BrL053E01E3D0F6']],
            72 => [$label => ['A ohne Warteschlange'], 438 => ['81']],
            // D-1231: switched off, so not offered.
            73 => [$label => ['B aus'], 430 => ['Off'], 438 => ['81'], 442 => ['0']],
            73 => [$label => ['Z ohne Rolle'], 430 => ['QL-1100']],
        ] + ($second ? [74 => [$label => ['Zweiter QL'], 430 => ['Zweiter_QL'], 438 => ['81']]] : []));

        return LabelCatalog::from($s, new Table([1 => $row('40×30', 40, 30, 40), 2 => $row('62×30', 62, 30, 62), 3 => $row('102×62', 102, 62, 102)]), [], [1 => 40, 2 => 40, 3 => 40], $printers, $rolls, new Table([90 => [$s->field('money_amount') => ['20']]]));
    }

    private function templates(): LabelCatalog
    {
        $s     = $this->structure();
        $label = $s->field('label');

        return LabelCatalog::from($s, new Table([
            90 => [$label => ['Platz'], 400 => ['holder62'], 401 => ['62'], 402 => ['50'], 403 => ['62'], 405 => ['1'], 406 => [self::LAYOUT]],
            91 => [$label => ['Kiste groß'], 400 => ['roll62'], 401 => ['100'], 402 => ['62'], 403 => ['62'], 405 => ['2'], 406 => [self::LAYOUT]],
            92 => [$label => ['Leer'], 400 => ['holder62'], 401 => ['62'], 402 => ['50'], 405 => ['1']],
            93 => [$label => ['Ohne Größe'], 400 => ['holder62'], 405 => ['1'], 406 => [self::LAYOUT]],
        ]));
    }

    private function structure(): Structure
    {
        return Structure::fromArray(['nodes' => ['label_template' => 23, 'label_printer' => 24, 'label_roll' => 25], 'fields' => ['label' => 1, 'money_amount' => 2,'template_key' => 400, 'template_width' => 401, 'template_height' => 402, 'template_roll' => 403,
            'template_copies' => 405, 'template_layout' => 406,
            'printer_queue' => 430, 'printer_turns' => 431, 'printer_offset' => 432, 'printer_line' => 433,
            'printer_options' => 435, 'printer_turn_options' => 436, 'printer_sizes' => 437, 'printer_loaded_roll' => 438, 'roll_width' => 440, 'roll_labels' => 441, 'printer_active' => 442, 'printer_retry' => 443, 'printer_pause' => 444]]);
    }
}
