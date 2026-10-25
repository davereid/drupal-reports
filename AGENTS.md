# Reports module

Drupal contrib module `reports`: plugin-based site reports, shown as DataTables tables or like core's status report, downloadable as CSV or Excel, and runnable with Drush. See `README.md` for features and the plugin API.

## Architecture

- Plugins: `src/Plugin/Report`, `#[Report]` attribute (`src/Attribute/Report.php`), managed by `ReportManager`.
  - `RealtimeReportInterface` builds rows on every request (`getRows()`).
  - `GeneratedReportInterface` builds them with the Batch API (`getBatchOperations()`): each operation is a public plugin method called with its arguments, a `ReportWriter` and `&$context`.
  - `ReportBase` is the base class; `EntityReportBase` (experimental, `@internal`) adds the `include` option (unpublished, trashed), entity queries and batches, and the entity columns from Helper's `EntityHelper::toArray()`; `StatusReportBase` is for `type: 'status'` reports.
- Columns (`getHeader()`/`getSheets()`) are labels or arrays with `label`, `filter` (`list`, `search` or FALSE), `url` (column key to link to) and `hidden`.
- `ReportStorage` keeps generated files in `getDirectory()` (`$settings['reports_directory']`, default `private://reports`; public and temporary directories get a warning), named `<key>.csv`, `<key>.<sheet>.csv` and `<key>.xlsx`, where the key is the plugin ID plus an HMAC of the plugin ID and options, keyed with the site's hash salt and private key, so file names can't be guessed. Keys never contain a dot. Status is in the `reports` key/value collection.
- `isApplicable()` hides a report's menu link when it doesn't apply (e.g. an optional module isn't installed); `getRequirements()` explains fixable problems. `ReportManager::getRequirements()`/`isUsable()` add the storage requirement for generated reports; pages, generate access, the options form and Drush use them. The status report only shows `getGlobalRequirements()` (storage).
- Generating is guarded by the status, not core's persistent lock (whose locks share one owner and can't be extended in later requests): `ReportStorage::start()` refuses with `ReportGeneratingException` while a generation of the same key is running, i.e. its `heartbeat` (updated by each batch operation) is under `GENERATING_TIMEOUT` (5 minutes) old. A short regular lock makes the check-and-set atomic.
- Download-only reports (`downloadOnly: TRUE`) keep only their Excel file; their sheet row counts are stored in the status.
- Reports are at `/admin/reports/{plugin ID}`, with menu links under Reports from `Plugin/Derivative/ReportMenuLinks`. Report IDs must not match core paths under `/admin/reports` (e.g. `status`, `dblog`): core's routes win.
- The page's filter bar (`js/reports.js`) uses Views exposed form markup; `ReportsHooks::libraryInfoAlter()` adds `views/views.module` as a dependency so admin themes style it.

## Conventions

- Batch callbacks declare the context untyped (`&$context`), with `@param array|\ArrayAccess $context`.
- Plugins validate their configuration in `setConfiguration()`: it comes from the query string and Drush options. Sort list-like options so equivalent options get the same storage key.
- Use list cache tags (bundle list tags, config list tags) in `getCacheTags()`, not individual entity tags.
- Prefer Helper's APIs (`EntityHelper`, `EntityType`, `ArrayHelper`, `TrashHelperTrait`). Code that should move to Helper gets a `@todo` and a task in Helper's local notes.
- Code must work on Drupal 11.3 (`core_version_requirement: ^11.3`), e.g. `FilterFormat::getRoles()` is 11.4 only.

## Tests

- Kernel tests in `tests/src/Kernel`, test plugins in `tests/modules/reports_test`.
- `GeneratedReportTest` sets `file_private_path` in `setUpFilesystem()` (the private stream wrapper must exist before the kernel boots), and installs every `entity_test*` entity type with link or text fields, since Absolute URLs scans all content entity types.
- Read download-only reports' data from their Excel file (`readSpreadsheet()`), since their CSVs are deleted.
