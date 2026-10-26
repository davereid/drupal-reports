<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists links that use absolute URLs, to the configured domains if any.
 *
 * Absolute URLs to the site's own domains break when the site's domain or
 * the linked page's path changes. Without domains, every absolute URL is
 * listed.
 */
#[Report(
  id: 'absolute_urls',
  label: new TranslatableMarkup('Absolute URLs'),
  description: new TranslatableMarkup('Links in content that use absolute URLs, e.g. to this site, which break when the domain or page path changes.'),
)]
class AbsoluteUrls extends LinkReportBase {

  /**
   * The current site's host name.
   */
  protected string $currentHost;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, [], $plugin_id, $plugin_definition);
    $instance->currentHost = $container->get('request_stack')->getMainRequest()?->getHost() ?? 'localhost';
    // The default domain depends on the request, so set it after injection.
    $instance->setConfiguration($configuration);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    $defaults = parent::defaultConfiguration();
    // The current host, and any domains from the definition.
    $defaults['domains'] = static::normalizeList(($this->currentHost ?? 'localhost') . ',' . static::normalizeList($defaults['domains'] ?? ''));
    return $defaults;
  }

  /**
   * {@inheritdoc}
   *
   * Domains are lowercased. None means any domain.
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $this->configuration['domains'] = static::normalizeList(strtolower(static::normalizeList($this->configuration['domains'])));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Domains'),
      '#description' => $this->t('Links to these domains are listed, e.g. the domains of this site. One per line. Leave empty to list absolute URLs to any domain.'),
      '#default_value' => implode("\n", $this->getListOption('domains')),
      '#rows' => 3,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): array {
    $build = parent::getSummary();
    $build['description'] = [
      '#markup' => $this->t('Links that use absolute URLs (to the selected domains, if any), in the following fields:'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#weight' => -10,
    ];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getValueHeader(): array {
    return [
      'link_text' => $this->t('Link text'),
      'link_url' => $this->t('Link URL'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Without filters, only entities whose stored values contain one of the
   * domains are checked.
   */
  protected function alterFieldQuery(QueryInterface $query, string $field_name, array $field, bool $filtered): void {
    // Filters can add links that aren't in the stored text, so filtered text
    // fields check every entity with a value.
    $domains = $this->getListOption('domains');
    if ($filtered || !$domains) {
      parent::alterFieldQuery($query, $field_name, $field, $filtered);
      return;
    }
    $or = $query->orConditionGroup();
    foreach (array_keys($field['columns']) as $column) {
      foreach ($domains as $domain) {
        $or->condition("$field_name.$column", "//$domain", 'CONTAINS');
      }
    }
    $query->condition($or);
  }

  /**
   * {@inheritdoc}
   */
  protected function getUrlRows(string $url, string $title): iterable {
    if ($this->isAbsoluteUrl($url)) {
      yield ['link_text' => trim($title), 'link_url' => $url];
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getHtmlRows(string $html): iterable {
    foreach ($this->getHtmlLinks($html) as [$text, $href]) {
      if ($this->isAbsoluteUrl($href)) {
        yield ['link_text' => trim($text), 'link_url' => $href];
      }
    }
  }

  /**
   * Checks whether a URL is an absolute URL to one of the configured domains.
   *
   * Without domains, any absolute http or https URL matches.
   */
  protected function isAbsoluteUrl(string $url): bool {
    $parts = parse_url(trim($url));
    if (!isset($parts['host']) || !in_array($parts['scheme'] ?? 'http', ['http', 'https'], TRUE)) {
      return FALSE;
    }
    $domains = $this->getListOption('domains');
    return !$domains || in_array(strtolower($parts['host']), $domains, TRUE);
  }

}
