<?php
/**
 * tib.php — Tibetan word search
 * --------------------------------
 * Searches xml/Mv.xml (Mahāvyutpatti), xml/lk4.xml (Lokesh Chandra)
 * and xml/saran.xml (Saran-u Gerel)
 * by the <tib> (Wylie) field and displays matching entries with Mongolian
 * equivalents and links to each reference in index.php.
 *
 * Data layout:
 *   ./xml/Mv.xml       : <list>/<item> with <tib>, <mng>, <ref> (#-delimited)
 *   ./xml/lk4.xml      : <list>/<entry> with <tib>, <mng>, <refs>/<ref>
 *   ./xml/saran.xml    : <list>/<entry> with <tib>, <mng>, <refs>/<ref>
 */

// ── Configuration ────────────────────────────────────────────────
$XML_DIR     = __DIR__ . '/xml';
$TIBETAN_XML = $XML_DIR . '/Mv.xml';
$LK4_XML     = $XML_DIR . '/lk4.xml';
$SARAN_XML   = $XML_DIR . '/saran.xml';
$ROOT_XML    = $XML_DIR . '/root.xml';

// ── Helpers ──────────────────────────────────────────────────────
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Load root.xml into a lookup map: ref → ['unicode' => '...', 'variants' => [...]]
 */
function loadRootIndex(string $path): array {
    if (!file_exists($path)) return [];
    $xml = @simplexml_load_file($path);
    if ($xml === false) return [];
    $map = [];
    foreach ($xml->item as $item) {
        $ref = trim((string)$item->ref);
        if ($ref === '') continue;
        $variants = [];
        foreach ($item->variants->v ?? [] as $v) {
            if ((string)$v['t'] === 'unicode') {
                $s = trim((string)$v);
                if ($s !== '') $variants[] = $s;
            }
        }
        $map[$ref] = [
            'unicode'  => trim((string)$item->unicode),
            'variants' => $variants,
        ];
    }
    return $map;
}

// Split a #-delimited ref string into an array of ref IDs, skipping +++ placeholders.
function parseRefString(string $refStr): array {
    preg_match_all('/#([A-Za-z0-9]+)(?=#)/', $refStr, $m);
    return $m[1];
}

// ── Data loading ─────────────────────────────────────────────────

/** Load Mv.xml into a flat array of items. */
function loadTibetanItems(string $path): array {
    if (!file_exists($path)) return [];
    $xml = @simplexml_load_file($path);
    if ($xml === false) return [];
    $out = [];
    foreach ($xml->item as $item) {
        $refs = parseRefString((string)$item->ref);
        $out[] = [
            'source' => 'tibetan',
            'coll'   => (string)$item->coll,
            'tib'    => (string)$item->tib,
            'mng'    => (string)$item->mng,
            'mng2'   => (string)$item->mng2,
            'skt'    => (string)$item->skt,
            'refs'   => $refs,
        ];
    }
    return $out;
}

/** Load lk4.xml into a flat array of items. */
function loadLk4Items(string $path): array {
    if (!file_exists($path)) return [];
    $xml = @simplexml_load_file($path);
    if ($xml === false) return [];
    $out = [];
    foreach ($xml->entry as $entry) {
        $refs = [];
        foreach ($entry->refs->ref ?? [] as $r) {
            $s = trim((string)$r);
            if ($s !== '') $refs[] = $s;
        }
        $out[] = [
            'source' => 'lk4',
            'id'     => (string)$entry['id'],
            'tib'    => (string)$entry->tib,
            'mng'    => (string)$entry->mng,
            'page'   => (string)$entry->page,
            'refs'   => $refs,
        ];
    }
    return $out;
}

/** Load saran.xml into a flat array of items (same structure as lk4.xml). */
function loadSaranItems(string $path): array {
    if (!file_exists($path)) return [];
    $xml = @simplexml_load_file($path);
    if ($xml === false) return [];
    $out = [];
    foreach ($xml->entry as $entry) {
        $refs = [];
        foreach ($entry->refs->ref ?? [] as $r) {
            $s = trim((string)$r);
            if ($s !== '') $refs[] = $s;
        }
        $out[] = [
            'source' => 'saran',
            'id'     => (string)$entry['id'],
            'tib'    => (string)$entry->tib,
            'mng'    => (string)$entry->mng,
            'page'   => (string)$entry->page,
            'refs'   => $refs,
        ];
    }
    return $out;
}

