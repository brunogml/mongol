<?php
/**
 * grammedit.php — Edit grammar paragraphs (§)
 *
 * URL modes:
 *   grammedit.php              → list all entries (search / browse)
 *   grammedit.php?ref=42       → edit form for §42
 *   grammedit.php?new=1        → new entry form
 *
 * POST actions (all require login):
 *   action=save         → save edits to an existing entry
 *   action=create       → create a new entry
 *   action=delete       → delete an entry
 *   action=add_para     → append a blank paragraph
 *   action=del_para     → delete a paragraph by pos
 *   action=move_para    → move paragraph up/down
 *   action=add_crossref → add cross-reference
 *   action=del_crossref → delete cross-reference
 *   action=create_grammar / edit_grammar / delete_grammar
 */

// ── Config ────────────────────────────────────────────────────────
$POPPE_XML = __DIR__ . '/poppe.xml';

// ── XML persistence ───────────────────────────────────────────────
/**
 * Load the XML document (singleton within a request).
 */
function &xmlDoc(): SimpleXMLElement {
    static $doc = null;
    if ($doc === null) {
        global $POPPE_XML;
        libxml_use_internal_errors(true);
        $doc = simplexml_load_file($POPPE_XML);
        if ($doc === false) {
            http_response_code(500);
            die('Could not load poppe.xml');
        }
    }
    return $doc;
}

/**
 * Save the current in-memory XML document back to disk.
 * Uses a temp file + atomic rename to avoid corruption.
 */
function saveXml(): void {
    global $POPPE_XML;
    $doc = xmlDoc();
    $dom = dom_import_simplexml($doc)->ownerDocument;
    $dom->formatOutput = true;
    $tmp = $POPPE_XML . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $dom->saveXML()) === false) {
        throw new RuntimeException('Could not write to ' . $tmp);
    }
    rename($tmp, $POPPE_XML);
}

/**
 * Return the next available integer id for a given collection
 * ('grammar' or 'entry' or 'paragraph').
 * We scan existing id attributes and return max+1.
 */
function nextId(string $collection): int {
    $doc = xmlDoc();
    $max = 0;
    if ($collection === 'grammar') {
        foreach ($doc->grammars->grammar as $g) {
            $max = max($max, (int)$g['id']);
        }
    } elseif ($collection === 'entry') {
        foreach ($doc->entries->entry as $e) {
            $max = max($max, (int)$e['id']);
        }
    }
    return $max + 1;
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

/**
 * Find a <grammar> SimpleXMLElement by id.
 */
function findGrammarNode(int $id): ?SimpleXMLElement {
    foreach (xmlDoc()->grammars->grammar as $g) {
        if ((int)$g['id'] === $id) return $g;
    }
    return null;
}

/**
 * Find an <entry> SimpleXMLElement by internal id.
 */
function findEntryById(int $id): ?SimpleXMLElement {
    foreach (xmlDoc()->entries->entry as $e) {
        if ((int)$e['id'] === $id) return $e;
    }
    return null;
}

/**
 * Find an <entry> by ref + grammar_id.
 */
function findEntryByRef(string $ref, int $grammarId): ?SimpleXMLElement {
    foreach (xmlDoc()->entries->entry as $e) {
        if ((string)$e['ref'] === $ref && (int)$e['grammar_id'] === $grammarId) return $e;
    }
    return null;
}

/**
 * Return all entries for a grammar as plain arrays (for the sidebar list).
 */
function listEntries(int $grammarId = 0, string $search = ''): array {
    $results = [];
    $sLow    = mb_strtolower(trim($search));
    foreach (xmlDoc()->entries->entry as $e) {
        if ($grammarId > 0 && (int)$e['grammar_id'] !== $grammarId) continue;
        if ($sLow !== '') {
            $hay = mb_strtolower((string)$e->title . ' ' . (string)$e->title2);
            foreach ($e->paragraphs->paragraph as $p) {
                $hay .= ' ' . mb_strtolower((string)$p);
            }
            if (mb_strpos($hay, $sLow) === false) continue;
        }
        $results[] = [
            'id'         => (int)$e['id'],
            'ref'        => (string)$e['ref'],
            'grammar_id' => (int)$e['grammar_id'],
            'ti'         => (string)$e->title,
            'ti2'        => (string)$e->title2,
        ];
        if ($sLow !== '' && count($results) >= 100) break;
    }
    return $results;
}

/**
 * Return paragraphs for an entry node as plain arrays.
 */
function getParagraphs(SimpleXMLElement $entry): array {
    $paras = [];
    foreach ($entry->paragraphs->paragraph as $p) {
        $paras[] = [
            'pos'  => (int)$p['pos'],
            'body' => (string)$p,
        ];
    }
    usort($paras, fn($a, $b) => $a['pos'] <=> $b['pos']);
    return $paras;
}

/**
 * Return cross-references for an entry node as plain arrays.
 */
function getCrossRefs(SimpleXMLElement $entry): array {
    static $idIndex = null;
    static $gramIndex = null;
    $doc = xmlDoc();
    if ($idIndex === null) {
        $idIndex = [];
        foreach ($doc->entries->entry as $e) { $idIndex[(int)$e['id']] = $e; }
    }
    if ($gramIndex === null) {
        $gramIndex = [];
        foreach ($doc->grammars->grammar as $g) {
            $gramIndex[(int)$g['id']] = ['author' => (string)$g->author, 'year' => $g->year ? (int)$g->year : null, 'slug' => (string)$g['slug']];
        }
    }

    $result = [];
    if (!$entry->cross_references->cross_reference) return $result;
    foreach ($entry->cross_references->cross_reference as $cr) {
        $otherId = (int)$cr['other_id'];
        if (!isset($idIndex[$otherId])) continue;
        $other = $idIndex[$otherId];
        $gi    = $gramIndex[(int)$other['grammar_id']] ?? ['author'=>'?','year'=>null,'slug'=>''];
        // Synthesise a stable cr id: entry_id:other_id
        $result[] = [
            'id'     => (string)$entry['id'] . ':' . $otherId,
            'other_id' => $otherId,
            'note'   => (string)$cr['note'],
            'ref'    => (string)$other['ref'],
            'ti'     => (string)$other->title,
            'ti2'    => (string)$other->title2,
            'author' => $gi['author'],
            'year'   => $gi['year'],
        ];
    }
    return $result;
}

/**
 * Write nav parts from a ti path string.
 */
function navPartsFromTi(string $ti): array {
    $parts = array_map('trim', explode(' - ', $ti));
    return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
}

/**
 * Set navigation sub-elements on an entry node from a ti string.
 */
function setEntryNav(SimpleXMLElement $entry, string $ti, string $ti2): void {
    [$ng, $ns, $nss] = navPartsFromTi($ti);
    // Remove and re-add title / navigation
    unset($entry->title);
    unset($entry->title2);
    unset($entry->navigation);

    $entry->addChild('title', htmlspecialchars($ti, ENT_XML1, 'UTF-8'));
    if ($ti2 !== '' && $ti2 !== $ti) {
        $entry->addChild('title2', htmlspecialchars($ti2, ENT_XML1, 'UTF-8'));
    }
    $nav = $entry->addChild('navigation');
    if ($ng !== '') $nav->addChild('group',   htmlspecialchars($ng,  ENT_XML1, 'UTF-8'));
    if ($ns !== '') $nav->addChild('sub',     htmlspecialchars($ns,  ENT_XML1, 'UTF-8'));
    if ($nss !== '') $nav->addChild('subsub', htmlspecialchars($nss, ENT_XML1, 'UTF-8'));
}

// ── Auth ──────────────────────────────────────────────────────────
$LOGIN_FILE = __DIR__ . '/login.txt';

function loadLogins(string $path): array {
    $logins = [];
    if (!file_exists($path)) return $logins;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode('###', $line, 2);
        if (count($parts) === 2) $logins[trim($parts[0])] = trim($parts[1]);
    }
    return $logins;
}

session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: grammedit.php');
    exit;
}

$loginError = '';
if (isset($_POST['login_user'])) {
    $logins = loadLogins($LOGIN_FILE);
    $user   = trim((string)($_POST['login_user'] ?? ''));
    $pass   = (string)($_POST['login_pass'] ?? '');
    if (isset($logins[$user]) && $logins[$user] === $pass) {
        $_SESSION['auth_user'] = $user;
        header('Location: grammedit.php' . (isset($_GET['ref']) ? '?ref=' . urlencode($_GET['ref']) : ''));
        exit;
    }
    $loginError = 'Incorrect username or password.';
}

$isLoggedIn = !empty($_SESSION['auth_user']);
$authUser   = $isLoggedIn ? (string)$_SESSION['auth_user'] : '';

