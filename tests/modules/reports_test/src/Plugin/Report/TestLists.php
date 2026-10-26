<?php

declare(strict_types=1);

namespace Drupal\reports_test\Plugin\Report;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\Plugin\Report\ReportBase;
use Drupal\reports\ReportWriter;

/**
 * A generated report with a list column.
 */
#[Report(
  id: 'reports_test_lists',
  label: new TranslatableMarkup('Test lists'),
)]
class TestLists extends ReportBase implements GeneratedReportInterface {

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    return [
      'name' => 'Name',
      'items' => ['label' => 'Items', 'list' => TRUE],
      'notes' => 'Notes',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    return [['processRows']];
  }

  /**
   * Batch operation: writes the rows.
   *
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processRows(ReportWriter $writer, &$context): void {
    $writer->addRow(['name' => 'Several', 'items' => ['One', 'Two'], 'notes' => "Line 1\nLine 2"]);
    $writer->addRow(['name' => 'None', 'items' => [], 'notes' => '']);
  }

}
