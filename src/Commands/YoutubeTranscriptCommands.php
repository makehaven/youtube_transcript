<?php

namespace Drupal\youtube_transcript\Commands;

use Drush\Commands\DrushCommands;
use Drupal\taxonomy\Entity\Term;
use Drupal\youtube_transcript\YoutubeTranscriptFetcher;

/**
 * Drush commands for YouTube Transcript fetching.
 */
class YoutubeTranscriptCommands extends DrushCommands {

  protected $fetcher;

  public function __construct(YoutubeTranscriptFetcher $fetcher) {
    $this->fetcher = $fetcher;
  }

  /**
   * Fetch transcripts for all badge taxonomy terms.
   *
   * @option overwrite
   *   Re-fetch even if a transcript already exists, and reset failure counts.
   *
   * @command youtube_transcript:fetch
   * @usage drush youtube_transcript:fetch
   *   Fetch missing badge transcripts.
   * @usage drush youtube_transcript:fetch --overwrite
   *   Re-fetch all badge transcripts, resetting any failure counts.
   */
  public function fetchTranscripts(array $options = ['overwrite' => FALSE]) {
    $overwrite = (bool) $options['overwrite'];
    $kv = \Drupal::keyValue('youtube_transcript');

    $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', 'badges')
      ->accessCheck(FALSE)
      ->execute();

    $terms = Term::loadMultiple($tids);
    $success = $skipped = $failed = 0;

    foreach ($terms as $term) {
      $url      = $term->get('field_badge_video')->getValue()[0]['input'] ?? '';
      $video_id = $url ? $this->fetcher->extractVideoId($url) : NULL;

      if (!$url) {
        $this->output()->writeln('[SKIP] ' . $term->label() . ' — no video URL.');
        $skipped++;
        continue;
      }

      $has_transcript = !$term->get('field_badge_video_transcript')->isEmpty();
      if ($has_transcript && !$overwrite) {
        $this->output()->writeln('[SKIP] ' . $term->label() . ' — transcript already exists.');
        $skipped++;
        continue;
      }

      if ($overwrite) {
        // Clear transcript and reset failure counter so fetchAndStoreTranscript runs.
        $term->set('field_badge_video_transcript', '');
        if ($video_id) {
          $kv->delete('fail_' . $video_id);
        }
      }

      $result = $this->fetcher->fetchAndStoreTranscript($term);

      if ($result) {
        if ($video_id) {
          $kv->delete('fail_' . $video_id);
        }
        $this->output()->writeln('[OK]   ' . $term->label());
        $success++;
      }
      else {
        $this->output()->writeln('[FAIL] ' . $term->label() . ' — ' . $this->fetcher->getLastError());
        $failed++;
      }
    }

    $this->output()->writeln("Done. Saved: {$success}  Skipped: {$skipped}  Failed: {$failed}");
  }

  /**
   * Fetch transcripts for tool_video paragraphs on item nodes.
   *
   * @option nid
   *   Process only this node ID.
   * @option overwrite
   *   Overwrite existing transcripts and reset failure counts.
   *
   * @command youtube_transcript:fetch-tool-videos
   * @usage drush youtube_transcript:fetch-tool-videos
   *   Fetch all missing tool video transcripts.
   * @usage drush youtube_transcript:fetch-tool-videos --nid=4553
   *   Fetch only for node 4553.
   * @usage drush youtube_transcript:fetch-tool-videos --overwrite
   *   Re-fetch all, resetting failure counts.
   */
  public function fetchToolVideoTranscripts(array $options = ['nid' => NULL, 'overwrite' => FALSE]) {
    $storage  = \Drupal::entityTypeManager()->getStorage('node');
    $kv       = \Drupal::keyValue('youtube_transcript');
    $overwrite = (bool) $options['overwrite'];

    if (!empty($options['nid'])) {
      $nids = [(int) $options['nid']];
    }
    else {
      $nids = $storage->getQuery()
        ->condition('type', 'item')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();
    }

    $this->output()->writeln('Processing ' . count($nids) . ' item node(s)...');
    $success = $skipped = $failed = 0;

    foreach ($storage->loadMultiple($nids) as $node) {
      if ($node->get('field_item_videos')->isEmpty()) {
        continue;
      }

      $this->output()->writeln('Node ' . $node->id() . ': ' . $node->getTitle());

      if ($overwrite) {
        // Reset failure counts for each video on this node before processing.
        foreach ($node->get('field_item_videos')->referencedEntities() as $para) {
          $url = $para->get('field_video_url')->first()->input ?? '';
          if ($vid = $this->fetcher->extractVideoId($url)) {
            $kv->delete('fail_' . $vid);
          }
        }
      }

      $results = $this->fetcher->fetchForNode($node, $overwrite);

      foreach ($results as $pid => $result) {
        if ($result === TRUE) {
          $this->output()->writeln("  [OK]   Paragraph {$pid}");
          $success++;
        }
        elseif ($result === FALSE) {
          $this->output()->writeln("  [FAIL] Paragraph {$pid}: " . $this->fetcher->getLastError());
          $failed++;
        }
        else {
          $this->output()->writeln("  [SKIP] Paragraph {$pid}: already has transcript.");
          $skipped++;
        }
      }
    }

    $this->output()->writeln("Done. Saved: {$success}  Skipped: {$skipped}  Failed: {$failed}");
  }