// ── Helpers ───────────────────────────────────────────────────────
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── POST actions (require login) ──────────────────────────────────
$flashOk  = '';
$flashErr = '';

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $doc    = xmlDoc();

    // ── Save existing entry ──
    if ($action === 'save') {
        $entryId      = (int)($_POST['entry_id'] ?? 0);
        $ref          = trim((string)($_POST['ref']        ?? ''));
        $ti           = trim((string)($_POST['ti']         ?? ''));
        $ti2          = trim((string)($_POST['ti2']        ?? ''));
        $grammarIdSave = (int)($_POST['grammar_id'] ?? 1);

        $node = findEntryById($entryId);
        if (!$node) {
            $flashErr = 'Entry not found.';
        } else {
            try {
                $node['ref']        = $ref;
                $node['grammar_id'] = $grammarIdSave;
                setEntryNav($node, $ti, $ti2);

                // Update existing paragraphs
                foreach ($node->paragraphs->paragraph as $p) {
                    $pos = (int)$p['pos'];
                    $key = "para_body_$pos";
                    if (isset($_POST[$key])) {
                        $body = (string)$_POST[$key];
                        // SimpleXML doesn't allow direct node text with markup;
                        // store as CDATA via DOM
                        $dom  = dom_import_simplexml($p);
                        $dom->nodeValue = '';
                        $dom->appendChild($dom->ownerDocument->createTextNode($body));
                    }
                }
                saveXml();
                $flashOk = '§' . h($ref) . ' saved.';
            } catch (Exception $ex) {
                $flashErr = 'Save failed: ' . h($ex->getMessage());
            }
        }
    }

    // ── Create new entry ──
    elseif ($action === 'create') {
        $ref          = trim((string)($_POST['ref']  ?? ''));
        $ti           = trim((string)($_POST['ti']   ?? ''));
        $ti2          = trim((string)($_POST['ti2']  ?? ''));
        $grammarIdNew = (int)($_POST['grammar_id'] ?? 1);

        if ($ref === '' || $ti === '') {
            $flashErr = 'Ref and section (ti) are required.';
        } else {
            try {
                $newId   = nextId('entry');
                $entries = $doc->entries;
                $e       = $entries->addChild('entry');
                $e['id']         = $newId;
                $e['ref']        = $ref;
                $e['grammar_id'] = $grammarIdNew;
                setEntryNav($e, $ti, $ti2);
                $paras = $e->addChild('paragraphs');

                $i = 0;
                while (isset($_POST["para_body_$i"])) {
                    $body = (string)$_POST["para_body_$i"];
                    $p    = $paras->addChild('paragraph');
                    $p['pos'] = $i;
                    $dom  = dom_import_simplexml($p);
                    $dom->nodeValue = '';
                    $dom->appendChild($dom->ownerDocument->createTextNode($body));
                    $i++;
                }
                saveXml();
                header('Location: grammedit.php?ref=' . urlencode($ref) . '&grammar_id=' . $grammarIdNew . '&ok=created');
                exit;
            } catch (Exception $ex) {
                $flashErr = 'Create failed: ' . h($ex->getMessage());
            }
        }
    }

    // ── Delete entry ──
    elseif ($action === 'delete') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $node    = findEntryById($entryId);
        if ($node) {
            $refVal = (string)$node['ref'];
            // Remove cross-references pointing to this entry from other entries
            foreach ($doc->entries->entry as $other) {
                if (!$other->cross_references->cross_reference) continue;
                foreach ($other->cross_references->cross_reference as $cr) {
                    if ((int)$cr['other_id'] === $entryId) {
                        $dom = dom_import_simplexml($cr);
                        $dom->parentNode->removeChild($dom);
                    }
                }
            }
            $dom = dom_import_simplexml($node);
            $dom->parentNode->removeChild($dom);
            saveXml();
            header('Location: grammedit.php?ok=deleted&deleted=' . urlencode($refVal));
            exit;
        }
    }

    // ── Add paragraph ──
    elseif ($action === 'add_para') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $node    = findEntryById($entryId);
        if ($node) {
            // Find max pos
            $maxPos = -1;
            foreach ($node->paragraphs->paragraph as $p) {
                $maxPos = max($maxPos, (int)$p['pos']);
            }
            $nextPos = $maxPos + 1;
            $p = $node->paragraphs->addChild('paragraph', '');
            $p['pos'] = $nextPos;
            saveXml();
            $eRef = (string)$node['ref'];
            $eGid = (int)$node['grammar_id'];
            header('Location: grammedit.php?ref=' . urlencode($eRef) . '&grammar_id=' . $eGid . '#para-' . $nextPos);
            exit;
        }
    }

    // ── Delete paragraph ──
    elseif ($action === 'del_para') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pos     = (int)($_POST['pos'] ?? 0);
        $node    = findEntryById($entryId);
        if ($node) {
            // Remove the paragraph at $pos
            foreach ($node->paragraphs->paragraph as $p) {
                if ((int)$p['pos'] === $pos) {
                    $dom = dom_import_simplexml($p);
                    $dom->parentNode->removeChild($dom);
                    break;
                }
            }
            // Resequence positions
            $i = 0;
            $sorted = [];
            foreach ($node->paragraphs->paragraph as $p) { $sorted[] = $p; }
            usort($sorted, fn($a,$b) => (int)$a['pos'] <=> (int)$b['pos']);
            foreach ($sorted as $p) { $p['pos'] = $i++; }
            saveXml();
            header('Location: grammedit.php?ref=' . urlencode((string)$node['ref']) . '&grammar_id=' . (int)$node['grammar_id']);
            exit;
        }
    }

    // ── Move paragraph up/down ──
    elseif ($action === 'move_para') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pos     = (int)($_POST['pos']      ?? 0);
        $dir     = (string)($_POST['dir']   ?? '');
        $swapPos = $dir === 'up' ? $pos - 1 : $pos + 1;
        $node    = findEntryById($entryId);
        if ($node) {
            $a = null; $b = null;
            foreach ($node->paragraphs->paragraph as $p) {
                if ((int)$p['pos'] === $pos)     $a = $p;
                if ((int)$p['pos'] === $swapPos) $b = $p;
            }
            if ($a && $b) {
                $a['pos'] = $swapPos;
                $b['pos'] = $pos;
            }
            saveXml();
            header('Location: grammedit.php?ref=' . urlencode((string)$node['ref']) . '&grammar_id=' . (int)$node['grammar_id'] . '#para-' . $swapPos);
            exit;
        }
    }

    // ── Create grammar ──
    elseif ($action === 'create_grammar') {
        $slug   = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)($_POST['grammar_slug']   ?? ''))));
        $author = trim((string)($_POST['grammar_author'] ?? ''));
        $title  = trim((string)($_POST['grammar_title']  ?? ''));
        $year   = (int)($_POST['grammar_year'] ?? 0);
        $note   = trim((string)($_POST['grammar_note']   ?? ''));

        if ($slug === '' || $author === '' || $title === '') {
            $flashErr = 'Slug, author, and title are required.';
        } else {
            // Check slug uniqueness
            $dup = false;
            foreach ($doc->grammars->grammar as $g) {
                if ((string)$g['slug'] === $slug) { $dup = true; break; }
            }
            if ($dup) {
                $flashErr = 'Slug already exists.';
            } else {
                try {
                    $newGid = nextId('grammar');
                    $g      = $doc->grammars->addChild('grammar');
                    $g['id']   = $newGid;
                    $g['slug'] = $slug;
                    $g->addChild('author', htmlspecialchars($author, ENT_XML1, 'UTF-8'));
                    $g->addChild('title',  htmlspecialchars($title,  ENT_XML1, 'UTF-8'));
                    if ($year) $g->addChild('year', (string)$year);
                    $g->addChild('note', htmlspecialchars($note, ENT_XML1, 'UTF-8'));
                    saveXml();
                    $flashOk = 'Grammar "' . h($title) . '" created.';
                } catch (Exception $ex) {
                    $flashErr = 'Create grammar failed: ' . h($ex->getMessage());
                }
            }
        }
    }

    // ── Edit grammar ──
    elseif ($action === 'edit_grammar') {
        $gid    = (int)($_POST['grammar_id']    ?? 0);
        $author = trim((string)($_POST['grammar_author'] ?? ''));
        $title  = trim((string)($_POST['grammar_title']  ?? ''));
        $year   = (int)($_POST['grammar_year']  ?? 0);
        $note   = trim((string)($_POST['grammar_note']   ?? ''));
        $gnode  = findGrammarNode($gid);
        if ($gnode) {
            try {
                // Update child elements
                $gnode->author = htmlspecialchars($author, ENT_XML1, 'UTF-8');
                $gnode->title  = htmlspecialchars($title,  ENT_XML1, 'UTF-8');
                if ($year) { $gnode->year = (string)$year; } else { unset($gnode->year); }
                $gnode->note   = htmlspecialchars($note,   ENT_XML1, 'UTF-8');
                saveXml();
                $flashOk = 'Grammar updated.';
            } catch (Exception $ex) {
                $flashErr = 'Edit grammar failed: ' . h($ex->getMessage());
            }
        }
    }

    // ── Delete grammar ──
    elseif ($action === 'delete_grammar') {
        $gid = (int)($_POST['grammar_id'] ?? 0);
        if ($gid === 1) {
            $flashErr = 'Cannot delete the default grammar (Poppe).';
        } else {
            $gnode = findGrammarNode($gid);
            if ($gnode) {
                try {
                    $dom = dom_import_simplexml($gnode);
                    $dom->parentNode->removeChild($dom);
                    // Remove entries belonging to this grammar
                    foreach ($doc->entries->entry as $e) {
                        if ((int)$e['grammar_id'] === $gid) {
                            $edom = dom_import_simplexml($e);
                            $edom->parentNode->removeChild($edom);
                        }
                    }
                    saveXml();
                    $flashOk = 'Grammar deleted.';
                } catch (Exception $ex) {
                    $flashErr = 'Delete grammar failed: ' . h($ex->getMessage());
                }
            }
        }
    }

    // ── Add cross-reference ──
    elseif ($action === 'add_crossref') {
        $entryId        = (int)($_POST['entry_id']        ?? 0);
        $otherRef       = trim((string)($_POST['other_ref']       ?? ''));
        $otherGrammarId = (int)($_POST['other_grammar_id'] ?? 0);
        $crNote         = trim((string)($_POST['cr_note']         ?? ''));

        // Resolve other entry
        $otherNode = null;
        if ($otherGrammarId > 0) {
            $otherNode = findEntryByRef($otherRef, $otherGrammarId);
        } else {
            foreach ($doc->entries->entry as $e) {
                if ((string)$e['ref'] === $otherRef) { $otherNode = $e; break; }
            }
        }

        $thisNode = findEntryById($entryId);

        if (!$otherNode) {
            $flashErr = 'No entry found with ref §' . h($otherRef) . ' in the selected grammar.';
        } elseif (!$thisNode) {
            $flashErr = 'Current entry not found.';
        } elseif ((int)$otherNode['id'] === $entryId) {
            $flashErr = 'Cannot link an entry to itself.';
        } else {
            $otherId = (int)$otherNode['id'];

            // Check if already linked
            $alreadyLinked = false;
            if ($thisNode->cross_references->cross_reference) {
                foreach ($thisNode->cross_references->cross_reference as $cr) {
                    if ((int)$cr['other_id'] === $otherId) { $alreadyLinked = true; break; }
                }
            }

            if ($alreadyLinked) {
                $flashErr = 'Cross-reference already exists.';
            } else {
                // Add cross_references container if missing
                foreach ([$thisNode, $otherNode] as $n) {
                    if (!$n->cross_references) $n->addChild('cross_references');
                }
                $cr1 = $thisNode->cross_references->addChild('cross_reference');
                $cr1['other_id'] = $otherId;
                $cr1['note']     = $crNote;
                $cr2 = $otherNode->cross_references->addChild('cross_reference');
                $cr2['other_id'] = $entryId;
                $cr2['note']     = $crNote;
                saveXml();
                $flashOk = 'Cross-reference added.';
            }
        }

        if ($thisNode) {
            header('Location: grammedit.php?ref=' . urlencode((string)$thisNode['ref']) . '&grammar_id=' . (int)$thisNode['grammar_id'] . '&ok=crossref#crossrefs');
            exit;
        }
    }

    // ── Delete cross-reference ──
    elseif ($action === 'del_crossref') {
        $entryId  = (int)($_POST['entry_id'] ?? 0);
        // cr_id is stored as "entryId:otherId"
        $crIdRaw  = (string)($_POST['cr_id'] ?? '');
        $crParts  = explode(':', $crIdRaw, 2);
        $otherId  = isset($crParts[1]) ? (int)$crParts[1] : 0;

        $thisNode  = findEntryById($entryId);
        $otherNode = findEntryById($otherId);

        // Remove from both directions
        foreach ([[$thisNode, $otherId], [$otherNode, $entryId]] as [$n, $removeId]) {
            if (!$n || !$n->cross_references->cross_reference) continue;
            foreach ($n->cross_references->cross_reference as $cr) {
                if ((int)$cr['other_id'] === $removeId) {
                    $dom = dom_import_simplexml($cr);
                    $dom->parentNode->removeChild($dom);
                    break;
                }
            }
        }
        saveXml();

        if ($thisNode) {
            header('Location: grammedit.php?ref=' . urlencode((string)$thisNode['ref']) . '&grammar_id=' . (int)$thisNode['grammar_id'] . '#crossrefs');
            exit;
        }
    }
}

