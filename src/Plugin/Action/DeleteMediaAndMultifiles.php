<?php

namespace Drupal\islandora\Plugin\Action;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\Entity\File;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\islandora\Plugin\Action\DeleteMediaAndFile;
use Drupal\Core\Entity\EntityFieldManagerInterface;


/**
 * Deletes a media and all associated files.
 *
 * @Action(
 *   id = "delete_media_and_all_files",
 *   label = @Translation("Delete media and all associated files"),
 *   type = "media"
 * )
 */
class DeleteMediaAndMultifiles extends DeleteMediaAndFile implements ContainerFactoryPluginInterface {

  private $entity_field_manager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MediaSourceService $media_source_service, Connection $connection, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $media_source_service, $connection, $logger);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setEntityFieldManager($container->get('entity_field.manager'));
    return $instance;

  }

  /**
   * Sets entity field manager.
   */
  public function setEntityFieldManager(EntityFieldManagerInterface $entity_field_manager) {
    $this->entity_field_manager = $entity_field_manager;
  }

  public function execute($entity = NULL) {
    if (!$entity) {
      return;
    }

    $delete = FALSE;
    $fields = $this->entity_field_manager->getFieldDefinitions('media', $entity->bundle());
    $files = [];
    foreach ($fields as $field) {
      $type = $field->getType();
      if ($type == 'file' || $type == 'image') {
        $files[] = $field->getName();
      }
    }
    foreach ($files as $field) {
      $target_id = $entity->get($field)->target_id;
      $file = File::load($target_id);
      if ($file) {
        $file->delete();
        $delete = TRUE;
      }
    }
    if($delete) {
      $entity->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('delete', $account, $return_as_object);
  }
}
