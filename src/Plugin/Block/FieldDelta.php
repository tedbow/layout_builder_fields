<?php

namespace Drupal\layout_builder_fields\Plugin\Block;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\node\Entity\Node;
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
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The block content entity.
   *
   * @var \Drupal\paragraphs\ParagraphInterface
   */
  protected $referenceEntity;

  /**
   * Whether a new paragraph is being created.
   *
   * @var bool
   */
  protected $isNew = TRUE;

  /**
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, FormatterPluginManager $formatter_manager, ModuleHandlerInterface $module_handler, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager, EntityDisplayRepositoryInterface $entityDisplayRepository) {
    $this->entityFieldManager = $entity_field_manager;
    $this->formatterManager = $formatter_manager;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entityDisplayRepository;

    // Get the entity type and field name from the plugin ID.
    list (, $entity_type_id, $bundle, $field_name) = explode(static::DERIVATIVE_SEPARATOR, $plugin_id, 4);
    $this->entityTypeId = $entity_type_id;
    $this->bundle = $bundle;
    $this->fieldName = $field_name;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
    if (!empty($this->configuration['delta']) || !empty($this->configuration['delta'])) {
      $this->isNew = FALSE;
    }
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
      $container->get('logger.channel.layout_builder'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * Gets the entity that has the field.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The entity.
   */
  protected function getEntity($form_state = NULL) {
    $context_values = $this->getContextValues();
    if (isset($context_values['entity'])) {
      return $context_values['entity'];
    }
    // @todo What is the proper way to get the entity in the form phase??
    if ($form_state) {
      if ($build_info = $form_state->getBuildInfo()) {
        foreach ($build_info['args'] as $arg) {
          if ($arg instanceof OverridesSectionStorageInterface) {
            $storage_id = $arg->getStorageId();
            list($entity_type_id, $entity_id) = explode('.', $storage_id);
            return $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
          }
        }
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $definition = $this->getPluginDefinition();
    $reference_entity = $this->getReferenceEntity($this->getEntity());
    try {
      $build = $this->entityTypeManager->getViewBuilder($definition['target_type'])->view($reference_entity);
    }
    catch (\Exception $e) {
      $build = [];
      $this->logger->warning('The field "%field" failed to render with the error of "%error".', ['%field' => $this->fieldName, '%error' => $e->getMessage()]);
    }
    if (!empty($reference_entity->in_preview) && !Element::getVisibleChildren($build)) {
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
    $definition = $this->getPluginDefinition();
    if ($entity = $this->getEntity($form_state)) {


      // Add the entity form display in a process callback so that #parents can
      // be successfully propagated to field widgets.
      $form['reference_entity_form'] = [
        '#type' => 'container',
        '#process' => [[static::class, 'processBlockForm']],
        '#entity' => $this->getReferenceEntity($entity),
      ];
      $options = $this->entityDisplayRepository->getViewModeOptionsByBundle($definition['target_type'], $definition['target_bundle']);

      $form['view_mode'] = [
        '#type' => 'select',
        '#options' => $options,
        '#title' => $this->t('View mode'),
        '#description' => $this->t('The view mode in which to render the block.'),
        '#default_value' => $this->configuration['view_mode'],
        '#access' => count($options) > 1,
      ];
      return $form;


      EntityFormDisplay::collectRenderDisplay($reference_entity, 'default');

      // Add the field form.
      $form_state->get('form_display')->buildForm($entity, $form, $form_state);



     /* // Add a submit button. Give it a class for easy JavaScript targeting.
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => t('Save'),
        //'#attributes' => ['class' => ['quickedit-form-submit']],
      ];*/
    }

    return $form;
  }
  /**
   * Process callback to insert a Custom Block form.
   *
   * @param array $element
   *   The containing element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The containing element, with the Custom Block form inserted.
   */
  public static function processBlockForm(array $element, FormStateInterface $form_state) {
    /** @var \Drupal\block_content\BlockContentInterface $entity */
    $entity = $element['#entity'];
    EntityFormDisplay::collectRenderDisplay($entity, 'edit')->buildForm($entity, $element, $form_state);
    $element['revision_log']['#access'] = FALSE;
    $element['info']['#access'] = FALSE;
    return $element;
  }


  /**
   * Initialize the form state and the entity before the first form build.
   */
  protected function init(FormStateInterface $form_state, FieldableEntityInterface $entity) {
    $form_state->set('entity', $entity);
    $field_name = $this->getFieldName();
    $form_state->set('field_name', $field_name);

    /** @var \Drupal\Core\Field\FieldItemListInterface $field */
    $field = $entity->get($field_name);
    if ($field->count() === 0) {
      $field->appendItem();

    }


    // Fetch the display used by the form. It is the display for the 'default'
    // form mode, with only the current field visible.
    $display = EntityFormDisplay::collectRenderDisplay($entity, 'default');
    foreach ($display->getComponents() as $name => $options) {
      if ($name != $field_name) {
        $display->removeComponent($name);
      }
    }
    $form_state->set('form_display', $display);
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) {
    $entity_form = $form['reference_entity_form'];
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $entity_form['#entity'];
    $form_display = EntityFormDisplay::collectRenderDisplay($entity, 'edit');
    $complete_form_state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $form_display->extractFormValues($entity, $entity_form, $complete_form_state);
    $form_display->validateFormValues($entity, $entity_form, $complete_form_state);
    // @todo Remove when https://www.drupal.org/project/drupal/issues/2948549 is closed.
    $form_state->setTemporaryValue('entity_form_parents', $entity_form['#parents']);
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    //$this->configuration['view_mode'] = $form_state->getValue('view_mode');

    // @todo Remove when https://www.drupal.org/project/drupal/issues/2948549 is closed.
    $entity_form = NestedArray::getValue($form, $form_state->getTemporaryValue('entity_form_parents'));
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $entity_form['#entity'];
    $form_display = EntityFormDisplay::collectRenderDisplay($entity, 'edit');
    $complete_form_state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $form_display->extractFormValues($entity, $entity_form, $complete_form_state);
    $this->configuration['entity_serialized'] = serialize($entity);
  }
  /**
   * Gets the field name.
   *
   * @return string
   */
  protected function getFieldName() {
    $id = $this->getConfiguration()['id'];
    $parts = explode(':', $id);
    return array_pop($parts);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['label']['#type'] = 'hidden';
    $form['label_display']  = [
      '#type' => 'hidden',
      '#value' => FALSE,
    ];
    $form['label']['#description'] = $this->t('The title of the block as shown to the user.');
    return $form;
  }

  /**
  * Loads or creates the block content entity of the block.
  *
  * @return \Drupal\block_content\BlockContentInterface
  *   The block content entity.
  */
  protected function getReferenceEntity(ContentEntityInterface $host_entity) {
    if (!isset($this->referenceEntity)) {
      $definition = $this->getPluginDefinition();
      $target_type = $definition['target_type'];
      if (!empty($this->configuration['entity_serialized'])) {
        $this->referenceEntity = unserialize($this->configuration['entity_serialized']);
      }
      elseif (!empty($this->configuration['delta'])) {
        $host_entity = $this->getEntity();
        $this->referenceEntity = $host_entity->get($definition['field_name'])->get($this->configuration['delta']);
      }
      else {
        $this->referenceEntity = $this->entityTypeManager->getStorage($target_type)->create([
          'type' => $definition['target_bundle'],
        ]);
        $this->referenceEntity->setParentEntity($host_entity, $definition['field_name']);
      }
    }
    return $this->referenceEntity;
  }

  public function saveReferencedEntity() {
    /** @var EntityInterface $entity */
    $entity = NULL;
    if (!empty($this->configuration['entity_serialized'])) {
      $entity = unserialize($this->configuration['entity_serialized']);
    }

    if ($entity) {
      $entity->save();
      $this->configuration['block_revision_id'] = $entity->getRevisionId();
      $this->configuration['entity_serialized'] = NULL;
    }

  }

}
