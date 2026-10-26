<?php

declare(strict_types=1);

namespace Drupal\reports\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a report plugin.
 *
 * @see \Drupal\reports\Plugin\Report\ReportInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Report extends Plugin {

  /**
   * Constructs a Report attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The report title.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A description, shown under the report's link on the
   *   Reports page (/admin/reports).
   * @param string|string[] $permission
   *   (optional) The permission needed to view the report, or a list of
   *   permissions that are all needed, e.g. ['access site reports', 'access
   *   files overview']. Defaults to 'access site reports'. Reports that
   *   replace routes use those routes' access requirements instead. Plugins
   *   can override access() for other rules.
   * @param string $type
   *   (optional) How the report is displayed: 'table' (default), or 'status'
   *   for core's status report. Status reports have the columns of
   *   \Drupal\reports\Plugin\Report\StatusReportBase.
   * @param bool $downloadOnly
   *   (optional) TRUE for reports that are only downloaded, e.g. ones with
   *   many sheets meant to be read as a spreadsheet. Their page lists the
   *   sheets with download links instead of showing a table.
   * @param array<string, string> $routes
   *   (optional) Routes of other modules that the report replaces, keyed by
   *   route name, with the sheet each one shows, e.g.
   *   ['entity.field_storage_config.collection' => self::SHEET_DEFAULT].
   *   The routes keep their path, access requirements, menu links and local
   *   tasks, so local tasks on them become the report's sheet tabs. Routes
   *   that don't exist, e.g. because their module isn't installed, are
   *   skipped.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly string|array $permission = 'access site reports',
    public readonly string $type = 'table',
    public readonly bool $downloadOnly = FALSE,
    public readonly array $routes = [],
    public readonly ?string $deriver = NULL,
  ) {}

}
