# Reports

Reports provides a plugin type for site reports. Reports are shown as sortable, searchable tables (with [DataTables](https://datatables.net/) and filters above the table, like a Views exposed form) or like core's status report, can be downloaded as CSV or Excel, and can be run with Drush. While a table's filters or search hide rows, the rows shown can be downloaded as CSV, in their current order.

Expensive reports are generated on demand with the Batch API and stored in the private file system. Each report shows when it was generated, and warns when it's older than a configurable interval or when the data it was built from has changed (using cache tags). A report can't be generated twice at the same time with the same options.

Submit bug reports and feature suggestions, or track changes, in the [issue queue](https://www.drupal.org/project/issues/reports).


## Requirements

- [Helper](https://www.drupal.org/project/helper)
- [OpenSpout](https://github.com/openspout/openspout), installed by Composer.
- [Symfony CSS Selector](https://symfony.com/doc/current/components/css_selector.html), installed by Composer, for the HTML elements report.
- The private file system, for generated reports (or another non-public directory, see Configuration). Realtime reports work without it. The status report warns when generated reports can't be stored.


## Installation

Install as you would normally install a contributed Drupal module. For further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

- Each report has its own page under _Reports_, e.g. `/admin/reports/absolute_urls`, listed on the _Reports_ page (`/admin/reports`). By default, they need the _View site reports_ permission; each report can require other permissions. _Unused files_ also needs _Access the Files overview page_, _Unused taxonomy terms_ needs _Access the taxonomy vocabulary overview page_, and _Site audit_ needs _Administer site configuration_.
- Set when generated reports are out of date at _Configuration_ » _System_ » _Reports_ (`/admin/config/system/reports`).
- Generated reports are stored in `private://reports`. To use another directory, such as a private S3 bucket, set `$settings['reports_directory'] = 's3private://reports';` in `settings.php`. It must be writable. `public://` works but the status report warns about it, since anyone with a file's URL could download reports, which can contain unpublished content and site details. `temporary://` also works with a warning, since it can be cleared at any time and isn't shared between web servers.
- Generated report files are deleted on cron once they're older than _Delete generated reports after_ (30 days by default), and generated again when next viewed. `drush reports:clean` does the same on demand.
- Generated reports process `$settings['reports_batch_size']` items per batch pass, set in `settings.php`. It defaults to core's `$settings['entity_update_batch_size']` (50).


## Included reports

- **Absolute URLs**: links in content (link and URI fields, Custom Field link and URI subfields, and `<a>` elements in text fields) that use absolute URLs to the site's own domains. Checks text as displayed (after text format filters) or as entered.
- **HTML elements**: elements in formatted text fields (and formatted Custom Field Text (long) subfields) that match an HTML tag, CSS selector or XPath expression, with their text and HTML. Checks text as displayed or as entered.
- **Field list**: replaces Field UI's Field list (/admin/reports/fields), with a sheet for each of its tabs: the fields, the fields used in views (Views UI) and field permissions (Field Permissions), so each can be filtered and downloaded.
- **Content counts**: the number of items of each bundle of each content entity type, and how many are published. Useful to find unused bundles.
- **Duplicate values**: content that shares the same value in a single-value text field, such as the title (the default) or a summary.
- **Site audit**: an Excel workbook (download only) with, for each entity type, a sheet listing its bundles (description, URL patterns, new revisions, workflow, translation, Layout Builder, and for content types their available menus, preview and author display settings, or for media types their source) and a sheet per bundle listing its fields (field type, allowed values, cardinality, required, widget, formatter, character limit, help text), with custom field subfields as their own rows; plus a sheet of text formats. Entity types whose fields are managed in the UI but have no bundles, such as users, get only their field sheet. The bundle summaries and the text formats sheet are options (_Also include_), on by default.
- **Unused files**: files that nothing uses (no file usage records), optionally including temporary files.
- **Unused taxonomy terms**: terms that no entity reference field references, optionally limited to some vocabularies. Requires the Taxonomy module.


## Drush commands

- `drush reports:list`: lists the reports and when they were generated.
- `drush reports:run <id> [--config=key=value] [--sheet=<sheet>] [--generate]`: outputs a report. Asks to regenerate a generated report that's missing or out of date, unless `--generate` is given. For download-only reports, such as Site audit, it gives the download URL instead of the rows (set `--uri` so the URL uses the site's address).
- `drush reports:generate <id> [--config=key=value]`: generates a report. `drush reports:generate --all` generates every generated report with its default options.
- `drush reports:clean`: deletes generated report files older than _Delete generated reports after_, and unfinished ones, as cron does.


## Writing reports

Report plugins live in `src/Plugin/Report` and use the `#[Report]` attribute. Its `permission` and `roles` use the format of a route's `_permission` and `_role` requirements: separated by `,` when all are required, or by `+` when any one is enough, e.g. `permission: 'access site reports,access files overview'` or `roles: 'editor+site_manager'`. Users who have the permissions or the roles can view the report (`ReportBase::access()`); override `access()` for other rules. Extend `ReportBase` and implement either:

- `RealtimeReportInterface`: `getRows()` builds the rows on every request.
- `GeneratedReportInterface`: `getBatchOperations()` returns plugin methods to run as batch operations. Each is called with its arguments, a `ReportWriter` and the batch context, and adds rows with `$writer->addRow($row, $sheet)`.

A report's page is `/admin/reports/<plugin ID>`. Choose an ID that no other route uses under `/admin/reports`, such as core's `status` or `dblog`: their routes win, so the report can't be shown. The status report warns about such reports, and they get no menu link.

### Report data

- `getHeader($sheet)` returns a sheet's columns, keyed by column key: a label, or `['label' => …, 'filter' => …]` where `filter` is `'list'` (a dropdown of the column's values, for columns with few different values), `'search'` (a text box) or `FALSE` (the default: the table's search box already searches every column). `'url' => '<column key>'` links the column's text to another column's URL, and `'hidden' => TRUE` leaves a column out of the page but keeps it in downloads and Drush. Reports with several sheets also override `getSheets()`, which returns the sheet labels keyed by sheet ID; the first sheet is `ReportInterface::SHEET_DEFAULT` for reports with one sheet. `getRows($sheet)` and the report writer's `addRow($row, $sheet)` take the same sheet IDs.
- Cells can be strings, numbers, `Url` or `Link` objects. Links are rendered as links. In files and Drush output, URLs become absolute URLs and links keep only their text.
- Status reports (`type: 'status'`) extend `StatusReportBase`.
- Reports meant to be read as a spreadsheet, such as ones with many sheets, can set `downloadOnly: TRUE` in their attribute: their page lists the sheets with their row counts and offers the Excel file, instead of showing a table. Only the Excel file is kept: the sheets' CSV files are deleted once it's written.
- Reports can replace other modules' pages with `routes` in their attribute: route names, each with the sheet it shows, e.g. `routes: ['entity.field_storage_config.collection' => self::SHEET_DEFAULT]`. The routes keep their path, access requirements, menu links and local tasks, so local tasks on them become the report's sheet tabs. The report's access, including its downloads, follows the requirements of the route that shows its default sheet, instead of its `permission`. Routes of modules that aren't installed are skipped.
- Reports that check formatted text in content can extend `FormattedTextReportBase` (experimental), as HTML elements does: it finds formatted text fields (including field types that extend them) and formatted custom field Text (long) subfields, adds a _Check text fields_ option, batches by field and builds the summary table. Implement `getValueHeader()` and `getHtmlRows()`. Reports that also check URLs extend `LinkReportBase` (experimental), as Absolute URLs does: it adds link and URI fields and custom field link and URI subfields, `getUrlRows()`, and `getHtmlLinks()` to find the links in formatted text. Modules can check other field types, or skip some, with `hook_reports_formatted_text_columns_alter()` and `hook_reports_link_columns_alter()`, and other custom field subfields with `hook_reports_formatted_text_custom_columns_alter()` and `hook_reports_link_custom_columns_alter()` (see `reports.api.php`), for every report built on each base class.
- Reports that list entities can extend `EntityReportBase` (experimental): it adds an _include_ option (unpublished content, content in the trash) and an options form for it (add your own options in `buildConfigurationForm()` before calling the parent), entity queries and batches that respect them, and the title, type, ID, page and edit URL and state columns (using Helper's `EntityHelper::getState()`: deleted, moderation state, published or unpublished).

### Options, summary, requirements, and cacheability

- Reports take options through `defaultConfiguration()`, and implement `PluginFormInterface` to show a form above the report. Generated reports are stored separately for each configuration.
- `getSummary()` returns a render array describing what the report checks, e.g. the entity types, bundles and fields it scans, rather than its current options. It's shown on the report page, and defaults to the report's description.
- The static `isApplicable(array $plugin_definition)` returns FALSE when the report doesn't apply to the site, e.g. an optional module it reports on isn't installed: it then has no menu link under Reports, and its pages return 404 Not Found.
- `getRequirements()` returns requirements in `hook_runtime_requirements()`'s format, e.g. a warning when there's nothing to report on. Errors prevent viewing and generating the report; warnings are shown with it. Storage for generated reports is checked for you, and is the only requirement on the status report.
- `toUrl($rel, $options)` returns the URL of the report's page (`canonical`), downloads (`csv`, `xlsx`) or generation (`generate`), with its current options.
- `getOperations()` adds buttons to the report page, next to the downloads (links the user can't access are hidden).
- `getCacheTags()` lists the data a report is built from. Prefer list tags, such as bundle list tags (`node_list:article`), to tags of individual entities.
