# Service slides

A service on any date can have several decks, such as music and liturgy. Each deck is an ordered list of template slides and library items, and generating it writes one merged ODP. The app merges files; slide editing stays in Impress.

This spec replaces the first-pass build, where deck order came from slide roles and the service page was a text box of titles. That order is right for music but wrong for liturgy, where library items sit between title slides.

## Concepts

- **Library item** (`slide_sources`): one ODP file, such as a song, Psalm 19, or the Shema. It has a kind (song, liturgy, or other), aliases, a slide count, and the first line of text on each slide.
- **Template** (`slide_templates`): one ODP file that supplies the look of the deck (masters, page size) and its structural slides (Welcome, Blank, Congregational Prayer, Torah Service, D'Var Torah). Each slide has a label.
- **Preset**: stored on the template. It is the default arrangement used to fill a new deck. It is made of steps.
- **Deck entries** (`service_deck_entries`): the actual ordered list for one deck. After a deck is created, its entries are the source of truth. Editing the preset later does not change existing decks.

## Presets

A preset is an ordered list of steps. There are two kinds.

**Template slide step.** Inserts one template slide.

```json
{ "type": "template_slide", "slide_id": 12 }
```

**Slot step.** A named place where library items go.

```json
{
  "type": "slot",
  "key": "songs",
  "label": "Songs",
  "kind": "song",
  "repeat": true,
  "separator_slide_id": 13,
  "default_source_ids": []
}
```

- `kind` filters the picker. It does not block other kinds.
- `repeat: true` means the slot takes any number of items. `false` means one item.
- `separator_slide_id` inserts that template slide after each item in the slot. The music Blank works this way.
- `default_source_ids` prefill the slot. Liturgy uses this, because Psalm 19, the Shema, the Amidah, and Adon Olam repeat every week.

Examples taken from the 10-31 decks:

- **Music:** Welcome, Blank, then a `songs` slot (repeat, separator Blank).
- **Liturgy:** `opening` slot (default Psalm 19), `shema` slot (default Shema), Congregational Prayer, `amidah` slot (default Amidah), Torah Service, D'Var Torah, `closing` slot (default Adon Olam).

The Hebrew and English readings are still added by hand in Impress after generation. A template can include a placeholder title slide for them.

Slide roles (`intro`, `separator`, `title`) stay only as labels that help the template editor suggest a starting preset. Deck order no longer comes from roles.

## Deck entries

Each entry is one row in the merged deck:

- `entry_type`: `template_slide` or `source`
- `slide_template_slide_id` or `slide_source_id`
- `slot_key`: the preset slot that the item filled, or null
- `auto`: true for separator slides inserted by a slot

Separators are stored as real entries, so the editor shows exactly what will be generated. When an item with an automatic separator is moved or removed, the separator moves or is removed with it. A user can delete a single automatic separator, and that choice is kept.

## Planner

`ServicePlanner` is shared by the UI, CSV import, and MCP.

- `createDeck(service, template)` builds entries from the preset, including slot defaults.
- `fillSlots(deck, slots)` takes `{slot_key: [titles]}`, matches titles to library items (exact title, then alias, then 80% similarity), replaces only those slots, and keeps everything else. It returns unmatched titles per slot.
- `fill(deck, titles)` is the short form. If the preset has exactly one repeating slot, the titles go there. Otherwise it returns an error asking for slot keys.
- `replaceEntries(deck, entries)` saves an ordered list from the editor.

Every change marks the deck stale.

## Staleness

A generated deck is stale when any of these change after `generated_at`:

- its entries
- its template file
- a source file it uses
- a source it uses is archived. The deck shows a warning, and generation still uses the stored file.

Files can now be replaced, both library items and templates. Replacing a file re-reads its slides and marks every affected deck stale. If a replaced template has fewer slides, entries that point at missing slides are flagged, and generation is blocked until those entries are fixed.

## Screens

**Library**
- Upload or replace an ODP file. The file is accepted only if it is a zip whose `mimetype` entry is `application/vnd.oasis.opendocument.presentation`. A PPTX file is rejected with "Save as ODP in Impress first."
- The list shows title, kind, slide count, aliases, and where the item was last used.
- An item can be expanded to show the first line of text on each slide (stored as `slide_lines`).
- Filter by kind, search by title or alias, archive.

**Templates**
- Upload or replace the file, and edit the name, default kind, and history tracking.
- Label each slide.
- Edit the preset as an ordered list of steps: add a template slide, add a slot (key, label, kind, repeat, separator, defaults), reorder, remove.
- On upload, the app suggests a preset from the slide roles, which the user can then edit.

**Service**
- The page has one tab per deck. "Add deck" picks a template and fills the deck from its preset.
- Each tab is a drag-to-order list. Template slides appear as small chips, and library items appear as cards with their slide count. Rows that belong to a slot show the slot name.
- Add a library item: a search box filtered to the slot's kind, with a toggle to show all kinds.
- Add a template slide: pick from that template's labeled slides.
- Remove an item. Reset the deck to its preset (this asks for confirmation).
- Each deck shows its status (draft, stale, generating, ready, or failed) and any warnings.
- Generate one deck or all decks, download one deck or a zip of all decks.

**History**
- Items from history-tracked templates, with play count and last used date, sortable.
- A by-date view showing what each service used.

## MCP and import

- `list_templates` returns each template's slots (key, label, kind, repeat).
- `upsert_service_deck` accepts `titles` (the short form) or `slots` (`{key: [titles]}`) and returns unmatched titles per slot.
- CSV columns are `date,template,slot,titles`. Titles are separated with `|`. A blank slot uses the short form.

## Schema changes

- `slide_sources`: add `slide_lines` (JSON) and `file_updated_at`.
- `slide_templates`: `preset` holds the steps above. Add `file_updated_at`.
- `service_deck_entries`: add `slot_key` (nullable) and `auto` (boolean).
- `service_decks`: add `blocked` (boolean) for entries that point at missing template slides.

## Tests

- A music deck from the preset puts a Blank after each song, and the merge test still produces 14 slides.
- A liturgy deck from the preset matches the 10-31 order: Psalm 19, Shema, Congregational Prayer, Amidah, then Adon Olam.
- `fillSlots` replaces one slot and leaves the others alone.
- The short form errors when a template has more than one repeating slot.
- Replacing a source or template marks dependent decks stale. A shorter template blocks generation.
- A PPTX file renamed to `.odp` is rejected.
- Generate all queues one job per deck, and download all returns every ready deck.
- The MCP `slots` form works, and so does the short form.

## Decisions

Confirmed:

1. **Reordering.** Drag and drop with `vue-draggable-plus` (SortableJS).
2. **Separators.** Stored as real, editable entries, not added at generation time.
3. **Liturgy defaults.** Each liturgy slot is prefilled with its usual item every week, so a typical liturgy deck needs no edits before generating.

Assumed, not yet confirmed:

4. **Existing decks.** Decks made with the first-pass build are converted once. Their entries stay as they are, with no `slot_key`.
