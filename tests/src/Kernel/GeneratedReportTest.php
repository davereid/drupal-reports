<?php

declare(strict_types=1);

namespace Drupal\Tests\reports\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\helper\Utility;
use Drupal\KernelTests\KernelTestBase;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\ReportGenerator;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests generating reports with the Batch API.
 */
#[Group('reports')]
#[RunTestsInSeparateProcesses]
class GeneratedReportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'filter',
    'link',
    'text',
    'image',
    'custom_field',
    'entity_test',
    'helper',
    'reports',
  ];

  /**
   * The report storage.
   */
  protected ReportStorage $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // The private stream wrapper is only registered when this is set before
    // the kernel boots.
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The Absolute URLs report checks every entity type with link or text
    // fields, including entity_test's other types with text base fields.
    foreach ($this->container->get('entity_field.manager')->getFieldMap() as $entity_type_id => $fields) {
      $types = array_column($fields, 'type');
      if (str_starts_with($entity_type_id, 'entity_test') && array_intersect($types, ['link', 'text', 'text_long', 'text_with_summary', 'custom'])) {
        $this->installEntitySchema($entity_type_id);
      }
    }
    $this->installEntitySchema('entity_test');
    $this->installConfig(['filter', 'reports']);
    $this->storage = $this->container->get(ReportStorage::class);
  }

  /**
   * Tests the Absolute URLs report.
   */
  public function testAbsoluteUrls(): void {
    FilterFormat::create(['format' => 'html', 'name' => 'HTML'])->save();
    foreach (['field_link' => 'link', 'field_text' => 'text_long'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'entity_test', 'type' => $type, 'cardinality' => -1])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'entity_test', 'bundle' => 'entity_test', 'label' => $name])->save();
    }
    EntityTest::create([
      'name' => 'First',
      'field_link' => [
        ['uri' => 'https://example.com/about', 'title' => 'About'],
        ['uri' => 'https://other.example.org/', 'title' => 'Other'],
        ['uri' => 'internal:/contact', 'title' => 'Contact'],
      ],
      'field_text' => [
        'value' => '<p><a href="http://EXAMPLE.com/news">News</a> <a href="/relative">Relative</a></p>',
        'format' => 'html',
      ],
    ])->save();
    EntityTest::create(['name' => 'Second', 'field_text' => ['value' => 'No links', 'format' => 'html']])->save();

    foreach (['filtered', 'raw'] as $source) {
      $report = $this->generate('absolute_urls', ['domains' => 'example.com', 'text_source' => $source]);
      $rows = iterator_to_array($this->storage->getRows($report), FALSE);
      $this->assertSame([
        ['First', 'field_link', 'About', 'https://example.com/about'],
        ['First', 'field_text', 'News', 'http://EXAMPLE.com/news'],
      ], array_map(fn ($row) => [$row['label'], $row['field'], $row['link_text'], $row['link_url']], $rows), $source);
      $this->assertSame('complete', $this->storage->getStatus($report)['status']);
    }

    // Saving content of a checked bundle changes the report's data.
    $this->assertContains('entity_test_list:entity_test', $report->getCacheTags());
    $this->assertFalse($this->storage->hasChanged($report));
    EntityTest::create(['name' => 'Third'])->save();
    $this->assertTrue($this->storage->hasChanged($report));

    // A cache rebuild purges cache tags, which lowers their checksum: that
    // isn't a change, and later changes are detected again.
    $this->container->get('cache_tags.invalidator')->purge();
    $this->assertFalse($this->storage->hasChanged($report));
    EntityTest::create(['name' => 'Fourth'])->save();
    $this->assertTrue($this->storage->hasChanged($report));

    // Reports are stored per configuration.
    $other = $this->container->get(ReportManager::class)->createInstance('absolute_urls', ['domains' => 'other.example.org']);
    $this->assertNull($this->storage->getFile($other));
    $this->assertNull($this->storage->getStatus($other));

    // Reports are stale after the configured time.
    $this->assertFalse($this->storage->isStale($report));
    $this->config('reports.settings')->set('regenerate_after', 1)->save();
    $status = $this->storage->getStatus($report);
    $status['completed'] -= 10;
    $this->container->get('keyvalue')->get('reports')->set($this->storage->getKey($report), $status);
    $this->assertTrue($this->storage->isStale($report));
    $this->config('reports.settings')->set('regenerate_after', 0)->save();
    $this->assertFalse($this->storage->isStale($report));
  }

  /**
   * Tests custom field Text (long) subfields in the Absolute URLs report.
   *
   * They're only checked on bundles where they're formatted, with the
   * subfield's default format.
   */
  public function testAbsoluteUrlsCustomFieldText(): void {
    FilterFormat::create([
      'format' => 'html',
      'name' => 'HTML',
      // Turns URLs into links, so only the filtered text has a link.
      'filters' => ['filter_url' => ['status' => TRUE, 'settings' => ['filter_url_length' => 72]]],
    ])->save();
    EntityTestHelper::createBundle('plain', 'Plain');
    FieldStorageConfig::create([
      'field_name' => 'field_custom',
      'entity_type' => 'entity_test',
      'type' => 'custom',
      'settings' => ['columns' => ['body' => ['name' => 'body', 'type' => 'string_long']]],
    ])->save();
    foreach (['entity_test' => TRUE, 'plain' => FALSE] as $bundle => $formatted) {
      FieldConfig::create([
        'field_name' => 'field_custom',
        'entity_type' => 'entity_test',
        'bundle' => $bundle,
        'settings' => ['field_settings' => ['body' => ['formatted' => $formatted, 'default_format' => 'html']]],
      ])->save();
      EntityTest::create([
        'type' => $bundle,
        'name' => $bundle,
        'field_custom' => ['body' => 'See https://example.com/page and <a href="https://example.com/link">Link</a>'],
      ])->save();
    }

    $expected = [
      'filtered' => [['entity_test', 'https://example.com/page'], ['entity_test', 'https://example.com/link']],
      'raw' => [['entity_test', 'https://example.com/link']],
    ];
    foreach ($expected as $source => $links) {
      $report = $this->generate('absolute_urls', ['domains' => 'example.com', 'text_source' => $source]);
      $rows = iterator_to_array($this->storage->getRows($report), FALSE);
      $this->assertSame($links, array_map(fn ($row) => [$row['label'], $row['link_url']], $rows), $source);
    }
  }

  /**
   * Tests including trashed entities in the Absolute URLs report.
   */
  public function testAbsoluteUrlsTrashed(): void {
    $this->enableModules(['trash']);
    $this->installConfig(['trash']);
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->config('trash.settings')->set('enabled_entity_types', ['entity_test_mulrevpub' => []])->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->storage = $this->container->get(ReportStorage::class);

    FieldStorageConfig::create(['field_name' => 'field_link', 'entity_type' => 'entity_test_mulrevpub', 'type' => 'link'])->save();
    FieldConfig::create(['field_name' => 'field_link', 'entity_type' => 'entity_test_mulrevpub', 'bundle' => 'entity_test_mulrevpub'])->save();
    foreach (['Kept', 'Trashed'] as $name) {
      $entity = EntityTestMulRevPub::create(['name' => $name, 'status' => 1, 'field_link' => ['uri' => "https://example.com/$name"]]);
      $entity->save();
    }
    // With Trash enabled for the entity type, deleting moves it to the trash.
    $entity->delete();

    foreach (['' => ['Kept'], 'trashed' => ['Kept', 'Trashed']] as $include => $expected) {
      $report = $this->generate('absolute_urls', ['domains' => 'example.com', 'include' => $include]);
      $rows = iterator_to_array($this->storage->getRows($report), FALSE);
      $this->assertSame($expected, array_column($rows, 'label'));
    }
    // The State column comes from EntityHelper::getState().
    $this->assertSame(['Published', 'Deleted'], array_column($rows, 'state'));
    // Trashed entities stay out of normal queries afterwards.
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub')->getQuery()->accessCheck(FALSE)->execute());
  }

  /**
   * Tests deleting old and unknown generated report files.
   */
  public function testDeleteExpired(): void {
    $report = $this->generate('absolute_urls', ['domains' => 'example.com']);
    $file = $this->storage->getFile($report);
    $key = $this->storage->getKey($report);
    // A file nothing generated, and a temporary directory from a batch that
    // was abandoned.
    file_put_contents(ReportStorage::DIRECTORY . '/unknown.csv', "x\n");
    mkdir(ReportStorage::DIRECTORY . '/abandoned.tmp');

    $this->assertSame(2, $this->storage->deleteExpired());
    $this->assertFileExists($file);
    $this->assertFileDoesNotExist(ReportStorage::DIRECTORY . '/unknown.csv');
    $this->assertDirectoryDoesNotExist(ReportStorage::DIRECTORY . '/abandoned.tmp');

    // Reports completed longer ago than delete_after are deleted with their
    // status.
    $keyvalue = $this->container->get('keyvalue')->get('reports');
    $status = $keyvalue->get($key);
    $status['completed'] -= 31 * 86400;
    $keyvalue->set($key, $status);
    $this->assertSame(1, $this->storage->deleteExpired());
    $this->assertFileDoesNotExist($file);
    $this->assertNull($this->storage->getStatus($report));

    // A status whose files are missing, as on a copy of the database without
    // the private files, is deleted when it's read.
    $report = $this->generate('absolute_urls', ['domains' => 'example.com']);
    unlink($this->storage->getFile($report));
    $this->assertNull($this->storage->getStatus($report));
    $this->assertNull($keyvalue->get($key));

    // A temporary directory of a report that's generating is kept for a day.
    $this->storage->start($report);
    $this->assertSame(0, $this->storage->deleteExpired());
    $this->assertDirectoryExists($this->storage->getTemporaryDirectory($report));
  }

  /**
   * Tests the site audit report, which has a sheet per bundle.
   */
  public function testSiteAudit(): void {
    EntityTestHelper::createBundle('second', 'Second bundle');
    FieldStorageConfig::create(['field_name' => 'field_text', 'entity_type' => 'entity_test', 'type' => 'text_long'])->save();
    FieldConfig::create(['field_name' => 'field_text', 'entity_type' => 'entity_test', 'bundle' => 'second', 'label' => 'Text', 'required' => TRUE])->save();
    \Drupal::service('entity_display.repository')->getFormDisplay('entity_test', 'second')->setComponent('field_text', ['type' => 'text_textarea'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'entity_test',
      'type' => 'custom',
      'settings' => ['columns' => ['title' => ['name' => 'title', 'type' => 'string', 'length' => 255]]],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'entity_test',
      'bundle' => 'second',
      'label' => 'Items',
      'settings' => ['field_settings' => ['title' => ['label' => 'Title', 'required' => TRUE, 'description' => 'The item title.']]],
    ])->save();

    $report = $this->generate('site_audit', ['entity_types' => 'entity_test']);
    $this->assertSame(['entity_test--bundles', 'entity_test__entity_test', 'entity_test__second', 'text_formats'], array_keys($report->getSheets()));

    // Download-only reports keep only their spreadsheet, and the row counts.
    $this->assertNull($this->storage->getFile($report, 'entity_test__second'));
    $this->assertTrue($this->storage->hasFiles($report));
    $data = $this->readSpreadsheet($report);
    $this->assertSame(['Test entity types', 'Test entity Entity Test Bundle', 'Test entity Second bundle', 'Text formats'], array_keys($data));
    $data = array_combine(array_keys($report->getSheets()), $data);
    $this->assertSame(array_map('count', $data), $this->storage->getStatus($report)['rows']);

    $bundles = array_column($data['entity_test--bundles'], 'label', 'name');
    $this->assertSame(['entity_test' => 'Entity Test Bundle', 'second' => 'Second bundle'], $bundles);
    $rows = array_column($data['entity_test__second'], NULL, 'name');
    $this->assertSame('Text', $rows['field_text']['label']);
    $this->assertSame('Yes', $rows['field_text']['required']);
    $this->assertSame((string) \Drupal::service('plugin.manager.field.widget')->getDefinition('text_textarea')['label'], $rows['field_text']['widget']);
    $this->assertSame((string) \Drupal::service('plugin.manager.field.field_type')->getDefinition('text_long')['label'], $rows['field_text']['type']);
    $this->assertSame('Hidden', $rows['field_text']['formatter']);

    // Custom field subfields have their own rows.
    $this->assertArrayHasKey('field_items', $rows);
    $subfield = $rows['field_items.title'];
    $this->assertSame('Items: Title', $subfield['label']);
    $this->assertSame('Yes', $subfield['required']);
    $this->assertSame('The item title.', $subfield['description']);
    $this->assertSame('255 (storage)', $subfield['character_limit']);
    $this->assertSame('Hidden', $subfield['widget']);

    // Filter's install config has the plain_text fallback format, which every
    // role can use.
    $formats = array_column($data['text_formats'], NULL, 'format');
    $this->assertSame('Plain text', $formats['plain_text']['name']);
    $this->assertSame('Yes', $formats['plain_text']['status']);
    $this->assertStringContainsString('(filter_html_escape)', $formats['plain_text']['filters']);
  }

  /**
   * Reads a report's spreadsheet.
   *
   * @return array<string, list<array<string, string>>>
   *   The rows of each tab, keyed by tab name, with the report's column keys.
   */
  protected function readSpreadsheet(GeneratedReportInterface $report): array {
    $headers = array_values(array_map(fn ($sheet) => array_keys($sheet['header']), $report->getSheets()));
    $reader = new Reader();
    $reader->open(\Drupal::service('file_system')->realpath($this->storage->getSpreadsheetFile($report)));
    $data = [];
    foreach ($reader->getSheetIterator() as $index => $sheet) {
      $keys = $headers[$index - 1];
      $data[$sheet->getName()] = [];
      $labels = TRUE;
      foreach ($sheet->getRowIterator() as $row) {
        // Skip the column labels.
        if ($labels) {
          $labels = FALSE;
          continue;
        }
        $cells = array_map('strval', $row->toArray());
        $data[$sheet->getName()][] = array_combine($keys, array_pad($cells, count($keys), ''));
      }
    }
    $reader->close();
    return $data;
  }

  /**
   * Generates a report.
   */
  protected function generate(string $id, array $configuration): GeneratedReportInterface {
    $report = $this->container->get(ReportManager::class)->createInstance($id, $configuration);
    assert($report instanceof GeneratedReportInterface);
    Utility::runBatch($this->container->get(ReportGenerator::class)->buildBatch($report));
    return $report;
  }

}
