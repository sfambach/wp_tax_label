<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\Problem;

/**
 * The words for what the core says is wrong — the core names a problem, the boundary says it in the user's language (AR-2).
 *
 * @see docs/konzept.md §6
 */
final class ProblemWords
{
    public static function of(Problem $problem): string
    {
        $d    = $problem->details;
        $text = match ($problem->code) {
            Problem::JSON         => sprintf(/* translators: %s: parser message */ __('Das JSON lässt sich nicht lesen: %s', 'taxmod-label'), (string) ($d['message'] ?? '')),
            Problem::SHAPE        => __('Erwartet wird eine Liste von Elementen oder {"label": …, "elements": […]}.', 'taxmod-label'),
            Problem::UNKNOWN_TYPE => sprintf(/* translators: %s: element type */ __('Unbekannter Typ «%s».', 'taxmod-label'), (string) ($d['type'] ?? '')),
            Problem::NEEDS_FIELD  => __('Braucht ein Feld oder einen festen Text.', 'taxmod-label'),
            Problem::NEEDS_MEDIA  => __('Braucht ein Bild aus der Mediathek.', 'taxmod-label'),
            Problem::NEEDS_BOX    => __('Braucht Lage und Größe (x, y ≥ 0; Breite, Höhe > 0).', 'taxmod-label'),
            Problem::OUTSIDE      => sprintf(/* translators: 1: width, 2: height in mm */ __('Ragt über das Etikett (%1$s × %2$s mm).', 'taxmod-label'), (string) ($d['w'] ?? ''), (string) ($d['h'] ?? '')),
            Problem::GREW_OUTSIDE => __('Der Text wächst über das Etikett hinaus.', 'taxmod-label'),
            Problem::TOO_SMALL    => sprintf(/* translators: 1: mm, 2: dpi */ __('Zu klein zum Scannen: braucht mindestens %1$s mm bei %2$s dpi.', 'taxmod-label'), (string) ($d['needMm'] ?? ''), (string) ($d['dpi'] ?? '')),
            Problem::TOO_FLAT     => sprintf(/* translators: %s: mm */ __('Die Balken sind niedriger als %s mm; ein Handscanner trifft sie schlecht.', 'taxmod-label'), (string) ($d['minMm'] ?? '')),
            Problem::BAD_VALUE    => sprintf(/* translators: 1: value, 2: what the code takes */ __('«%1$s» passt nicht in diesen Code: %2$s', 'taxmod-label'), (string) ($d['value'] ?? ''), self::codeRule((string) ($d['type'] ?? ''))),
            Problem::NO_PICTURE   => sprintf(/* translators: %s: media id */ __('Bild %s fehlt in der Mediathek oder ist kein JPEG/PNG.', 'taxmod-label'), (string) ($d['media'] ?? '')),
            Problem::BAD_SIZE     => __('Das Etikett braucht Breite und Höhe zwischen 1 und 500 mm.', 'taxmod-label'),
            Problem::CUT          => __('Der Text passt nicht ganz und wird gekürzt.', 'taxmod-label'),
            default               => $problem->code,
        };

        return $problem->element > 0 ? sprintf(/* translators: 1: element number, 2: problem */ __('Element %1$d: %2$s', 'taxmod-label'), $problem->element, $text) : $text;
    }

    /**
     * @param list<Problem> $problems
     * @return list<array{element: int, error: bool, text: string}>
     */
    public static function list(array $problems): array
    {
        return array_map(static fn (Problem $p): array => ['element' => $p->element, 'error' => $p->error, 'text' => self::of($p)], $problems);
    }

    public static function ofError(LabelError $error): string
    {
        return match ($error->reason) {
            'not_configured' => sprintf(/* translators: %s: structure key */ __('Das Modell der Etiketten ist noch nicht eingespielt: %s fehlt.', 'taxmod-label'), $error->detail),
            'not_a_label'    => sprintf(/* translators: %s: id */ __('Kein Etikett: %s.', 'taxmod-label'), $error->detail),
            'not_a_size'     => sprintf(/* translators: %s: given */ __('Keine Größe in ganzen Millimetern über 0: %s.', 'taxmod-label'), $error->detail),
            'without_name'   => __('Ein Etikett braucht einen Namen.', 'taxmod-label'),
            'in_use'         => sprintf(/* translators: %s: names */ __('Wird noch benutzt von: %s.', 'taxmod-label'), $error->detail),
            'not_a_roll'     => sprintf(/* translators: %s: id */ __('Keine Rolle: %s.', 'taxmod-label'), $error->detail),
            'not_a_printer'  => sprintf(/* translators: %s: id */ __('Kein Drucker: %s.', 'taxmod-label'), $error->detail),
            default          => $error->getMessage(),
        };
    }

    private static function codeRule(string $type): string
    {
        return match ($type) {
            'ean13'  => __('EAN-13 braucht 12 oder 13 Ziffern mit passender Prüfziffer.', 'taxmod-label'),
            'code39' => __('Code 39 nimmt 0–9, A–Z (groß), Leerzeichen und - . $ / + %.', 'taxmod-label'),
            'code128' => __('Code 128 nimmt druckbare ASCII-Zeichen (keine Umlaute).', 'taxmod-label'),
            default  => __('Der Text ist zu lang für diesen Code.', 'taxmod-label'),
        };
    }
}
