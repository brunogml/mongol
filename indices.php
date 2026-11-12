<?php
ini_set('memory_limit', '256M');
/**
 * indices.php — Search in definitions
 * ------------------------------------
 * Standalone page for full-text search across the pre-built definition
 * indices produced by extractdict.py (indices/def_index_<dico>.json).
 *
 * Query parameters
 *   dq        search string
 *   dqwhole   1 = whole-word match only
 *   dqd[]     dico keys to restrict to (omit = all)
 */

// ── Configuration — mirrors index.php ────────────────────────────
$INDICES_DIR = __DIR__ . '/indices';
$DICO_ORDER  = ['lessing','kowalewski','schmidt','boberg','bawden','vietze',
                 'chinese','chinese2','lamrd','cevel','tibetan','lk4','saran','raghu','mostaert'];
$DICO_LABELS = [
    'lessing'    => 'Lessing',
    'kowalewski' => 'Kowalewski',
    'schmidt'    => 'Schmidt',
    'boberg'     => 'Boberg',
    'chinese'    => '新蒙汉词典',
    'chinese2'   => '蒙汉词典',
    'lamrd'      => 'Большой словарь',
    'bawden'     => 'Bawden',
    'vietze'     => 'Vietze',
    'tibetan'    => 'Mahāvyutpatti',
    'lk4'        => 'Lokesh Chandra',
    'saran'      => 'Saran-u Gerel',
    'raghu'      => 'Raghu Vira',
    'mostaert'   => 'Mostaert',
    'cevel'      => 'Cevel',
];

// ── Helpers ───────────────────────────────────────────────────────
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Search pre-built JSON indices.
 * $dicos: restrict to these keys; empty = all.
 * $matchWhole: require word boundary around match.
 */
function searchDefinitions(
    string $query,
    string $indicesDir,
    bool   $matchWhole = false,
    array  $dicos      = []
): array {
    $query = trim($query);
    if ($query === '' || !is_dir($indicesDir)) return [];
    $qLower  = mb_strtolower($query, 'UTF-8');
    $pattern = '/(?<![^\W_])\Q' . preg_quote($qLower, '/') . '\E(?![^\W_])/u';
    $hits    = [];
    $seen    = [];

    $matchStr = function (string $text) use ($qLower, $matchWhole, $pattern): bool {
        $lower = mb_strtolower($text, 'UTF-8');
        return $matchWhole
            ? (bool) preg_match($pattern, $lower)
            : mb_strpos($lower, $qLower, 0, 'UTF-8') !== false;
    };

    foreach (glob($indicesDir . '/def_index_*.json') ?: [] as $file) {
        $dico = preg_replace('/^def_index_|\.json$/', '', basename($file));
        if ($dicos && !in_array($dico, $dicos, true)) continue;
        $rows = json_decode(file_get_contents($file), true) ?? [];
        foreach ($rows as $row) {
            $key = ($row['ref'] ?? '') . '|' . $dico;
            if (isset($seen[$key])) continue;

            $defMatched  = $matchStr($row['def'] ?? '');
            // Collect matching examples (each has unicode, transcript, def)
            $matchedExamples = [];
            foreach ($row['examples'] ?? [] as $ex) {
                if ($matchStr($ex['def'] ?? '')) {
                    $matchedExamples[] = $ex;
                }
            }

            if ($defMatched || $matchedExamples) {
                $hits[]     = array_merge($row, [
                    'dico'             => $dico,
                    'def_matched'      => $defMatched,
                    'matched_examples' => $matchedExamples,
                ]);
                $seen[$key] = true;
            }
        }
    }
    return $hits;
}

/** Wrap every occurrence of $query in <mark> tags (case-insensitive). */
function highlightDef(string $text, string $query): string {
    if ($query === '') return h($text);
    return preg_replace_callback(
        '/' . preg_quote($query, '/') . '/iu',
        fn($m) => '<mark>' . h($m[0]) . '</mark>',
        h($text)
    );
}

