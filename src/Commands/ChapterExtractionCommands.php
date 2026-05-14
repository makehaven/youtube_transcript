<?php

namespace Drupal\youtube_transcript\Commands;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands that build YouTube chapter timestamps from saved transcripts.
 *
 * Reads field_badge_video_transcript (SRT-formatted) and field_badge_video on
 * a badges taxonomy term, asks the site's default chat model to extract major
 * section breakpoints, and writes them into field_badge_video_timestamps
 * (multi-value link field, uri + title) as jump-to-time YouTube links.
 *
 * Usage:
 *   lando drush yt:chapters --tid=280 --dry-run
 *   lando drush yt:chapters --all-ready --dry-run
 *   lando drush yt:chapters --all-ready --include-broken
 *
 * Live deployment path is documented in the module README — code ships via the
 * normal Composer/Pantheon flow, then run the command via
 * `terminus drush <site>.live -- yt:chapters --all-ready --include-broken`.
 */
class ChapterExtractionCommands extends DrushCommands {

  /**
   * Taxonomy term IDs whose existing chapter data is broken and should be
   * re-extracted from scratch. Add new TIDs here if other legacy entries
   * surface.
   *
   * - 273 Laser Cutter:   `&t=11` instead of `?t=11` on every entry (same URI).
   * - 430 Shapeoko Mill:  `internal:/none` URIs with timestamps in title text.
   */
  protected const BROKEN_TIDS = [273, 430];

  /**
   * Soft upper bound on chapters extracted per video. The model is told to
   * pick fewer for short videos.
   */
  protected const MAX_CHAPTERS = 14;

