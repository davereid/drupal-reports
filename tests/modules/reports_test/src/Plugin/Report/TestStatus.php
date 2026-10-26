<?php

declare(strict_types=1);

namespace Drupal\reports_test\Plugin\Report;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Drupal\reports\Plugin\Report\StatusReportBase;

/**
 * A status report.
 */
#[Report(
  id: 'reports_test_status',
  label: new TranslatableMarkup('Test status'),
  type: 'status',
)]
class TestStatus extends StatusReportBase {

  /**
   * {@inheritdoc}
   */
  public function getRows(string $sheet = self::SHEET_DEFAULT): iterable {
    yield [
      'key' => 'cron',
      'label' => 'Cron',
      'status' => 'Late',
      'severity' => RequirementSeverity::Warning,
      'description' => 'Cron has not run.',
    ];
  }

}
