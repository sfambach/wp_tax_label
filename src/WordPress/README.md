# WordPress

The boundary: the print button (`taxmod_label_button`), the abilities `taxmod-label/*`, the editor's REST routes, the pages Etiketten and Drucker, and the plugin start.

- Reaches the model only through the modeller's `ModelDoor` (D-1186); never another module's code.
- Capability → nonce → validate → sanitize → act → escape (CD-5).
- Concept: [`docs/konzept.md`](../../docs/konzept.md) §6–§7.
