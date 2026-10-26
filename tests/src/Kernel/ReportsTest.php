<?php

declare(strict_types=1);

namespace Drupal\Tests\reports\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\reports\Plugin\Report\RealtimeReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
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

    // User 1 bypasses permissions, so create it first.
    $this->createUser();
    $reporter = $this->createUser(['access site reports']);
    $admin = $this->createUser(['access site reports', 'administer site configuration']);
    // The default permission.
    $this->assertTrue($manager->createInstance('reports_test_status')->access($reporter)->isAllowed());
    // A report's own permission.
    $this->assertFalse($manager->createInstance('reports_test_table')->access($reporter)->isAllowed());
    $this->assertTrue($manager->createInstance('reports_test_table')->access($admin)->isAllowed());
    // A list of permissions are all needed.
    $files = $this->createUser(['access site reports', 'access files overview']);
    $this->assertFalse($manager->createInstance('unused_files')->access($reporter)->isAllowed());
    $this->assertTrue($manager->createInstance('unused_files')->access($files)->isAllowed());
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
    // The defaults given explicitly are stored as the defaults.
    $this->assertStringStartsWith('reports_test_table--', $storage->getKey($default));
    $this->assertSame($storage->getKey($default), $storage->getKey($manager->createInstance('reports_test_table', ['count' => 2])));
    // Reports without options also have a hash, so file names can't be
    // guessed, e.g. in a public directory.
    $status_key = $storage->getKey($manager->createInstance('reports_test_status'));
    $this->assertStringStartsWith('reports_test_status--', $status_key);
    // The hash depends on the site's hash salt.
    $this->setSetting('hash_salt', 'another salt');
    $this->assertNotSame($status_key, $storage->getKey($manager->createInstance('reports_test_status')));
  }

  /**
   * Tests converting rows to plain text.
   */
  public function testNormalizeRow(): void {
    $report = $this->container->get(ReportManager::class)->createInstance('reports_test_table');
    assert($report instanceof RealtimeReportInterface);
    $header = $report->getHeader();
    $rows = array_map(fn ($row) => ReportWriter::normalizeRow($header, $row), iterator_to_array($report->getRows(), FALSE));
    $this->assertSame([
      ['Row 1', 'Link 1', 'https://example.com/1', '0'],
      ['Row 2', 'Link 2', 'https://example.com/2', '1'],
    ], $rows);

    $status = $this->container->get(ReportManager::class)->createInstance('reports_test_status');
    assert($status instanceof RealtimeReportInterface);
    $row = iterator_to_array($status->getRows(), FALSE)[0];
    $this->assertSame(['cron', 'Cron', 'Late', 'Warning', 'Cron has not run.'], ReportWriter::normalizeRow($status->getHeader(), $row));
  }

  /**
   * Tests requirements, and menu links of reports that don't apply.
   */
  public function testRequirements(): void {
    $manager = $this->container->get(ReportManager::class);
    $table = $manager->createInstance('reports_test_table');
    $this->assertTrue($manager->isApplicable('reports_test_table'));
    $this->assertSame([], $manager->getRequirements($table));
    $this->assertTrue($manager->isUsable($table));

    $inapplicable = $manager->createInstance('reports_test_inapplicable');
    $this->assertFalse($manager->isApplicable('reports_test_inapplicable'));
    $this->assertFalse($manager->isApplicable('missing'));
    $this->assertFalse($manager->isUsable($inapplicable));

    // The route parameter converter only converts reports that apply, with
    // the options from the query string.
    $converter = $this->container->get('reports.param_converter');
    $this->container->get('request_stack')->getCurrentRequest()->query->set('config', ['count' => 5]);
    $converted = $converter->convert('reports_test_table', ['type' => 'report'], 'report', []);
    $this->assertInstanceOf(ReportInterface::class, $converted);
    $this->assertEquals(['count' => 5], $converted->getConfiguration());
    $this->assertNull($converter->convert('reports_test_inapplicable', ['type' => 'report'], 'report', []));
    $this->assertNull($converter->convert('missing', ['type' => 'report'], 'report', []));

    // Reports that don't apply have no menu link.
    $links = $this->container->get('plugin.manager.menu.link')->getDefinitions();
    $this->assertArrayHasKey('reports.report:reports_test_table', $links);
    $this->assertArrayNotHasKey('reports.report:reports_test_inapplicable', $links);
  }

  /**
   * Tests reports whose page is taken by another route.
   */
  public function testRouteConflict(): void {
    $this->container->get('router.builder')->rebuild();
    $manager = $this->container->get(ReportManager::class);
    $this->assertNull($manager->getConflictingRoute('reports_test_table'));
    $this->assertSame('reports.test_shadowing', $manager->getConflictingRoute('reports_test_shadowed'));

    $requirements = $manager->getGlobalRequirements();
    $this->assertArrayNotHasKey('reports_route_conflict:reports_test_table', $requirements);
    $this->assertStringContainsString('reports.test_shadowing', (string) $requirements['reports_route_conflict:reports_test_shadowed']['description']);

    $links = $this->container->get('plugin.manager.menu.link')->getDefinitions();
    $this->assertArrayHasKey('reports.report:reports_test_table', $links);
    $this->assertArrayNotHasKey('reports.report:reports_test_shadowed', $links);
  }

  /**
   * Tests reports that replace other modules' routes, with the Field list.
   */
  public function testReplacedRoutes(): void {
    $this->enableModules(['field', 'field_ui', 'text', 'entity_test']);
    $this->installEntitySchema('entity_test');
    FieldStorageConfig::create(['field_name' => 'field_text', 'entity_type' => 'entity_test', 'type' => 'text'])->save();
    FieldConfig::create(['field_name' => 'field_text', 'entity_type' => 'entity_test', 'bundle' => 'entity_test'])->save();
    $this->container->get('router.builder')->rebuild();
    $manager = $this->container->get(ReportManager::class);

    // Field UI's route shows the report, with only its own access check.
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.field_storage_config.collection');
    $this->assertSame('/admin/reports/fields', $route->getPath());
    $this->assertSame('fields', $route->getDefault('report'));
    $this->assertSame('default', $route->getDefault('sheet'));
    $this->assertSame(['_permission' => 'administer content types'], $route->getRequirements());
    // Routes of modules that aren't installed are skipped.
    $this->assertSame(['entity.field_storage_config.collection' => 'default'], $manager->getReplacedRoutes('fields'));
    $this->assertNull($manager->getConflictingRoute('fields'));
    // Field UI's menu link is used instead of the report's own.
    $links = $this->container->get('plugin.manager.menu.link')->getDefinitions();
    $this->assertArrayNotHasKey('reports.report:fields', $links);

    // The report's access, which its downloads use, is the route's.
    $report = $manager->createInstance('fields');
    $this->createUser();
    $this->assertTrue($report->access($this->createUser(['administer content types']))->isAllowed());
    $this->assertFalse($report->access($this->createUser(['access site reports']))->isAllowed());

    // Sheets that need other modules or permissions are left out.
    $this->assertSame(['default'], array_keys($report->getSheets()));
    assert($report instanceof RealtimeReportInterface);
    $rows = iterator_to_array($report->getRows(), FALSE);
    $row = array_values(array_filter($rows, fn ($row) => $row['id'] === 'field_text'))[0];
    $this->assertSame('entity_test', $row['entity_type']);
    $this->assertSame('Short text (module: text)', $row['type']);
    $this->assertSame('Entity Test Bundle', $row['usage']);
  }

  /**
   * Tests report URLs.
   */
  public function testToUrl(): void {
    $manager = $this->container->get(ReportManager::class);
    $report = $manager->createInstance('reports_test_table', ['count' => 3]);
    $url = $report->toUrl('csv', ['query' => ['sheet' => 'default']]);
    $this->assertSame('reports.report.csv', $url->getRouteName());
    $this->assertSame(['report' => 'reports_test_table'], $url->getRouteParameters());
    // The configuration is kept in the query string.
    $this->assertSame(['sheet' => 'default', 'config' => ['count' => 3]], $url->getOption('query'));
    // Reports without options have no configuration in the URL.
    $this->assertNull($manager->createInstance('reports_test_status')->toUrl()->getOption('query'));

    $this->expectException(\InvalidArgumentException::class);
    $report->toUrl('missing');
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
