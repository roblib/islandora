<?php

namespace Drupal\islandora\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\islandora\MediaSource\MediaSourceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for the 'Delete media and file(s)' action.
 */
class ConfirmDeleteMediaAndFile extends DeleteMultipleForm {

  /**
   * Media source service.
   *
   * @var \Drupal\islandora\MediaSource\MediaSourceService
   */
  protected $mediaSourceService;

  /**
   * Logger.
   *
   * @var Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * List of media targeted.
   *
   * @var array
   */
  protected $selection = [];

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, PrivateTempStoreFactory $temp_store_factory, MessengerInterface $messenger, MediaSourceService $media_source_service, LoggerInterface $logger) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->tempStore = $temp_store_factory->get('media_and_file_delete_confirm');
    $this->messenger = $messenger;
    $this->mediaSourceService = $media_source_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('tempstore.private'),
      $container->get('messenger'),
      $container->get('islandora.media_source_service'),
      $container->get('logger.channel.islandora'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_and_file_delete_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->selection),
      'Are you sure you want to delete this media and associated files?',
      'Are you sure you want to delete these media and associated files?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.media.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    return parent::buildForm($form, $form_state, 'media');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Similar to parent::submitForm(), but let's blend in the related files and
    // optimize based on the fact that we know we're working with media.
    $total_count = 0;
    $delete_media = [];
    $delete_media_translations = [];
    $delete_files = [];
    $inaccessible_entities = [];
    $media_storage = $this->entityTypeManager->getStorage('media');
    $file_storage = $this->entityTypeManager->getStorage('file');
    $media = $media_storage->loadMultiple(array_keys($this->selection));
    foreach ($this->selection as $id => $selected_langcodes) {
      $entity = $media[$id];
      if (!$entity->access('delete', $this->currentUser)) {
        $inaccessible_entities[] = $entity;
        continue;
      }
      // Check for files.
      $fields = $this->entityFieldManager->getFieldDefinitions('media', $entity->bundle());
      $files = [];
      foreach ($fields as $field) {
        $type = $field->getType();
        if ($type == 'file' || $type == 'image') {
          $target_id = $entity->get($field->getName())->target_id;
          $file = File::load($target_id);
          if ($file) {
            if (!$file->access('delete', $this->currentUser)) {
              $inaccessible_entities[] = $file;
              continue;
            }
            $delete_files[$file->id()] = $file;
            $total_count++;
          }
        }
      }

      foreach ($selected_langcodes as $langcode) {
        // We're only working with media, which are translatable.
        $entity = $entity->getTranslation($langcode);
        if ($entity->isDefaultTranslation()) {
          $delete_media[$id] = $entity;
          unset($delete_media_translations[$id]);
          $total_count += count($entity->getTranslationLanguages());
        }
        elseif (!isset($delete_media[$id])) {
          $delete_media_translations[$id][] = $entity;
        }
      }
    }
    if ($delete_media) {
      $media_storage->delete($delete_media);
      foreach ($delete_media as $entity) {
        $this->logger->notice('The media %label has been deleted.', [
          '%label' => $entity->label(),
        ]);
      }
    }
    if ($delete_files) {
      $file_storage->delete($delete_files);
      foreach ($delete_files as $entity) {
        $this->logger->notice('The file %label has been deleted.', [
          '%label' => $entity->label(),
        ]);
      }
    }
    if ($delete_media_translations) {
      foreach ($delete_media_translations as $id => $translations) {
        $entity = $media[$id];
        foreach ($translations as $translation) {
          $entity->removeTranslation($translation->language()->getId());
        }
        $entity->save();
        foreach ($translations as $translation) {
          $this->logger->notice('The media %label @language translation has been deleted', [
            '%label' => $entity->label(),
            '@language' => $translation->language()->getName(),
          ]);
        }
        $total_count += count($translations);
      }
    }
    if ($total_count) {
      $this->messenger->addStatus($this->getDeletedMessage($total_count));
    }
    if ($inaccessible_entities) {
      $this->messenger->addWarning($this->getInaccessibleMessage(count($inaccessible_entities)));
    }
    $this->tempStore->delete($this->currentUser->id());
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