// ── Search ───────────────────────────────────────────────────────

/**
 * Search items whose <tib> field contains the query as a substring.
 * Empty query returns empty array.
 */
function searchTibetan(string $query, array $items): array {
    $query = trim($query);
    if ($query === '') return [];
    $hits   = [];
    $qLower = mb_strtolower($query, 'UTF-8');
    foreach ($items as $item) {
        $tib = mb_strtolower($item['tib'], 'UTF-8');
        if (mb_strpos($tib, $qLower) !== false) {
            $hits[] = $item;
        }
    }
    return $hits;
}

// ── Dispatch ─────────────────────────────────────────────────────
$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$tibItems   = loadTibetanItems($TIBETAN_XML);
$lk4Items   = loadLk4Items($LK4_XML);
$saranItems = loadSaranItems($SARAN_XML);
$rootIndex  = loadRootIndex($ROOT_XML);
$allItems   = array_merge($tibItems, $lk4Items, $saranItems);

$results    = [];
$totalTib   = 0;
$totalLk4   = 0;
$totalSaran = 0;

if ($query !== '') {
    $results = searchTibetan($query, $allItems);
    foreach ($results as $r) {
        if ($r['source'] === 'tibetan') $totalTib++;
        elseif ($r['source'] === 'lk4')  $totalLk4++;
        else                              $totalSaran++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tibetan Search<?= $query !== '' ? ' · '.h($query) : '' ?> — Mongolian Dictionary</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Noto+Serif:ital,wght@0,400;0,600;1,400&display=swap">
<style>
  :root {
      --paper:     #ffffff;
      --paper-2:   #f2f6fb;
      --ink:       #0f2747;
      --ink-soft:  #51678a;
      --rule:      #c4d4e6;
      --accent:    #1c4f8b;
      --accent-2:  #4a8bc4;
      --highlight: #dfeaf6;
  }
  mark {
      background: #ffe066;
      color: inherit;
      border-radius: 2px;
      padding: 0 1px;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
      background: var(--paper);
      color: var(--ink);
      font-family: "Noto Serif", Georgia, serif;
      font-size: 16px;
      line-height: 1.55;
      min-height: 100vh;
  }

  /* ── Masthead ── */
  .masthead {
      border-bottom: 1px solid var(--rule);
      background: var(--paper-2);
      padding: 1.4rem 0 1.2rem;
  }
  .masthead-inner {
      max-width: 1000px; margin: 0 auto; padding: 0 1.5rem;
      display: flex; align-items: center; gap: 1.6rem; flex-wrap: wrap;
  }
  .brand {
      display: flex; align-items: center; gap: .9rem;
      text-decoration: none; color: inherit;
  }
  .soyombo {
      height: 68px; width: auto;
      flex-shrink: 0; display: block;
  }
  .brand-text {
      font-family: "EB Garamond", serif;
      font-size: 1.35rem; line-height: 1.15;
      font-weight: 500; color: var(--ink);
      letter-spacing: .005em;
  }
  .brand-sub {
      display: block;
      font-style: italic; font-weight: 400;
      font-size: .92em; color: var(--ink-soft);
  }
  .search-form {
      flex: 1; min-width: 280px;
      display: flex; gap: .5rem;
      background: #fff; border: 1px solid var(--rule);
      border-radius: 2px; padding: .25rem .25rem .25rem .8rem;
  }
  .search-form input[type=search] {
      flex: 1; border: 0; outline: 0; background: transparent;
      font: inherit; font-size: 1.05rem; padding: .35rem 0;
      color: var(--ink);
  }
  .search-form button {
      border: 0; background: var(--accent); color: #fff;
      font: inherit; font-weight: 600; letter-spacing: .04em;
      padding: .45rem 1.1rem; cursor: pointer;
      text-transform: uppercase; font-size: .78rem;
      border-radius: 1px; transition: background .15s;
  }
  .search-form button:hover { background: #143d6e; }

  /* ── Page shell: sidebar + content ── */
  .page-shell {
      max-width: 1060px; margin: 0 auto; padding: 0 1.5rem;
      display: grid;
      grid-template-columns: 168px 1fr;
      gap: 2.5rem;
      align-items: start;
  }

  /* ── Left nav ── */
  .dict-nav {
      position: sticky; top: 1.75rem;
      padding-top: 2rem;
  }
  .dict-nav-title {
      font-family: "EB Garamond", serif;
      font-size: .68rem; font-weight: 600;
      letter-spacing: .14em; text-transform: uppercase;
      color: var(--ink-soft);
      margin: 0 0 .6rem .1rem;
  }
  .dict-nav ul {
      list-style: none; padding: 0; margin: 0;
      border-left: 1px solid var(--rule);
  }
  .dict-nav ul li { margin: 0; }
  .dict-nav ul li a {
      display: block;
      padding: .35rem .65rem .35rem .75rem;
      font-family: "EB Garamond", serif;
      font-size: .97rem;
      color: var(--ink-soft);
      text-decoration: none;
      border-left: 2px solid transparent;
      margin-left: -1px;
      transition: color .12s, border-color .12s, background .12s;
      line-height: 1.3;
  }
  .dict-nav ul li a:hover {
      color: var(--ink);
      border-left-color: var(--rule);
      background: rgba(255,255,255,.45);
  }
  .dict-nav ul li a.active {
      color: var(--accent);
      font-weight: 500;
      border-left-color: var(--accent);
      background: rgba(28,79,139,.06);
  }

  /* ── Content shell ── */
  main {
      min-width: 0;
      margin: 2rem 0 4rem;
      padding: 0;
  }

  /* ── Results header ── */
  .results-header {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: 1rem; color: var(--ink-soft);
      border-bottom: 1px solid var(--rule);
      padding-bottom: .5rem; margin-bottom: 1.25rem;
      display: flex; justify-content: space-between; align-items: baseline;
  }
  .results-header .count { font-size: .85rem; }

  /* ── Source section ── */
  .source-section {
      margin-top: 1.75rem;
      scroll-margin-top: 1.5rem;
  }
  .source-section-title {
      font-family: "EB Garamond", serif;
      font-size: 1.15rem; font-weight: 600; letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--accent); margin: 0 0 .6rem;
      padding-bottom: .25rem;
      border-bottom: 1px solid var(--rule);
      display: flex; align-items: baseline; justify-content: space-between;
  }
  .source-section-title .count {
      font-size: .7em; font-weight: 400; letter-spacing: .06em;
      text-transform: lowercase; font-style: italic;
      color: var(--ink-soft);
  }

  /* ── Entry item ── */
  .entry-item {
      display: block;
      padding: .8rem 0 .8rem 1rem;
      border-left: 2px solid var(--paper-2);
      margin: .75rem 0;
      transition: border-color .12s;
  }
  .entry-item:hover { border-left-color: var(--accent); }

  /* ── Mongolian script: single horizontal string ── */
  .tib-mng {
      font-family: "Mongolian Baiti", serif;
      font-size: 1.75rem;
      line-height: 1.3;
      color: var(--ink);
      display: inline;
      writing-mode: horizontal-tb;
      -webkit-writing-mode: horizontal-tb;
  }

  .entry-body { }

  /* mng + mng2 stacked as block lines */
  .mng-group {
      display: block;
  }
  .mng2-wrap {
      display: flex;
      flex-direction: row;
      align-items: baseline;
      gap: .35rem;
  }
  .mng2-wrap .tib-mng {
      color: var(--ink-soft);
      font-size: 1.45rem;
  }
  .mng2-label {
      font-family: "EB Garamond", serif;
      font-size: .7rem; font-style: italic;
      color: var(--ink-soft);
      letter-spacing: .05em;
      white-space: nowrap;
  }

  /* Tibetan headword */
  .tib-word {
      font-family: "EB Garamond", serif;
      font-size: 1.15rem; font-weight: 500;
      color: var(--ink);
  }
  .tib-word.tib-unicode {
      font-family: "Noto Serif Tibetan", "Microsoft Himalaya", serif;
      font-size: 2rem; line-height: 2; font-weight: 400;
  }

  /* Collection / page label */
  .entry-coll {
      font-family: "EB Garamond", serif;
      font-style: italic; font-size: .92rem;
      color: var(--ink-soft);
  }

  /* Sanskrit gloss */
  .entry-skt {
      font-family: "Noto Serif", serif;
      font-size: .9rem; color: var(--ink-soft);
  }

  /* Page badge */
  .pg {
      font-family: "Courier New", monospace; font-size: .75rem;
      color: var(--ink-soft);
      border: 1px solid var(--rule); padding: 1px 6px; border-radius: 2px;
      text-decoration: none; transition: color .12s, border-color .12s, background .12s;
      margin-left: .3rem;
  }
  a.pg:hover { color: var(--accent); border-color: var(--accent); background: #fff; }

  /* ── Refs chips ── */
  .refs-row {
      display: flex; flex-wrap: wrap; gap: .4rem;
  }
  .ref-chip {
      display: inline-flex;
      flex-direction: row;
      align-items: stretch;
      font-family: "Courier New", monospace; font-size: .76rem;
      color: var(--accent);
      background: var(--highlight);
      border: 1px solid var(--rule);
      border-radius: 2px; padding: 2px 4px;
      text-decoration: none;
      transition: background .12s, border-color .12s, color .12s;
      line-height: 1.3;
      gap: 4px;
  }
  .ref-chip:hover {
      background: var(--accent); color: #fff; border-color: var(--accent);
  }
  .chip-mng {
      font-family: "Mongolian Baiti", serif;
      font-size: 1rem;
      color: var(--ink);
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
      display: block;
      min-height: 2.5em;
  }
  .chip-mng-variant {
      color: var(--ink-soft);
      font-size: .9rem;
      border-left: 1px solid var(--rule);
      padding-left: 3px;
  }
  .ref-chip:hover .chip-mng {
      color: #fff;
  }
  .ref-chip:hover .chip-mng-variant {
      color: rgba(255,255,255,.75);
      border-left-color: rgba(255,255,255,.3);
  }

  /* ── Landing ── */
  .landing h2 {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1.4rem; margin: 0 0 .75rem; color: var(--ink);
  }
  .landing p { color: var(--ink-soft); }
  .landing .hint {
      margin-top: 1rem; padding: 1rem 1.25rem;
      background: #fff; border-left: 3px solid var(--accent);
      border-radius: 2px; color: var(--ink-soft);
  }
  .landing code {
      font-family: "Courier New", monospace; font-size: .92em;
      background: var(--highlight); padding: 0 .25em; border-radius: 2px;
  }

  .no-results {
      font-style: italic; color: var(--ink-soft); padding: 1.5rem 0;
  }

  /* ── Line layout inside entry body ── */
  .entry-line {
      display: block;
      margin-bottom: .25rem;
  }
  .entry-line .entry-coll {
      margin-left: .4em;
  }
  .refs-row {
      margin-top: .4rem;
  }

  @media (max-width: 680px) {
      .page-shell {
          grid-template-columns: 1fr;
          gap: 0; padding: 0 1rem;
      }
      .dict-nav {
          position: static; padding-top: 1rem;
          border-bottom: 1px solid var(--rule);
          padding-bottom: .75rem; margin-bottom: .25rem;
      }
      .dict-nav ul {
          display: flex; flex-wrap: wrap; gap: .1rem .25rem; border-left: none;
      }
      .dict-nav ul li a {
          border: 1px solid var(--rule); border-radius: 2px;
          padding: .2rem .55rem; font-size: .85rem; margin-left: 0;
      }
      .dict-nav ul li a.active {
          border-color: var(--accent); background: rgba(28,79,139,.08);
      }
      main { margin-top: 1.25rem; }
  }
  @media (max-width: 540px) {
      .masthead-inner { gap: 1rem; }
      .soyombo        { height: 54px; }
      .brand-text     { font-size: 1.15rem; }
  }
</style>
</head>
<body>

<header class="masthead">
  <div class="masthead-inner">
    <a class="brand" href="index.php">
      <img class="soyombo" src="soyombo.png" alt="" width="36" height="68">
      <span class="brand-text">Lexicographical Resources<span class="brand-sub">for the Mongolian Language</span></span>
    </a>
    <form class="search-form" method="get" action="" role="search">
      <input type="search" name="q" value="<?= h($query) ?>"
             placeholder="Tibetan Wylie, e.g. sangs rgyas…"
             autocomplete="off" spellcheck="false"
             <?= $query === '' ? 'autofocus' : '' ?>>
      <button type="submit">Search</button>
    </form>
  </div>
</header>

<div class="page-shell">

  <nav class="dict-nav" aria-label="Sources">
    <p class="dict-nav-title">Sources</p>
    <ul>
      <li>
        <a href="#src-tibetan" class="<?= $totalTib > 0 ? 'active' : '' ?>">
          Mahāvyutpatti
        </a>
      </li>
      <li>
        <a href="#src-lk4" class="<?= $totalLk4 > 0 ? 'active' : '' ?>">
          Lokesh Chandra
        </a>
      </li>
      <li>
        <a href="#src-saran" class="<?= $totalSaran > 0 ? 'active' : '' ?>">
          Saran-u Gerel
        </a>
      </li>
    </ul>
    <br>
    <p class="dict-nav-title">Other</p>
    <ul>
      <li><a href="index.php">← Dictionary</a></li>
    </ul>
  </nav>

<main>

<?php if ($query !== ''): ?>

  <div class="results-header">
    <span>Tibetan results for <em>&ldquo;<?= h($query) ?>&rdquo;</em></span>
    <span class="count"><?= count($results) ?> <?= count($results) === 1 ? 'match' : 'matches' ?></span>
  </div>

  <?php if (!$results): ?>
    <p class="no-results">No Tibetan entries match this string.</p>
  <?php else: ?>

    <?php
      // Split results by source
      $tibResults   = array_filter($results, fn($r) => $r['source'] === 'tibetan');
      $lk4Results   = array_filter($results, fn($r) => $r['source'] === 'lk4');
      $saranResults = array_filter($results, fn($r) => $r['source'] === 'saran');

      /**
       * Render Mongolian text as a single inline span (Mongolian Baiti, horizontal).
       */
      function renderMng(string $mng): string {
          if ($mng === '') return '';
          return '<span class="tib-mng">' . h($mng) . '</span>';
      }

      /**
       * Render a ref chip: Mongolian Unicode from root.xml + variants, linked to index.php.
       */
      function renderRefChip(string $rid, array $rootIndex): string {
          $href = 'index.php?ref=' . urlencode($rid);
          $entry = $rootIndex[$rid] ?? null;
          $inner = '';
          if ($entry && $entry['unicode'] !== '') {
              $inner .= '<span class="chip-mng">' . h($entry['unicode']) . '</span>';
              foreach ($entry['variants'] as $v) {
                  $inner .= '<span class="chip-mng chip-mng-variant">' . h($v) . '</span>';
              }
          } else {
              // fallback: show the ref ID if not found in root.xml
              $inner = h($rid);
          }
          return '<a class="ref-chip" href="' . h($href) . '" title="' . h($rid) . '">'
               . $inner . '</a>';
      }
    ?>

    <?php if ($tibResults): ?>
    <section class="source-section" id="src-tibetan">
      <h2 class="source-section-title">
        <span>Mahāvyutpatti</span>
        <span class="count"><?= count($tibResults) ?> <?= count($tibResults) === 1 ? 'entry' : 'entries' ?></span>
      </h2>
      <?php foreach ($tibResults as $item): ?>
        <div class="entry-item">
          <div class="mng-group">
            <?= renderMng($item['mng']) ?>
            <?php if ($item['mng2'] !== ''): ?>
            <div class="mng2-wrap">
              <span class="mng2-label">alt.</span>
              <?= renderMng($item['mng2']) ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="entry-body">
            <div class="entry-line">
              <span class="tib-word" data-wylie="<?= h($item['tib']) ?>"><?= h($item['tib']) ?></span>
              <?php if ($item['coll'] !== ''): ?>
                <span class="entry-coll"><?= h($item['coll']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($item['skt'] !== ''): ?>
              <div class="entry-line">
                <span class="entry-skt"><?= h($item['skt']) ?></span>
              </div>
            <?php endif; ?>
            <?php if ($item['refs']): ?>
              <div class="refs-row">
                <?php foreach ($item['refs'] as $rid): ?>
                  <?= renderRefChip($rid, $rootIndex) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($lk4Results): ?>
    <section class="source-section" id="src-lk4">
      <h2 class="source-section-title">
        <span>Lokesh Chandra</span>
        <span class="count"><?= count($lk4Results) ?> <?= count($lk4Results) === 1 ? 'entry' : 'entries' ?></span>
      </h2>
      <?php foreach ($lk4Results as $item): ?>
        <div class="entry-item">
          <?= renderMng($item['mng']) ?>
          <div class="entry-body">
            <div class="entry-line">
              <span class="tib-word" data-wylie="<?= h($item['tib']) ?>"><?= h($item['tib']) ?></span>
              <?php if ($item['page'] !== '' && $item['page'] !== '0'): ?>
                <a class="pg" href="img.php?dic=lk4&amp;id=<?= urlencode($item['page']) ?>"
                   target="_blank" rel="noopener">p.&nbsp;<?= h($item['page']) ?></a>
              <?php endif; ?>
            </div>
            <?php if ($item['refs']): ?>
              <div class="refs-row">
                <?php foreach ($item['refs'] as $rid): ?>
                  <?= renderRefChip($rid, $rootIndex) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($saranResults): ?>
    <section class="source-section" id="src-saran">
      <h2 class="source-section-title">
        <span>Saran-u Gerel</span>
        <span class="count"><?= count($saranResults) ?> <?= count($saranResults) === 1 ? 'entry' : 'entries' ?></span>
      </h2>
      <?php foreach ($saranResults as $item): ?>
        <div class="entry-item">
          <?= renderMng($item['mng']) ?>
          <div class="entry-body">
            <div class="entry-line">
              <span class="tib-word" data-wylie="<?= h($item['tib']) ?>"><?= h($item['tib']) ?></span>
              <?php if ($item['page'] !== '' && $item['page'] !== '0'): ?>
                <a class="pg" href="img.php?dic=saran&amp;id=<?= urlencode(rtrim($item['page'], 'ab')) ?>"
                   target="_blank" rel="noopener">p.&nbsp;<?= h($item['page']) ?></a>
              <?php endif; ?>
            </div>
            <?php if ($item['refs']): ?>
              <div class="refs-row">
                <?php foreach ($item['refs'] as $rid): ?>
                  <?= renderRefChip($rid, $rootIndex) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

  <?php endif; ?>

<?php else: ?>

  <div class="landing">
    <h2>Search Tibetan across the glossaries.</h2>
    <p>Enter a Tibetan word in Wylie transliteration to find matching entries
       in the Mahāvyutpatti cross-reference list and the Lokesh Chandra
       Tibetan-Mongolian dictionary. Each result shows the Mongolian equivalent
       and links back to the full entry in the main dictionary.</p>
    <div class="hint">
      <p style="margin:0">Examples: <code>sangs rgyas</code> · <code>bcom ldan</code> · <code>kang pa</code></p>
    </div>
    <p style="margin-top:1.2rem">
      <em><?= count($tibItems) ?> Mahāvyutpatti items · <?= count($lk4Items) ?> Lokesh Chandra entries · <?= count($saranItems) ?> Saran-u Gerel entries.</em>
    </p>
  </div>

<?php endif; ?>

</main>

</div><!-- /.page-shell -->

<script>
// Scroll-spy: highlight the sidebar link for the visible source section
(function () {
    const sections = document.querySelectorAll('.source-section[id]');
    if (!sections.length) return;
    const navLinks = document.querySelectorAll('.dict-nav a[href^="#src-"]');
    if (!navLinks.length) return;

    const linkMap = {};
    navLinks.forEach(a => { linkMap[a.getAttribute('href').slice(1)] = a; });

    let current = null;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                if (current) current.classList.remove('active');
                current = linkMap[e.target.id] || null;
                if (current) current.classList.add('active');
            }
        });
    }, { rootMargin: '-10% 0px -80% 0px', threshold: 0 });

    sections.forEach(s => observer.observe(s));
})();
</script>

<script src="EwtsConverter.js"></script>
<script>
(function () {
  if (typeof jsEWTS === 'undefined') return;

  var query    = <?= json_encode($query) ?>;
  var queryUni = query ? jsEWTS.fromWylie(query) : '';

  function reEscape(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  document.querySelectorAll('.tib-word').forEach(function (el) {
    var wylie = (el.dataset.wylie || el.textContent).trim();
    if (!wylie) return;
    var uni = jsEWTS.fromWylie(wylie);
    if (!uni) return;
    el.classList.add('tib-unicode');

    if (!queryUni) {
      el.textContent = uni;
      return;
    }

    // Split the Unicode result on the Unicode query and wrap matches in <mark>
    var re    = new RegExp('(' + reEscape(queryUni) + ')', 'g');
    var parts = uni.split(re);
    el.textContent = '';
    var frag = document.createDocumentFragment();
    parts.forEach(function (part) {
      if (part === queryUni) {
        var mark = document.createElement('mark');
        mark.textContent = part;
        frag.appendChild(mark);
      } else {
        frag.appendChild(document.createTextNode(part));
      }
    });
    el.appendChild(frag);
  });
})();
</script>
</body>
</html>