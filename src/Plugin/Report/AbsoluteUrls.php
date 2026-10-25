<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists links that use absolute URLs to the site's own domains.
 *
 * These links break when the site's domain or the linked page's path
 * changes.
 */
#[Report(
  id: 'absolute_urls',
  label: new TranslatableMarkup('Absolute URLs'),
  description: new TranslatableMarkup('Links in content that use absolute URLs to this site, which break when the domain or page path changes.'),
)]
class AbsoluteUrls extends TextFieldReportBase implements PluginFormInterface {

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
    return ['domains' => [$this->currentHost ?? 'localhost']] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Domains can be given as a list, or a string separated by commas or new
   * lines, as in the query string and Drush options.
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $domains = $this->configuration['domains'];
    $domains = is_array($domains) ? $domains : preg_split('/[\s,]+/', (string) $domains);
    $domains = array_unique(array_filter(array_map(fn ($domain) => strtolower(trim((string) $domain)), $domains)));
    // Sorted, so the same domains are stored as the same report.
    sort($domains);
    $this->configuration['domains'] = $domains;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Domains'),
      '#description' => $this->t('The domains of this site, one per line. Links to them are listed.'),
      '#default_value' => implode("\n", $this->configuration['domains']),
      '#required' => TRUE,
      '#rows' => 3,
    ];
    return $this->buildTextFieldConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): array {
    $build = parent::getSummary();
    $build['description'] = [
      '#markup' => $this->t('Links that contain absolute URLs with the selected domains, in the following fields:'),
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
    if ($filtered || !$this->configuration['domains']) {
      parent::alterFieldQuery($query, $field_name, $field, $filtered);
      return;
    }
    $or = $query->orConditionGroup();
    foreach (array_keys($field['columns']) as $column) {
      foreach ($this->configuration['domains'] as $domain) {
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
   */
  protected function isAbsoluteUrl(string $url): bool {
    $parts = parse_url(trim($url));
    return isset($parts['host'])
      && in_array($parts['scheme'] ?? 'http', ['http', 'https'], TRUE)
      && in_array(strtolower($parts['host']), $this->configuration['domains'], TRUE);
  }

}
