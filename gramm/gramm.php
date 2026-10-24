<?php
/**
 * Mongolian Grammar — Poppe browser
 * -----------------------------------
 * Displays and explores poppe.xml.
 *
 * URL modes:
 *   gramm.php              → landing / search
 *   gramm.php?ref=42       → single paragraph view
 *   gramm.php?q=ablative   → full-text search results
 *   gramm.php?section=Cases → all paragraphs in a top-level section
 */

// ── Configuration ────────────────────────────────────────────────
$POPPE_XML = __DIR__ . '/poppe.xml';

// ── XML loader (singleton) ───────────────────────────────────────
function xmlDoc(): SimpleXMLElement {
    static $doc = null;
    if ($doc === null) {
        global $POPPE_XML;
        $doc = simplexml_load_file($POPPE_XML);
        if ($doc === false) {
            http_response_code(500);
            die('Could not load poppe.xml');
        }
    }
    return $doc;
}

// ── Grammar helpers ───────────────────────────────────────────────
function getGrammars(): array {
    $rows = [];
    foreach (xmlDoc()->grammars->grammar as $g) {
        $rows[] = [
            'id'     => (int)$g['id'],
            'slug'   => (string)$g['slug'],
            'author' => (string)$g->author,
            'title'  => (string)$g->title,
            'year'   => $g->year ? (int)$g->year : null,
            'note'   => (string)$g->note,
        ];
    }
    usort($rows, fn($a, $b) => ($a['year'] ?? 0) <=> ($b['year'] ?? 0) ?: $a['id'] <=> $b['id']);
    return $rows;
}

function currentGrammarId(): int {
    static $gid = null;
    if ($gid === null) {
        $slug = trim((string)($_GET['grammar'] ?? ''));
        if ($slug !== '') {
            foreach (xmlDoc()->grammars->grammar as $g) {
                if ((string)$g['slug'] === $slug) {
                    $gid = (int)$g['id'];
                    break;
                }
            }
            if ($gid === null) $gid = 1;
        } else {
            $gid = 1;
        }
    }
    return $gid;
}

function currentGrammarSlug(): string {
    $gid = currentGrammarId();
    foreach (xmlDoc()->grammars->grammar as $g) {
        if ((int)$g['id'] === $gid) return (string)$g['slug'];
    }
    return 'poppe';
}

function grammarUrl(string $slug, array $extra = []): string {
    $params = array_merge(['grammar' => $slug], $extra);
    return 'gramm.php?' . http_build_query($params);
}

// ── Authentication (shared with index.php) ───────────────────────
$LOGIN_FILE = __DIR__ . '/login.txt';

function loadLogins(string $path): array {
    $logins = [];
    if (!file_exists($path)) return $logins;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode('###', $line, 2);
        if (count($parts) === 2) {
            $logins[trim($parts[0])] = trim($parts[1]);
        }
    }
    return $logins;
}

session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: gramm.php');
    exit;
}

$loginError = '';
if (isset($_POST['login_user'])) {
    $logins   = loadLogins($LOGIN_FILE);
    $user     = trim((string)($_POST['login_user'] ?? ''));
    $password = (string)($_POST['login_pass'] ?? '');
    if (isset($logins[$user]) && $logins[$user] === $password) {
        $_SESSION['auth_user'] = $user;
        $dest = strtok($_SERVER['REQUEST_URI'] ?? 'gramm.php', '?');
        $qs   = $_GET;
        unset($qs['logout']);
        header('Location: ' . $dest . ($qs ? '?' . http_build_query($qs) : ''));
        exit;
    }
    $loginError = 'Incorrect username or password.';
}

$isLoggedIn = !empty($_SESSION['auth_user']);
$authUser   = $isLoggedIn ? (string)$_SESSION['auth_user'] : '';

// ── Helpers ──────────────────────────────────────────────────────
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Parse inline markup within a paragraph body string.
 *
 * <uig rom="mal">ᠮᠠᠯ</uig>  → script span + italic romanization
 * <i>text</i>                 → <em class="para-em">text</em>
 * <mng>transcript</mng>       → <span class="mng-tr">…</span>
 * §NNN                        → <a href="gramm.php?ref=NNN">§ NNN</a>
 */