// ── GET params ────────────────────────────────────────────────────
$ref         = trim((string)($_GET['ref']        ?? ''));
$newEntry    = isset($_GET['new']);
$search      = trim((string)($_GET['q']          ?? ''));
$editGrammar = (int)($_GET['grammar_id'] ?? 0);

if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'created') $flashOk = 'Entry created.';
    if ($_GET['ok'] === 'deleted') $flashOk = '§' . h((string)($_GET['deleted'] ?? '')) . ' deleted.';
}

// ── Load data for edit view ───────────────────────────────────────
$entry      = null;   // SimpleXMLElement or null
$entryArr   = null;   // flat array for template use
$paragraphs = [];
$crossRefs  = [];
$grammars   = getGrammars();
$showGrammarMgr = isset($_GET['grammars']);

if ($ref !== '' && !$newEntry) {
    if ($editGrammar > 0) {
        $entry = findEntryByRef($ref, $editGrammar);
    } else {
        // First match (fallback)
        foreach (xmlDoc()->entries->entry as $e) {
            if ((string)$e['ref'] === $ref) { $entry = $e; break; }
        }
    }
    if ($entry) {
        $paragraphs = getParagraphs($entry);
        $crossRefs  = getCrossRefs($entry);
        $entryArr   = [
            'id'         => (int)$entry['id'],
            'ref'        => (string)$entry['ref'],
            'ti'         => (string)$entry->title,
            'ti2'        => (string)$entry->title2,
            'grammar_id' => (int)$entry['grammar_id'],
        ];
    }
}

// Distinct ti values for datalist autocomplete
$allTi = [];
foreach (xmlDoc()->entries->entry as $e) {
    $ti = (string)$e->title;
    if ($ti !== '' && !in_array($ti, $allTi, true)) $allTi[] = $ti;
}
sort($allTi);

// Sidebar list
$listEntries = [];
if (!$entry && !$newEntry) {
    $listEntries = listEntries($editGrammar, $search);
}

// Counts for landing page
function countAll(string $what): int {
    $doc = xmlDoc();
    if ($what === 'entry')     { $c = 0; foreach ($doc->entries->entry as $e) $c++; return $c; }
    if ($what === 'paragraph') { $c = 0; foreach ($doc->entries->entry as $e) foreach ($e->paragraphs->paragraph as $p) $c++; return $c; }
    return 0;
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grammar Editor<?= $entryArr ? ' · §' . h($entryArr['ref']) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Noto+Serif:ital,wght@0,400;0,600;1,400&display=swap">
<style>
:root {
    --paper:    #f6fafe;
    --paper-2:  #e9f1fa;
    --ink:      #0f2747;
    --ink-soft: #51678a;
    --rule:     #c4d4e6;
    --accent:   #1c4f8b;
    --accent-2: #4a8bc4;
    --danger:   #b0260f;
    --ok:       #1a6b3a;
}
*, *::before, *::after { box-sizing: border-box; }
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
    padding: 1rem 0;
}
.masthead-inner {
    max-width: 1080px; margin: 0 auto; padding: 0 1.5rem;
    display: flex; align-items: center; gap: 1.2rem; flex-wrap: wrap;
}
.brand {
    font-family: "EB Garamond", serif; font-size: 1.25rem;
    font-weight: 600; color: var(--ink); text-decoration: none;
    display: flex; align-items: center; gap: .5rem;
}
.brand-badge {
    background: var(--accent); color: #fff;
    font-size: .65rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; padding: .18em .5em;
    border-radius: 2px; vertical-align: middle;
}
.mast-right {
    margin-left: auto; display: flex; align-items: center;
    gap: .8rem; font-family: "EB Garamond", serif; font-size: .97rem;
}
.mast-right a { color: var(--ink-soft); text-decoration: none; }
.mast-right a:hover { color: var(--accent); }
.mast-user { color: var(--ink-soft); font-style: italic; }

/* ── Login wall ── */
.login-wall {
    max-width: 360px; margin: 5rem auto; padding: 2rem;
    border: 1px solid var(--rule); background: #fff;
    border-radius: 3px;
}
.login-wall h2 { margin: 0 0 1.2rem; font-family: "EB Garamond", serif; }
.form-row { margin-bottom: .8rem; }
.form-row label { display: block; font-size: .85rem; color: var(--ink-soft); margin-bottom: .2rem; }
.form-row input[type=text],
.form-row input[type=password] {
    width: 100%; padding: .45rem .6rem;
    border: 1px solid var(--rule); border-radius: 2px;
    font: inherit; font-size: .97rem; background: var(--paper);
    color: var(--ink); outline: none;
}
.form-row input:focus { border-color: var(--accent-2); }
.error-msg { color: var(--danger); font-size: .88rem; margin-bottom: .8rem; }
.btn { display: inline-block; cursor: pointer; border: none; border-radius: 2px;
       font: inherit; font-size: .88rem; font-weight: 600; letter-spacing: .04em;
       text-transform: uppercase; padding: .5rem 1.1rem; text-decoration: none; transition: background .15s; }
