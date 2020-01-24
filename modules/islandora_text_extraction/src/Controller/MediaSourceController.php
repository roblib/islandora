<?php

namespace Drupal\islandora_text_extraction\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class MediaSourceController.
 */
class MediaSourceController extends ControllerBase {

  /**
   * Adds file to existing media.
   *
   * @param Drupal\media\Entity\Media $media
   *   The media to which file is added.
   * @param string $destination_field
   *   The name of the media field to add file reference.
   * @param string $destination_text_field
   *   The name of the media field to add file reference.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   201 on success with a Location link header.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   */
  public function attachToMedia(
    Media $media,
    string $destination_field,
    string $destination_text_field,
    Request $request) {
    $content_location = $request->headers->get('Content-Location', "");
    $contents = $request->getContent();

    if ($contents) {
      $file = file_save_data($contents, $content_location, FILE_EXISTS_REPLACE);
      if ($media->hasField($destination_field)) {
        $media->{$destination_field}->setValue([
          'target_id' => $file->id(),
        ]);
      }
      else {
        $this->getLogger('islandora')->warning("Field $destination_field is not defined in  Media Type {$media->bundle()}");
      }
      if ($media->hasField($destination_text_field)) {
        $media->{$destination_text_field}->setValue(nl2br($contents));
      }
      else{
        $this->getLogger('islandora')->warning("Field $destination_text_field is not defined in Media Type {$media->bundle()}");
      }
      $media->save();
    }
    // We'd only ever get here if testing the function with GET.
    return new Response("<h1>Complete</h1>");
  }

}
