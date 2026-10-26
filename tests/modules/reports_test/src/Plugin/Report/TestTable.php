<?php

declare(strict_types=1);

namespace Drupal\reports_test\Plugin\Report;

use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reports\Attribute\Report;
use Drupal\reports\Plugin\Report\RealtimeReportInterface;
use Drupal\reports\Plugin\Report\ReportBase;

/**
 * A configurable table report with links.
 */
#[Report(
  id: 'reports_test_table',
  label: new TranslatableMarkup('Test table'),
  permission: 'administer site configuration',
)]
class TestTable extends ReportBase implements RealtimeReportInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['count' => 2];
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    return ['name' => 'Name', 'link' => 'Link', 'url' => 'URL', 'flag' => 'Flag'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(string $sheet = self::SHEET_DEFAULT): iterable {
    for ($i = 1; $i <= (int) $this->configuration['count']; $i++) {
      yield [
        'name' => "Row $i",
        'link' => Link::fromTextAndUrl("Link $i", Url::fromUri("https://example.com/$i")),
        'url' => Url::fromUri("https://example.com/$i"),
        'flag' => $i % 2 === 0,
      ];
    }
  }

}