function renderParagraph(string $body): string {
    // 1. <uig rom="romanization">script</uig>
    $body = preg_replace_callback(
        '/<uig\s+rom="([^"]*)">(.*?)<\/uig>/us',
        function ($m) {
            $rom    = trim($m[1]);
            $script = trim($m[2]);
            $out    = '';
            if ($script !== '') {
                $tokens = preg_split('/\s+/u', $script, -1, PREG_SPLIT_NO_EMPTY);
                $words = [];
                foreach ($tokens as $tok) {
                    if ($words !== [] &&
                        preg_match('/^[\x{180E}\x{1820}-\x{1827}]{1,3}$/u', $tok)) {
                        $words[count($words) - 1] .= ' ' . $tok;
                    } else {
                        $words[] = $tok;
                    }
                }
                if (count($words) > 5) {
                    $spans = array_map(fn($w) =>
                        '<span class="mng-word">' . h($w) . '</span>', $words);
                    $out .= '<span class="mng-script mng-multi">' . implode('', $spans) . '</span>';
                } else {
                    $out .= '<span class="mng-script">' . h($script) . '</span>';
                }
            }
            if ($rom !== '') {
                $out .= ' <em class="mng-rom">' . h($rom) . '</em>';
            }
            return $out;
        },
        $body
    );

    // 2. Italic: <i>…</i>
    $body = preg_replace_callback(
        '/<i>(.*?)<\/i>/us',
        function ($m) {
            return '<em class="para-em">' . h($m[1]) . '</em>';
        },
        $body
    );

    // 3. <mng>…</mng> inline elements
    $body = preg_replace_callback(
        '/<mng>(.*?)<\/mng>/us',
        function ($m) {
            return '<span class="mng-tr">' . h(html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')) . '</span>';
        },
        $body
    );

    // 4. <tbl>…</tbl> table blocks
    $body = preg_replace_callback(
        '/<tbl>(.*?)<\/tbl>/us',
        function ($m) {
            $rows = array_filter(array_map('trim', explode(';;', $m[1])));
            if (!$rows) return '';
            $html  = '<table class="po-table">';
            $first = true;
            foreach ($rows as $row) {
                $cells = array_map('trim', explode('|', $row));
                if ($first) {
                    $html  .= '<thead><tr>';
                    foreach ($cells as $c) {
                        $html .= '<th>' . renderParagraph($c) . '</th>';
                    }
                    $html  .= '</tr></thead><tbody>';
                    $first  = false;
                } else {
                    $html .= '<tr>';
                    foreach ($cells as $c) {
                        $html .= '<td>' . renderParagraph($c) . '</td>';
                    }
                    $html .= '</tr>';
                }
            }
            $html .= '</tbody></table>';
            return $html;
        },
        $body
    );

    // 5. <img src="…"> inline images from img/ folder
    $body = preg_replace_callback(
        '/<img\s+src="([^"]*)"(?:\s+alt="([^"]*)")?[^>]*>/us',
        function ($m) {
            $src = trim($m[1]);
            $alt = isset($m[2]) ? trim($m[2]) : '';
            $src = 'img/' . basename($src);
            return '<figure class="po-figure">'
                 . '<img class="po-img" src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy">'
                 . ($alt !== '' ? '<figcaption class="po-figcaption">' . h($alt) . '</figcaption>' : '')
                 . '</figure>';
        },
        $body
    );

    // 6. Cross-reference links: §123 or § 123
    $body = preg_replace_callback(
        '/§\s*([0-9]+[a-z]?)/u',
        function ($m) {
            $slug = isset($_GET['grammar']) ? '&grammar=' . urlencode($_GET['grammar']) : '';
            return '<a class="para-ref" href="gramm.php?ref=' . urlencode($m[1]) . $slug . '">§&thinsp;' . h($m[1]) . '</a>';
        },
        $body
    );

    return $body;
}

// ── XML data access ──────────────────────────────────────────────

/**
 * Return all entry SimpleXMLElement nodes for a given grammar_id,
 * in document order (which is id order from the export).
 */
function allEntriesForGrammar(int $gid): array {
    $result = [];
    foreach (xmlDoc()->entries->entry as $e) {
        if ((int)$e['grammar_id'] === $gid) {
            $result[] = $e;
        }
    }
    return $result;
}

/**
 * Return cross-references for a given XML entry node, with linked entry details.
 * Mirrors the old getCrossRefsForEntry() output shape.
 */
function getCrossRefsForEntry(SimpleXMLElement $entryNode): array {
    if (!$entryNode->cross_references->cross_reference) return [];

    $doc    = xmlDoc();
    $result = [];

    // Build a quick id→entry index on first use
    static $idIndex = null;
    if ($idIndex === null) {
        $idIndex = [];
        foreach ($doc->entries->entry as $e) {
            $idIndex[(int)$e['id']] = $e;
        }
    }
    // Build grammar id→grammar info index
    static $gramIndex = null;
    if ($gramIndex === null) {
        $gramIndex = [];
        foreach ($doc->grammars->grammar as $g) {
            $gramIndex[(int)$g['id']] = [
                'author' => (string)$g->author,
                'year'   => $g->year ? (int)$g->year : null,
                'slug'   => (string)$g['slug'],
            ];
        }
    }

    foreach ($entryNode->cross_references->cross_reference as $cr) {
        $otherId = (int)$cr['other_id'];
        $note    = (string)($cr['note'] ?? '');
        if (!isset($idIndex[$otherId])) continue;
        $other = $idIndex[$otherId];
        $gid2  = (int)$other['grammar_id'];
        $gi    = $gramIndex[$gid2] ?? ['author' => '?', 'year' => null, 'slug' => ''];
        $result[] = [
            'note'   => $note,
            'ref'    => (string)$other['ref'],
            'ti2'    => (string)$other->title2,
            'ti'     => (string)$other->title,
            'author' => $gi['author'],
            'year'   => $gi['year'],
            'slug'   => $gi['slug'],
        ];
    }

    // Sort: by year ASC, then entry id ASC
    usort($result, function($a, $b) {
        $ya = $a['year'] ?? 0; $yb = $b['year'] ?? 0;
        return $ya <=> $yb;
    });

    return $result;
}

/**
 * Find the single XML entry node for a given ref + grammar_id.
 */
function findEntryNode(string $ref, int $gid): ?SimpleXMLElement {
    foreach (xmlDoc()->entries->entry as $e) {
        if ((string)$e['ref'] === $ref && (int)$e['grammar_id'] === $gid) {
            return $e;
        }
    }
    return null;
}

/**
 * Turn an XML entry node into the entry array used by templates.
 */
function hydrateEntryNode(SimpleXMLElement $e): array {
    $ti  = (string)$e->title;
    $ti2 = (string)$e->title2;

    $navGroup  = (string)$e->navigation->group;
    $navSub    = (string)$e->navigation->sub    ?: null;
    $navSubSub = (string)$e->navigation->subsub ?: null;

    $parts = array_map('trim', array_filter([$navGroup, $navSub, $navSubSub], fn($v) => $v !== null && $v !== ''));

    $paragraphs = [];
    foreach ($e->paragraphs->paragraph as $p) {
        $paragraphs[] = (string)$p;
    }

    return [
        'id'         => (int)$e['id'],
        'ref'        => (string)$e['ref'],
        'ti'         => $ti,
        'ti2'        => $ti2,
        'nav_group'  => $navGroup,
        'nav_sub'    => $navSub,
        'nav_subsub' => $navSubSub,
        'section'    => $ti,
        'breadcrumb' => ($ti2 !== '') ? $ti2 : $ti,
        'parts'      => $parts,
        'paragraphs' => $paragraphs,
        'cross_refs' => getCrossRefsForEntry($e),
    ];
}

/**
 * Returns the hydrated entry array for a given ref, or null.
 */
function getEntryByRef(string $ref): ?array {
    $node = findEntryNode($ref, currentGrammarId());
    return $node ? hydrateEntryNode($node) : null;
}

/**
 * Fetch all entries whose navigation path starts with $section prefix.
 */
function getEntriesBySection(string $section): array {
    $parts = array_map('trim', explode(' - ', $section));
    $depth = count($parts);
    $gid   = currentGrammarId();

    $results = [];
    foreach (xmlDoc()->entries->entry as $e) {
        if ((int)$e['grammar_id'] !== $gid) continue;
        $g  = (string)$e->navigation->group;
        $s  = (string)$e->navigation->sub;
        $ss = (string)$e->navigation->subsub;
        $match = match($depth) {
            1 => $g === $parts[0],
            2 => $g === $parts[0] && $s === $parts[1],
            default => $g === $parts[0] && $s === ($parts[1] ?? '') && $ss === ($parts[2] ?? ''),
        };
        if ($match) {
            $results[] = hydrateEntryNode($e);
        }
    }
    return $results;
}

/**
 * Full-text search across title, title2, and paragraph text.
 * Mirrors FTS5 phrase search with a plain substring fallback.
 */
function searchEntries(string $query): array {
    $gid   = currentGrammarId();
    $qLow  = mb_strtolower(trim($query));
    $results = [];

    foreach (xmlDoc()->entries->entry as $e) {
        if ((int)$e['grammar_id'] !== $gid) continue;

        // Search title fields
        $haystack  = mb_strtolower((string)$e->title) . ' '
                   . mb_strtolower((string)$e->title2) . ' ';
        // Search paragraph text
        foreach ($e->paragraphs->paragraph as $p) {
            $haystack .= mb_strtolower((string)$p) . ' ';
        }

        if (mb_strpos($haystack, $qLow) !== false) {
            $results[] = hydrateEntryNode($e);
        }
        if (count($results) >= 200) break;
    }
    return $results;
}

/**
 * Get prev/next entry (by document order within the grammar).
 */
function getPrevNext(string $ref): array {
    $gid   = currentGrammarId();
    $nodes = allEntriesForGrammar($gid);

    $found = null;
    foreach ($nodes as $i => $e) {
        if ((string)$e['ref'] === $ref) { $found = $i; break; }
    }
    if ($found === null) return [null, null];

    $prev = ($found > 0)                  ? hydrateEntryNode($nodes[$found - 1]) : null;
    $next = ($found < count($nodes) - 1)  ? hydrateEntryNode($nodes[$found + 1]) : null;
    return [$prev, $next];
}

/**
 * Build the 3-level nav tree from the XML.
 */
function buildNavGroups(): array {
    $gid  = currentGrammarId();
    $tree = [];

    foreach (xmlDoc()->entries->entry as $e) {
        if ((int)$e['grammar_id'] !== $gid) continue;
        $g  = (string)$e->navigation->group;
        $s  = (string)$e->navigation->sub    ?: null;
        $ss = (string)$e->navigation->subsub ?: null;

        if ($g === '' || str_starts_with($g, 'manque')) continue;

        if (!isset($tree[$g])) {
            $tree[$g] = ['slug' => $g, 'count' => 0, 'subs' => []];
        }
        $tree[$g]['count']++;

        if ($s === null) continue;
        $sSlug = $g . ' - ' . $s;
        if (!isset($tree[$g]['subs'][$s])) {
            $tree[$g]['subs'][$s] = ['slug' => $sSlug, 'count' => 0, 'subsubs' => []];
        }
        $tree[$g]['subs'][$s]['count']++;

        if ($ss === null) continue;
        $ssSlug = $sSlug . ' - ' . $ss;
        if (!isset($tree[$g]['subs'][$s]['subsubs'][$ss])) {
            $tree[$g]['subs'][$s]['subsubs'][$ss] = ['slug' => $ssSlug, 'count' => 0];
        }
        $tree[$g]['subs'][$s]['subsubs'][$ss]['count']++;
    }
    return $tree;
}

/**
 * Render the cross-reference box for an entry array.
 */
function renderCrossRefs(array $entry): void {
    if (empty($entry['cross_refs'])) return;
    echo '<div class="crossref-box">';
    echo '<p class="crossref-box-title">See also in other grammars</p><ul>';
    foreach ($entry['cross_refs'] as $cr) {
        $href  = 'gramm.php?ref=' . urlencode($cr['ref']) . '&grammar=' . urlencode($cr['slug']);
        $label = htmlspecialchars($cr['ti2'] ?: $cr['ti'], ENT_QUOTES, 'UTF-8');
        $gram  = htmlspecialchars($cr['author'] . ' ' . (int)$cr['year'], ENT_QUOTES, 'UTF-8');
        $ref   = htmlspecialchars($cr['ref'], ENT_QUOTES, 'UTF-8');
        echo '<li>';
        echo '<a class="cr-ref" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">§' . $ref . '</a>';
        echo '<span class="cr-grammar">' . $gram . '</span>';
        echo '<span class="cr-label">' . $label . '</span>';
        if ($cr['note'] !== '') {
            echo '<span class="cr-note">— ' . htmlspecialchars($cr['note'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</li>';
    }
    echo '</ul></div>';
}

// ── Entry count helper (landing page) ────────────────────────────
function countEntriesForGrammar(int $gid): int {
    $n = 0;
    foreach (xmlDoc()->entries->entry as $e) {
        if ((int)$e['grammar_id'] === $gid) $n++;
    }
    return $n;
}

// ── Dispatch ──────────────────────────────────────────────────────
$query   = isset($_GET['q'])       ? trim((string)$_GET['q'])       : '';
$ref     = isset($_GET['ref'])     ? trim((string)$_GET['ref'])     : '';
$section = isset($_GET['section']) ? trim((string)$_GET['section']) : '';
$grammarSlug = currentGrammarSlug();
$grammars    = getGrammars();

$navGroups = buildNavGroups();

$currentEntry   = null;
$sectionEntries = [];
$searchResults  = [];
$prevEntry      = null;
$nextEntry      = null;

if ($ref !== '') {
    $currentEntry = getEntryByRef($ref);
    if ($currentEntry) {
        [$prevEntry, $nextEntry] = getPrevNext($ref);
    }
} elseif ($section !== '') {
    $sectionEntries = getEntriesBySection($section);
} elseif ($query !== '') {
    $searchResults = searchEntries($query);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mongolian Grammar — Poppe<?php
    if ($currentEntry)       echo ' · §' . h($currentEntry['ref']);
    elseif ($section !== '') echo ' · ' . h($section);
    elseif ($query !== '')   echo ' · ' . h($query);
?></title>
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
  .soyombo { height: 68px; width: auto; flex-shrink: 0; display: block; }
  .brand-text {
      font-family: "EB Garamond", serif;
      font-size: 1.35rem; line-height: 1.15;
      font-weight: 500; color: var(--ink);
  }
  .brand-sub {
      display: block; font-style: italic; font-weight: 400;
      font-size: .92em; color: var(--ink-soft);
  }
  .search-form {
      flex: 1; min-width: 260px;
      display: flex; gap: .5rem;
      background: #fff; border: 1px solid var(--rule);
      border-radius: 2px; padding: .25rem .25rem .25rem .8rem;
  }
  .search-form input[type=search] {
      flex: 1; border: 0; outline: 0; background: transparent;
      font: inherit; font-size: 1.05rem; padding: .35rem 0; color: var(--ink);
  }
  .search-form button {
      border: 0; background: var(--accent); color: #fff;
      font: inherit; font-weight: 600; letter-spacing: .04em;
      padding: .45rem 1.1rem; cursor: pointer;
      text-transform: uppercase; font-size: .78rem;
      border-radius: 1px; transition: background .15s;
  }
  .search-form button:hover { background: #143d6e; }

  /* ── Masthead user controls ── */
  .mast-user {
      display: flex; align-items: center; gap: .85rem;
      flex-shrink: 0;
      font-family: "EB Garamond", serif; font-size: .97rem;
  }
  .mast-home-link {
      display: inline-flex; align-items: center; gap: .25rem;
      color: var(--ink-soft); font-family: "EB Garamond", serif;
      font-size: .95rem; text-decoration: none;
      border-bottom: 1px dotted var(--rule);
      transition: color .12s;
  }
  .mast-home-link:hover { color: var(--accent); border-color: var(--accent); }
  .mast-username { color: var(--ink-soft); font-style: italic; }
  .mast-logout {
      color: var(--ink-soft); text-decoration: none;
      border-bottom: 1px dotted var(--rule); transition: color .12s;
  }
  .mast-logout:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Masthead sign-in dropdown ── */
  .mast-login-wrap { position: relative; }
  .mast-signin-btn {
      border: 1px solid var(--rule); background: transparent;
      color: var(--ink-soft);
      font-family: "EB Garamond", serif; font-size: .97rem; font-style: italic;
      padding: .3rem .7rem; cursor: pointer; border-radius: 2px;
      transition: color .12s, border-color .12s, background .12s;
  }
  .mast-signin-btn:hover,
  .mast-signin-btn[aria-expanded="true"] {
      color: var(--accent); border-color: var(--accent);
      background: rgba(28,79,139,.05);
  }
  .signin-dropdown {
      position: absolute; top: calc(100% + .5rem); right: 0;
      width: 240px; background: #fff; border: 1px solid var(--rule);
      border-radius: 3px; box-shadow: 0 4px 20px rgba(15,39,71,.12);
      padding: 1.1rem 1.1rem .9rem; z-index: 200;
  }
  .signin-dropdown[hidden] { display: none; }
  .signin-field { margin-bottom: .7rem; }
  .signin-field label {
      display: block; font-size: .7rem; font-weight: 600;
      letter-spacing: .1em; text-transform: uppercase;
      color: var(--ink-soft); margin-bottom: .25rem;
      font-style: normal; font-family: "EB Garamond", serif;
  }
  .signin-field input {
      width: 100%; border: 1px solid var(--rule);
      padding: .4rem .6rem; font: inherit; font-size: .95rem;
      background: var(--paper); border-radius: 2px; transition: border-color .12s;
  }
  .signin-field input:focus { outline: none; border-color: var(--accent); }
  .signin-submit {
      width: 100%; margin-top: .2rem;
      border: 0; background: var(--accent); color: #fff;
      font: inherit; font-weight: 600; letter-spacing: .05em;
      padding: .45rem .8rem; cursor: pointer;
      text-transform: uppercase; font-size: .76rem;
      border-radius: 2px; transition: background .15s;
  }
  .signin-submit:hover { background: #143d6e; }
  .signin-error {
      background: #fdf3f3; border-left: 2px solid #b04545;
      padding: .35rem .6rem; font-size: .85rem;
      color: #b04545; margin-bottom: .7rem; border-radius: 1px;
  }

  /* ── Page shell ── */
  .page-shell {
      max-width: 1060px; margin: 0 auto; padding: 0 1.5rem;
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: 2.5rem;
      align-items: start;
  }

  /* ── Left nav ── */
  .dict-nav {
      position: sticky; top: 1.75rem;
      padding-top: 2rem;
      max-height: calc(100vh - 3.5rem);
      overflow-y: auto;
  }
  .dict-nav-title {
      font-family: "EB Garamond", serif;
      font-size: .68rem; font-weight: 600;
      letter-spacing: .14em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 .6rem .1rem;
  }

  /* Accordion group */
  .nav-group { margin: 0 0 .15rem; }
  .nav-group-btn {
      width: 100%; text-align: left;
      background: none; border: none; border-left: 1px solid var(--rule);
      padding: .32rem .5rem .32rem .72rem;
      font-family: "EB Garamond", serif; font-size: .94rem; font-weight: 600;
      color: var(--ink); cursor: pointer; display: flex;
      align-items: center; justify-content: space-between;
      transition: background .12s, color .12s;
      line-height: 1.3;
      text-decoration: none;
  }
  .nav-group-btn:hover { background: rgba(255,255,255,.45); color: var(--accent); }
  .nav-group-btn.has-active { color: var(--accent); border-left-color: var(--accent); }
  .nav-group-arrow {
      font-size: .7em; margin-left: .4em; flex-shrink: 0;
      transition: transform .18s; display: inline-block;
  }
  .nav-group-btn[aria-expanded="true"] .nav-group-arrow { transform: rotate(90deg); }

  /* Subgroup list */
  .nav-sub {
      list-style: none; padding: 0; margin: 0;
      border-left: 1px solid var(--rule);
      overflow: hidden;
  }
  .nav-sub[hidden] { display: none; }
  .nav-sub li { margin: 0; }
  .nav-sub li a {
      display: block;
      padding: .24rem .55rem .24rem 1.1rem;
      font-family: "EB Garamond", serif; font-size: .88rem;
      color: var(--ink-soft); text-decoration: none;
      border-left: 2px solid transparent; margin-left: -1px;
      transition: color .12s, border-color .12s, background .12s;
      line-height: 1.3;
  }
  .nav-sub li a:hover {
      color: var(--ink); border-left-color: var(--rule);
      background: rgba(255,255,255,.45);
  }
  .nav-sub li a.active {
      color: var(--accent); font-weight: 500;
      border-left-color: var(--accent);
      background: rgba(28,79,139,.06);
  }

  /* ── Sub-accordion (level 2 → level 3) ── */
  .nav-sub-group { margin: 0; }
  .nav-sub-btn {
      display: flex; align-items: center; justify-content: space-between;
      width: 100%; padding: .24rem .45rem .24rem 1.1rem;
      font-family: "EB Garamond", serif; font-size: .88rem;
      color: var(--ink-soft); text-decoration: none;
      border-left: 2px solid transparent; margin-left: -1px;
      transition: color .12s, border-color .12s, background .12s;
      line-height: 1.3;
  }
  .nav-sub-btn:hover {
      color: var(--ink); border-left-color: var(--rule);
      background: rgba(255,255,255,.45);
  }
  .nav-sub-btn.has-active {
      color: var(--accent); font-weight: 500;
      border-left-color: var(--accent);
  }
  .nav-sub-btn[aria-expanded="true"] .nav-group-arrow { transform: rotate(90deg); }
  .nav-subsub {
      list-style: none; padding: 0; margin: 0;
      border-left: 1px solid var(--rule);
  }
  .nav-subsub[hidden] { display: none; }
  .nav-subsub li a {
      display: block;
      padding: .2rem .45rem .2rem 1.5rem;
      font-family: "EB Garamond", serif; font-size: .84rem;
      color: var(--ink-soft); text-decoration: none;
      border-left: 2px solid transparent; margin-left: -1px;
      transition: color .12s, border-color .12s, background .12s;
      line-height: 1.25;
  }
  .nav-subsub li a:hover {
      color: var(--ink); border-left-color: var(--rule);
      background: rgba(255,255,255,.45);
  }
  .nav-subsub li a.active {
      color: var(--accent); font-weight: 500;
      border-left-color: var(--accent);
      background: rgba(28,79,139,.06);
  }
  /* Top-level-only items (no subgroup) */
  .nav-top-item {
      border-left: 1px solid var(--rule);
  }
  .nav-top-item a {
      display: block;
      padding: .32rem .5rem .32rem .72rem;
      font-family: "EB Garamond", serif; font-size: .94rem; font-weight: 600;
      color: var(--ink); text-decoration: none;
      border-left: 2px solid transparent; margin-left: -1px;
      transition: color .12s, border-color .12s, background .12s;
      line-height: 1.3;
  }
  .nav-top-item a:hover { color: var(--accent); background: rgba(255,255,255,.45); }
  .nav-top-item a.active {
      color: var(--accent); border-left-color: var(--accent);
      background: rgba(28,79,139,.06);
  }
  .dict-nav .nav-count {
      font-size: .72em; font-style: italic; font-weight: 400;
      color: var(--ink-soft); margin-left: .3em; letter-spacing: 0;
  }
  html { scroll-behavior: smooth; }

  /* ── Content area ── */
  main {
      min-width: 0; margin: 2rem 0 4rem; padding: 0;
  }

  /* ── Backlink ── */
  .backlink {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .95rem; color: var(--ink-soft); text-decoration: none;
      display: inline-block; margin-bottom: 1.2rem;
  }
  .backlink:hover { color: var(--accent); }

  /* ── Landing ── */
  .landing h2 {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1.4rem; margin: 0 0 .75rem; color: var(--ink);
  }
  .landing p { color: var(--ink-soft); }
  .landing .intro-box {
      margin-top: 1rem; padding: 1rem 1.25rem;
      background: #fff; border-left: 3px solid var(--accent);
      border-radius: 2px;
  }

  /* ── Search results ── */
  .results-header {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: 1rem; color: var(--ink-soft);
      border-bottom: 1px solid var(--rule);
      padding-bottom: .5rem; margin-bottom: .75rem;
      display: flex; justify-content: space-between; align-items: baseline;
  }
  .results-header .count { font-size: .85rem; }
  .result-list { list-style: none; padding: 0; margin: 0; }
  .result-list li a {
      display: block;
      padding: .6rem .25rem; border-bottom: 1px dotted var(--rule);
      color: inherit; text-decoration: none; transition: background .12s;
  }
  .result-list li a:hover { background: rgba(255,255,255,.55); }
  .result-ref {
      font-family: "Courier New", monospace; font-size: .8rem;
      color: var(--accent); margin-right: .6em;
  }
  .result-title {
      font-family: "EB Garamond", serif; font-size: 1.05rem;
      color: var(--ink-soft); font-style: italic;
  }
  .result-snippet {
      font-size: .9rem; color: var(--ink-soft);
      margin-top: .2rem; line-height: 1.45;
  }
  .result-snippet em { background: var(--highlight); font-style: normal; padding: 0 .15em; }
  .no-results { font-style: italic; color: var(--ink-soft); padding: 1.5rem 0; }

  /* ── Subgroup anchor headings (group-level browse) ── */
  .subgroup-anchor {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1.15rem; color: var(--ink);
      border-top: 1px solid var(--rule);
      margin: 2rem 0 1rem; padding-top: 1rem;
      scroll-margin-top: 2rem;
  }
  .subgroup-anchor:first-of-type { border-top: none; margin-top: 0; }

  .subsubgroup-anchor {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1rem; color: var(--ink-soft);
      font-style: italic;
      border-top: 1px dotted var(--rule);
      margin: 1.4rem 0 .75rem; padding-top: .75rem;
      scroll-margin-top: 2rem;
  }

  /* ── Section browse ── */
  .section-header {
      border-top: 3px double var(--ink);
      border-bottom: 1px solid var(--rule);
      padding: 1rem 0 .85rem; margin-bottom: 1.5rem;
  }
  .section-header h1 {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1.55rem; margin: 0; color: var(--ink);
  }
  .section-header .section-count {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .9rem; color: var(--ink-soft); margin-top: .3rem;
  }

  /* ── Prev/Next paragraph navigation ── */
  .para-nav {
      display: flex; justify-content: space-between; align-items: center;
      gap: 1rem; padding: .5rem .7rem;
      margin-bottom: .9rem; background: var(--paper-2);
      border: 1px solid var(--rule); border-radius: 2px;
      font-family: "EB Garamond", serif; font-size: .95rem;
  }
  .para-nav a {
      display: flex; align-items: baseline; gap: .4rem;
      color: var(--accent); text-decoration: none; transition: color .12s;
      max-width: 44%;
  }
  .para-nav a:hover { color: #143d6e; }
  .para-nav .nav-arrow { flex-shrink: 0; }
  .para-nav .nav-ref {
      font-family: "Courier New", monospace; font-size: .78rem;
      color: var(--ink-soft); flex-shrink: 0;
  }
  .para-nav .nav-label {
      font-style: italic; color: var(--ink-soft);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .para-nav a.nav-next { margin-left: auto; text-align: right; flex-direction: row-reverse; }

  /* ── Single paragraph / section entry ── */
  .po-entry {
      margin: 0 0 2.2rem;
      padding: 0 0 1.6rem;
      border-bottom: 1px solid var(--rule);
  }
  .po-entry:last-child { border-bottom: none; }

  .po-head {
      border-top: 3px double var(--ink);
      border-bottom: 1px solid var(--rule);
      padding: 1rem 0 .85rem; margin-bottom: 1.2rem;
  }
  .po-head .po-ref {
      font-family: "Courier New", monospace; font-size: .78rem;
      color: var(--ink-soft); margin-bottom: .3rem;
  }
  .po-head .po-breadcrumb {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .88rem; color: var(--ink-soft);
      display: flex; flex-wrap: wrap; gap: 0 .3em; align-items: baseline;
  }
  .po-head .bc-sep { color: var(--rule); }
  .po-head .bc-part { color: var(--ink-soft); }
  .po-head .bc-part a {
      color: inherit; text-decoration: none;
      border-bottom: 1px dotted var(--rule);
      transition: color .12s, border-color .12s;
  }
  .po-head .bc-part a:hover { color: var(--accent); border-color: var(--accent); }
  .po-head .bc-last { color: var(--ink); font-style: normal; font-weight: 500; }

  /* Section entry header (smaller, used when listing multiple entries) */
  .po-entry-head {
      display: flex; align-items: baseline; gap: .7em;
      margin-bottom: .7rem;
  }
  .po-entry-ref {
      font-family: "Courier New", monospace; font-size: .78rem;
      color: var(--accent); flex-shrink: 0;
  }
  .po-entry-breadcrumb {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .88rem; color: var(--ink-soft);
  }
  .po-entry-breadcrumb a {
      color: var(--accent); text-decoration: none;
      border-bottom: 1px dotted var(--rule);
      transition: color .12s;
  }
  .po-entry-breadcrumb a:hover { color: #143d6e; }

  /* ── Paragraphs ── */
  .po-para {
      margin: 0 0 .85rem;
      line-height: 1.7;
      font-size: .985rem;
  }
  .po-para:last-child { margin-bottom: 0; }

  /* Mongolian script within a paragraph */
  .mng-script {
      font-family: "Mongolian Baiti", serif;
      font-size: 1.55em;
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
      display: inline-block;
      line-height: 1.05;
      vertical-align: middle;
      color: var(--ink);
      margin: 0 .15em;
  }

  /* Multi-word Mongolian: each word is its own vertical column */
  .mng-script.mng-multi {
      writing-mode: horizontal-tb;
      -webkit-writing-mode: horizontal-tb;
      display: inline-flex;
      flex-direction: row;
      align-items: flex-start;
      vertical-align: middle;
      gap: .1em;
  }
  .mng-word {
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
      display: inline-block;
      line-height: 1.05;
  }

  /* Italic text within paragraphs */
  em.para-em {
      font-style: italic;
  }

  /* Transcript after Mongolian script */
  .mng-tr {
      font-family: "EB Garamond", serif;
      font-style: italic; font-size: 1em;
      color: var(--accent);
  }

  /* Romanization immediately after Mongolian script span */
  em.mng-rom {
      font-family: "EB Garamond", serif;
      font-style: italic; font-size: 1em;
      color: var(--ink);
  }

  .po-table {
      border-collapse: collapse;
      margin: .6rem 0 .8rem;
      font-size: .93rem;
      width: auto;
      max-width: 100%;
  }
  .po-table th, .po-table td {
      border: 1px solid var(--rule);
      padding: .3rem .65rem;
      text-align: left;
      vertical-align: top;
      line-height: 1.45;
  }
  .po-table th {
      background: var(--paper-2);
      font-family: "EB Garamond", serif;
      font-weight: 600;
      font-size: .88rem;
      letter-spacing: .03em;
      color: var(--ink);
  }
  .po-table tr:nth-child(even) td { background: rgba(233,241,250,.45); }

  /* Cross-reference links §NNN */
  a.para-ref {
      font-family: "Courier New", monospace; font-size: .82em;
      color: var(--accent-2); text-decoration: none;
      border-bottom: 1px dotted var(--accent-2);
      transition: color .12s;
  }
  a.para-ref:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Inline images ── */
  .po-figure {
      display: block;
      margin: .9rem 0 1rem;
      text-align: center;
  }
  .po-img {
      max-width: 100%;
      height: auto;
      border: 1px solid var(--rule);
      border-radius: 2px;
      box-shadow: 0 1px 4px rgba(15,39,71,.08);
  }
  .po-figcaption {
      font-family: "EB Garamond", serif;
      font-style: italic;
      font-size: .88rem;
      color: var(--ink-soft);
      margin-top: .35rem;
  }

  /* ── Alert ── */
  .alert {
      padding: 1rem 1.25rem; background: #fff;
      border-left: 3px solid var(--accent);
      font-style: italic; color: var(--ink-soft);
  }

  /* ── Grammar switcher ── */
  .grammar-switcher {
      display: flex; flex-direction: column; gap: .25rem; align-items: flex-start;
  }
  .gsw-pill {
      font-family: "EB Garamond", serif; font-size: .82rem; font-style: italic;
      color: var(--ink-soft); text-decoration: none;
      border: 1px solid var(--rule); border-radius: 20px;
      padding: .2rem .7rem; transition: all .15s;
      white-space: nowrap;
  }
  .gsw-pill:hover { color: var(--accent); border-color: var(--accent); }
  .gsw-pill.active { background: var(--accent); color: #fff; border-color: var(--accent); }
  .gsw-year { font-style: normal; font-size: .85em; opacity: .8; }

  /* ── Grammar listing (landing) ── */
  .grammar-cards { display: grid; gap: 1rem; margin-top: 1.25rem; }
  .grammar-card {
      border: 1px solid var(--rule); border-radius: 3px;
      padding: .9rem 1.15rem; background: #fff;
      text-decoration: none; color: inherit; display: block;
      transition: border-color .15s, box-shadow .15s;
  }
  .grammar-card:hover { border-color: var(--accent-2); box-shadow: 0 2px 8px rgba(28,79,139,.08); }
  .grammar-card-title {
      font-family: "EB Garamond", serif; font-size: 1.1rem;
      font-weight: 500; color: var(--ink); margin: 0 0 .2rem;
  }
  .grammar-card-meta {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .88rem; color: var(--ink-soft);
  }
  .grammar-card-count {
      font-size: .8rem; color: var(--ink-soft); margin-top: .25rem;
  }

  /* ── Responsive ── */
  @media (max-width: 720px) {
      .page-shell { grid-template-columns: 1fr; gap: 0; padding: 0 1rem; }
      .dict-nav {
          position: static; max-height: none;
          padding-top: 1rem; border-bottom: 1px solid var(--rule);
          padding-bottom: .75rem; margin-bottom: .25rem;
          overflow-y: visible;
      }
      .nav-group-btn, .nav-top-item a {
          font-size: .83rem;
      }
      .nav-sub li a { font-size: .8rem; }
  }
  @media (max-width: 540px) {
      .masthead-inner { gap: 1rem; }
      .soyombo { height: 54px; }
      .brand-text { font-size: 1.15rem; }
      .mng-script { font-size: 1.3em; }
  }
  /* ── Cross-reference box ── */
  .crossref-box {
      margin-top: 1.6rem; padding: .75rem 1rem;
      border: 1px solid var(--rule); border-left: 3px solid var(--accent-2);
      border-radius: 2px; background: var(--paper-2);
  }
  .result-list li .crossref-box { margin-top: .5rem; }
  .crossref-box-title {
      font-family: "EB Garamond", serif; font-size: .72rem; font-weight: 600;
      letter-spacing: .12em; text-transform: uppercase; color: var(--ink-soft);
      margin: 0 0 .5rem;
  }
  .crossref-box ul { list-style: none; padding: 0; margin: 0; }
  .crossref-box li {
      display: flex; gap: .6em; align-items: baseline;
      padding: .28rem 0; border-bottom: 1px dotted var(--rule);
      font-family: "EB Garamond", serif; font-size: .95rem;
  }
  .crossref-box li:last-child { border-bottom: none; }
  .crossref-box .cr-ref { font-family: "Courier New", monospace; font-size: .8em; color: var(--accent); flex-shrink: 0; }
  .crossref-box .cr-grammar { font-style: italic; font-size: .82rem; color: var(--accent-2); flex-shrink: 0; }
  .crossref-box .cr-label { color: var(--ink-soft); }
  .crossref-box .cr-note { font-style: italic; font-size: .84rem; color: var(--ink-soft); }
</style>
</head>
<body>

<header class="masthead">
  <div class="masthead-inner">
    <a class="brand" href="gramm.php">
      <img class="soyombo" src="../soyombo.png" alt="" width="36" height="68">
      <?php
      $cg = array_values(array_filter($grammars, fn($g) => $g['id'] === currentGrammarId()));
      $cg = $cg[0] ?? ['author' => 'Nicholas Poppe', 'year' => 1954];
    ?>
    <span class="brand-text">Mongolian Grammar<span class="brand-sub"><?= h($cg['author']) ?> · <?= (int)$cg['year'] ?></span></span>
    </a>
    <?php if (count($grammars) > 1): ?>
    <div class="grammar-switcher">
      <?php foreach ($grammars as $g): ?>
        <a href="gramm.php?grammar=<?= urlencode($g['slug']) ?>"
           class="gsw-pill <?= $g['id'] === currentGrammarId() ? 'active' : '' ?>">
          <?= h($g['author']) ?> <span class="gsw-year"><?= (int)$g['year'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form class="search-form" method="get" action="gramm.php" role="search">
      <?php if ($grammarSlug !== 'poppe'): ?>
        <input type="hidden" name="grammar" value="<?= h($grammarSlug) ?>">
      <?php endif; ?>
      <input type="search" name="q" value="<?= h($query) ?>"
             placeholder="Search grammar… ablative, vowel, verb…"
             autocomplete="off" spellcheck="false"
             <?= ($ref === '' && $query === '' && $section === '') ? 'autofocus' : '' ?>>
      <button type="submit">Search</button>
    </form>
    <div class="mast-user">
      <?php if ($isLoggedIn): ?>
        <span class="mast-username"><?= h($authUser) ?></span>
        <a class="mast-logout" href="gramm.php?logout=1">Sign out</a>
      <?php else: ?>
        <div class="mast-login-wrap">
          <button class="mast-signin-btn" id="signin-toggle" aria-expanded="false" aria-controls="signin-dropdown">Sign in</button>
          <div class="signin-dropdown" id="signin-dropdown" hidden>
            <?php if ($loginError !== ''): ?>
              <div class="signin-error"><?= h($loginError) ?></div>
            <?php endif; ?>
            <form method="post" action="gramm.php<?= $_SERVER['QUERY_STRING'] ? '?' . h($_SERVER['QUERY_STRING']) : '' ?>">
              <div class="signin-field">
                <label for="login_user">Username</label>
                <input type="text" id="login_user" name="login_user" autocomplete="username"
                       value="<?= h((string)($_POST['login_user'] ?? '')) ?>">
              </div>
              <div class="signin-field">
                <label for="login_pass">Password</label>
                <input type="password" id="login_pass" name="login_pass" autocomplete="current-password">
              </div>
              <button type="submit" class="signin-submit">Sign in</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php
// ── Build active section for sidebar highlighting ────────────────
$activeSection = '';
if ($currentEntry)       $activeSection = $currentEntry['section'];
elseif ($section !== '') $activeSection = $section;
elseif ($sectionEntries) $activeSection = $sectionEntries[0]['section'] ?? '';

function anchorId(string $label): string {
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($label)));
}

function gp(): string {
    global $grammarSlug;
    return ($grammarSlug !== 'poppe') ? '&grammar=' . urlencode($grammarSlug) : '';
}

function isUnder(string $active, string $prefix): bool {
    return $active === $prefix
        || strncmp($active, $prefix . ' - ', strlen($prefix) + 3) === 0;
}
?>

<div class="page-shell">

  <!-- ── Left sidebar nav ── -->
  <nav class="dict-nav" aria-label="Grammar sections">
    <p class="dict-nav-title">Sections</p>
    <?php foreach ($navGroups as $gLabel => $gData):
        if (strpos($gLabel, 'manque') === 0 || $gLabel === 'xxx') continue;

        $gSlug      = $gLabel;
        $gActive    = isUnder($activeSection, $gLabel);
        $hasSubs    = !empty($gData['subs']);
        $gId        = 'ng-' . anchorId($gLabel);
    ?>
      <?php if (!$hasSubs): ?>
        <!-- ── plain top-level link ── -->
        <div class="nav-top-item">
          <a href="gramm.php?section=<?= urlencode($gSlug) . gp() ?>"
             class="<?= $gActive ? 'active' : '' ?>">
            <?= h($gLabel) ?><span class="nav-count">(<?= $gData['count'] ?>)</span>
          </a>
        </div>

      <?php else: ?>
        <!-- ── group accordion ── -->
        <div class="nav-group">
          <a class="nav-group-btn <?= $gActive ? 'has-active' : '' ?>"
             href="gramm.php?section=<?= urlencode($gSlug) . gp() ?>"
             aria-expanded="<?= $gActive ? 'true' : 'false' ?>"
             aria-controls="<?= h($gId) ?>">
            <?= h($gLabel) ?><span class="nav-count">(<?= $gData['count'] ?>)</span>
            <span class="nav-group-arrow">▶</span>
          </a>
          <ul class="nav-sub" id="<?= h($gId) ?>" <?= $gActive ? '' : 'hidden' ?>>
            <?php foreach ($gData['subs'] as $sLabel => $sData):
                $sSlug   = $gLabel . ' - ' . $sLabel;
                $sActive = isUnder($activeSection, $sSlug);
                $hasSubSubs = !empty($sData['subsubs']);
                $sId     = 'ns-' . anchorId($sSlug);
            ?>
              <?php if (!$hasSubSubs): ?>
                <!-- ── plain sub link ── -->
                <li>
                  <a href="gramm.php?section=<?= urlencode($sSlug) . gp() ?>"
                     class="<?= $sActive ? 'active' : '' ?>">
                    <?= h($sLabel) ?><span class="nav-count">(<?= $sData['count'] ?>)</span>
                  </a>
                </li>

              <?php else: ?>
                <!-- ── sub accordion ── -->
                <li class="nav-sub-group">
                  <a class="nav-sub-btn <?= $sActive ? 'has-active' : '' ?>"
                     href="gramm.php?section=<?= urlencode($sSlug) . gp() ?>"
                     aria-expanded="<?= $sActive ? 'true' : 'false' ?>"
                     aria-controls="<?= h($sId) ?>">
                    <?= h($sLabel) ?><span class="nav-count">(<?= $sData['count'] ?>)</span>
                    <span class="nav-group-arrow">▶</span>
                  </a>
                  <ul class="nav-subsub" id="<?= h($sId) ?>" <?= $sActive ? '' : 'hidden' ?>>
                    <?php foreach ($sData['subsubs'] as $ssLabel => $ssData):
                        $ssSlug   = $sSlug . ' - ' . $ssLabel;
                        $ssActive = ($activeSection === $ssSlug);
                    ?>
                      <li>
                        <a href="gramm.php?section=<?= urlencode($ssSlug) . gp() ?>"
                           class="<?= $ssActive ? 'active' : '' ?>">
                          <?= h($ssLabel) ?><span class="nav-count">(<?= $ssData['count'] ?>)</span>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <main>

<?php if ($currentEntry !== null): ?>
  <!-- ── Single paragraph view ──────────────────────────────── -->

  <?php
    $backHref = $section !== ''
        ? 'gramm.php?section=' . urlencode($section) . gp()
        : ($query !== '' ? 'gramm.php?q=' . urlencode($query) : 'gramm.php?section=' . urlencode($currentEntry['section']));
  ?>
  <a class="backlink" href="<?= h($backHref) ?>">← back</a>

  <?php if ($prevEntry !== null || $nextEntry !== null): ?>
  <div class="para-nav">
    <?php if ($prevEntry !== null): ?>
      <a href="gramm.php?ref=<?= urlencode($prevEntry['ref']) . gp() ?>" class="nav-prev">
        <span class="nav-arrow">←</span>
        <span class="nav-ref">§<?= h($prevEntry['ref']) ?></span>
        <span class="nav-label"><?= h($prevEntry['breadcrumb']) ?></span>
      </a>
    <?php else: ?>
      <span></span>
    <?php endif; ?>
    <?php if ($nextEntry !== null): ?>
      <a href="gramm.php?ref=<?= urlencode($nextEntry['ref']) . gp() ?>" class="nav-next">
        <span class="nav-arrow">→</span>
        <span class="nav-ref">§<?= h($nextEntry['ref']) ?></span>
        <span class="nav-label"><?= h($nextEntry['breadcrumb']) ?></span>
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="po-head">
    <div class="po-ref">§ <?= h($currentEntry['ref']) ?></div>
    <div class="po-breadcrumb">
      <?php
        $parts = $currentEntry['parts'];
        $last  = count($parts) - 1;
        foreach ($parts as $i => $part):
            if ($i > 0): ?><span class="bc-sep"> › </span><?php endif;
            if ($i < $last): ?>
              <span class="bc-part"><a href="gramm.php?section=<?= urlencode($parts[0]) . gp() ?>"><?= h($part) ?></a></span>
            <?php else: ?>
              <span class="bc-last"><?= h($part) ?></span>
            <?php endif;
        endforeach;
      ?>
    </div>
  </div>

  <?php foreach ($currentEntry['paragraphs'] as $p): ?>
    <p class="po-para"><?= renderParagraph($p) ?></p>
  <?php endforeach; ?>
  <?php renderCrossRefs($currentEntry); ?>

<?php elseif ($sectionEntries !== []): ?>
  <!-- ── Section browse ──────────────────────────────────────── -->

  <div class="section-header">
    <h1><?= h($section) ?></h1>
    <div class="section-count"><?= count($sectionEntries) ?> paragraph<?= count($sectionEntries) === 1 ? '' : 's' ?></div>
  </div>

  <?php
  $sectionDepth = count(array_filter(array_map('trim', explode(' - ', $section))));
  $lastSub    = null;
  $lastSubSub = null;
  foreach ($sectionEntries as $e):
      if ($sectionDepth <= 1 && $e['nav_sub'] !== $lastSub):
          $lastSub    = $e['nav_sub'];
          $lastSubSub = null;
          if ($e['nav_sub'] !== null):
  ?>
    <h2 class="subgroup-anchor" id="<?= h(anchorId($e['nav_sub'])) ?>">
      <?= h($e['nav_sub']) ?>
    </h2>
  <?php   endif; endif; ?>
  <?php
      if ($sectionDepth <= 2 && $e['nav_subsub'] !== $lastSubSub):
          $lastSubSub = $e['nav_subsub'];
          if ($e['nav_subsub'] !== null):
  ?>
    <h3 class="subsubgroup-anchor" id="<?= h(anchorId($e['nav_sub'] . '-' . $e['nav_subsub'])) ?>">
      <?= h($e['nav_subsub']) ?>
    </h3>
  <?php   endif; endif; ?>
    <div class="po-entry">
      <div class="po-entry-head">
        <span class="po-entry-ref">§ <?= h($e['ref']) ?></span>
        <span class="po-entry-breadcrumb">
          <a href="gramm.php?ref=<?= urlencode($e['ref']) . gp() ?>"><?= h($e['breadcrumb']) ?></a>
        </span>
      </div>
      <?php foreach ($e['paragraphs'] as $p): ?>
        <p class="po-para"><?= renderParagraph($p) ?></p>
      <?php endforeach; ?>
      <?php renderCrossRefs($e); ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($query !== ''): ?>
  <!-- ── Search results ─────────────────────────────────────── -->

  <div class="results-header">
    <span>Results for <em>&ldquo;<?= h($query) ?>&rdquo;</em></span>
    <span class="count"><?= count($searchResults) ?> <?= count($searchResults) === 1 ? 'match' : 'matches' ?></span>
  </div>

  <?php if (!$searchResults): ?>
    <p class="no-results">No paragraphs match this search.</p>
  <?php else: ?>
    <ul class="result-list">
      <?php foreach ($searchResults as $e):
          $snippetRaw = '';
          foreach ($e['paragraphs'] as $p) {
              $snippetRaw = preg_replace('/<uig[^>]*>.*?<\/uig>/us', '…', (string)$p);
              $snippetRaw = strip_tags($snippetRaw);
              if (trim($snippetRaw) !== '') break;
          }
          $snipLen = 160;
          $qpos = mb_stripos($snippetRaw, $query);
          if ($qpos !== false) {
              $start   = max(0, $qpos - 40);
              $snippet = ($start > 0 ? '…' : '') . mb_substr($snippetRaw, $start, $snipLen);
              if (mb_strlen($snippetRaw) > $start + $snipLen) $snippet .= '…';
              $snippet = preg_replace('/(' . preg_quote(h($query), '/') . ')/ui', '<em>$1</em>', h($snippet));
          } else {
              $snippet = h(mb_substr($snippetRaw, 0, $snipLen)) . (mb_strlen($snippetRaw) > $snipLen ? '…' : '');
          }
      ?>
        <li>
          <a href="gramm.php?ref=<?= urlencode($e['ref']) . gp() ?>&amp;q=<?= urlencode($query) ?>">
            <span class="result-ref">§ <?= h($e['ref']) ?></span>
            <span class="result-title"><?= h($e['breadcrumb']) ?></span>
            <?php if ($snippet): ?>
              <div class="result-snippet"><?= $snippet ?></div>
            <?php endif; ?>
          </a>
          <?php renderCrossRefs($e); ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

<?php else: ?>
  <!-- ── Landing ──────────────────────────────────────────────── -->

  <?php if (count($grammars) > 1 && !isset($_GET['grammar'])): ?>
    <!-- ── Multi-grammar index ── -->
    <div class="landing">
      <h2>Mongolian Grammars</h2>
      <p style="color:var(--ink-soft)">Select a grammar to browse its sections and paragraphs.</p>
      <div class="grammar-cards">
        <?php foreach ($grammars as $g):
            $cnt = countEntriesForGrammar($g['id']);
        ?>
          <a class="grammar-card" href="gramm.php?grammar=<?= urlencode($g['slug']) ?>">
            <div class="grammar-card-title"><?= h($g['title']) ?></div>
            <div class="grammar-card-meta"><?= h($g['author']) ?><?= $g['year'] ? ' · ' . (int)$g['year'] : '' ?></div>
            <?php if ($g['note']): ?><div class="grammar-card-meta" style="margin-top:.15rem"><?= h($g['note']) ?></div><?php endif; ?>
            <div class="grammar-card-count"><?= $cnt ?> paragraphs</div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <!-- ── Single grammar landing ── -->
    <?php
      $cg2  = array_values(array_filter($grammars, fn($g) => $g['id'] === currentGrammarId()));
      $cg2  = $cg2[0] ?? ['author'=>'Nicholas Poppe','title'=>'Grammar of Written Mongolian','year'=>1954,'note'=>''];
      $cnt2 = countEntriesForGrammar(currentGrammarId());
    ?>
    <div class="landing">
      <h2><?= h($cg2['title']) ?></h2>
      <p><?= h($cg2['author']) ?><?= $cg2['year'] ? ' · ' . (int)$cg2['year'] : '' ?>.
         <?= $cnt2 ?> numbered paragraphs.<?= $cg2['note'] ? ' ' . h($cg2['note']) : '' ?></p>
      <div class="intro-box">
        <p style="margin:0 0 .5rem">Browse by section using the navigation on the left, or search for any term, example, or concept.</p>
        <p style="margin:0">Cross-references like <a class="para-ref" href="gramm.php?ref=1<?= $grammarSlug !== 'poppe' ? '&grammar=' . h($grammarSlug) : '' ?>">§&thinsp;1</a> are clickable throughout.</p>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

  </main>

</div><!-- /.page-shell -->

<script>
// ── Sign-in dropdown ─────────────────────────────────────────────
(function () {
  const toggle   = document.getElementById('signin-toggle');
  const dropdown = document.getElementById('signin-dropdown');
  if (!toggle || !dropdown) return;
  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    const open = dropdown.hasAttribute('hidden');
    dropdown.toggleAttribute('hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { const fi = dropdown.querySelector('input'); if (fi) fi.focus(); }
  });
  document.addEventListener('click', function (e) {
    if (!dropdown.hasAttribute('hidden') && !dropdown.contains(e.target)) {
      dropdown.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !dropdown.hasAttribute('hidden')) {
      dropdown.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    }
  });
  <?php if ($loginError !== ''): ?>
  dropdown.removeAttribute('hidden');
  toggle.setAttribute('aria-expanded', 'true');
  <?php endif; ?>
})();

// ── Accordion nav ────────────────────────────────────────────────
(function () {
  function initAccordion(selector) {
    document.querySelectorAll(selector).forEach(function (btn) {
      var arrow = btn.querySelector('.nav-group-arrow');
      if (arrow) {
        arrow.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var subId = btn.getAttribute('aria-controls');
          var sub   = document.getElementById(subId);
          if (!sub) return;
          var expanded = btn.getAttribute('aria-expanded') === 'true';
          btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          sub.toggleAttribute('hidden', expanded);
        });
      }
      btn.addEventListener('click', function () {
        var subId = btn.getAttribute('aria-controls');
        var sub   = document.getElementById(subId);
        if (sub) {
          btn.setAttribute('aria-expanded', 'true');
          sub.removeAttribute('hidden');
        }
      });
    });
  }
  initAccordion('.nav-group-btn');
  initAccordion('.nav-sub-btn');
})();
</script>

</body>
</html>