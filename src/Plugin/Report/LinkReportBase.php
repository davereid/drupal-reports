<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\UriItem;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;
use Drupal\link\Plugin\Field\FieldType\LinkItem;

/**
 * Base class for reports that check URLs and links in content.
 *
 * Also checks link and URI fields, including field types that extend them,
 * and custom field link and URI subfields, which FormattedTextReportBase skips.
 * Subclasses list what they find in URLs with getUrlRows(), and in formatted
 * text with getHtmlRows(), e.g. with getHtmlLinks().
 *
 * @internal
 *   Experimental until more reports use it: it may still change.
 */
abstract class LinkReportBase extends FormattedTextReportBase {

  /**
   * Lists what the report finds in a URL value.
   *
   * @param string $url
   *   The URL, from a link field or a link or URI subfield.
   * @param string $title
   *   The link text, if the field has one.
   *
   * @return iterable<array<string, mixed>>
   *   Rows for the getValueHeader() columns. None by default.
   */
  protected function getUrlRows(string $url, string $title): iterable {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Also link fields (LinkItem): 'uri', with its link text in 'title'; and URI
   * fields (UriItem): 'value'. File URIs (FileUriItem), such as
   * public://file.pdf, aren't URLs to check. Modules can change these columns
   * with hook_reports_link_columns_alter().
   */
  protected function getDefaultFieldTypeColumns(string $type): array {
    $class = $this->fieldTypeManager->getDefinition($type, FALSE)['class'] ?? NULL;
    $is_uri = $class && is_a($class, UriItem::class, TRUE) && !is_a($class, FileUriItem::class, TRUE);
    $columns = match (TRUE) {
      $class === NULL => [],
      is_a($class, LinkItem::class, TRUE) => ['uri' => ['text' => FALSE, 'title' => 'title']],
      $is_uri => ['value' => ['text' => FALSE, 'title' => NULL]],
      default => [],
    };
    $this->moduleHandler->alter('reports_link_columns', $columns, $type);
    return $columns + parent::getDefaultFieldTypeColumns($type);
  }

  /**
   * {@inheritdoc}
   *
   * Also link and URI subfields. Modules can change these subfields with
   * hook_reports_link_custom_columns_alter().
   */
  protected function getCustomFieldColumns(FieldStorageDefinitionInterface $storage_definition): array {
    $columns = [];
    foreach ($storage_definition->getSetting('columns') ?? [] as $name => $column) {
      $columns[$name] = match ($column['type']) {
        'link' => ['text' => FALSE, 'title' => $name . '__title', 'subfield_type' => 'link'],
        'uri' => ['text' => FALSE, 'title' => NULL, 'subfield_type' => 'uri'],
        default => NULL,
      };
    }
    $columns = array_filter($columns);
    $this->moduleHandler->alter('reports_link_custom_columns', $columns, $storage_definition);
    // The URL subfields, with the parent's formatted text subfields. Built
    // separately, so the URL alter hook only gets URL subfields.
    return $columns + parent::getCustomFieldColumns($storage_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Rows from getUrlRows() for URL columns.
   */
  protected function getColumnRows(FieldableEntityInterface $entity, string $field_name, FieldItemInterface $item, string $column, array $info, string $value, bool $filtered): iterable {
    if ($info['text']) {
      yield from parent::getColumnRows($entity, $field_name, $item, $column, $info, $value, $filtered);
      return;
    }
    yield from $this->getUrlRows($value, $info['title'] ? (string) $item->{$info['title']} : '');
  }

  /**
   * Gets the links in HTML.
   *
   * @return iterable<array{0: string, 1: string}>
   *   The text and href of each <a> element.
   *
   * @todo Use a Helper method to get the links in HTML.
   */
  protected function getHtmlLinks(string $html): iterable {
    if (!str_contains($html, '<a')) {
      return;
    }
    foreach (Html::load($html)->getElementsByTagName('a') as $link) {
      if ($link->hasAttribute('href')) {
        yield [$link->textContent, $link->getAttribute('href')];
      }
    }
  }

}