.btn-primary  { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #143d6e; }
.btn-danger   { background: var(--danger); color: #fff; }
.btn-danger:hover { background: #8b1b09; }
.btn-ghost    { background: transparent; color: var(--ink-soft); border: 1px solid var(--rule); }
.btn-ghost:hover { border-color: var(--accent-2); color: var(--accent); }
.btn-sm { font-size: .78rem; padding: .3rem .7rem; }
.btn-icon { background: none; border: none; cursor: pointer; color: var(--ink-soft);
            font-size: 1rem; padding: .2rem .35rem; border-radius: 2px; transition: color .12s, background .12s; }
.btn-icon:hover { color: var(--accent); background: var(--paper-2); }
.btn-icon.danger:hover { color: var(--danger); background: #fdecea; }

/* ── Layout ── */
.shell {
    max-width: 1080px; margin: 0 auto; padding: 2rem 1.5rem;
    display: grid; grid-template-columns: 260px 1fr; gap: 2.5rem;
}
@media (max-width: 720px) { .shell { grid-template-columns: 1fr; } }

/* ── Sidebar ── */
.sidebar {}
.sidebar-title {
    font-family: "EB Garamond", serif; font-weight: 600; font-size: .78rem;
    letter-spacing: .12em; text-transform: uppercase; color: var(--ink-soft);
    margin: 0 0 .6rem;
}
.search-row {
    display: flex; gap: .4rem; margin-bottom: 1rem;
}
.search-row input[type=search] {
    flex: 1; padding: .35rem .55rem;
    border: 1px solid var(--rule); border-radius: 2px;
    font: inherit; font-size: .9rem; background: #fff; color: var(--ink); outline: none;
}
.search-row input:focus { border-color: var(--accent-2); }
.entry-list {
    list-style: none; padding: 0; margin: 0;
    border: 1px solid var(--rule); border-radius: 2px;
    max-height: calc(100vh - 240px); overflow-y: auto;
    background: #fff;
}
.entry-list li a {
    display: block; padding: .32rem .65rem;
    border-bottom: 1px solid var(--rule);
    text-decoration: none; color: var(--ink-soft);
    font-family: "EB Garamond", serif; font-size: .92rem;
    line-height: 1.3; transition: background .1s, color .1s;
}
.entry-list li:last-child a { border-bottom: none; }
.entry-list li a:hover { background: var(--paper-2); color: var(--accent); }
.entry-list li a.active { background: var(--paper-2); color: var(--accent); font-weight: 500; }
.entry-list li a .eref { font-weight: 600; margin-right: .3em; color: var(--ink); }
.entry-list li a .eti  { font-size: .82em; color: var(--ink-soft); display: block; }
.sidebar-new { margin-top: .75rem; }

/* ── Main content ── */
.main {}
.flash {
    padding: .6rem 1rem; border-radius: 2px; margin-bottom: 1.2rem;
    font-family: "EB Garamond", serif; font-size: .97rem;
}
.flash-ok  { background: #d4edda; color: var(--ok);    border: 1px solid #b8dacc; }
.flash-err { background: #fdecea; color: var(--danger); border: 1px solid #f5c6c0; }

/* ── Edit form ── */
.edit-header {
    display: flex; align-items: baseline; gap: 1rem;
    justify-content: space-between; flex-wrap: wrap;
    margin-bottom: 1.4rem; border-bottom: 1px solid var(--rule); padding-bottom: .8rem;
}
.edit-title {
    font-family: "EB Garamond", serif; font-size: 1.5rem;
    font-weight: 600; margin: 0; color: var(--ink);
}
.edit-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }

.field-group {
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .8rem;
    margin-bottom: 1rem;
}
@media (max-width: 680px) { .field-group { grid-template-columns: 1fr; } }

.field { margin-bottom: .8rem; }
.field label {
    display: block; font-size: .8rem; font-weight: 600; letter-spacing: .06em;
    text-transform: uppercase; color: var(--ink-soft); margin-bottom: .25rem;
}
.field input[type=text],
.field textarea {
    width: 100%; padding: .45rem .65rem;
    border: 1px solid var(--rule); border-radius: 2px;
    font: inherit; font-size: .97rem; background: #fff; color: var(--ink);
    outline: none; transition: border-color .15s;
}
.field input:focus,
.field textarea:focus { border-color: var(--accent-2); }
.field input.ref-input { max-width: 120px; font-family: "EB Garamond", serif; font-weight: 600; }
.field-hint { font-size: .78rem; color: var(--ink-soft); margin-top: .2rem; }

/* ── Paragraphs ── */
.section-label {
    font-family: "EB Garamond", serif; font-weight: 600; font-size: .78rem;
    letter-spacing: .12em; text-transform: uppercase; color: var(--ink-soft);
    margin: 1.6rem 0 .6rem; border-top: 1px solid var(--rule); padding-top: .9rem;
}
.para-block {
    border: 1px solid var(--rule); border-radius: 3px;
    background: #fff; margin-bottom: .75rem;
    transition: border-color .15s;
}
.para-block:focus-within { border-color: var(--accent-2); }
.para-block.uig-block { border-left: 3px solid var(--accent-2); }
.para-toolbar {
    display: flex; align-items: center; gap: .2rem;
    padding: .3rem .4rem; border-bottom: 1px solid var(--rule);
    background: var(--paper-2); border-radius: 3px 3px 0 0;
}
.para-pos {
    font-family: "EB Garamond", serif; font-size: .82rem;
    color: var(--ink-soft); margin-right: auto;
    font-style: italic;
}
.para-toolbar .divider { width: 1px; height: 1em; background: var(--rule); margin: 0 .15rem; }
.para-body {
    width: 100%; border: none; outline: none; resize: vertical;
    padding: .6rem .75rem; min-height: 3.5rem;
    font: inherit; font-size: .97rem; color: var(--ink);
    background: transparent; border-radius: 0 0 3px 3px;
    line-height: 1.55;
}
.para-preview {
    padding: .45rem .75rem .55rem;
    font-size: .94rem; color: var(--ink-soft);
    border-top: 1px dashed var(--rule);
    min-height: 2rem;
}
.para-preview .mng-script {
    font-family: "Mongolian Baiti", "Noto Serif", serif;
    font-size: 1.5em; writing-mode: vertical-lr;
    display: inline-block; line-height: 1.05;
    vertical-align: middle; margin: 0 .12em;
}
.para-preview .mng-script.mng-multi {
    writing-mode: horizontal-tb;
    display: inline-flex; flex-direction: row;
    align-items: flex-start; gap: .1em;
    vertical-align: middle;
}
.para-preview .mng-word {
    writing-mode: vertical-lr;
    display: inline-block; line-height: 1.05;
}
.para-preview em.mng-rom { font-style: italic; }
.para-preview em.para-em { font-style: italic; }
.add-para-btn {
    display: flex; align-items: center; gap: .4rem; justify-content: center;
    width: 100%; padding: .5rem; border: 1px dashed var(--rule); border-radius: 3px;
    background: none; color: var(--ink-soft); cursor: pointer;
    font: inherit; font-size: .88rem; transition: border-color .15s, color .15s;
    margin-top: .25rem;
}
.add-para-btn:hover { border-color: var(--accent-2); color: var(--accent); }
.save-bar {
    position: sticky; bottom: 0;
    background: var(--paper-2); border-top: 1px solid var(--rule);
    padding: .75rem 1.5rem; display: flex; gap: .75rem; align-items: center;
    margin: 1.5rem -1.5rem -2rem; z-index: 10;
}
.delete-zone {
    margin-top: 2rem; padding: 1rem;
    border: 1px solid #f5c6c0; border-radius: 3px;
    background: #fef9f8;
}
.delete-zone summary {
    cursor: pointer; color: var(--danger); font-family: "EB Garamond", serif;
    font-size: .94rem; user-select: none;
}
.delete-zone summary:hover { text-decoration: underline; }
.delete-zone p { font-size: .88rem; color: var(--ink-soft); margin: .6rem 0; }
.insert-btn {
    background: none; border: 1px solid var(--rule); border-radius: 2px;
    padding: .15rem .4rem; font-size: .78rem; cursor: pointer; color: var(--ink-soft);
    font-family: "EB Garamond", serif; transition: border-color .12s, color .12s;
}
.insert-btn:hover { border-color: var(--accent-2); color: var(--accent); }
.para-preview .po-table {
    border-collapse: collapse;
    margin: .4rem 0 .5rem;
    font-size: .9rem;
    width: auto; max-width: 100%;
}
.para-preview .po-table th,
.para-preview .po-table td {
    border: 1px solid var(--rule);
    padding: .25rem .55rem;
    text-align: left; vertical-align: top;
}
.para-preview .po-table th {
    background: var(--paper-2);
    font-family: "EB Garamond", serif;
    font-weight: 600; font-size: .85rem;
}
.tbl-helper {
    display: none; background: var(--paper); border: 1px solid var(--rule);
    border-radius: 2px; padding: .5rem .7rem; margin: 0 .75rem .5rem;
    font-size: .85rem;
}
.tbl-helper.visible { display: block; }
.tbl-helper-row {
    display: flex; gap: .5rem; align-items: center; margin-bottom: .4rem; flex-wrap: wrap;
}
.tbl-helper label { font-size: .78rem; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
.tbl-helper input[type=number] {
    width: 60px; padding: .25rem .4rem; border: 1px solid var(--rule);
    border-radius: 2px; font: inherit; font-size: .95rem; background: #fff; outline: none;
}
.tbl-helper input[type=number]:focus { border-color: var(--accent-2); }
.tbl-grid { margin-top: .45rem; overflow-x: auto; }
.tbl-grid table { border-collapse: collapse; }
.tbl-grid td { padding: .15rem; }
.tbl-grid input[type=text] {
    width: 120px; padding: .25rem .4rem; border: 1px solid var(--rule);
    border-radius: 2px; font: inherit; font-size: .88rem; background: #fff; outline: none;
}
.tbl-grid input[type=text]:focus { border-color: var(--accent-2); }
.tbl-grid tr:first-child input[type=text] {
    font-weight: 600; background: var(--paper-2);
}
.img-helper {
    display: none; background: var(--paper); border: 1px solid var(--rule);
    border-radius: 2px; padding: .4rem .6rem; margin: 0 .75rem .5rem;
    font-size: .85rem;
}
.img-helper.visible { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
.img-helper label { font-size: .78rem; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
.img-helper input {
    flex: 1; min-width: 140px; padding: .25rem .5rem; border: 1px solid var(--rule);
    border-radius: 2px; font: inherit; font-size: .95rem; background: #fff; outline: none;
}
.img-helper input:focus { border-color: var(--accent-2); }
.para-preview .po-figure {
    display: block; margin: .6rem 0 .7rem; text-align: center;
}
.para-preview .po-img {
    max-width: 100%; height: auto; max-height: 260px;
    border: 1px solid var(--rule); border-radius: 2px;
    box-shadow: 0 1px 4px rgba(15,39,71,.08);
}
.para-preview .po-figcaption {
    font-family: "EB Garamond", serif; font-style: italic;
    font-size: .85rem; color: var(--ink-soft); margin-top: .25rem;
}
.uig-helper {
    display: none; background: var(--paper); border: 1px solid var(--rule);
    border-radius: 2px; padding: .4rem .6rem; margin: 0 .75rem .5rem;
    font-size: .85rem;
}
.uig-helper.visible { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
.uig-helper label { font-size: .78rem; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .06em; }
.uig-helper input {
    flex: 1; min-width: 100px; padding: .25rem .5rem; border: 1px solid var(--rule);
    border-radius: 2px; font: inherit; font-size: .95rem; background: #fff; outline: none;
}
.uig-helper input:focus { border-color: var(--accent-2); }
.uig-helper input.uig-script { font-family: "Mongolian Baiti", "Noto Serif", serif; font-size: 1.3em; }
.crossref-section {
    margin-top: 2rem; padding-top: 1rem;
    border-top: 1px solid var(--rule);
}
.crossref-section .section-label { margin-top: 0; border-top: none; padding-top: 0; }
.crossref-list { list-style: none; padding: 0; margin: 0 0 .75rem; }
.crossref-item {
    display: flex; align-items: baseline; gap: .6rem;
    padding: .35rem .5rem; border-bottom: 1px dotted var(--rule);
    font-family: "EB Garamond", serif; font-size: .95rem;
}
.crossref-item:last-child { border-bottom: none; }
.crossref-ref { font-family: "Courier New", monospace; font-size: .78rem; color: var(--accent); flex-shrink: 0; }
.crossref-grammar { font-size: .8rem; color: var(--accent-2); font-style: italic; flex-shrink: 0; }
.crossref-ti { color: var(--ink-soft); flex: 1; }
.crossref-note { font-style: italic; color: var(--ink-soft); font-size: .85rem; }
.crossref-add {
    display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;
    margin-top: .5rem;
}
.crossref-add input[type=text] {
    padding: .3rem .55rem; border: 1px solid var(--rule); border-radius: 2px;
    font: inherit; font-size: .9rem; background: #fff; color: var(--ink); outline: none;
    transition: border-color .15s;
}
.crossref-add input[type=text]:focus { border-color: var(--accent-2); }
.crossref-add input.ref-in { width: 90px; }
.crossref-add input.note-in { flex: 1; min-width: 140px; }
</style>
</head>
<body>

<!-- ── Masthead ── -->
<header class="masthead">
  <div class="masthead-inner">
    <a class="brand" href="grammedit.php">
      Grammar <span class="brand-badge">Editor</span>
    </a>
    <div class="mast-right">
      <a href="gramm.php">← Reader</a>
      <a href="grammedit.php?grammars=1" style="color:var(--ink-soft);text-decoration:none;font-style:italic;font-size:.9rem">Grammars</a>
      <?php if ($isLoggedIn): ?>
        <span class="mast-user"><?= h($authUser) ?></span>
        <a href="grammedit.php?logout=1">Sign out</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if (!$isLoggedIn): ?>
<!-- ── Login wall ── -->
<div class="login-wall">
  <h2>Sign in to edit</h2>
  <?php if ($loginError): ?>
    <div class="error-msg"><?= h($loginError) ?></div>
  <?php endif; ?>
  <form method="post">
    <div class="form-row">
      <label for="lu">Username</label>
      <input type="text" id="lu" name="login_user" autocomplete="username">
    </div>
    <div class="form-row">
      <label for="lp">Password</label>
      <input type="password" id="lp" name="login_pass" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary">Sign in</button>
  </form>
</div>

<?php else: ?>
<!-- ── Main shell ── -->
<div class="shell">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <p class="sidebar-title">Paragraphs</p>
    <form method="get" class="search-row" style="flex-wrap:wrap;gap:.3rem">
      <?php if (count($grammars) > 1): ?>
      <select name="grammar_id" onchange="this.form.submit()"
              style="width:100%;margin-bottom:.25rem;padding:.28rem .45rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.85rem;background:#fff;color:var(--ink)">
        <option value="0" <?= $editGrammar === 0 ? 'selected' : '' ?>>All grammars</option>
        <?php foreach ($grammars as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $editGrammar === (int)$g['id'] ? 'selected' : '' ?>>
            <?= h($g['author']) ?> <?= (int)$g['year'] ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <input type="search" name="q" placeholder="search…" value="<?= h($search) ?>" autocomplete="off" style="flex:1">
      <button type="submit" class="btn btn-ghost btn-sm">Go</button>
    </form>
    <ul class="entry-list" id="entry-list">
      <?php foreach ($listEntries as $le): ?>
        <li>
          <a href="grammedit.php?ref=<?= urlencode($le['ref']) ?>&grammar_id=<?= (int)$le['grammar_id'] ?>"
             class="<?= ($entryArr && $entryArr['ref'] === $le['ref'] && $entryArr['grammar_id'] === $le['grammar_id']) ? 'active' : '' ?>">
            <span class="eref">§<?= h($le['ref']) ?></span>
            <span class="eti"><?= h($le['ti2'] ?: $le['ti']) ?></span>
            <?php if ($editGrammar === 0 && count($grammars) > 1):
                $lg = array_values(array_filter($grammars, fn($g) => (int)$g['id'] === (int)$le['grammar_id']));
                $lg = $lg[0] ?? null;
                if ($lg): ?>
              <span class="eti" style="color:var(--accent-2);font-style:normal"><?= h($lg['author']) ?></span>
            <?php endif; endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="sidebar-new">
      <a href="grammedit.php?new=1<?= $editGrammar > 0 ? '&grammar_id=' . $editGrammar : '' ?>"
         class="btn btn-ghost btn-sm" style="width:100%;text-align:center;display:block;">+ New §</a>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="main">
    <?php if ($flashOk):  ?><div class="flash flash-ok"><?= $flashOk ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="flash flash-err"><?= $flashErr ?></div><?php endif; ?>

    <?php if ($showGrammarMgr): ?>
    <!-- ── Grammar management ── -->
    <div class="edit-header">
      <h1 class="edit-title">Grammars</h1>
    </div>

    <table style="width:100%;border-collapse:collapse;font-family:'EB Garamond',serif;font-size:.97rem;margin-bottom:1.5rem">
      <thead>
        <tr style="border-bottom:2px solid var(--rule)">
          <th style="text-align:left;padding:.3rem .6rem;font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Author</th>
          <th style="text-align:left;padding:.3rem .6rem;font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Title</th>
          <th style="text-align:left;padding:.3rem .6rem;font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Year</th>
          <th style="text-align:left;padding:.3rem .6rem;font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Slug</th>
          <th style="padding:.3rem .6rem"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grammars as $g): ?>
          <tr style="border-bottom:1px solid var(--rule)">
            <form method="post">
              <input type="hidden" name="action"     value="edit_grammar">
              <input type="hidden" name="grammar_id" value="<?= (int)$g['id'] ?>">
              <td style="padding:.35rem .6rem">
                <input type="text" name="grammar_author" value="<?= h($g['author']) ?>"
                       style="width:100%;border:1px solid var(--rule);border-radius:2px;padding:.25rem .45rem;font:inherit;font-size:.93rem;background:#fff">
              </td>
              <td style="padding:.35rem .6rem">
                <input type="text" name="grammar_title" value="<?= h($g['title']) ?>"
                       style="width:100%;border:1px solid var(--rule);border-radius:2px;padding:.25rem .45rem;font:inherit;font-size:.93rem;background:#fff">
              </td>
              <td style="padding:.35rem .6rem">
                <input type="number" name="grammar_year" value="<?= (int)$g['year'] ?>"
                       style="width:80px;border:1px solid var(--rule);border-radius:2px;padding:.25rem .45rem;font:inherit;font-size:.93rem;background:#fff">
              </td>
              <td style="padding:.35rem .6rem;color:var(--ink-soft);font-style:italic;font-size:.85rem"><?= h($g['slug']) ?></td>
              <td style="padding:.35rem .6rem;display:flex;gap:.4rem;align-items:center">
                <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                <?php if ($g['id'] !== 1): ?>
                <button type="button" class="btn btn-danger btn-sm"
                        onclick="if(confirm('Delete this grammar and all its entries?')){
                            this.form.elements['action'].value='delete_grammar';
                            this.form.submit();}">Del</button>
                <?php endif; ?>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Add new grammar -->
    <h3 style="font-family:'EB Garamond',serif;font-weight:500;font-size:1.1rem;margin:0 0 .8rem;border-top:1px solid var(--rule);padding-top:1rem">Add grammar</h3>
    <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem">
      <input type="hidden" name="action" value="create_grammar">
      <div class="field">
        <label>Slug <span style="font-weight:400;font-style:italic;color:var(--ink-soft)">(URL key, e.g. <em>schmidt</em>)</span></label>
        <input type="text" name="grammar_slug" required placeholder="schmidt"
               style="width:100%;padding:.42rem .65rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.97rem;background:#fff">
      </div>
      <div class="field">
        <label>Author</label>
        <input type="text" name="grammar_author" required placeholder="Isaac Jacob Schmidt"
               style="width:100%;padding:.42rem .65rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.97rem;background:#fff">
      </div>
      <div class="field" style="grid-column:span 2">
        <label>Title</label>
        <input type="text" name="grammar_title" required placeholder="Grammatik der mongolischen Sprache"
               style="width:100%;padding:.42rem .65rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.97rem;background:#fff">
      </div>
      <div class="field">
        <label>Year</label>
        <input type="number" name="grammar_year" placeholder="1831"
               style="width:120px;padding:.42rem .65rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.97rem;background:#fff">
      </div>
      <div class="field">
        <label>Note <span style="font-weight:400;font-style:italic;color:var(--ink-soft)">(optional)</span></label>
        <input type="text" name="grammar_note" placeholder="First systematic grammar of Written Mongolian"
               style="width:100%;padding:.42rem .65rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.97rem;background:#fff">
      </div>
      <div style="grid-column:span 2">
        <button type="submit" class="btn btn-primary">Create grammar</button>
      </div>
    </form>

    <?php elseif ($newEntry || $entryArr): ?>
    <?php
      $isNew   = $newEntry && !$entryArr;
      $formRef = $isNew ? '' : $entryArr['ref'];
      $formTi  = $isNew ? '' : $entryArr['ti'];
      $formTi2 = $isNew ? '' : $entryArr['ti2'];
      $formId  = $isNew ? 0  : $entryArr['id'];
    ?>

    <div class="edit-header">
      <h1 class="edit-title"><?= $isNew ? 'New §' : '§ ' . h($formRef) ?></h1>
      <?php if (!$isNew): ?>
      <div class="edit-actions">
        <?php
          $slugMap = array_column($grammars, 'slug', 'id');
          $viewSlug = $slugMap[$entryArr['grammar_id']] ?? 'poppe';
        ?>
        <a href="gramm.php?ref=<?= urlencode($formRef) ?>&grammar=<?= urlencode($viewSlug) ?>"
           class="btn btn-ghost btn-sm" target="_blank">View ↗</a>
      </div>
      <?php endif; ?>
    </div>

    <form method="post" id="edit-form">
      <input type="hidden" name="action"   value="<?= $isNew ? 'create' : 'save' ?>">
      <input type="hidden" name="entry_id" value="<?= $formId ?>">

      <!-- ── Metadata ── -->
      <div class="field-group">
        <div class="field">
          <label for="f-ref">§ Ref</label>
          <input type="text" id="f-ref" name="ref" class="ref-input"
                 value="<?= h($formRef) ?>" required autocomplete="off"
                 placeholder="e.g. 42">
          <div class="field-hint">Unique identifier</div>
        </div>
        <div class="field" style="grid-column: span 2">
          <label for="f-ti">Section (ti)</label>
          <input type="text" id="f-ti" name="ti"
                 value="<?= h($formTi) ?>" required autocomplete="off"
                 list="ti-list" placeholder="Group - Sub - Subsub">
          <div class="field-hint">Navigation path, e.g. <em>Derivation - Nouns - Denominal nouns</em></div>
        </div>
      </div>
      <div class="field">
        <label for="f-ti2">Paragraph title (ti2)</label>
        <input type="text" id="f-ti2" name="ti2"
               value="<?= h($formTi2) ?>" autocomplete="off"
               list="ti-list" placeholder="Leave blank to inherit from ti">
        <div class="field-hint">Displayed as heading. Usually the full <em>Group - Sub - Subsub - Paragraph title</em></div>
      </div>

      <!-- Grammar selector -->
      <div class="field" style="margin-bottom:.8rem">
        <label for="f-grammar">Grammar</label>
        <select id="f-grammar" name="grammar_id"
                style="width:100%;padding:.42rem .65rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.97rem;background:#fff;color:var(--ink);outline:none">
          <?php $defaultGid = $isNew ? ($editGrammar ?: 1) : (int)($entryArr['grammar_id'] ?? 1); ?>
          <?php foreach ($grammars as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= ($defaultGid === (int)$g['id']) ? 'selected' : '' ?>>
              <?= h($g['author']) ?> — <?= h($g['title']) ?><?= $g['year'] ? ' (' . (int)$g['year'] . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint">Which grammar this paragraph belongs to</div>
      </div>

      <datalist id="ti-list">
        <?php foreach ($allTi as $tiOpt): ?>
          <option value="<?= h($tiOpt) ?>">
        <?php endforeach; ?>
      </datalist>

      <!-- ── Paragraphs ── -->
      <p class="section-label">Paragraphs</p>

      <div id="para-container">
        <?php foreach ($paragraphs as $p):
          $pos   = (int)$p['pos'];
          $total = count($paragraphs);
          $isUig = strpos($p['body'], '<uig') !== false;
        ?>
          <div class="para-block <?= $isUig ? 'uig-block' : '' ?>" id="para-<?= $pos ?>">
            <div class="para-toolbar">
              <span class="para-pos">¶ <?= $pos + 1 ?></span>

              <button type="button" class="insert-btn" onclick="insertUig(<?= $pos ?>)" title="Insert Mongolian script wrapper">
                ᠮ uig
              </button>
              <button type="button" class="insert-btn" style="font-style:italic" onclick="insertItalic(<?= $pos ?>)" title="Wrap selection in italic">
                <i>i</i>
              </button>
              <button type="button" class="insert-btn" onclick="insertRef(<?= $pos ?>)" title="Insert §ref">
                §ref
              </button>
              <button type="button" class="insert-btn" onclick="insertTable(<?= $pos ?>)" title="Insert table">
                ⊞ tbl
              </button>
              <button type="button" class="insert-btn" onclick="insertImg(<?= $pos ?>)" title="Insert image from img/">
                🖼 img
              </button>

              <span class="divider"></span>

              <?php if ($pos > 0): ?>
              <button type="button" class="btn-icon" title="Move up"
                      onclick="movePara(<?= $formId ?>, <?= $pos ?>, 'up')">↑</button>
              <?php endif; ?>
              <?php if ($pos < $total - 1): ?>
              <button type="button" class="btn-icon" title="Move down"
                      onclick="movePara(<?= $formId ?>, <?= $pos ?>, 'down')">↓</button>
              <?php endif; ?>

              <button type="button" class="btn-icon danger" title="Delete paragraph"
                      onclick="delPara(<?= $formId ?>, <?= $pos ?>)">✕</button>
            </div>

            <div class="uig-helper" id="uig-helper-<?= $pos ?>">
              <label>Roman</label>
              <input type="text" id="uig-rom-<?= $pos ?>" placeholder="mal"
                     oninput="autoConvert(<?= $pos ?>)">
              <label>Script</label>
              <input type="text" class="uig-script" id="uig-script-<?= $pos ?>"
                     placeholder="ᠮᠠᠯ" dir="ltr">
              <button type="button" class="insert-btn" onclick="commitUig(<?= $pos ?>)">Insert</button>
              <button type="button" class="btn-icon" onclick="hideUig(<?= $pos ?>)">✕</button>
            </div>

            <div class="tbl-helper" id="tbl-helper-<?= $pos ?>">
              <div class="tbl-helper-row">
                <label>Cols</label>
                <input type="number" id="tbl-cols-<?= $pos ?>" value="3" min="1" max="10"
                       oninput="rebuildTblGrid(<?= $pos ?>)">
                <label>Rows (incl. header)</label>
                <input type="number" id="tbl-rows-<?= $pos ?>" value="3" min="2" max="30"
                       oninput="rebuildTblGrid(<?= $pos ?>)">
                <button type="button" class="insert-btn" onclick="commitTable(<?= $pos ?>)">Insert</button>
                <button type="button" class="btn-icon" onclick="hideTbl(<?= $pos ?>)">✕</button>
              </div>
              <div class="tbl-grid" id="tbl-grid-<?= $pos ?>"></div>
            </div>

            <div class="img-helper" id="img-helper-<?= $pos ?>">
              <label>Filename</label>
              <input type="text" id="img-file-<?= $pos ?>"
                     placeholder="diagram.png"
                     title="File must exist in img/ on the server">
              <label>Alt / caption</label>
              <input type="text" id="img-alt-<?= $pos ?>" placeholder="optional caption">
              <button type="button" class="insert-btn" onclick="commitImg(<?= $pos ?>)">Insert</button>
              <button type="button" class="btn-icon" onclick="hideImg(<?= $pos ?>)">✕</button>
            </div>

            <textarea class="para-body" id="para-body-<?= $pos ?>"
                      name="para_body_<?= $pos ?>"
                      rows="<?= max(2, substr_count($p['body'], "\n") + 2) ?>"
                      oninput="updatePreview(<?= $pos ?>)"
                      ><?= h($p['body']) ?></textarea>

            <div class="para-preview" id="para-preview-<?= $pos ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>

      <button type="button" class="add-para-btn" onclick="addPara(<?= $formId ?>, <?= $isNew ? 0 : (int)($entryArr['grammar_id'] ?? 1) ?>)">
        + Add paragraph
      </button>

      <div class="save-bar">
        <button type="submit" class="btn btn-primary">Save §<?= h($formRef) ?></button>
        <span style="font-size:.85rem;color:var(--ink-soft);font-family:'EB Garamond',serif">
          <?= count($paragraphs) ?> paragraph<?= count($paragraphs) !== 1 ? 's' : '' ?>
        </span>
        <a href="grammedit.php" class="btn btn-ghost btn-sm" style="margin-left:auto">Cancel</a>
      </div>

    </form>

    <?php if (!$isNew): ?>
    <!-- ── Cross-references ── -->
    <div class="crossref-section" id="crossrefs">
      <p class="section-label">Cross-references to other grammars</p>
      <?php if ($crossRefs): ?>
      <ul class="crossref-list">
        <?php foreach ($crossRefs as $cr): ?>
          <li class="crossref-item">
            <span class="crossref-ref">§<?= h($cr['ref']) ?></span>
            <span class="crossref-grammar"><?= h($cr['author']) ?> <?= (int)$cr['year'] ?></span>
            <span class="crossref-ti"><?= h($cr['ti2'] ?: $cr['ti']) ?></span>
            <?php if ($cr['note'] !== ''): ?>
              <span class="crossref-note"><?= h($cr['note']) ?></span>
            <?php endif; ?>
            <form method="post" style="margin:0;display:inline"
                  onsubmit="return confirm('Remove this cross-reference?')">
              <input type="hidden" name="action"   value="del_crossref">
              <input type="hidden" name="entry_id" value="<?= $formId ?>">
              <input type="hidden" name="cr_id"    value="<?= h($cr['id']) ?>">
              <button type="submit" class="btn-icon danger" title="Remove">✕</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
        <p style="font-size:.88rem;color:var(--ink-soft);font-style:italic;margin:.3rem 0 .7rem">No cross-references yet.</p>
      <?php endif; ?>

      <form method="post" class="crossref-add">
        <input type="hidden" name="action"   value="add_crossref">
        <input type="hidden" name="entry_id" value="<?= $formId ?>">
        <select name="other_grammar_id"
                style="padding:.3rem .55rem;border:1px solid var(--rule);border-radius:2px;font:inherit;font-size:.88rem;background:#fff;color:var(--ink);outline:none;transition:border-color .15s"
                onfocus="this.style.borderColor='var(--accent-2)'" onblur="this.style.borderColor='var(--rule)'">
          <?php
            $currentGid  = (int)($entryArr['grammar_id'] ?? 1);
            $othersFirst = array_merge(
                array_filter($grammars, fn($g) => (int)$g['id'] !== $currentGid),
                array_filter($grammars, fn($g) => (int)$g['id'] === $currentGid)
            );
            foreach ($othersFirst as $g):
          ?>
            <option value="<?= (int)$g['id'] ?>"><?= h($g['author']) ?> <?= (int)$g['year'] ?><?= (int)$g['id'] === $currentGid ? ' (this)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="other_ref" class="ref-in" placeholder="§ ref"
               required autocomplete="off" title="Ref number in the selected grammar">
        <input type="text" name="cr_note" class="note-in" placeholder="Note (optional)"
               autocomplete="off">
        <button type="submit" class="btn btn-ghost btn-sm">Link §</button>
      </form>
      <p style="font-size:.75rem;color:var(--ink-soft);margin:.35rem 0 0">
        Select the grammar, enter the §&thinsp;ref. The link will appear on both entries.
      </p>
    </div>

    <!-- ── Danger zone ── -->
    <details class="delete-zone" style="margin-top:1.5rem">
      <summary>Delete this §</summary>
      <p>This will permanently delete §<?= h($formRef) ?> and all its paragraphs.</p>
      <form method="post" onsubmit="return confirm('Delete §<?= h($formRef) ?>? This cannot be undone.')">
        <input type="hidden" name="action"   value="delete">
        <input type="hidden" name="entry_id" value="<?= $formId ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete §<?= h($formRef) ?></button>
      </form>
    </details>
    <?php endif; ?>

    <?php else: ?>
    <!-- ── Landing ── -->
    <div style="padding-top:1rem">
      <h2 style="font-family:'EB Garamond',serif;font-weight:500;margin:0 0 .5rem">
        Select a § to edit
      </h2>
      <p style="color:var(--ink-soft);font-size:.97rem;margin:0">
        <?= countAll('entry') ?> entries ·
        <?= countAll('paragraph') ?> paragraphs
      </p>
    </div>
    <?php endif; ?>

  </main>
</div>
<?php endif; ?>

<script>
// ── Image insert helper ───────────────────────────────────────────
function insertImg(pos) {
    var h = document.getElementById('img-helper-' + pos);
    if (h) {
        h.classList.toggle('visible');
        if (h.classList.contains('visible'))
            document.getElementById('img-file-' + pos)?.focus();
    }
}
function hideImg(pos) {
    var h = document.getElementById('img-helper-' + pos);
    if (h) h.classList.remove('visible');
}
function commitImg(pos) {
    var file = (document.getElementById('img-file-' + pos)?.value || '').trim();
    var alt  = (document.getElementById('img-alt-'  + pos)?.value || '').trim();
    if (!file) { hideImg(pos); return; }
    file = file.replace(/.*[\\\/]/, '');
    var markup = alt
        ? '<img src="' + file + '" alt="' + alt.replace(/"/g, '&quot;') + '">'
        : '<img src="' + file + '">';
    insertAtCursor('para-body-' + pos, markup);
    document.getElementById('img-file-' + pos).value = '';
    document.getElementById('img-alt-'  + pos).value = '';
    hideImg(pos);
    updatePreview(pos);
}

// ── Preview rendering ─────────────────────────────────────────────
function renderBody(raw) {
    raw = raw.replace(/<tbl>([\s\S]*?)<\/tbl>/g, function(_, inner) {
        var rows = inner.split(';;').map(function(r){ return r.trim(); }).filter(Boolean);
        if (!rows.length) return '';
        var html = '<table class="po-table">';
        rows.forEach(function(row, idx) {
            var cells = row.split('|').map(function(c){ return c.trim(); });
            if (idx === 0) {
                html += '<thead><tr>' + cells.map(function(c){ return '<th>' + renderBody(c) + '</th>'; }).join('') + '</tr></thead><tbody>';
            } else {
                html += '<tr>' + cells.map(function(c){ return '<td>' + renderBody(c) + '</td>'; }).join('') + '</tr>';
            }
        });
        html += '</tbody></table>';
        return html;
    });
    raw = raw.replace(/<uig\s+rom="([^"]*)">([^<]*?)<\/uig>/g, function(_, rom, script) {
        script = script.trim(); rom = rom.trim();
        var out = '';
        if (script) {
            var words = script.split(/\s+/).filter(Boolean);
            if (words.length > 3) {
                out += '<span class="mng-script mng-multi">' + words.map(function(w){ return '<span class="mng-word">' + esc(w) + '</span>'; }).join('') + '</span>';
            } else {
                out += '<span class="mng-script">' + esc(script) + '</span>';
            }
        }
        if (rom) out += ' <em class="mng-rom">' + esc(rom) + '</em>';
        return out;
    });
    raw = raw.replace(/<i>([\s\S]*?)<\/i>/g, function(_, inner) {
        return '<em class="para-em">' + esc(inner) + '</em>';
    });
    raw = raw.replace(/<img\s+src="([^"]*)"(?:\s+alt="([^"]*)")?[^>]*>/g, function(_, src, alt) {
        src = 'img/' + src.replace(/.*[\\\/]/, '');
        alt = alt || '';
        var cap = alt ? '<div class="po-figcaption">' + esc(alt) + '</div>' : '';
        return '<figure class="po-figure"><img class="po-img" src="' + esc(src) + '" alt="' + esc(alt) + '" loading="lazy">' + cap + '</figure>';
    });
    raw = raw.replace(/§\s*([0-9]+[a-z]?)/g, '<a href="gramm.php?ref=$1">§$1</a>');
    return raw;
}
function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function updatePreview(pos) {
    var ta  = document.getElementById('para-body-' + pos);
    var pre = document.getElementById('para-preview-' + pos);
    if (!ta || !pre) return;
    pre.innerHTML = renderBody(ta.value) || '<span style="opacity:.4;font-style:italic">empty</span>';
    var block = document.getElementById('para-' + pos);
    if (block) block.classList.toggle('uig-block', ta.value.includes('<uig'));
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.para-body').forEach(function(ta) {
        var m = ta.id.match(/para-body-(\d+)/);
        if (m) updatePreview(parseInt(m[1]));
    });
});

// ── Mongolian script helper ───────────────────────────────────────
function insertUig(pos) {
    var h = document.getElementById('uig-helper-' + pos);
    if (h) { h.classList.toggle('visible'); if (h.classList.contains('visible')) document.getElementById('uig-rom-' + pos)?.focus(); }
}
function hideUig(pos) {
    var h = document.getElementById('uig-helper-' + pos);
    if (h) h.classList.remove('visible');
}
function commitUig(pos) {
    var script = (document.getElementById('uig-script-' + pos)?.value || '').trim();
    var rom    = (document.getElementById('uig-rom-'    + pos)?.value || '').trim();
    if (!script && !rom) { hideUig(pos); return; }
    var marker = '<uig rom="' + rom.replace(/"/g, '&quot;') + '">' + script + '</uig>';
    insertAtCursor('para-body-' + pos, marker);
    document.getElementById('uig-script-' + pos).value = '';
    document.getElementById('uig-rom-'    + pos).value = '';
    hideUig(pos);
    updatePreview(pos);
}
function autoConvert(pos) {
    var rom    = document.getElementById('uig-rom-'    + pos)?.value || '';
    var script = document.getElementById('uig-script-' + pos);
    if (script) script.value = romToScript(rom);
}

// ── Poppe romanization → Traditional Mongolian Script ────────────
var ROM_TABLE = [
    ['ng', '\u1829'], ['sh', '\u1831'], ['š', '\u1831'], ['S', '\u1831'], ['ch', 'ᠽ'],
    ['a', '\u1820'], ['e', '\u1821'], ['i', '\u1822'], ['o', '\u1823'], ['u', '\u1824'],
    ['\u00f6', '\u1825'], ['\u00fc', '\u1826'],
    ['n', '\u1828'], ['b', '\u182a'], ['p', '\u182b'], ['q', '\u182c'], ['g', '\u182d'],
    ['\u03b3', '\u182d'], ['m', '\u182e'], ['l', '\u182f'], ['s', '\u1830'], ['t', '\u1832'],
    ['d', '\u1833'], ['c', '\u1834'], ['j', '\u1835'], ['y', '\u1836'], ['r', '\u1837'],
    ['w', '\u1838'], ['v', '\u1838'], ['f', '\u1839'], ['k', '\u183a'], ['z', '\u1834'],
    ['h', '\u1859'],
];
function romToScript(input) {
    if (!input) return '';
    var s = input;
    s = s.split('-iyan').join(' ᠢᠶᠠᠨ'); s = s.split('-iyen').join(' ᠢᠶᠡᠨ');
    s = s.split('-lug-a').join(' ᠯᠤᠭ ᠠ'); s = s.split('-luγ-a').join(' ᠯᠤᠭ ᠠ');
    s = s.split('-lüge').join(' ᠯᠦᠭᠡ');
    s = s.split('-iyar').join(' ᠢᠶᠠᠷ'); s = s.split('-iyer').join(' ᠢᠶᠡᠷ');
    s = s.split('-daγan').join(' ᠳ᠋ᠠᠭᠠᠨ'); s = s.split('-degen').join(' ᠳ᠋ᠡᠭᠡᠨ');
    s = s.split('-aca').join(' ᠡᠴᠡ'); s = s.split('-ece').join(' ᠡᠴᠡ');
    s = s.split('-yin').join(' ᠶᠢᠨ'); s = s.split('-un').join(' ᠤᠨ'); s = s.split('-\u00fcn').join(' ᠤᠨ');
    s = s.split('-da').join(' ᠳ᠋ᠠ'); s = s.split('-de').join(' ᠳ᠋ᠡ');
    s = s.split('-yi').join(' ᠶᠢ'); s = s.split('g-a').join('ᠭ᠎ᠠ');
    s = s.split('-a').join(' ᠠ'); s = s.split('-i').join(' ᠢ'); s = s.split('-e').join(' ᠡ');
    s = s.split('-u').join(' ᠤ'); s = s.split('-\u00fc').join(' ᠤ');
    s = s.split('-d\u00fcr').join('-dur'); s = s.split('-t\u00fcr').join('-tur');
    s = s.split('-dur').join(' ᠳ᠋ᠤᠷ'); s = s.split('-tur').join(' ᠲᠤᠷ');
    s = s.split('-').join(' ᠎');
    return s.split(' ').map(function(word){ return convertWord(word); }).join(' ');
}
function convertWord(word) {
    var out = '', lower = word.toLowerCase(), i = 0;
    while (i < lower.length) {
        var matched = false;
        for (var j = 0; j < ROM_TABLE.length; j++) {
            var rom = ROM_TABLE[j][0], scr = ROM_TABLE[j][1];
            if (lower.substr(i, rom.length) === rom) { out += scr; i += rom.length; matched = true; break; }
        }
        if (!matched) { out += lower[i]; i++; }
    }
    return out;
}

function insertRef(pos) {
    var n = prompt('§ number:');
    if (n !== null && n.trim() !== '') insertAtCursor('para-body-' + pos, '§' + n.trim());
    updatePreview(pos);
}
function insertItalic(pos) {
    var el = document.getElementById('para-body-' + pos);
    if (!el) return;
    var s = el.selectionStart, e = el.selectionEnd, sel = el.value.slice(s, e);
    var replacement = sel ? '<i>' + sel + '</i>' : '<i></i>';
    el.value = el.value.slice(0, s) + replacement + el.value.slice(e);
    el.selectionStart = el.selectionEnd = sel ? s + replacement.length : s + 3;
    el.focus(); updatePreview(pos);
}
function insertAtCursor(id, text) {
    var el = document.getElementById(id);
    if (!el) return;
    var s = el.selectionStart, e = el.selectionEnd;
    el.value = el.value.slice(0, s) + text + el.value.slice(e);
    el.selectionStart = el.selectionEnd = s + text.length;
    el.focus();
}

// ── Table builder ─────────────────────────────────────────────────
function insertTable(pos) {
    var h = document.getElementById('tbl-helper-' + pos);
    if (!h) return;
    h.classList.toggle('visible');
    if (h.classList.contains('visible')) rebuildTblGrid(pos);
}
function hideTbl(pos) { var h = document.getElementById('tbl-helper-' + pos); if (h) h.classList.remove('visible'); }
function rebuildTblGrid(pos) {
    var cols = Math.max(1, Math.min(parseInt(document.getElementById('tbl-cols-' + pos)?.value || 3), 10));
    var rows = Math.max(2, Math.min(parseInt(document.getElementById('tbl-rows-' + pos)?.value || 3), 30));
    var grid = document.getElementById('tbl-grid-' + pos);
    if (!grid) return;
    var html = '<table>';
    for (var r = 0; r < rows; r++) {
        html += '<tr>';
        for (var c = 0; c < cols; c++) {
            html += '<td><input type="text" id="tbl-cell-' + pos + '-' + r + '-' + c + '" placeholder="' + (r === 0 ? 'Header ' + (c+1) : 'Row ' + r + ' · ' + (c+1)) + '"></td>';
        }
        html += '</tr>';
    }
    html += '</table>';
    grid.innerHTML = html;
}
function commitTable(pos) {
    var cols = Math.max(1, Math.min(parseInt(document.getElementById('tbl-cols-' + pos)?.value || 3), 10));
    var rows = Math.max(2, Math.min(parseInt(document.getElementById('tbl-rows-' + pos)?.value || 3), 30));
    var rowParts = [];
    for (var r = 0; r < rows; r++) {
        var cells = [];
        for (var c = 0; c < cols; c++) {
            var val = (document.getElementById('tbl-cell-' + pos + '-' + r + '-' + c)?.value || '').replace(/;;/g, ';').replace(/\|(?![^<]*>)/g, '/');
            cells.push(val);
        }
        rowParts.push(cells.join('|'));
    }
    insertAtCursor('para-body-' + pos, '<tbl>' + rowParts.join(';;') + '</tbl>');
    hideTbl(pos); updatePreview(pos);
}

// ── Paragraph actions ─────────────────────────────────────────────
function submitAction(fields) {
    var form = document.createElement('form');
    form.method = 'post';
    Object.entries(fields).forEach(function([k,v]) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = v;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
}
function addPara(entryId, grammarId) { submitAction({ action: 'add_para', entry_id: entryId, grammar_id: grammarId }); }
function delPara(entryId, pos) {
    if (!confirm('Delete paragraph ' + (pos+1) + '?')) return;
    submitAction({ action: 'del_para', entry_id: entryId, pos: pos });
}
function movePara(entryId, pos, dir) { submitAction({ action: 'move_para', entry_id: entryId, pos: pos, dir: dir }); }

// ── Auto-fill ti2 from ti if ti2 is blank ────────────────────────
var tiInput  = document.getElementById('f-ti');
var ti2Input = document.getElementById('f-ti2');
if (tiInput && ti2Input) {
    tiInput.addEventListener('input', function() {
        if (ti2Input.value.trim() === '' || ti2Input.dataset.autofilled === '1') {
            ti2Input.value = tiInput.value;
            ti2Input.dataset.autofilled = '1';
        }
    });
    ti2Input.addEventListener('input', function() { ti2Input.dataset.autofilled = ''; });
}
</script>
</body>
</html>