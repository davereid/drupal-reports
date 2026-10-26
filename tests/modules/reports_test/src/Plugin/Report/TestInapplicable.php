<?php

declare(strict_types=1);

namespace Drupal\reports_test\Plugin\Report;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Drupal\reports\Plugin\Report\RealtimeReportInterface;
use Drupal\reports\Plugin\Report\ReportBase;

/**
 * A report that doesn't apply, and has an unmet requirement.
 */
#[Report(
  id: 'reports_test_inapplicable',
  label: new TranslatableMarkup('Test inapplicable'),
)]
class TestInapplicable extends ReportBase implements RealtimeReportInterface {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(array $plugin_definition): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirements(): array {
    return [
      'module' => [
        'title' => 'Test module',
        'description' => 'The test module is missing.',
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    return ['name' => 'Name'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(string $sheet = self::SHEET_DEFAULT): iterable {
    return [];
  }

}
