<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

/**
 * A report built on every request.
 */
interface RealtimeReportInterface extends ReportInterface {

  /**
   * Gets the rows of a sheet.
   *
   * @param string $sheet
   *   The sheet ID.
   *
   * @return iterable<array<string, mixed>>
   *   Rows keyed by column key.
   */
  public function getRows(string $sheet = 'default'): iterable;

}