  /**
   * Show which videos have hit the failure limit and are being skipped by cron.
   *
   * Lists each blocked video with a direct YouTube Studio captions link so you
   * can upload a manual SRT/VTT caption, then run youtube_transcript:retry-failures
   * to reset the counters and let cron try again.
   *
   * @command youtube_transcript:failures
   */
  public function showFailures() {
    $kv    = \Drupal::keyValue('youtube_transcript');
    $max   = YOUTUBE_TRANSCRIPT_MAX_FAILURES;
    $found = 0;

    foreach ($kv->getAll() as $key => $value) {
      if (str_starts_with($key, 'fail_') && (int) $value >= $max) {
        $video_id = substr($key, 5);
        $this->output()->writeln('');
        $this->output()->writeln("BLOCKED ({$value} failures): https://youtu.be/{$video_id}");
        $this->output()->writeln("  Add captions: https://studio.youtube.com/video/{$video_id}/translations");
        $found++;
      }
    }

    if ($found === 0) {
      $this->output()->writeln('No blocked videos.');
    }
    else {
      $this->output()->writeln('');
      $this->output()->writeln("After uploading manual captions in YouTube Studio, run:");
      $this->output()->writeln("  drush youtube_transcript:retry-failures");
    }
  }

  /**
   * Reset failure counters for all blocked videos so cron will retry them.
   *
   * Use this after you have uploaded manual SRT/VTT captions in YouTube Studio
   * for one or more blocked videos. Cron will attempt each on the next run.
   *
   * @option video-id
   *   Reset only this specific YouTube video ID instead of all blocked videos.
   *
   * @command youtube_transcript:retry-failures
   * @usage drush youtube_transcript:retry-failures
   *   Reset all blocked videos.
   * @usage drush youtube_transcript:retry-failures --video-id=abc123
   *   Reset a single video.
   */
  public function retryFailures(array $options = ['video-id' => NULL]) {
    $kv      = \Drupal::keyValue('youtube_transcript');
    $max     = YOUTUBE_TRANSCRIPT_MAX_FAILURES;
    $reset   = 0;

    if (!empty($options['video-id'])) {
      $key = 'fail_' . $options['video-id'];
      if ($kv->get($key, 0) > 0) {
        $kv->delete($key);
        $this->output()->writeln("Reset failure counter for video {$options['video-id']}.");
        $reset++;
      }
      else {
        $this->output()->writeln("No failure counter found for video {$options['video-id']}.");
      }
    }
    else {
      foreach ($kv->getAll() as $key => $value) {
        if (str_starts_with($key, 'fail_') && (int) $value >= $max) {
          $kv->delete($key);
          $video_id = substr($key, 5);
          $this->output()->writeln("Reset: https://youtu.be/{$video_id}");
          $reset++;
        }
      }
    }

    if ($reset === 0) {
      $this->output()->writeln('No blocked videos to reset.');
    }
    else {
      $this->output()->writeln('');
      $this->output()->writeln("{$reset} video(s) reset. Cron will retry them on the next run.");
      $this->output()->writeln("Or run now: drush php:eval \"youtube_transcript_cron();\"");
    }
  }

}
