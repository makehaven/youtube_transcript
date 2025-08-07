<?php

namespace Drupal\youtube_transcript\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\TermForm;
use Drupal\Core\Url;

/**
 * Modifies the taxonomy term edit form to add a "Refresh Transcript" button.
 */
class YoutubeTranscriptTermForm extends TermForm {

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $term = $this->entity;
    if ($term->id()) {
      $form['actions']['refresh_transcript'] = [
        '#type' => 'link',
        '#title' => $this->t('Refresh Transcript'),
        '#url' => Url::fromRoute('youtube_transcript.refresh', ['term_id' => $term->id()]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return $form;
  }
}
