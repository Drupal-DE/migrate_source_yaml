<?php

namespace Drupal\migrate_source_yaml\Plugin\migrate_plus\data_parser;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate_plus\DataParserPluginBase;
use Symfony\Component\Yaml\Yaml as YamlComponent;

/**
 * Obtain Yaml data for migration.
 *
 * @DataParser(
 *   id = "yaml",
 *   title = @Translation("Yaml")
 * )
 */
class Yaml extends DataParserPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Iterator over the Yaml data.
   *
   * @var \Iterator
   */
  protected $iterator;

  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    // ID field(s) are required.
    if (empty($configuration['ids'])) {
      throw new MigrateException('You must declare "ids" as a unique array of fields in your source settings.');
    }
    if (!isset($configuration['item_selector'])) {
      $configuration['item_selector'] = '';
    }

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Retrieves the Yaml data and returns it as an array.
   *
   * @param string $url
   *   URL of a Yaml feed.
   *
   * @return array
   *   The selected data to be iterated.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   */
  protected function getSourceData($url) {
    $response = $this->getDataFetcherPlugin()->getResponseContent($url);
    // Convert objects to associative arrays.
    $source_data = YamlComponent::parse($response, TRUE);
    // Backwards-compatibility for depth selection.
    if (is_int($this->itemSelector)) {
      return $this->selectByDepth($source_data);
    }

    // Otherwise, we're using xpath-like selectors.
    $selectors = array_filter(explode('/', trim($this->itemSelector, '/')));
    foreach ($selectors as $selector) {
      $source_data = $source_data[$selector];
    }
    return $source_data;
  }

  /**
   * Get the source data for reading.
   *
   * @param array $raw_data
   *   Raw data from the Yaml feed.
   *
   * @return array
   *   Selected items at the requested depth of the Yaml feed.
   */
  protected function selectByDepth(array $raw_data) {
    // Return the results in a recursive iterator that can traverse
    // multidimensional arrays.
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveArrayIterator($raw_data), \RecursiveIteratorIterator::SELF_FIRST);
    $items = [];
    // Backwards-compatibility - an integer item_selector is interpreted as a
    // depth. When there is an array of items at the expected depth, pull that
    // array out as a distinct item.
    $identifierDepth = $this->itemSelector;
    $iterator->rewind();
    while ($iterator->valid()) {
      $item = $iterator->current();
      if (is_array($item) && $iterator->getDepth() == $identifierDepth) {
        $items[] = $item;
      }
      $iterator->next();
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  protected function openSourceUrl($url) {
    // (Re)open the provided URL.
    $source_data = $this->getSourceData($url);
    $this->iterator = new \ArrayIterator($source_data);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchNextRow() {
    $current = $this->iterator->current();
    if ($current) {
      foreach ($this->fieldSelectors() as $field_name => $selector) {
        $field_data = $current;
        $field_selectors = explode('/', trim($selector, '/'));
        foreach ($field_selectors as $field_selector) {
          $field_data = $field_data[$field_selector];
        }
        $this->currentItem[$field_name] = $field_data;
      }
      if (!empty($this->configuration['include_raw_data'])) {
        $this->currentItem['raw'] = $current;
      }
      $this->iterator->next();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldSelectors() {
    $fields = [];
    foreach ($this->configuration['fields'] as $key => $field_info) {
      if (!is_array($field_info)) {
        $fields[$key] = $key;
      }
      else if (isset($field_info['selector'])) {
        $fields[$field_info['name']] = $field_info['selector'];
      }
    }
    return $fields;
  }

}
