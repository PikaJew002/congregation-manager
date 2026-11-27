# PHPStan cleanup

Survey taken 2026-11-25 on `dev` (level 7, Larastan): **214 errors** in 43 files.
Re-run once the WeeklyEmail work lands, then work through the phases below in order.
Run with `-v`; without it the output stops after the first 30 errors.

```bash
vendor/bin/phpstan analyse -v --memory-limit=2G
```

## Where they are

| Area                                                   | Errors |
| ------------------------------------------------------ | ------ |
| `app/Modules/ServiceSlides`                            | 129    |
| `app/Modules/ZoomRecordings`                           | 79     |
| Everything else (WeeklyEmail, ModuleAccess, Mail, Mcp) | 6      |

| Identifier                                  | Count |
| ------------------------------------------- | ----- |
| `property.notFound`                         | 101   |
| `argument.type`                             | 34    |
| `argument.templateType` / `*.unresolvable*` | 25    |
| `return.type`                               | 9     |
| `method.notFound`                           | 9     |
| `missingType.generics`                      | 8     |
| other (17 kinds)                            | 28    |

## Root causes

Two missing-type problems cause about 60% of the errors. The rest are mostly
`false` returns from PHP's DOM, file and JSON functions that the code never checks.

1. **Models with no `@property` docblock.** Larastan only reads columns from
   `database/migrations`, so it doesn't know the columns created by migrations in
   `app/Modules/*/database/migrations`. WeeklyEmail and most ServiceSlides models
   already list their columns in a docblock. These don't:
    - `ZoomRecording`: 53 errors
    - `ZoomRecordingSetting`: 11 errors
    - `ServiceDeckEntry`: 1 error, plus more that show up through relations
2. **Relations with no generic types.** `Service::decks()`, `ServiceDeck::service/template/entries()`,
   `ServiceDeckEntry::deck/templateSlide/source()` and `ServiceDeck::scopeUsingSource()`
   don't declare their generic types. So every value read through them is typed as
   plain `Model`. That causes about 50 more errors in `ServicePlanner`, `GenerateServiceDeck`,
   `DeckController`, `ServiceController`, `HistoryController` and `GetService`.
   `SlideTemplate` already does this correctly (`@return HasMany<SlideTemplateSlide, $this>`).

## Plan

### Phase 1: model types (about 130 errors, low risk) — done 2026-11-26, 214 → 66

- Add `@property` docblocks to `ZoomRecording`, `ZoomRecordingSetting` and `ServiceDeckEntry`.
  Build them from the module migrations and `casts()`, using the same style as `EmailRun` and `ServiceDeck`.
- Add `@return BelongsTo<X, $this>` / `HasMany<X, $this>` to the relations listed above,
  and `Builder<ServiceDeck>` to `scopeUsingSource`.
- Re-run PHPStan. Many of the `argument.type`, `return.type`, `method.notFound`
  (`slots()`, `slides()`) and "unresolvable type" errors in ServiceSlides should be gone.
  Fix whatever is left in those files one by one.

Alternative considered: set Larastan's `databaseMigrationsPath` to include the module
migration folders. That's less to write, but it goes against the docblock convention
the other models already follow. Docblocks are also more precise for casts like `list<string>`.

### Phase 2: real bugs PHPStan found (fix and test each) — done 2026-11-26, 66 → 60

The index was confirmed missing in MySQL. New `2026_12_01_*_add_unique_team_index_*` migrations
remove any duplicate settings rows (keeping each congregation's oldest) and add the index. The
original migrations now put `unique()` on the column, so fresh installs get it from the start.

- **Unique index probably never created.** In `create_weekly_email_tables.php:26` and
  `create_zoom_recording_tables.php:13`, `->constrained()->cascadeOnDelete()->unique()`
  calls `unique()` on the foreign-key definition, not on the column. Check the real
  schema. If the index is missing, add a new migration that creates it.
  Don't edit the old migrations, which have already run.
- **`env()` in a job** (`TranscodeAndUpload.php:43-44`): it returns null once config is
  cached. Move `FFMPEG_BINARY`/`FFPROBE_BINARY` into a config file and read them with `config()`.
- **`preg_split` can return false** (`WeeklyEmailController.php:45`). This file is being
  edited in the other session, so leave it until that work merges.
- **`MailChannelsTransport.php:43`**: `getOriginalMessage()` is a `RawMessage`. Check it
  is a `Message` before converting, and throw if it isn't.

### Phase 3: unchecked `false` returns (about 45 errors, medium risk) — done 2026-11-26, 60 → 7

New `App\Support\LocalFile` reads and opens local files or throws. The `OdpDocument` trait
has `cloneElement`, `importElement` and `xml` helpers. `OdpInspector` runs its XPath queries
through one `elements()` helper.

Throw an exception when the call fails. Don't cast the result.

- **File I/O**: `file_get_contents`, `fopen` and `json_encode` results are passed straight to
  `Storage::put`, `stream_copy_to_stream` and the like. Affected files: `SlideFiles`,
  `GenerateServiceDeck`, `TranscodeAndUpload`, `UpdateManifest`, `OdpDeckBuilder::manifest`,
  and `OdpDocument::readZip`, a trait used by `OdpDeckBuilder` and `SongSlideFormatter`.
  Add a small helper, or use `JSON_THROW_ON_ERROR` and checked reads.
- **DOM**: `cloneNode()`/`importNode()` return `DOMNode|false`, and `DOMXPath::query()` can
  return `false`. Affected: `OdpDeckBuilder` (16), `SongSlideFormatter` (11), `OdpInspector` (9).
  Add one helper in `OdpDocument` that clones or imports a node and returns a `DOMElement`
  or throws. Then remove the five `instanceof DOMElement` checks that PHPStan says are always true.
- `OdpDeckBuilder:52/61`: `path` is optional in the source array shape. Either make it
  required in the shape or handle it being missing.

### Phase 4: small cleanups (about 15 errors, trivial) — done 2026-11-26, 7 → 0

`ServicePlanner::replaceEntries` keeps its `array_values`: the deck editor passes request
input there, which isn't guaranteed to be a list, so its `@param` now says so instead.

`array_values` on arrays that are already lists, unnecessary `?->`, the always-true
`match` arm in `OdpDocument:161`, `is_string` on a value that is always a string, the
`return.unusedType` in `SlideLinkDownloader`, missing array value types
(`CongregationServer::registeredTools`, `OdpDeckBuilder::importSource`), the
`ModuleAccess::grantsFor` return type, and `array_map('trim', …)` in `ServiceController:206`.

### Rules

- No `@phpstan-ignore`, no baseline, no `@var` overrides, no casts just to quiet an error.
- Run the test suite after each phase (`php artisan test`). Before committing, run
  `npm run check` if any front-end files changed.
- Once at 0 errors, consider adding PHPStan to CI so new errors can't pile up again.
