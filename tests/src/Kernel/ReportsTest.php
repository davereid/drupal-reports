<?php

declare(strict_types=1);

namespace Drupal\Tests\reports\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;
use Drupal\reports\ReportWriter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests report plugins, access, configuration and output.
 */
#[Group('reports')]
#[RunTestsInSeparateProcesses]
class ReportsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'helper', 'reports', 'reports_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Tests discovery and access.
   */
  public function testAccess(): void {
    $manager = $this->container->get(ReportManager::class);
    $this->assertTrue($manager->hasDefinition('reports_test_table'));
    $this->assertSame('status', $manager->getDefinition('reports_test_status')['type']);

    $reporter = $this->createUser(['access site reports']);
    $admin = $this->createUser(['access site reports', 'administer site configuration']);
    // The default permission.
    $this->assertTrue($manager->createInstance('reports_test_status')->access($reporter)->isAllowed());
    // A report's own permission.
    $this->assertFalse($manager->createInstance('reports_test_table')->access($reporter)->isAllowed());
    $this->assertTrue($manager->createInstance('reports_test_table')->access($admin)->isAllowed());
  }

  /**
   * Tests configuration and storage keys.
   */
  public function testConfiguration(): void {
    $manager = $this->container->get(ReportManager::class);
    $storage = $this->container->get(ReportStorage::class);

    $report = $manager->createInstance('reports_test_table', ['count' => 3, 'unknown' => 'x']);
    $this->assertSame(['count' => 3], $report->getConfiguration());
    $this->assertCount(3, iterator_to_array($storage->getRows($report)));

    $default = $manager->createInstance('reports_test_table');
    $this->assertSame(['count' => 2], $default->getConfiguration());
    $this->assertNotSame($storage->getKey($default), $storage->getKey($report));
    // Reports with options always have a hash, even for the defaults, and the
    // defaults given explicitly are stored as the defaults.
    $this->assertStringStartsWith('reports_test_table--', $storage->getKey($default));
    $this->assertSame($storage->getKey($default), $storage->getKey($manager->createInstance('reports_test_table', ['count' => 2])));
    // Reports without options don't.
    $this->assertSame('reports_test_status', $storage->getKey($manager->createInstance('reports_test_status')));
  }

  /**
   * Tests converting rows to plain text.
   */
  public function testNormalizeRow(): void {
    $report = $this->container->get(ReportManager::class)->createInstance('reports_test_table');
    $header = $report->getSheets()['default']['header'];
    $rows = array_map(fn ($row) => ReportWriter::normalizeRow($header, $row), iterator_to_array($report->getRows(), FALSE));
    $this->assertSame([
      ['Row 1', 'Link 1', 'https://example.com/1', '0'],
      ['Row 2', 'Link 2', 'https://example.com/2', '1'],
    ], $rows);

    $status = $this->container->get(ReportManager::class)->createInstance('reports_test_status');
    $row = iterator_to_array($status->getRows(), FALSE)[0];
    $this->assertSame(['cron', 'Cron', 'Late', 'Warning', 'Cron has not run.'], ReportWriter::normalizeRow($status->getSheets()['default']['header'], $row));
  }

  /**
   * Tests converting rows to spreadsheet rows with links.
   */
  public function testSpreadsheetRow(): void {
    $header = [
      'title' => ['label' => 'Title', 'url' => 'page'],
      'page' => ['label' => 'Page', 'hidden' => TRUE],
      'link' => 'Link',
      'text' => 'Text',
    ];
    $long = 'https://example.com/' . str_repeat('a', 250);
    $cells = ReportWriter::toSpreadsheetRow($header, ['Say "hi"', 'https://example.com/1', $long, 'Plain'])->cells;
    // Columns with a 'url' column link their text to it, with quotes doubled.
    $this->assertInstanceOf(FormulaCell::class, $cells[0]);
    $this->assertSame('=HYPERLINK("https://example.com/1","Say ""hi""")', $cells[0]->getValue());
    $this->assertSame('Say "hi"', $cells[0]->getComputedValue());
    // Absolute URLs link to themselves.
    $this->assertSame('=HYPERLINK("https://example.com/1")', $cells[1]->getValue());
    // URLs over Excel's 255 character limit, and other text, stay plain.
    $this->assertNotInstanceOf(FormulaCell::class, $cells[2]);
    $this->assertSame($long, $cells[2]->getValue());
    $this->assertSame('Plain', $cells[3]->getValue());
  }

}
