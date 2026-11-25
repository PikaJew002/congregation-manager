# Library content search

Search the slide library by the words on the slides, not only by title, author, and alias. A worship leader who remembers "my heart will choose to say" should find Blessed Be Your Name, and Claude should be able to do the same through MCP.

## What we have today

- `slide_sources.slide_lines` holds the first line of each slide, cut at 200 characters. It is written on upload and replace, and is not searched.
- The Library page and the deck editor's picker filter in the browser over every library item, matching title, author (Library only), and aliases.
- The `search-library` MCP tool queries the database with `LIKE '%query%'` on title and aliases, sorted by title, with a keyset cursor. It does not escape `%` or `_` in the query.
- `OdpInspector::slides()` and `PptxInspector::slides()` already read every paragraph on every slide, skipping speaker notes, but keep only the first.
- Production runs MySQL 8.0; tests run SQLite in memory.

## Stored text

Add two columns to `slide_sources`:

- `slide_text` (JSON, nullable): one entry per slide, each a list of that slide's lines as written. It is used for excerpts and for showing which slide matched.
- `search_text` (LONGTEXT, nullable): the whole item normalized for matching, slides joined by a newline and the lines within a slide joined by a space. A phrase that wraps across lines on one slide, like "Every blessing You pour out I'll / Turn back to praise", still matches.

Normalization happens in PHP, so MySQL and SQLite behave the same:

- Unicode NFKC, then lowercase.
- Strip Hebrew niqqud and cantillation marks (U+0591 to U+05C7), so typed Hebrew without vowels matches pointed text.
- Strip Latin diacritics after NFD decomposition.
- Turn dashes and the Hebrew maqaf into spaces, and remove apostrophes (straight, curly, and geresh), so "I'll", "I’ll", and "Ill" all match.
- Drop all other punctuation.
- Collapse whitespace.

The query goes through the same function, which leaves only letters, digits, and spaces, so slide text queries cannot carry `LIKE` wildcards. Title and alias matching escape `%` and `_` with `!`, which MySQL and SQLite both accept as an `ESCAPE` character; this also fixes the existing title search.

`slide_lines` stays as it is, since templates and the picker use it.

## Writing the text

- Each slide from `SlideInspector::slides()` gains `lines`, every non-empty line on it, from the paragraph walk both inspectors already do. The file is read once.
- `SlideFiles::sourceFileAttributes()` fills `slide_text` and `search_text` alongside `slide_lines`, from the file decks are built from (`file_path`), so upload, replace, and the MCP `add-library-item` path all get it.
- Text is taken from the uploaded file, which has the same words as the formatted copy; `SlideFiles::reformatSource()` leaves it alone, because formatting changes layout and fonts, never the words.
- A command, `service-slides:index-slide-text`, fills items without slide text (all kinds, archived included) by downloading the file decks use from the files disk. `--all` reads every item again. It reports items it could not read and leaves them for the next run.
- A data migration runs the command once, as `format_existing_library_songs` does for formatting, so no manual step follows the deploy.
- `SlideSource` hides both columns from serialization, so pages that send whole library rows do not ship the lyrics.

## Matching and ranking

A shared query class, `LibrarySearch`, used by both the web endpoint and MCP:

- Split the normalized query into words. An item matches its content when every word appears in `search_text` (one `LIKE` per word, ANDed). This tolerates line breaks, word order, and missing words in between without full-text indexes.
- Each result gets a `match` of `title`, `alias`, or `content`, ranked in that order. A title or alias match still wins when the words also appear in the lyrics.
- Within a rank, sort by title then id. The MCP cursor carries the rank as well as the title and id.
- For content matches, find the best slide in PHP from `slide_text`: the exact phrase on one slide first, then the slide with the most query words. Return its number (from 1) and a short excerpt, about 120 characters around the match.
- Scope to the current team and skip archived items unless asked, as today.

A congregation's library is hundreds of items, a few kilobytes of text each, so `LIKE` scans per team are fast. If that stops being true, a MySQL `FULLTEXT` index on `search_text` can replace the `LIKE` clauses inside `LibrarySearch` without changing callers. MySQL's default parser handles Hebrew words, but its three-character minimum token and stopwords would need care.

## MCP

`search-library` keeps its name and inputs and gains:

- `scope` (optional): `all` (default), `titles` (title and aliases only, today's behaviour), or `content`.
- Each item adds `match` and, for content matches, `slide` and `excerpt`.

The description changes to say it searches titles, aliases, and the words on the slides, so a model can search by a remembered lyric. Existing callers keep working: a query that matched before still matches, ranked first.

## Web UI

Shipping every item's full text to the browser would add hundreds of kilobytes to the Library page, so content matching runs on the server:

- New route `GET slides/library/search?q=&kind=&archived=` returning JSON `{ items: [{ id, slide, excerpt }] }`: the slide text matches from `LibrarySearch`, limited to 50. Titles and aliases are already matched in the browser.
- Library page: title, author, and alias filtering stays instant in the browser. After typing pauses (250 ms, at least 3 characters), it calls the endpoint and adds the content matches below the title matches, with the slide number and the excerpt, the matched words in bold.
- The deck editor's picker gets the same behaviour through a small shared composable, so choosing a song by a lyric works where people pick songs.
- Results that match only in the lyrics are labelled, so it is clear why they appear.

## Tests

- Normalization: case, curly apostrophes, punctuation, niqqud, and Latin accents.
- Inspectors: `slideText()` on the ODP and PPTX fixtures, with speaker notes excluded.
- `LibrarySearch`: a phrase wrapping across lines, words out of order, ranking of title over content, team scoping, archived items, the `%` and `_` escaping, and the excerpt and slide number.
- MCP: `scope`, the new fields, and cursors across ranks.
- Upload, replace, and reformat fill the text; the backfill command fills missing rows and reports unreadable files.
- Feature test for the web endpoint, including another team's items never appearing.

## Order of work

1. Migration, normalizer, and `slideText()` in both inspectors.
2. Writing the text on upload, replace, and reformat, plus the backfill command.
3. `LibrarySearch` and the MCP tool changes.
4. The web endpoint, then the Library page and the deck editor picker.
5. Deploy; the data migration reads the existing items' text.
