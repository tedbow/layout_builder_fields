<?php

namespace Drupal\layout_builder_fields;

use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\SectionComponent;

class EntityOperations {
  use LayoutEntityHelperTrait;

  public function handlePreSave($entity) {
    if ($this->isLayoutCompatibleEntity($entity) && $this->isEntityUsingFieldOverride($entity)) {
      foreach ($this->getParagraphComponents($entity) as $paragraph_component) {
        $this->saveParagraphComponent($entity, $paragraph_component);
      }

    }
  }

  protected function getParagraphComponents($entity) {
    $sections = $this->getEntitySections($entity);
    $paragraph_components = [];
    foreach ($sections as $section) {
      $components = $section->getComponents();
      foreach ($components as $component) {
        $plugin = $component->getPlugin();
        if ($plugin instanceof DerivativeInspectionInterface) {
          if ($plugin->getBaseId() === 'field_delta') {
            $paragraph_components[] = $component;
          }
        }
      }
    }
    return $paragraph_components;
  }

  /**
   * Determines if an entity is using a field for the layout override.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE if the entity is using a field for a layout override.
   */
  protected function isEntityUsingFieldOverride(EntityInterface $entity) {
    return $entity instanceof FieldableEntityInterface && $entity->hasField('layout_builder__layout');
  }

  /**
   * Saves an inline block component.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity with the layout.
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component with an inline block.
   * @param bool $new_revision
   *   Whether a new revision of the block should be created.
   * @param bool $duplicate_blocks
   *   Whether the blocks should be duplicated.
   */
  protected function saveParagraphComponent(EntityInterface $entity, SectionComponent $component) {
    /** @var \Drupal\layout_builder_fields\Plugin\Block\FieldDelta $plugin */
    $plugin = $component->getPlugin();
    $pre_save_configuration = $plugin->getConfiguration();
    $plugin->saveReferencedEntity();
    $post_save_configuration = $plugin->getConfiguration();
    if ($duplicate_blocks || (empty($pre_save_configuration['block_revision_id']) && !empty($post_save_configuration['block_revision_id']))) {
      $this->usage->addUsage($this->getPluginBlockId($plugin), $entity);
    }
    $component->setConfiguration($post_save_configuration);
  }

}