  /**
   * Hard lower bound; if the model returns fewer than this and the transcript
   * is long, we log a warning and skip the save.
   */
  protected const MIN_CHAPTERS = 3;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiProviderPluginManager $aiProvider,
  ) {
    parent::__construct();
  }

  /**
   * Extract chapter timestamps from saved YouTube transcripts on badges.
   *
   * @command youtube_transcript:extract-chapters
   * @aliases yt:chapters,yt-chapters
   *
   * @option tid Process a single badge by taxonomy term ID.
   * @option all-ready Process every badge that has a transcript but no chapter timestamps.
   * @option include-broken Also re-process badges in the broken-data allowlist (see class constant).
   * @option force Overwrite chapter data even when it already looks valid.
   * @option dry-run Print proposed chapters but do not save.
   * @option model Override the model ID (defaults to the site's default chat model).
   *
   * @usage drush yt:chapters --tid=280 --dry-run
   *   Preview chapters for the Band Saw badge.
   * @usage drush yt:chapters --all-ready --dry-run
   *   Preview chapters for every badge that's ready to process.
   * @usage drush yt:chapters --all-ready --include-broken
   *   Save chapters for all ready badges and re-extract the broken ones.
   */
  public function extractChapters(array $options = [
    'tid' => NULL,
    'all-ready' => FALSE,
    'include-broken' => FALSE,
    'force' => FALSE,
    'dry-run' => FALSE,
    'model' => NULL,
  ]): void {
    $targets = $this->resolveTargets($options);

    if (empty($targets)) {
      $this->writeln('<info>No badges matched.</info>');
      $this->writeln('Tip: pass --tid=<id>, or --all-ready, optionally with --include-broken.');
      return;
    }

    $this->writeln(sprintf(
      '<info>Processing %d badge(s)%s.</info>',
      count($targets),
      $options['dry-run'] ? ' (dry-run — nothing will be saved)' : ''
    ));

    // Resolve AI provider once. We prefer the site's "complex JSON" default
    // because this task returns a JSON array and benefits from a stronger
    // reasoning model than the plain chat default.
    try {
      try {
        $default = $this->aiProvider->getDefaultProviderForOperationType('chat_with_complex_json');
      }
      catch (\Throwable $e) {
        // Older sites or stripped configs may not have the complex_json default
        // — fall back to plain chat.
        $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
      }
      $provider = $this->aiProvider->createInstance($default['provider_id']);
      $model = $options['model'] ?: $default['model_id'];
      $this->writeln(sprintf('<comment>Using model: %s / %s</comment>', $default['provider_id'], $model));
    }
    catch (\Exception $e) {
      $this->logger()->error('Cannot load default AI chat provider: ' . $e->getMessage());
      return;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $saved = $skipped = $errors = 0;

    foreach ($storage->loadMultiple($targets) as $term) {
      try {
        $this->processOne($term, $provider, $model, $options, $saved, $skipped);
      }
      catch (\Throwable $e) {
        $errors++;
        $this->logger()->error(sprintf(
          "  Failed [%s] %s: %s",
          $term->id(),
          $term->label(),
          $e->getMessage()
        ));
      }
    }

    $this->writeln('');
    $this->writeln(sprintf(
      '<info>Done — %d saved, %d skipped, %d error(s).</info>',
      $saved,
      $skipped,
      $errors
    ));
  }

  // ---------------------------------------------------------------------------
  // Target selection.
  // ---------------------------------------------------------------------------

  /**
   * Build the list of taxonomy term IDs to process.
   */
  protected function resolveTargets(array $options): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Single-tid mode wins outright — caller asked for one badge, so deliver
    // exactly that without applying the all-ready filter.
    if (!empty($options['tid'])) {
      return [(int) $options['tid']];
    }

    $tids = [];

    if ($options['all-ready']) {
      $query = $storage->getQuery()
        ->condition('vid', 'badges')
        ->exists('field_badge_video_transcript')
        ->exists('field_badge_video')
        ->accessCheck(FALSE);

      if (!$options['force']) {
        // Skip badges that already have any chapter entries — unless
        // --include-broken adds them back below.
        $query->notExists('field_badge_video_timestamps');
      }

      $tids = array_values($query->execute());
    }

    if ($options['include-broken']) {
      $tids = array_unique(array_merge($tids, self::BROKEN_TIDS));
    }

    return $tids;
  }

  // ---------------------------------------------------------------------------
  // Per-badge processing.
  // ---------------------------------------------------------------------------

  /**
   * Process a single badge term.
   */
  protected function processOne(
    TermInterface $term,
    $provider,
    string $model,
    array $options,
    int &$saved,
    int &$skipped,
  ): void {
    $tid = $term->id();
    $name = $term->label();

    $video_id = $this->getVideoId($term);
    $transcript = $this->getTranscript($term);

    $this->writeln('');
    $this->writeln(sprintf('<info>[%d] %s</info>', $tid, $name));

    if (!$video_id) {
      $this->writeln('  <comment>No YouTube video ID — skip.</comment>');
      $skipped++;
      return;
    }
    if (!$transcript) {
      $this->writeln('  <comment>No transcript text — skip.</comment>');
      $skipped++;
      return;
    }

    // Skip if existing data looks valid and we aren't forcing.
    $existing = $this->describeExistingTimestamps($term);
    $is_broken_tid = in_array($tid, self::BROKEN_TIDS, TRUE);
    if ($existing['count'] > 0 && !$options['force'] && !$is_broken_tid) {
      $this->writeln(sprintf(
        '  <comment>Already has %d chapter(s). Use --force to overwrite — skip.</comment>',
        $existing['count']
      ));
      $skipped++;
      return;
    }

    $duration = $this->detectDurationSeconds($transcript);
    $this->writeln(sprintf(
      '  Video: youtu.be/%s   |   Runtime: %s   |   Transcript: %s chars   |   Existing chapters: %d%s',
      $video_id,
      $duration ? $this->formatTime($duration) : '?',
      number_format(mb_strlen($transcript)),
      $existing['count'],
      $existing['count'] > 0 ? ' (' . $existing['status'] . ')' : ''
    ));

    $chapters = $this->extractChaptersForTranscript($provider, $model, $name, $transcript);

    // Coverage sanity check: warn if the last chapter ends well before the
    // video does. The user can re-run with a stronger --model.
    if ($duration > 60 && !empty($chapters)) {
      $last_sec = end($chapters)['seconds'];
      reset($chapters);
      $coverage = $last_sec / $duration;
      if ($coverage < 0.70) {
        $this->writeln(sprintf(
          '  <comment>WARN: Last chapter at %s of %s — only %d%% coverage. Consider re-running with --model=gpt-4o.</comment>',
          $this->formatTime($last_sec),
          $this->formatTime($duration),
          (int) round($coverage * 100)
        ));
      }
    }

    if (count($chapters) < self::MIN_CHAPTERS) {
      $this->writeln(sprintf(
        '  <comment>Model returned only %d chapter(s); below minimum. Skip.</comment>',
        count($chapters)
      ));
      $skipped++;
      return;
    }

    $this->writeln(sprintf('  Proposed chapters (%d):', count($chapters)));
    foreach ($chapters as $c) {
      $this->writeln(sprintf(
        '    %s  %s',
        $this->formatTime($c['seconds']),
        $c['title']
      ));
    }

    if ($options['dry-run']) {
      $this->writeln('  <comment>DRY RUN — not saving.</comment>');
      return;
    }

    // Build link values.
    $values = [];
    foreach ($chapters as $c) {
      $values[] = [
        'uri' => sprintf('https://youtu.be/%s?t=%d', $video_id, $c['seconds']),
        'title' => $c['title'],
        'options' => [],
      ];
    }

    $term->set('field_badge_video_timestamps', $values);
    $term->save();
    $this->writeln(sprintf('  <info>Saved %d chapter link(s).</info>', count($values)));
    $saved++;
  }

  // ---------------------------------------------------------------------------
  // Data accessors.
  // ---------------------------------------------------------------------------

  /**
   * Get the first video_id from field_badge_video.
   */
  protected function getVideoId(TermInterface $term): ?string {
    if (!$term->hasField('field_badge_video') || $term->get('field_badge_video')->isEmpty()) {
      return NULL;
    }
    $first = $term->get('field_badge_video')->first();
    if (!$first) {
      return NULL;
    }
    // The youtube field stores video_id on its own column.
    $values = $first->getValue();
    return $values['video_id'] ?? $values['input'] ?? NULL;
  }

  /**
   * Get the transcript text (first delta).
   */
  protected function getTranscript(TermInterface $term): ?string {
    if (!$term->hasField('field_badge_video_transcript') || $term->get('field_badge_video_transcript')->isEmpty()) {
      return NULL;
    }
    $value = (string) ($term->get('field_badge_video_transcript')->value ?? '');
    return trim($value) ?: NULL;
  }

  /**
   * Describe what's currently in field_badge_video_timestamps so the operator
   * can see whether we're replacing useful data.
   */
  protected function describeExistingTimestamps(TermInterface $term): array {
    $result = ['count' => 0, 'status' => ''];
    if (!$term->hasField('field_badge_video_timestamps') || $term->get('field_badge_video_timestamps')->isEmpty()) {
      return $result;
    }
    $items = $term->get('field_badge_video_timestamps');
    $result['count'] = $items->count();
    $broken = 0;
    foreach ($items as $item) {
      $uri = (string) $item->uri;
      // "Broken" heuristic: no `?t=` time anchor, or uses `internal:/none`.
      if (stripos($uri, 'internal:/') === 0) {
        $broken++;
      }
      elseif (strpos($uri, '?t=') === FALSE && strpos($uri, '&t=') === FALSE) {
        $broken++;
      }
      elseif (strpos($uri, 'youtu.be/') !== FALSE && strpos($uri, '?t=') === FALSE) {
        // Has `&t=` but missing the `?` — also broken (Laser Cutter pattern).
        $broken++;
      }
    }
    if ($broken > 0) {
      $result['status'] = sprintf('%d of %d entries malformed', $broken, $items->count());
    }
    else {
      $result['status'] = 'looks ok';
    }
    return $result;
  }

  // ---------------------------------------------------------------------------
  // AI call.
  // ---------------------------------------------------------------------------

  /**
   * Ask the model for a JSON list of chapters.
   *
   * @return array<int,array{seconds:int,title:string}>
   */
  protected function extractChaptersForTranscript($provider, string $model, string $badgeName, string $transcript): array {
    // Compute the actual video duration from the last SRT timestamp. We use
    // this both to tell the model the target range AND to discard any
    // chapter the model invents beyond the end of the video (gpt-4.1
    // hallucinated 35-minute marks on a 27-minute Laser Cutter transcript).
    $duration_seconds = $this->detectDurationSeconds($transcript);

    // Trim very long transcripts. The 90,000-char cap stays well inside any
    // modern chat context and covers the longest badge transcripts we have.
    if (mb_strlen($transcript) > 90000) {
      $transcript = mb_substr($transcript, 0, 90000);
    }

    $max = self::MAX_CHAPTERS;

    $duration_label = $duration_seconds
      ? sprintf('%d:%02d (mm:ss)', intdiv($duration_seconds, 60), $duration_seconds % 60)
      : 'unknown';

    $system = <<<PROMPT
You receive an SRT-formatted YouTube transcript of a makerspace training video.
Your job: identify the major section boundaries that would be useful as
chapter markers on the video page — the kind of jump-points a member would
want to skip back to.

OUTPUT FORMAT (strict)
Return ONLY a JSON array. No prose, no markdown fences. Each item:
  { "seconds": <integer>, "title": <string> }
- "seconds" is the start time of the section, expressed in whole seconds from
  the beginning of the video (parse the SRT HH:MM:SS,ms timestamps —
  hours×3600 + minutes×60 + seconds).
- "title" is 3-9 words, plain text, factual (no emoji, no markdown).
  Match the existing makerspace style: "Safety measures", "Changing a blade",
  "Using the rip fence", "Types of paper available". Do NOT repeat the tool
  name in every title (the title already appears on the page next to these
  links). Do NOT include time codes in the title.

RULES
- Aim for 5 to {$max} chapters total. Use fewer for short videos. Each
  chapter must cover a meaningfully distinct topic — don't fragment a single
  topic into multiple chapters.
- Chapters MUST span the full video. The last chapter's seconds value must
  be within the final ~10% of the total runtime. Do NOT stop early.
- The first chapter does NOT need to be at second 0. Skip generic intros
  unless they contain content worth jumping back to.
- Chapters must be in ascending time order and at least ~25 seconds apart.
- Use information actually present in the transcript. Do not invent topics.
- Prefer concrete operational topics (safety, setup, technique, common
  mistakes, cleanup) over generic structural labels ("Introduction", "Outro").
PROMPT;

    $user = "Badge / tool: {$badgeName}\nTotal video duration: {$duration_label}\n\nTranscript (SRT):\n{$transcript}";

    $input = new ChatInput([
      new ChatMessage('user', $user),
    ]);
    $input->setSystemPrompt($system);

    $response = $provider->chat($input, $model, ['youtube_transcript', 'extract_chapters']);
    $normalized = $response->getNormalized();
    $text = method_exists($normalized, 'getText')
      ? $normalized->getText()
      : (string) $normalized;

    return $this->parseChapterJson($text, $duration_seconds);
  }

  /**
   * Parse and sanity-check the model's JSON output.
   *
   * Tolerates a code fence wrapper since models sometimes ignore the
   * "no markdown" instruction. Discards entries beyond the detected video
   * duration (with a 30-second tolerance for SRT rounding).
   *
   * @return array<int,array{seconds:int,title:string}>
   */
  protected function parseChapterJson(string $text, int $duration_seconds = 0): array {
    $text = trim($text);

    // Strip ```json ... ``` fences if present.
    if (str_starts_with($text, '```')) {
      $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
      $text = preg_replace('/```\s*$/', '', $text);
      $text = trim($text);
    }

    // Find the first JSON array in the response (defensive).
    if (!str_starts_with($text, '[')) {
      $start = strpos($text, '[');
      $end = strrpos($text, ']');
      if ($start !== FALSE && $end !== FALSE && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
      }
    }

    $data = json_decode($text, TRUE);
    if (!is_array($data)) {
      $this->logger()->warning('Model returned non-JSON output: ' . mb_substr($text, 0, 200));
      return [];
    }

    // Allow 30s slop past the detected end for SRT rounding / late credits.
    $upper_bound = $duration_seconds > 0 ? $duration_seconds + 30 : PHP_INT_MAX;

    $clean = [];
    $last = -PHP_INT_MAX;
    foreach ($data as $row) {
      if (!is_array($row)) {
        continue;
      }
      $sec = $row['seconds'] ?? NULL;
      $title = $row['title'] ?? '';
      if (!is_numeric($sec)) {
        continue;
      }
      $sec = (int) $sec;
      $title = trim(strip_tags((string) $title));
      // Strip any leading time-codes the model snuck into the title.
      $title = preg_replace('/^\d{1,2}:\d{2}(?::\d{2})?\s*[-:–]\s*/', '', $title) ?? $title;
      if ($sec < 0 || $title === '' || mb_strlen($title) > 120) {
        continue;
      }
      if ($sec > $upper_bound) {
        // Hallucinated timestamp beyond end of video — drop.
        continue;
      }
      if ($sec - $last < 25) {
        // Too close to previous; skip.
        continue;
      }
      $clean[] = ['seconds' => $sec, 'title' => $title];
      $last = $sec;
      if (count($clean) >= self::MAX_CHAPTERS) {
        break;
      }
    }
    return $clean;
  }

  /**
   * Pull the last HH:MM:SS,ms end-timestamp from the SRT to estimate runtime.
   */
  protected function detectDurationSeconds(string $srt): int {
    if (!preg_match_all('/(\d{1,2}):(\d{2}):(\d{2}),\d{3}\s*-->\s*(\d{1,2}):(\d{2}):(\d{2}),\d{3}/', $srt, $matches, PREG_SET_ORDER)) {
      return 0;
    }
    $last = end($matches);
    return ((int) $last[4]) * 3600 + ((int) $last[5]) * 60 + ((int) $last[6]);
  }

  /**
   * Format seconds as M:SS or H:MM:SS for human-readable output.
   */
  protected function formatTime(int $seconds): string {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
      return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
  }

}
