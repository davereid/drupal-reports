<?php

declare(strict_types=1);

namespace Drupal\reports_test\Plugin\Report;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Drupal\reports\Plugin\Report\RealtimeReportInterface;
use Drupal\reports\Plugin\Report\ReportBase;

/**
 * A report whose page is taken by a route in reports_test.routing.yml.
 */
#[Report(
  id: 'reports_test_shadowed',
  label: new TranslatableMarkup('Test shadowed'),
)]
class TestShadowed extends ReportBase implements RealtimeReportInterface {

  /**
   * {@inheritdoc}
   */
  public function getHeader(): array {
    return ['name' => 'Name'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(string $sheet = 'default'): iterable {
    return [];
  }

}
