# Reports

Reports provides a plugin type for site reports. Reports are shown as sortable, searchable tables (with [DataTables](https://datatables.net/) and filters above the table, like a Views exposed form) or like core's status report, can be downloaded as CSV or Excel, and can be run with Drush. While a table's filters or search hide rows, the rows shown can be downloaded as CSV, in their current order.

Expensive reports are generated on demand with the Batch API and stored in the private file system. Each report shows when it was generated, and warns when it's older than a configurable interval or when the data it was built from has changed (using cache tags).

Submit bug reports and feature suggestions, or track changes, in the [issue queue](https://www.drupal.org/project/issues/reports).


## Requirements

- [Helper](https://www.drupal.org/project/helper)
- [OpenSpout](https://github.com/openspout/openspout), installed by Composer.
- The private file system, for generated reports (or another non-public directory, see Configuration). Realtime reports work without it. The status report warns when generated reports can't be stored.


## Installation

Install as you would normally install a contributed Drupal module. For further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

- Reports are listed at _Reports_ » _Reports_ (`/admin/reports/reports`). By default, they need the _View site reports_ permission; each report can require other permissions. _Unused files_ also needs _Access the Files overview page_, _Unused taxonomy terms_ needs _Access the taxonomy vocabulary overview page_, and _Site audit_ needs _Administer site configuration_.
- Set when generated reports are out of date at _Configuration_ » _System_ » _Reports_ (`/admin/config/system/reports`).
- Generated reports are stored in `private://reports`. To use another directory, such as a private S3 bucket, set `$settings['reports_directory'] = 's3private://reports';` in `settings.php`. It must be writable. `public://` works but the status report warns about it, since anyone with a file's URL could download reports, which can contain unpublished content and site details. `temporary://` also works with a warning, since it can be cleared at any time and isn't shared between web servers.
- Generated report files are deleted on cron once they're older than _Delete generated reports after_ (30 days by default), and generated again when next viewed. `drush reports:clean` does the same on demand.
- Generated reports process `$settings['reports_batch_size']` items per batch pass, set in `settings.php`. It defaults to core's `$settings['entity_update_batch_size']` (50).


## Included reports

- **Absolute URLs**: links in content (link fields, Custom Field link and URI subfields, and `<a>` elements in text fields) that use absolute URLs to the site's own domains. Checks text as displayed (after text format filters) or as entered.
- **Duplicate values**: content that shares the same value in a single-value text field, such as the title (the default) or a summary.
- **Unused files**: files that nothing uses (no file usage records), optionally including temporary files.
- **Unused taxonomy terms**: terms that no entity reference field references, optionally limited to some vocabularies. Requires the Taxonomy module.
- **Content counts**: the number of items of each bundle of each content entity type, and how many are published. Useful to find unused bundles.
- **Site audit**: an Excel workbook with, for each entity type, a sheet listing its bundles (description, available menus, URL patterns) and a sheet per bundle listing its fields (field type, allowed values, cardinality, required, widget, formatter, character limit, help text), with custom field subfields as their own rows; plus a sheet of text formats.


## Drush commands

- `drush reports:list`: lists the reports and when they were generated.
- `drush reports:run <id> [--config=key=value] [--sheet=<sheet>] [--generate]`: outputs a report. Asks to regenerate a generated report that's missing or out of date, unless `--generate` is given. For download-only reports, such as Site audit, it gives the download URL instead of the rows (set `--uri` so the URL uses the site's address).
- `drush reports:generate <id> [--config=key=value]`: generates a report. `drush reports:generate --all` generates every generated report with its default options.


## Writing reports

Report plugins live in `src/Plugin/Report` and use the `#[Report]` attribute. Its `permission` is one permission or a list of permissions that are all required (`ReportBase::access()`); override `access()` for other rules. Extend `ReportBase` and implement either:

- `RealtimeReportInterface`: `getRows()` builds the rows on every request.
- `GeneratedReportInterface`: `getBatchOperations()` returns plugin methods to run as batch operations. Each is called with its arguments, a `ReportWriter` and the batch context, and adds rows with `$writer->addRow($row, $sheet)`.

Report data:

- `getHeader()` returns the columns, keyed by column key: a label, or `['label' => …, 'filter' => …]` where `filter` is `'list'` (a dropdown of the column's values, for columns with few different values), `'search'` (a text box) or `FALSE` (the default: the table's search box already searches every column). `'url' => '<column key>'` links the column's text to another column's URL, and `'hidden' => TRUE` leaves a column out of the page but keeps it in downloads and Drush. Reports with several sheets override `getSheets()` instead.
- Cells can be strings, numbers, `Url` or `Link` objects. Links are rendered as links. In files and Drush output, URLs become absolute URLs and links keep only their text.
- Status reports (`type: 'status'`) extend `StatusReportBase`.
- Reports meant to be read as a spreadsheet, such as ones with many sheets, can set `downloadOnly: TRUE` in their attribute: their page lists the sheets with their row counts and offers the Excel file, instead of showing a table. Only the Excel file is kept: the sheets' CSV files are deleted once it's written.
- Reports that check URLs and formatted text in content can extend `TextFieldReportBase` (experimental), as Absolute URLs does: it finds link, formatted text and custom field subfields, adds a _Check text fields_ option, batches by field and builds the summary table. Implement `getValueHeader()`, and `getUrlRows()` and/or `getHtmlRows()`.
- Reports that list entities can extend `EntityReportBase` (experimental): it adds an _include_ option (unpublished content, content in the trash), entity queries and batches that respect them, and the title, type, ID, page and edit URL and state columns (using Helper's `EntityHelper::getState()`: deleted, moderation state, published or unpublished).

Options and cache tags:

- Reports take options through `defaultConfiguration()`, and implement `PluginFormInterface` to show a form above the report. Generated reports are stored separately for each configuration.
- `getSummary()` returns a render array describing what the report checks (not its current options); by default, the report's description, e.g. the entity types, bundles and fields it scans. It's shown on the report page.
- The static `isApplicable(array $plugin_definition)` returns FALSE when the report doesn't apply to the site, e.g. an optional module it reports on isn't installed: it then has no menu link under Reports, and its pages return 404 Not Found.
- `getRequirements()` returns requirements in `hook_runtime_requirements()`'s format, e.g. a warning when there's nothing to report on. Errors prevent viewing and generating the report; warnings are shown with it. Storage for generated reports is checked for you, and is the only requirement on the status report.
- `getOperations()` adds buttons to the report page, next to the downloads (links the user can't access are hidden).
- `getCacheTags()` lists the data a report is built from. Prefer list tags, such as bundle list tags (`node_list:article`), to tags of individual entities.
