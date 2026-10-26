<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExceptionInterface;

/**
 * Lists HTML elements in formatted text that match a tag, CSS or XPath.
 */
#[Report(
  id: 'html_elements',
  label: new TranslatableMarkup('HTML elements'),
  description: new TranslatableMarkup('Elements in formatted text that match an HTML tag, CSS selector or XPath expression.'),
)]
class HtmlElements extends FormattedTextReportBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + ['selector_type' => 'tag', 'selector' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    if (!in_array($this->configuration['selector_type'], ['tag', 'css', 'xpath'], TRUE)) {
      $this->configuration['selector_type'] = 'tag';
    }
    $this->configuration['selector'] = trim((string) $this->configuration['selector']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['selector_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Find'),
      '#options' => [
        'tag' => $this->t('HTML tag, e.g. <code>blockquote</code>'),
        'css' => $this->t('CSS selector, e.g. <code>a[target="_blank"]</code>'),
        'xpath' => $this->t('XPath expression, e.g. <code>//h2[not(normalize-space())]</code>'),
      ],
      '#default_value' => $this->configuration['selector_type'],
    ];
    $form['selector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Selector'),
      '#default_value' => $this->configuration['selector'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $report = clone $this;
    $report->setConfiguration($form_state->getValues());
    if ($report->getXpath() === NULL) {
      $form_state->setErrorByName('selector', $this->t('The selector is not valid.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Also an error when the selector is invalid. A missing selector isn't an
   * error, so the page shows the options form before a report is run.
   */
  public function getRequirements(): array {
    $requirements = parent::getRequirements();
    if ($this->configuration['selector'] !== '' && $this->getXpath() === NULL) {
      $requirements['selector'] = [
        'title' => $this->t('Selector'),
        'description' => $this->t('Enter a valid HTML tag, CSS selector or XPath expression.'),
        'severity' => RequirementSeverity::Error,
      ];
    }
    return $requirements;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): array {
    $build = parent::getSummary();
    $build['description'] = [
      '#markup' => $this->t('Elements that match an HTML tag, CSS selector or XPath expression, in the following fields:'),
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
      'element_text' => $this->t('Text'),
      'element_html' => $this->t('HTML'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getHtmlRows(string $html): iterable {
    $xpath = $this->getXpath();
    if ($xpath === NULL) {
      return;
    }
    $document = Html::load($html);
    foreach ((new \DOMXPath($document))->query($xpath) ?: [] as $node) {
      yield [
        'element_text' => trim($node->textContent),
        'element_html' => $document->saveHTML($node),
      ];
    }
  }

  /**
   * Gets the XPath expression for the configured selector.
   *
   * @return string|null
   *   The expression, or NULL if the selector is empty or invalid.
   */
  protected function getXpath(): ?string {
    $selector = $this->configuration['selector'];
    $xpath = match (TRUE) {
      $selector === '' => NULL,
      $this->configuration['selector_type'] === 'tag' => preg_match('/^[a-z][a-z0-9-]*$/i', $selector) ? '//' . strtolower($selector) : NULL,
      $this->configuration['selector_type'] === 'css' => $this->convertCss($selector),
      default => $selector,
    };
    // DOMXPath::query() warns and returns FALSE for invalid expressions.
    return $xpath !== NULL && @(new \DOMXPath(new \DOMDocument()))->query($xpath) !== FALSE ? $xpath : NULL;
  }

  /**
   * Converts a CSS selector to XPath.
   *
   * @return string|null
   *   The expression, or NULL if the selector is invalid.
   */
  protected function convertCss(string $selector): ?string {
    try {
      return (new CssSelectorConverter())->toXPath($selector);
    }
    catch (ExceptionInterface) {
      return NULL;
    }
  }

}