// ── Dispatch ─────────────────────────────────────────────────────
$dq      = isset($_GET['dq'])      ? trim((string)$_GET['dq'])  : '';
$dqWhole = isset($_GET['dqwhole']) && $_GET['dqwhole'] === '1';
$dqDicos = (isset($_GET['dqd']) && is_array($_GET['dqd']))
           ? array_values(array_filter(array_map('strval', $_GET['dqd'])))
           : [];

// Discover available index files, sorted by DICO_ORDER
$availIdx = [];
foreach (glob($INDICES_DIR . '/def_index_*.json') ?: [] as $f) {
    $k = preg_replace('/^def_index_|\.json$/', '', basename($f));
    $availIdx[$k] = $DICO_LABELS[$k] ?? ucfirst($k);
}
$orderedIdx = [];
foreach ($DICO_ORDER as $k) { if (isset($availIdx[$k])) $orderedIdx[$k] = $availIdx[$k]; }
foreach ($availIdx as $k => $v) { if (!isset($orderedIdx[$k])) $orderedIdx[$k] = $v; }

$allSelected = empty($dqDicos);
$defResults  = [];
$byRef       = [];

if ($dq !== '') {
    $defResults = searchDefinitions($dq, $INDICES_DIR, $dqWhole, $dqDicos);
    foreach ($defResults as $hit) { $byRef[$hit['ref']][] = $hit; }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Search in definitions<?= $dq !== '' ? ' · ' . h($dq) : '' ?> — Mongolian Lexicography</title>
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
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
      background: var(--paper); color: var(--ink);
      font-family: "Noto Serif", Georgia, serif;
      font-size: 16px; line-height: 1.55; min-height: 100vh;
  }

  /* ── Masthead ── */
  .masthead {
      border-bottom: 1px solid var(--rule);
      background: var(--paper-2);
      padding: 1.4rem 0 1.2rem;
  }
  .masthead-inner {
      max-width: 900px; margin: 0 auto; padding: 0 1.5rem;
      display: flex; align-items: center; gap: 1.2rem; flex-wrap: wrap;
  }
  .brand {
      display: flex; align-items: center; gap: .9rem;
      text-decoration: none; color: inherit; flex-shrink: 0;
  }
  .soyombo { height: 54px; width: auto; display: block; }
  .brand-text {
      font-family: "EB Garamond", serif;
      font-size: 1.15rem; line-height: 1.2; font-weight: 500; color: var(--ink);
  }
  .brand-sub {
      display: block; font-style: italic; font-weight: 400;
      font-size: .88em; color: var(--ink-soft);
  }
  .back-link {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .97rem; color: var(--ink-soft); text-decoration: none;
      border-bottom: 1px dotted var(--rule); transition: color .12s, border-color .12s;
      margin-left: auto;
  }
  .back-link:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Main layout ── */
  main {
      max-width: 900px; margin: 2rem auto 4rem; padding: 0 1.5rem;
  }
  .page-title {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1.5rem; color: var(--ink);
      margin: 0 0 1.4rem; letter-spacing: .01em;
  }

  /* ── Search form ── */
  .def-search-form {
      display: flex; gap: .4rem; margin-bottom: .6rem;
  }
  .def-search-form input[type=search] {
      flex: 1; border: 1px solid var(--rule); background: #fff;
      font: inherit; font-size: 1.05rem; padding: .45rem .8rem;
      border-radius: 2px; color: var(--ink);
  }
  .def-search-form input[type=search]:focus { outline: none; border-color: var(--accent); }
  .def-search-form button {
      border: 0; background: var(--accent); color: #fff;
      font: inherit; font-weight: 600; font-size: .78rem;
      letter-spacing: .04em; text-transform: uppercase;
      padding: .45rem 1.2rem; border-radius: 2px; cursor: pointer;
      transition: background .15s;
  }
  .def-search-form button:hover { background: #143d6e; }

  /* ── Options row ── */
  .def-search-opts {
      display: flex; align-items: center; gap: .9rem; margin-bottom: 1rem;
      font-family: "EB Garamond", serif; font-size: .9rem; color: var(--ink-soft);
  }
  .def-search-opts label { display: flex; align-items: center; gap: .3rem; cursor: pointer; }

  /* ── Dictionary filter ── */
  .def-dico-filter {
      display: flex; flex-wrap: wrap; gap: .35rem .5rem;
      margin-bottom: 1.4rem; padding: .7rem .85rem;
      background: var(--paper-2); border: 1px solid var(--rule); border-radius: 2px;
  }
  .def-dico-filter-label {
      width: 100%; font-family: "EB Garamond", serif; font-size: .72rem;
      font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
      color: var(--ink-soft); margin-bottom: .25rem;
  }
  .def-dico-chk {
      display: inline-flex; align-items: center; gap: .25rem;
      font-family: "EB Garamond", serif; font-size: .92rem; color: var(--ink-soft);
      cursor: pointer; padding: .18rem .5rem;
      border: 1px solid var(--rule); border-radius: 2px;
      background: var(--paper); transition: border-color .12s, color .12s, background .12s;
      user-select: none;
  }
  .def-dico-chk input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
  .def-dico-chk.checked { background: var(--accent); border-color: var(--accent); color: #fff; }
  .def-dico-filter-actions {
      width: 100%; display: flex; gap: .5rem; margin-top: .35rem;
  }
  .def-dico-filter-actions button {
      border: 1px solid var(--rule); background: transparent; color: var(--ink-soft);
      font-family: "EB Garamond", serif; font-size: .84rem; padding: .12rem .55rem;
      border-radius: 2px; cursor: pointer; transition: color .12s, border-color .12s;
  }
  .def-dico-filter-actions button:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Results ── */
  mark { background: #fde68a; color: inherit; border-radius: 2px; padding: 0 .05em; }
  .def-results-header {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: 1rem; color: var(--ink-soft);
      border-bottom: 1px solid var(--rule);
      padding-bottom: .5rem; margin-bottom: .9rem;
      display: flex; justify-content: space-between; align-items: baseline;
  }
  .def-results-header .count { font-size: .85rem; }
  .no-results { font-style: italic; color: var(--ink-soft); padding: 1.5rem 0; }
  .def-result-list { list-style: none; padding: 0; margin: 0; }
  .def-result-list li { border-bottom: 1px dotted var(--rule); padding: .85rem .25rem; }
  .def-result-list li:last-child { border-bottom: none; }

  .def-result-hw {
      display: flex; align-items: center; gap: .65rem;
      margin-bottom: .4rem; flex-wrap: wrap;
  }
  .def-result-hw .uni {
      font-family: "Mongolian Baiti", serif; font-size: 1.6rem; line-height: 1.1;
      writing-mode: vertical-lr; -webkit-writing-mode: vertical-lr; color: var(--ink);
  }
  .def-result-hw .hw-text { display: flex; flex-direction: column; gap: .05rem; }
  .def-result-hw a.hw-link {
      font-family: "EB Garamond", serif; font-weight: 600;
      font-size: 1.12rem; color: var(--accent); text-decoration: none;
  }
  .def-result-hw a.hw-link:hover { text-decoration: underline; }
  .def-result-hw .cyr {
      font-family: "Noto Serif", serif; font-size: .93rem; color: var(--ink-soft);
  }
  .def-result-hw .refid {
      font-family: "Courier New", monospace; font-size: .74rem; color: var(--ink-soft);
  }
  .def-result-badges { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center; }
  .def-result-dico-badge {
      font-family: "EB Garamond", serif; font-size: .72rem;
      letter-spacing: .07em; text-transform: uppercase;
      color: #fff; background: var(--accent-2);
      padding: 2px 8px; border-radius: 2px; white-space: nowrap;
  }
  .def-result-entry-link {
      font-family: "EB Garamond", serif; font-size: .84rem; font-style: italic;
      color: var(--ink-soft); text-decoration: none;
      border: 1px solid var(--rule); border-radius: 2px; padding: .12rem .55rem;
      margin-left: auto;
      transition: color .12s, border-color .12s, background .12s;
  }
  .def-result-entry-link:hover { color: var(--accent); border-color: var(--accent); background: #fff; }

  .def-result-snippet {
      font-family: "Noto Serif", serif; font-size: .93rem;
      color: var(--ink-soft); line-height: 1.65;
      white-space: pre-wrap; word-wrap: break-word;
      display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical;
      overflow: hidden;
  }
  .def-result-snippet.expanded { display: block; }
  .def-result-toggle {
      display: none; font-family: "EB Garamond", serif; font-style: italic;
      font-size: .84rem; color: var(--accent-2);
      background: none; border: none; padding: 0; cursor: pointer; margin-top: .2rem;
  }
  .def-result-toggle.visible { display: inline; }
  .def-sublabel {
      font-family: "EB Garamond", serif; font-style: italic;
      color: var(--ink-soft); font-size: .88rem; margin-right: .4em;
  }
  /* matched examples */
  .def-result-examples {
      margin-top: .5rem; padding: .45rem .8rem;
      border-left: 1px dashed var(--rule);
      font-size: .9rem;
  }
  .def-result-examples-label {
      font-family: "EB Garamond", serif; font-size: .68rem; font-weight: 600;
      letter-spacing: .1em; text-transform: uppercase;
      color: var(--ink-soft); margin-bottom: .3rem;
  }
  .def-result-example {
      display: flex; gap: .6rem; align-items: baseline;
      padding: .2rem 0; color: var(--ink-soft);
      border-top: 1px dotted var(--paper-2);
  }
  .def-result-example:first-of-type { border-top: none; }
  .def-result-example .ex-uni {
      font-family: "Mongolian Baiti", serif; font-size: 1.3rem;
      line-height: 1.1; color: var(--ink); flex-shrink: 0;
      writing-mode: vertical-lr; -webkit-writing-mode: vertical-lr;
  }
  .def-result-example .ex-def { flex: 1; line-height: 1.55; }

  @media (max-width: 600px) {
      .masthead-inner { gap: .8rem; }
      .soyombo { height: 44px; }
      main { margin-top: 1.25rem; padding: 0 1rem; }
      .def-result-entry-link { margin-left: 0; }
  }
</style>
</head>
<body>

<header class="masthead">
  <div class="masthead-inner">
    <a class="brand" href="index.php">
      <img class="soyombo" src="soyombo.png" alt="" width="36" height="54">
      <span class="brand-text">Mongolian Lexicography<span class="brand-sub">Search in definitions</span></span>
    </a>
    <a class="back-link" href="index.php">← Dictionary</a>
  </div>
</header>

<main>
  <h1 class="page-title">Search in definitions</h1>

  <form class="def-search-form" method="get" action="" id="def-search-form">
    <input type="search" name="dq" id="def-search-input"
           value="<?= h($dq) ?>"
           placeholder="English word, Russian phrase, Chinese character…"
           autocomplete="off" spellcheck="false"
           <?= $dq === '' ? 'autofocus' : '' ?>>
    <?php if ($dqWhole): ?><input type="hidden" name="dqwhole" value="1"><?php endif; ?>
    <?php if (!$allSelected): foreach ($dqDicos as $dk): ?>
      <input type="hidden" name="dqd[]" value="<?= h($dk) ?>">
    <?php endforeach; endif; ?>
    <button type="submit">Search</button>
  </form>

  <div class="def-search-opts">
    <label title="Match only complete words (e.g. 'arm' won't match 'army')">
      <input type="checkbox" id="dqwhole-chk" <?= $dqWhole ? 'checked' : '' ?>>
      whole word only
    </label>
    <?php if (!$orderedIdx): ?>
      <span style="color:#b04545">No indices found — run extractdict.py first</span>
    <?php endif; ?>
  </div>

  <?php if ($orderedIdx): ?>
  <div class="def-dico-filter" id="def-dico-filter">
    <span class="def-dico-filter-label">Dictionaries</span>
    <?php foreach ($orderedIdx as $dk => $dlabel): ?>
      <label class="def-dico-chk <?= ($allSelected || in_array($dk, $dqDicos, true)) ? 'checked' : '' ?>"
             id="dfl-<?= h($dk) ?>">
        <input type="checkbox" value="<?= h($dk) ?>"
               <?= ($allSelected || in_array($dk, $dqDicos, true)) ? 'checked' : '' ?>>
        <?= h($dlabel) ?>
      </label>
    <?php endforeach; ?>
    <div class="def-dico-filter-actions">
      <button type="button" onclick="defDicoAll()">all</button>
      <button type="button" onclick="defDicoNone()">none</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($dq !== ''): ?>
    <?php if (!$defResults): ?>
      <p class="no-results">No definitions contain &ldquo;<?= h($dq) ?>&rdquo;<?= $dqWhole ? ' (whole word)' : '' ?><?= !$allSelected ? ' in the selected dictionaries' : '' ?>.</p>
    <?php else: ?>
      <div class="def-results-header">
        <span>Definitions containing <em>&ldquo;<?= h($dq) ?>&rdquo;</em><?= $dqWhole ? ' <small style="font-size:.8em;opacity:.7">(whole word)</small>' : '' ?></span>
        <span class="count"><?= count($defResults) ?> <?= count($defResults) === 1 ? 'match' : 'matches' ?> · <?= count($byRef) ?> <?= count($byRef) === 1 ? 'entry' : 'entries' ?></span>
      </div>
      <ul class="def-result-list">
      <?php foreach ($byRef as $hitRef => $hits):
          $first = $hits[0];
          $hw    = $first['unicode']    ?? '';
          $cyr   = $first['cyrillic']   ?? '';
          $tr    = $first['transcript'] !== '' ? $first['transcript'] : ($first['transcript2'] ?? '');
          $entryUrl = 'index.php?ref=' . urlencode($hitRef) . ($dq !== '' ? '&dq=' . urlencode($dq) : '');
      ?>
        <li>
          <div class="def-result-hw">
            <?php if ($hw !== ''): ?>
              <span class="uni"><?= h($hw) ?></span>
            <?php endif; ?>
            <div class="hw-text">
              <a class="hw-link" href="<?= h($entryUrl) ?>"><?= h($tr ?: $cyr ?: $hitRef) ?></a>
              <?php if ($cyr !== '' && $tr !== ''): ?>
                <span class="cyr"><?= h($cyr) ?></span>
              <?php endif; ?>
              <span class="refid"><?= h($hitRef) ?></span>
            </div>
            <div class="def-result-badges">
              <?php foreach ($hits as $hit):
                  $dicoLabel = $DICO_LABELS[$hit['dico']] ?? ucfirst($hit['dico']); ?>
                <span class="def-result-dico-badge"><?= h($dicoLabel) ?></span>
              <?php endforeach; ?>
            </div>
            <a class="def-result-entry-link" href="<?= h($entryUrl) ?>">open entry →</a>
          </div>

          <?php foreach ($hits as $i => $hit):
              $sublabel       = '';
              $ic = $hit['item_cyrillic']   ?? '';
              $it = $hit['item_transcript'] ?? '';
              if ($ic !== '' && $ic !== $cyr) $sublabel = $ic;
              elseif ($it !== '' && $it !== $tr) $sublabel = $it;
              $sid            = 'ds' . preg_replace('/[^A-Za-z0-9]/', '_', $hitRef) . '_' . $i;
              $defMatched     = $hit['def_matched']      ?? true;
              $matchedExamples = $hit['matched_examples'] ?? [];
          ?>
          <?php if ($defMatched): ?>
          <div class="def-result-snippet" id="snip-<?= h($sid) ?>">
            <?php if ($sublabel !== ''): ?><span class="def-sublabel"><?= h($sublabel) ?></span><?php endif; ?>
            <?= highlightDef($hit['def'], $dq) ?>
          </div>
          <button class="def-result-toggle" id="tog-<?= h($sid) ?>"
                  onclick="defToggle('<?= h($sid) ?>')">show more ▾</button>
          <?php elseif ($sublabel !== ''): ?>
          <div style="font-family:'EB Garamond',serif;font-style:italic;color:var(--ink-soft);font-size:.88rem;margin-bottom:.2rem"><?= h($sublabel) ?></div>
          <?php endif; ?>
          <?php if ($matchedExamples): ?>
          <div class="def-result-examples">
            <div class="def-result-examples-label">Matching examples</div>
            <?php foreach ($matchedExamples as $ex): ?>
            <div class="def-result-example">
              <?php if (!empty($ex['unicode'])): ?>
                <span class="ex-uni"><?= h($ex['unicode']) ?></span>
              <?php endif; ?>
              <span class="ex-def"><?= highlightDef($ex['def'], $dq) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>

</main>

<script>
// ── Dictionary filter ────────────────────────────────────────────
var form   = document.getElementById('def-search-form');
var filter = document.getElementById('def-dico-filter');

// Whole-word checkbox
(function () {
  var chk = document.getElementById('dqwhole-chk');
  if (!chk || !form) return;
  chk.addEventListener('change', function () {
    var h = form.querySelector('input[name="dqwhole"]');
    if (chk.checked) {
      if (!h) { h = document.createElement('input'); h.type='hidden'; h.name='dqwhole'; h.value='1'; form.appendChild(h); }
    } else { if (h) h.remove(); }
    form.submit();
  });
})();

// Pill toggles — submit on each change
if (filter && form) {
  filter.querySelectorAll('.def-dico-chk input[type=checkbox]').forEach(function (inp) {
    inp.addEventListener('change', function () { defDicoApply(); });
  });
}

function defDicoApply() {
  if (!form || !filter) return;
  form.querySelectorAll('input[name="dqd[]"]').forEach(function (el) { el.remove(); });
  var checked = [];
  filter.querySelectorAll('.def-dico-chk').forEach(function (label) {
    var inp = label.querySelector('input[type=checkbox]');
    label.classList.toggle('checked', inp.checked);
    if (inp.checked) checked.push(inp.value);
  });
  var total = filter.querySelectorAll('.def-dico-chk input[type=checkbox]').length;
  if (checked.length > 0 && checked.length < total) {
    checked.forEach(function (v) {
      var h = document.createElement('input');
      h.type='hidden'; h.name='dqd[]'; h.value=v;
      form.appendChild(h);
    });
  }
  form.submit();
}

function defDicoAll() {
  if (filter) filter.querySelectorAll('.def-dico-chk input').forEach(function (i) { i.checked=true; });
  defDicoApply();
}
function defDicoNone() {
  if (filter) filter.querySelectorAll('.def-dico-chk input').forEach(function (i) { i.checked=false; });
  defDicoApply();
}

// Show "show more" on clipped snippets
document.querySelectorAll('.def-result-snippet').forEach(function (el) {
  if (el.scrollHeight > el.clientHeight + 4) {
    var btn = document.getElementById('tog-' + el.id.replace('snip-', ''));
    if (btn) btn.classList.add('visible');
  }
});

function defToggle(id) {
  var snip = document.getElementById('snip-' + id);
  var btn  = document.getElementById('tog-'  + id);
  if (!snip || !btn) return;
  var exp = snip.classList.toggle('expanded');
  btn.textContent = exp ? 'show less ▴' : 'show more ▾';
}
</script>

</body>
</html>