<?php

namespace Drupal\layout_builder_fields\Plugin\Block;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block that renders a field from an entity.
 *
 * @Block(
 *   id = "field_delta",
 *   deriver = "\Drupal\layout_builder_fields\Plugin\Derivative\FieldDelta",
 * )
 */
class FieldDelta extends BlockBase implements ContextAwarePluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The formatter manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $formatterManager;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The bundle ID.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The field name.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * The field definition.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface
   */
  protected $fieldDefinition;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new FieldBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FormatterPluginManager $formatter_manager
   *   The formatter manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, FormatterPluginManager $formatter_manager, ModuleHandlerInterface $module_handler, LoggerInterface $logger) {
    $this->entityFieldManager = $entity_field_manager;
    $this->formatterManager = $formatter_manager;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger;

    // Get the entity type and field name from the plugin ID.
    list (, $entity_type_id, $bundle, $field_name) = explode(static::DERIVATIVE_SEPARATOR, $plugin_id, 4);
    $this->entityTypeId = $entity_type_id;
    $this->bundle = $bundle;
    $this->fieldName = $field_name;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('module_handler'),
      $container->get('logger.channel.layout_builder')
    );
  }

  /**
   * Gets the entity that has the field.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The entity.
   */
  protected function getEntity() {
    return $this->getContextValue('entity');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $entity = $this->getEntity();
    try {
      $build = $entity->get($this->fieldName)->view();
    }
    catch (\Exception $e) {
      $build = [];
      $this->logger->warning('The field "%field" failed to render with the error of "%error".', ['%field' => $this->fieldName, '%error' => $e->getMessage()]);
    }
    if (!empty($entity->in_preview) && !Element::getVisibleChildren($build)) {
      $build['content']['#markup'] = new TranslatableMarkup('Placeholder for the "@field" field', ['@field' => $this->getFieldDefinition()->getLabel()]);
    }
    CacheableMetadata::createFromObject($this)->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $entity = $this->getEntity();

    // First consult the entity.
    $access = $entity->access('view', $account, TRUE);
    if (!$access->isAllowed()) {
      return $access;
    }

    // Check that the entity in question has this field.
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($this->fieldName)) {
      return $access->andIf(AccessResult::forbidden());
    }

    // Check field access.
    $field = $entity->get($this->fieldName);
    $access = $access->andIf($field->access('view', $account, TRUE));
    if (!$access->isAllowed()) {
      return $access;
    }

    // Check to see if the field has any values.
    if ($field->isEmpty()) {
      return $access->andIf(AccessResult::forbidden());
    }
    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'label_display' => FALSE,
      'formatter' => [
        'label' => 'above',
        'type' => $this->pluginDefinition['default_formatter'],
        'settings' => [],
        'third_party_settings' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();
    if ($build_info = $form_state->getBuildInfo()) {
      /** @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage $override_storage */
      $override_storage = $build_info['args'][0];
      $entity = $override_storage->getEntity();
      // Add the field form.
      $form_display = EntityFormDisplay::collectRenderDisplay($entity, 'edit');
      $fo->buildForm($entity, $form, $form_state);

      // Add a submit button. Give it a class for easy JavaScript targeting.
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => t('Save'),
        //'#attributes' => ['class' => ['quickedit-form-submit']],
      ];
    }





    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['formatter'] = $form_state->getValue('formatter');
  }



}
