# Taxmod Label

Labels for the Taxonomy Modeller: the label editor (drawing beside the JSON), the codes (QR, DataMatrix, Code128, EAN-13, Code39), the print dialog and the abilities `taxmod-label/*`. It takes over all labelling from the modeller and the stock module (D-1292).

- Concept: [`docs/konzept.md`](docs/konzept.md)
- Needs the Taxonomy Modeller ≥ 0.2.61 (`ModelDoor::labelValues`, `ModelDoor::queuePrint`).
- Pages: Logistik › Einstellungen › Druck › Etiketten / Drucker.

## Checks

```sh
composer install
vendor/bin/phpunit                          # core, no WordPress
python3 -I scripts/dev/scan-check.py         # draws every code for 203/300 dpi and decodes it (pip: zxing-cpp pillow; pdftoppm)
```
