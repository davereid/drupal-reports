<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * A report.
 *
 * Reports implement either RealtimeReportInterface, built on every request,
 * or GeneratedReportInterface, built with the Batch API and stored in a file.
 *
 * Reports can take input: give them a default configuration, and implement
 * \Drupal\Core\Plugin\PluginFormInterface for the form shown above the
 * report. Configuration comes from the query string or Drush options, so
 * treat it as untrusted input. Generated reports are stored separately for
 * each configuration.
 *
 * Reports' cacheability describes the data they're built from. Generated
 * reports record their cache tags when generation starts, and are shown as
 * changed when any of them is invalidated afterwards. Prefer list cache tags,
 * such as bundle list tags, to tags of individual entities.
 *
 * Row cells can be scalars, enums, \Drupal\Core\Url, \Drupal\Core\Link,
 * markup or arrays (lists). See README.md for how each is shown on the page,
 * in downloads and in Drush output, and ReportWriter::normalizeCell().
 */
interface ReportInterface extends PluginInspectionInterface, ConfigurableInterface, CacheableDependencyInterface {

  /**
   * The ID of the sheet of reports with one sheet.
   */
  public const SHEET_DEFAULT = 'default';

  /**
   * Gets the report's sheets.
   *
   * Most reports have one sheet, self::SHEET_DEFAULT. A report with several
   * sheets, such as one per content type, shows them as tabs.
   *
   * @return array<string, string|\Stringable>
   *   The sheet labels, keyed by sheet ID. getHeader() gets their columns.
   */
  public function getSheets(): array;

  /**
   * Gets the columns of a sheet.
   *
   * @param string $sheet
   *   (optional) The sheet ID, from getSheets().
   *
   * @return array<string, string|\Stringable|array{label: string|\Stringable, filter?: string|false, url?: string, hidden?: bool}>
   *   Columns keyed by column key. Rows are keyed by the same column keys.
   *   Each column is a label, or an array with:
   *   - label: The column label.
   *   - filter: (optional) A filter below the column heading: 'list' for a
   *     list of the column's values, for columns with few different values,
   *     or 'search' for a text search. Defaults to FALSE: the table's search
   *     box already searches every column.
   *   - url: (optional) The key of a column with a URL to link this column's
   *     text to on the page.
   *   - hidden: (optional) TRUE to leave the column out of the page's table.
   *     It's still in downloads and Drush output.
   *   - list: (optional) TRUE for columns whose cells are lists (arrays),
   *     shown as bulleted lists on the page, or an item list style for
   *     another look, e.g. 'comma-list'. Generated reports store lists one
   *     item per line, and split these columns' cells back into arrays when
   *     they're read, so they're shown as lists on the page and as arrays in
   *     Drush's JSON and YAML output. Arrays in other columns are shown as
   *     comma lists.
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array;

  /**
   * Summarizes what the report checks.
   *
   * Shown on the report page, e.g. the entity types, bundles and fields a
   * report scans, so users know what generating it will do. It describes the
   * report, not its current options, which the options form shows.
   * ReportBase returns the report's description.
   *
   * @return array
   *   A render array, or an empty array for no summary.
   */
  public function getSummary(): array;

  /**
   * Checks whether the report applies to this site at all.
   *
   * Reports that don't apply, e.g. because an optional module they report on
   * isn't installed, have no menu link under Reports, are left out of
   * drush reports:generate --all, and their pages return 404 Not Found. For
   * problems a site can fix, such as missing storage, use getRequirements()
   * instead.
   *
   * It's static, like core's isApplicable() on field formatters and widgets,
   * so it can be checked without creating the plugin. Use \Drupal:: for the
   * services it needs.
   *
   * @param array $plugin_definition
   *   The report's plugin definition, e.g. to check a derivative.
   *
   * @return bool
   *   TRUE if the report applies.
   *
   * @see \Drupal\reports\ReportManager::isApplicable()
   */
  public static function isApplicable(array $plugin_definition): bool;

  /**
   * Checks whether the report can be used on this site.
   *
   * Use it for what the report needs, such as a module or fields to check.
   * Keep it cheap: the status report checks every report. Storage for
   * generated reports is checked by ReportManager::getRequirements().
   *
   * @return array<string, array{title: string|\Stringable, value?: mixed, description?: string|\Stringable, severity: \Drupal\Core\Extension\Requirement\RequirementSeverity}>
   *   Requirements keyed by name, as for hook_runtime_requirements(). An
   *   error prevents viewing and generating the report; a warning is shown
   *   with it.
   */
  public function getRequirements(): array;

  /**
   * Gets the report's own operations.
   *
   * They're shown as buttons on the report page, next to the download
   * buttons. Links the user can't access are hidden.
   *
   * @return array<string, array{title: string|\Stringable, url: \Drupal\Core\Url, weight?: int}>
   *   Operations keyed by name, as for the 'operations' element.
   */
  public function getOperations(): array;

  /**
   * Gets a URL of the report, with its current configuration.
   *
   * @param string $rel
   *   (optional) Which URL: 'canonical' (the report page, default), 'csv',
   *   'xlsx' or 'generate'.
   * @param array $options
   *   (optional) Options for the URL, as for \Drupal\Core\Url::fromRoute().
   *   The report's configuration is added to the query string, as 'config'.
   *
   * @return \Drupal\Core\Url
   *   The URL.
   *
   * @throws \InvalidArgumentException
   *   If $rel isn't one of the above.
   */
  public function toUrl(string $rel = 'canonical', array $options = []): Url;

  /**
   * Checks access to the report.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account): AccessResultInterface;

}
