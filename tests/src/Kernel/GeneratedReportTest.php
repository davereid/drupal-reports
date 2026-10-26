<?php

declare(strict_types=1);

namespace Drupal\Tests\reports\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\helper\Utility;
use Drupal\KernelTests\KernelTestBase;
use Drupal\reports\Exception\ReportGeneratingException;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\ReportGenerator;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests generating reports with the Batch API.
 */
#[Group('reports')]
#[RunTestsInSeparateProcesses]
class GeneratedReportTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;

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
      $scanned = ['link', 'text', 'text_long', 'text_with_summary', 'uri', 'custom'];
      if (str_starts_with($entity_type_id, 'entity_test') && array_intersect($types, $scanned)) {
        $this->installEntitySchema($entity_type_id);
      }
    }
    $this->installEntitySchema('entity_test');
    $this->installConfig(['system', 'filter', 'reports']);
    $this->storage = $this->container->get(ReportStorage::class);
  }

  /**
   * Tests the Absolute URLs report.
   */
  public function testAbsoluteUrls(): void {
    FilterFormat::create(['format' => 'html', 'name' => 'HTML'])->save();
    foreach (['field_link' => 'link', 'field_text' => 'text_long', 'field_uri' => 'uri'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'type' => $type,
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'label' => $name,
      ])->save();
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
      'field_uri' => ['https://example.com/uri', 'https://other.example.org/uri'],
    ])->save();
    EntityTest::create(['name' => 'Second', 'field_text' => ['value' => 'No links', 'format' => 'html']])->save();

    foreach (['filtered', 'raw'] as $source) {
      $report = $this->generate('absolute_urls', ['domains' => 'example.com', 'text_source' => $source]);
      $rows = iterator_to_array($this->storage->getRows($report), FALSE);
      $this->assertSame([
        ['First', 'field_link', 'About', 'https://example.com/about'],
        ['First', 'field_text', 'News', 'http://EXAMPLE.com/news'],
        ['First', 'field_uri', '', 'https://example.com/uri'],
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
   * Tests the HTML elements report.
   */
  public function testHtmlElements(): void {
    FilterFormat::create(['format' => 'html', 'name' => 'HTML'])->save();
    foreach (['field_link' => 'link', 'field_text' => 'text_long'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'entity_test', 'type' => $type])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'label' => $name,
      ])->save();
    }
    EntityTest::create([
      'name' => 'First',
      'field_link' => ['uri' => 'https://example.com/blockquote', 'title' => 'blockquote'],
      'field_text' => [
        'value' => '<blockquote>Quote</blockquote><p><a href="/a" target="_blank">New</a> <a href="/b">Same</a></p>',
        'format' => 'html',
      ],
    ])->save();

    $cases = [
      ['tag', 'BLOCKQUOTE', [['Quote', '<blockquote>Quote</blockquote>']]],
      ['css', 'a[target="_blank"]', [['New', '<a href="/a" target="_blank">New</a>']]],
      ['xpath', '//a[@href="/b"]', [['Same', '<a href="/b">Same</a>']]],
    ];
    foreach ($cases as [$type, $selector, $expected]) {
      $report = $this->generate('html_elements', [
        'selector_type' => $type,
        'selector' => $selector,
        'text_source' => 'raw',
      ]);
      $rows = iterator_to_array($this->storage->getRows($report), FALSE);
      $this->assertSame($expected, array_map(fn ($row) => [$row['element_text'], $row['element_html']], $rows), $selector);
      $this->assertSame(['field_text'], array_unique(array_column($rows, 'field')));
    }
    // Link fields aren't checked.
    $report = $this->container->get(ReportManager::class)->createInstance('html_elements', ['selector' => 'a']);
    assert($report instanceof GeneratedReportInterface);
    $fields = array_column(array_column($report->getBatchOperations(), 1), 1);
    $this->assertContains('field_text', $fields);
    $this->assertNotContains('field_link', $fields);

    // Invalid or missing selectors are errors.
    foreach ([['tag', 'a b'], ['css', 'a[['], ['xpath', '//a['], ['tag', '']] as [$type, $selector]) {
      $configuration = ['selector_type' => $type, 'selector' => $selector];
      $report = $this->container->get(ReportManager::class)->createInstance('html_elements', $configuration);
      $this->assertSame(RequirementSeverity::Error, $report->getRequirements()['selector']['severity'] ?? NULL, $selector);
    }
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
   * Tests changing the checked fields and subfields with alter hooks.
   *
   * The reports_test module checks plain text fields and subfields as URLs,
   * and skips URI fields.
   */
  public function testAbsoluteUrlsColumnsAlter(): void {
    $this->enableModules(['reports_test']);
    // Plain text fields are now checked, including entity_test's other types'
    // string_long base fields, such as revision log messages.
    foreach ($this->container->get('entity_field.manager')->getFieldMap() as $entity_type_id => $fields) {
      $base_table = $this->container->get('entity_type.manager')->getDefinition($entity_type_id)->getBaseTable();
      if (str_starts_with($entity_type_id, 'entity_test_') && in_array('string_long', array_column($fields, 'type'), TRUE)
        && !$this->container->get('database')->schema()->tableExists($base_table)) {
        $this->installEntitySchema($entity_type_id);
      }
    }
    $fields = [
      'field_plain' => ['type' => 'string_long'],
      'field_uri' => ['type' => 'uri'],
      'field_custom' => [
        'type' => 'custom',
        'settings' => ['columns' => ['url' => ['name' => 'url', 'type' => 'string']]],
      ],
    ];
    foreach ($fields as $name => $values) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'entity_test'] + $values)->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'entity_test', 'bundle' => 'entity_test'])->save();
    }
    EntityTest::create([
      'name' => 'First',
      'field_plain' => 'https://example.com/plain',
      'field_uri' => 'https://example.com/uri',
      'field_custom' => ['url' => 'https://example.com/custom'],
    ])->save();

    $report = $this->generate('absolute_urls', ['domains' => 'example.com']);
    $rows = iterator_to_array($this->storage->getRows($report), FALSE);
    $this->assertSame([
      ['field_plain', 'https://example.com/plain'],
      ['field_custom', 'https://example.com/custom'],
    ], array_map(fn ($row) => [$row['field'], $row['link_url']], $rows));
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

    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'entity_test_mulrevpub',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'entity_test_mulrevpub',
      'bundle' => 'entity_test_mulrevpub',
    ])->save();
    foreach (['Kept', 'Trashed'] as $name) {
      $entity = EntityTestMulRevPub::create([
        'name' => $name,
        'status' => 1,
        'field_link' => ['uri' => "https://example.com/$name"],
      ]);
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
   * Tests the reports directory setting.
   */
  public function testDirectory(): void {
    $this->assertSame('private://reports', $this->storage->getDirectory());
    $this->assertTrue($this->storage->isAvailable());

    // Another writable directory can be used.
    $this->setSetting('reports_directory', 'temporary://my-reports/');
    $this->assertSame('temporary://my-reports', $this->storage->getDirectory());
    $this->assertTrue($this->storage->isAvailable());
    $this->assertTrue($this->storage->isTemporary());
    $report = $this->generate('absolute_urls', ['domains' => 'example.com']);
    $this->assertStringStartsWith('temporary://my-reports/', $this->storage->getFile($report));

    // The public file system can be used, with a warning.
    $manager = $this->container->get(ReportManager::class);
    $this->setSetting('reports_directory', 'public://reports');
    $this->assertTrue($this->storage->isAvailable());
    $this->assertTrue($this->storage->isPublic());
    $report = $manager->createInstance('content_counts');
    $this->assertSame(RequirementSeverity::Warning, $manager->getRequirements($report)['reports_storage']['severity']);
    $this->assertTrue($manager->isUsable($report));
    // The status report only shows storage, not each report's requirements.
    $this->assertSame(['reports_storage'], array_keys($manager->getGlobalRequirements()));

    // Unknown stream wrappers are refused.
    foreach (['missing://reports'] as $directory) {
      $this->setSetting('reports_directory', $directory);
      $this->assertFalse($this->storage->isAvailable(), $directory);
      $this->assertNotNull($this->storage->getUnavailableReason(), $directory);
      // Generated reports then have an error requirement and can't be used.
      $report = $this->container->get(ReportManager::class)->createInstance('content_counts');
      $this->assertSame(RequirementSeverity::Error, $this->container->get(ReportManager::class)->getRequirements($report)['reports_storage']['severity'], $directory);
      $this->assertFalse($this->container->get(ReportManager::class)->isUsable($report), $directory);
    }
  }

  /**
   * Tests the unused files report.
   */
  public function testUnusedFiles(): void {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $files = [];
    foreach (['used' => TRUE, 'unused' => TRUE, 'temporary' => FALSE] as $name => $permanent) {
      file_put_contents("public://$name.txt", $name);
      $files[$name] = File::create(['uri' => "public://$name.txt", 'filename' => "$name.txt"]);
      $permanent ? $files[$name]->setPermanent() : $files[$name]->setTemporary();
      $files[$name]->save();
    }
    $this->container->get('file.usage')->add($files['used'], 'reports_test', 'node', 1);

    $report = $this->generate('unused_files', []);
    $this->assertSame(['unused.txt'], array_column(iterator_to_array($this->storage->getRows($report), FALSE), 'filename'));
    $report = $this->generate('unused_files', ['temporary' => TRUE]);
    $this->assertSame(['unused.txt', 'temporary.txt'], array_column(iterator_to_array($this->storage->getRows($report), FALSE), 'filename'));
  }

  /**
   * Tests the unused taxonomy terms report.
   */
  public function testUnusedTerms(): void {
    $this->enableModules(['taxonomy']);
    $this->installEntitySchema('taxonomy_term');
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'other', 'name' => 'Other'])->save();
    $this->createEntityReferenceField('entity_test', 'entity_test', 'field_tags', 'Tags', 'taxonomy_term');
    $terms = [];
    foreach (['Used' => 'tags', 'Parent' => 'tags', 'Unused' => 'other'] as $name => $vid) {
      $terms[$name] = Term::create(['vid' => $vid, 'name' => $name]);
      $terms[$name]->save();
    }
    // A child term doesn't make its parent used.
    Term::create(['vid' => 'tags', 'name' => 'Child', 'parent' => $terms['Parent']->id()])->save();
    EntityTest::create(['name' => 'Tagged', 'field_tags' => $terms['Used']])->save();

    $report = $this->generate('unused_terms', []);
    $names = array_column(iterator_to_array($this->storage->getRows($report), FALSE), 'label');
    sort($names);
    $this->assertSame(['Child', 'Parent', 'Unused'], $names);

    $report = $this->generate('unused_terms', ['vocabularies' => 'other']);
    $this->assertSame(['Unused'], array_column(iterator_to_array($this->storage->getRows($report), FALSE), 'label'));
  }

  /**
   * Tests the duplicate values report.
   */
  public function testDuplicateValues(): void {
    foreach (['Same', 'Same', 'Other', 'Same'] as $name) {
      EntityTest::create(['name' => $name])->save();
    }
    $report = $this->generate('duplicate_values', ['field' => 'entity_test.name']);
    $rows = iterator_to_array($this->storage->getRows($report), FALSE);
    $this->assertSame(['Same', 'Same', 'Same'], array_column($rows, 'value'));
    $this->assertSame(['3', '3', '3'], array_column($rows, 'count'));

    // An unknown field is an error.
    $manager = $this->container->get(ReportManager::class);
    $this->assertFalse($manager->isUsable($manager->createInstance('duplicate_values', ['field' => 'entity_test.missing'])));
  }

  /**
   * Tests that a report can't be generated twice at the same time.
   */
  public function testGenerating(): void {
    $report = $this->container->get(ReportManager::class)->createInstance('absolute_urls', ['domains' => 'example.com']);
    $this->storage->start($report);
    $this->assertTrue($this->storage->isGenerating($report));
    try {
      $this->storage->start($report);
      $this->fail('A report being generated was started again.');
    }
    catch (ReportGeneratingException) {
      // Expected.
    }

    // Other options of the same report can be generated meanwhile.
    $other = $this->container->get(ReportManager::class)->createInstance('absolute_urls', ['domains' => 'example.org']);
    $this->storage->start($other);

    // Without batch activity for a while, the generation was abandoned, and
    // the report can be generated again.
    $keyvalue = $this->container->get('keyvalue')->get('reports');
    $status = $keyvalue->get($this->storage->getKey($report));
    $status['heartbeat'] -= ReportStorage::GENERATING_TIMEOUT + 1;
    $keyvalue->set($this->storage->getKey($report), $status);
    $this->assertFalse($this->storage->isGenerating($report));
    $this->storage->start($report);
    $this->assertTrue($this->storage->isGenerating($report));
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
    file_put_contents($this->storage->getDirectory() . '/unknown.csv', "x\n");
    mkdir($this->storage->getDirectory() . '/abandoned.tmp');

    $this->assertSame(2, $this->storage->deleteExpired());
    $this->assertFileExists($file);
    $this->assertFileDoesNotExist($this->storage->getDirectory() . '/unknown.csv');
    $this->assertDirectoryDoesNotExist($this->storage->getDirectory() . '/abandoned.tmp');

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
    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'entity_test',
      'bundle' => 'second',
      'label' => 'Text',
      'required' => TRUE,
    ])->save();
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
      'settings' => [
        'field_settings' => [
          'title' => ['label' => 'Title', 'required' => TRUE, 'description' => 'The item title.'],
        ],
      ],
    ])->save();

    $report = $this->generate('site_audit', ['entity_types' => 'entity_test']);
    $this->assertSame(['entity_test--bundles', 'entity_test__entity_test', 'entity_test__second', 'text_formats'], array_keys($report->getSheets()));

    // Download-only reports keep only their spreadsheet, and the row counts.
    $this->assertNull($this->storage->getFile($report, 'entity_test__second'));
    $this->assertTrue($this->storage->hasFiles($report));
    $data = $this->readSpreadsheet($report);
    $tabs = ['Test entity types', 'Test entity Entity Test Bundle', 'Test entity Second bundle', 'Text formats'];
    $this->assertSame($tabs, array_keys($data));
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
    // OpenSpout can't read from the test's virtual file system.
    $path = tempnam(sys_get_temp_dir(), 'reports');
    copy($this->storage->getSpreadsheetFile($report), $path);
    $reader = new Reader();
    $reader->open($path);
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
    unlink($path);
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
