<?php
/**
 * Mongolian Dictionary — online search
 * ------------------------------------
 * Data layout:
 *   ./xml/root.xml              : flat <list>/<item> word list
 *   ./xml/{letter}/REFID.xml    : individual entry, where {letter} is
 *                                 the lowercase first letter of REFID
 *   ./xml/tibetan.xml           : Mahāvyutpatti cross-reference list;
 *                                 entries are merged at display time
 *
 * Search matches the beginning of transcript / unicode / cyrillic.
 * Transcript search applies Mongolian equivalence classes — see
 * normalizeTranscript() below.
 */

// ── Authentication ───────────────────────────────────────────────
$LOGIN_FILE = __DIR__ . '/login.txt';

/**
 * Parse login.txt — one "user###password" pair per line.
 * Lines that don't contain "###" or are blank are ignored.
 */
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

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle login POST
$loginError = '';
if (isset($_POST['login_user'])) {
    $logins   = loadLogins($LOGIN_FILE);
    $user     = trim((string)($_POST['login_user'] ?? ''));
    $password = (string)($_POST['login_pass'] ?? '');
    if (isset($logins[$user]) && $logins[$user] === $password) {
        $_SESSION['auth_user'] = $user;
        // Redirect to the same URL (GET) so a refresh doesn't re-POST
        $dest = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
        $qs   = $_GET;
        unset($qs['logout']);
        header('Location: ' . $dest . ($qs ? '?' . http_build_query($qs) : ''));
        exit;
    }
    $loginError = 'Incorrect username or password.';
}

$isLoggedIn = !empty($_SESSION['auth_user']);
$authUser   = $isLoggedIn ? (string)$_SESSION['auth_user'] : '';

// ── Configuration ────────────────────────────────────────────────
$XML_DIR      = __DIR__ . '/xml';
$ROOT_XML     = $XML_DIR . '/root.xml';
$TIBETAN_XML  = $XML_DIR . '/Mv.xml';
$LK4_XML      = $XML_DIR . '/lk4.xml';
$SARAN_XML    = $XML_DIR . '/saran.xml';
$RAGHU_XML    = $XML_DIR . '/raghu.xml';

// Dictionary ordering (lowercase keys). "lamrd" is the spelling
// used in the source XML; "lamdr" is the spelling used in the
// brief — both are accepted and kept adjacent in the order.
$DICO_ORDER = ['lessing','kowalewski','schmidt','boberg','bawden','vietze','chinese','chinese2','lamrd','cevel','tibetan','lk4','saran','raghu','mostaert'];
$DICO_LABELS = [
    'lessing'    => 'Lessing',
    'kowalewski' => 'Kowalewski',
    'schmidt'    => 'Schmidt',
    'boberg'     => 'Boberg',
    'chinese'    => 'Chinese',
    'chinese2'   => 'Chinese 2',
    'lamrd'      => 'Lamrd',
    'bawden'     => 'Bawden',
    'vietze'     => 'Vietze',
    'tibetan'    => 'Mahāvyutpatti',
    'lk4'        => 'Lokesh Chandra',
    'saran'      => 'Saran-u Gerel',
    'raghu'      => 'Raghu Vira',
    'mostaert'   => 'Mostaert',
    'cevel'      => 'Cevel',
];

$DICO_INFO = [
    'lessing' => [
        'title' => 'Mongolian-English Dictionary',
        'meta'  => 'Ferdinand D. Lessing · University of California Press, 1960',
        'body'  => 'The standard Western reference for classical written Mongolian, with ~13,000 headwords. Exceptional depth in Buddhist and Lamaist terminology, with Tibetan and Sanskrit cross-references. Entries include citations from classical texts. An essential tool for researchers working with traditional manuscripts and religious literature.',
    ],
    'kowalewski' => [
        'title' => 'Dictionnaire Mongol-Russe-Français',
        'meta'  => 'Osip M. Kovalevski · Kazan University Press, 1844–1849 (3 vols.)',
        'body'  => 'The most comprehensive 19th-century Mongolian dictionary, with ~25,000–27,000 headwords in Russian and French. Based on extensive fieldwork among Buryat and Mongolian populations, and on a wide range of classical texts including the Buddhist Kanjur and Tanjur. The foundational reference on which all later Western dictionaries drew.',
    ],
    'schmidt' => [
        'title' => 'Mongolisch-Deutsches Wörterbuch',
        'meta'  => 'Isaac Jacob Schmidt · Imperial Academy of Sciences, St. Petersburg, 1835',
        'body'  => 'The first substantial Mongolian dictionary for a German-reading scholarly audience, with ~8,000–10,000 headwords. Schmidt\'s years as a missionary among the Kalmyk population along the Volga gave the work a practical grounding unusual for its era. Best used alongside his companion grammar of 1831. Preceded Kovalevski by nearly a decade.',
    ],
    'boberg' => [
        'title' => 'Mongolian-English Dictionary',
        'meta'  => 'Inga Boberg · Copenhagen, 1954–1955',
        'body'  => 'Grew out of the Scandinavian missionary linguistic tradition in Mongolia. More attentive to colloquial and spoken Mongolian than the purely philological dictionaries. Provides English glosses, making it directly accessible to anglophone users. A useful complement to Lessing for everyday and practical vocabulary that academic dictionaries underrepresent.',
    ],
	'bawden' => [
		'title' => 'Mongolian-English Dictionary',
		'meta'  => 'Charles R. Bawden · Kegan Paul International, 1997',
		'body'  => 'A comprehensive dictionary of modern Mongolian (Khalkha), covering everyday, literary, and scientific vocabulary with ~10,000 headwords. Complements Lessing by focusing on the contemporary written standard rather than classical sources. Particularly useful for 20th-century texts and modern scholarly literature.',
	],
	'vietze' => [
		'title' => 'Mongolisch-Deutsches Wörterbuch',
		'meta'  => 'Hans-Peter Vietze · Verlag Enzyklopädie Leipzig, 1988',
		'body'  => 'A solid German-language reference for modern Khalkha Mongolian, developed within the East German academic tradition of Mongolian studies. Covers standard literary vocabulary with grammatical annotations. Valuable for German-speaking researchers and as a cross-reference alongside English-language dictionaries.',
	],
    'chinese' => [
        'title' => '新蒙汉词典 (New Mongolian-Chinese Dictionary)',
        'meta'  => '编委会编 · Inner Mongolia People\'s Publishing House, Hohhot',
        'body'  => 'The standard Mongolian-Chinese reference for Inner Mongolia, covering classical literary Mongolian (in traditional script) alongside modern standard and administrative vocabulary. Compiled by an institutional editorial committee and revised across multiple editions. Reflects the bilingual governance needs of the Inner Mongolia Autonomous Region and stands at the end of a lexicographical tradition reaching back to Qing dynasty multilingual glossaries.',
    ],
    'chinese2' => [
        'title' => '蒙汉词典（增订本）(Mongolian-Chinese Dictionary, Revised Edition)',
        'meta'  => '内蒙古大学蒙古学研究院蒙古语文研究所编 · Inner Mongolia University Press, 1999',
        'body'  => 'A revised and expanded Mongolian-Chinese dictionary compiled by the Institute of Mongolian Language and Literature at the Academy of Mongolian Studies, Inner Mongolia University. Published in 1999 in A5 format, with a preface and conventions (pp. 1–30), main text (pp. 1–1414), and appendices (pp. 1415–1519). Covers classical written Mongolian (in traditional script) alongside modern standard vocabulary, with Chinese glosses throughout. Represents the Inner Mongolian institutional lexicographical tradition and serves as a companion to the 新蒙汉词典.',
    ],
    'tibetan' => [
        'title' => 'Mahāvyutpatti (in progress)',
        'meta'  => 'Tibetan-Sanskrit-Mongolian canonical glossary',
        'body'  => 'Entries drawn from the Mahāvyutpatti, a canonical multilingual Buddhist glossary compiled in Tibet in the early 9th century. Provides Tibetan, Sanskrit, and Mongolian equivalents for Buddhist technical terminology. Used here as a cross-reference for entries that appear in the Mongolian Buddhist canonical translation tradition.',
    ],
    'lk4' => [
        'title' => 'Tibetan-Mongolian Dictionary (in progress)',
        'meta'  => 'Lokesh Chandra · International Academy of Indian Culture, New Delhi',
        'body'  => 'A Tibetan-Mongolian dictionary compiled by Lokesh Chandra, providing Mongolian equivalents for Tibetan Buddhist terminology. Drawn from the tradition of Tibetan-Mongolian lexicographical works produced in connection with the translation of canonical literature. An important resource for tracing the Tibetan sources of Mongolian Buddhist vocabulary.',
    ],
    'saran' => [
        'title' => 'Saran-u Gerel Tibetan-Mongolian Dictionary (in progress)',
        'meta'  => 'Tibetan-Mongolian Buddhist glossary',
        'body'  => 'A Tibetan-Mongolian dictionary providing Mongolian equivalents for Tibetan Buddhist terminology, keyed by canonical reference. Complements the Lokesh Chandra dictionary as an additional lexicographical source for Buddhist vocabulary in the Mongolian translation tradition.',
    ],
    'raghu' => [
        'title' => 'A New Mongolian-Sanskrit Dictionary (in progress)',
        'meta'  => 'Raghu Vira · International Academy of Indian Culture, New Delhi, 1953',
        'body'  => 'A Sanskrit-Mongolian dictionary compiled by Raghu Vira, providing Sanskrit equivalents for Mongolian Buddhist terms drawn from the Mongolian canonical translation tradition. Entries pair Sanskrit terms (in IAST transliteration) with their Mongolian equivalents in traditional script, cross-referenced to canonical works. An important resource for identifying the Sanskrit originals behind Mongolian Buddhist vocabulary and for tracing terminological choices made by Mongolian translators.',
    ],
    'mostaert' => [
        'title' => 'Dictionnaire ordos (in progress)',
        'meta'  => 'Antoine Mostaert · Catholic University of America Press, Washington D.C., 1941–1944',
        'body'  => 'A comprehensive French-language dictionary of Ordos Mongolian, a dialect spoken in the Ordos region of Inner Mongolia. Compiled by the Belgian missionary and scholar Antoine Mostaert, it remains a foundational reference for Ordos dialectology and for the study of spoken Mongolian varieties distinct from the classical written tradition.',
    ],
    'cevel' => [
        'title' => 'Монгол хэлний товч тайлбар толь',
        'meta'  => 'Ya. Cevel · Mongolian Academy of Sciences, Institute of Language and Literature · Ulaanbaatar, 1966',
        'body'  => 'A concise explanatory dictionary of the Mongolian language (~30,000 headwords), authored by Ya. Cevel and edited by Kh. Luvsanbaldan. Compiled under the auspices of the Academy of Sciences of the Mongolian People\'s Republic and published by the State Publishing Committee in Ulaanbaatar. Unlike bilingual dictionaries, this is a monolingual Mongolian reference work: definitions are given in Mongolian, with etymological notes and stylistic labels. Based on an extensive survey of the literary vocabulary and reflects the modern Khalkha written standard. An important primary source for the Mongolian lexicographical tradition of the 20th century.',
    ],
];


// ── Transcript normalization ─────────────────────────────────────
// Produces a canonical form for the equivalence classes described
// in the brief. Words whose normalized forms share a common prefix
// are considered matches.
function normalizeTranscript(string $word): string {
    $word  = mb_strtolower($word, 'UTF-8');
    $word  = str_replace('-', '', $word);   // ignore hyphens: aqa matches aq-a
    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
    $n     = count($chars);
    $out   = '';

    $back  = ['a','o','u'];           // back vowels
    $front = ['e','i','ö','ü'];       // front vowels

    for ($i = 0; $i < $n; $i++) {
        $ch   = $chars[$i];
        $prev = $i > 0      ? $chars[$i-1] : '';
        $next = $i < $n - 1 ? $chars[$i+1] : '';

        // rounded vowels
        if     ($ch === 'o' || $ch === 'u') { $out .= 'U'; }          // back
        elseif ($ch === 'ö' || $ch === 'ü') { $out .= 'Ö'; }          // front

        // velar consonants
        elseif ($ch === 'γ' || $ch === 'q') { $out .= 'Q'; }          // always back
        elseif ($ch === 'g' || $ch === 'k') {
            // g is the ambiguous one — back with back vowels, front with front vowels
            if      (in_array($next,  $back, true) || in_array($prev,  $back, true)) $out .= 'Q';
            elseif  (in_array($next, $front, true) || in_array($prev, $front, true)) $out .= 'K';
            else                                                                     $out .= 'Q';
        }

        // dental stops
        elseif ($ch === 't' || $ch === 'd') { $out .= 'T'; }

        // labial stops
        elseif ($ch === 'b' || $ch === 'p' || $ch === 'f') { $out .= 'B'; }

        // j/y word-initial → Y; j/c (and č, ǰ) medial → C
        elseif (($ch === 'j' || $ch === 'ǰ' || $ch === 'y') && $i === 0) { $out .= 'Y'; }
        elseif (($ch === 'j' || $ch === 'ǰ' || $ch === 'c' || $ch === 'č') && $i > 0) { $out .= 'C'; }

        else { $out .= $ch; }
    }
    return $out;
}

// ── Data loading ─────────────────────────────────────────────────
function loadRoots(string $rootXml): array {
    if (!file_exists($rootXml)) return [];
    $xml = @simplexml_load_file($rootXml);
    if ($xml === false) return [];
    $out = [];
    foreach ($xml->item as $it) {
        $variants = [];
        if (isset($it->variants->v)) {
            foreach ($it->variants->v as $v) {
                $variants[] = ['type' => (string)$v['t'], 'value' => (string)$v];
            }
        }
        $out[] = [
            'ref'         => (string)$it->ref,
            'unicode'     => (string)$it->unicode,
            'transcript'  => (string)$it->transcript,
            'transcript2' => (string)$it->transcript2,
            'cyrillic'    => (string)$it->cyrillic,
            'variants'    => $variants,
        ];
    }
    return $out;
}

function loadEntry(string $ref, string $xmlDir): ?SimpleXMLElement {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $ref)) return null;       // path-traversal guard
    $letter = strtolower(substr($ref, 0, 1));
    $path   = $xmlDir . '/' . $letter . '/' . $ref . '.xml';
    if (!file_exists($path)) return null;
    $xml = @simplexml_load_file($path);
    return $xml === false ? null : $xml;
}

// Load see_also refs from an entry's XML element.
function loadSeeAlso(?SimpleXMLElement $entry): array {
    if ($entry === null) return [];
    $out = [];
    if (isset($entry->see_also->ref)) {
        foreach ($entry->see_also->ref as $r) {
            $out[] = (string)$r;
        }
    }
    return $out;
}

// Load approximate refs from an entry's XML element.
function loadApproximate(?SimpleXMLElement $entry): array {
    if ($entry === null) return [];
    $out = [];
    if (isset($entry->approximate->ref)) {
        foreach ($entry->approximate->ref as $r) {
            $out[] = (string)$r;
        }
    }
    return $out;
}

// Load related_to refs from an entry's XML element.
function loadRelatedTo(?SimpleXMLElement $entry): array {
    if ($entry === null) return [];
    $out = [];
    if (isset($entry->related_to->ref)) {
        foreach ($entry->related_to->ref as $r) {
            $out[] = (string)$r;
        }
    }
    return $out;
}

// Load derivatives refs from an entry's XML element.
function loadDerivatives(?SimpleXMLElement $entry): array {
    if ($entry === null) return [];
    $out = [];
    if (isset($entry->derivatives->ref)) {
        foreach ($entry->derivatives->ref as $r) {
            $out[] = (string)$r;
        }
    }
    return $out;
}

// Loads tibetan.xml and returns a map: ref_id => array of assoc arrays.
// Each assoc array represents one Mahāvyutpatti item that cites that ref.
function loadTibetan(string $tibetanXml): array {
    if (!file_exists($tibetanXml)) return [];
    $xml = @simplexml_load_file($tibetanXml);
    if ($xml === false) return [];
    $map = [];
    foreach ($xml->item as $item) {
        $refStr = (string)$item->ref;
        // Extract valid ref IDs between # delimiters; skip "+++ " placeholders
        preg_match_all('/#([A-Za-z0-9]+)(?=#)/', $refStr, $m);
        foreach ($m[1] as $id) {
            $map[$id][] = [
                'coll' => (string)$item->coll,
                'mng'  => (string)$item->mng,
                'mng2' => (string)$item->mng2,
                'skt'  => (string)$item->skt,
                'tib'  => (string)$item->tib,
                'page' => (string)$item->page,
                'refs' => $m[1],   // all ref IDs from this item
            ];
        }
    }
    return $map;
}

// Loads lk4.xml and returns a map: ref_id => array of assoc arrays.
// Each entry has: tib (Wylie), mng (Unicode), page, refs[].
function loadLk4(string $lk4Xml): array {
    if (!file_exists($lk4Xml)) return [];
    $xml = @simplexml_load_file($lk4Xml);
    if ($xml === false) return [];
    $map = [];
    foreach ($xml->entry as $entry) {
        $refs = [];
        foreach ($entry->refs->ref ?? [] as $r) {
            $s = trim((string)$r);
            if ($s !== '') $refs[] = $s;
        }
        foreach ($refs as $r) {
            $map[$r][] = [
                'tib'  => (string)$entry->tib,
                'mng'  => (string)$entry->mng,
                'page' => (string)$entry->page,
                'refs' => $refs,
            ];
        }
    }
    return $map;
}

// Loads saran.xml — identical structure to lk4.xml.
function loadSaran(string $saranXml): array {
    if (!file_exists($saranXml)) return [];
    $xml = @simplexml_load_file($saranXml);
    if ($xml === false) return [];
    $map = [];
    foreach ($xml->entry as $entry) {
        $refs = [];
        foreach ($entry->refs->ref ?? [] as $r) {
            $s = trim((string)$r);
            if ($s !== '') $refs[] = $s;
        }
        foreach ($refs as $r) {
            $map[$r][] = [
                'tib'  => (string)$entry->tib,
                'mng'  => (string)$entry->mng,
                'page' => (string)$entry->page,
                'refs' => $refs,
            ];
        }
    }
    return $map;
}

// Loads raghu.xml — Sanskrit-Mongolian dictionary.
// Structure: <list><entry id="rg…"><skt>…</skt><mng>…</mng><page>…</page><refs><ref>…</ref></refs></entry></list>
// Returns a map: ref_id => array of assoc arrays.
function loadRaghu(string $raghuXml): array {
    if (!file_exists($raghuXml)) return [];
    $xml = @simplexml_load_file($raghuXml);
    if ($xml === false) return [];
    $map = [];
    foreach ($xml->entry as $entry) {
        $refs = [];
        foreach ($entry->refs->ref ?? [] as $r) {
            $s = trim((string)$r);
            if ($s !== '') $refs[] = $s;
        }
        foreach ($refs as $r) {
            $map[$r][] = [
                'id'   => (string)$entry['id'],
                'skt'  => (string)$entry->skt,
                'mng'  => (string)$entry->mng,
                'page' => (string)$entry->page,
                'refs' => $refs,
            ];
        }
    }
    return $map;
}

// ── Fuzzy vowel normalization ─────────────────────────────────────
// Collapses all vowels into a single class so that, e.g., searching for
// "ibilkü" also finds "ibelkü", "abulku", etc.
// Only used for the fuzzy/approximate search mode.
function normalizeFuzzy(string $word): string {
    $word  = mb_strtolower($word, 'UTF-8');
    $word  = str_replace('-', '', $word);
    // Replace every vowel (back and front, including umlauts) with a
    // single placeholder 'V' so all vowel variants become equivalent.
    $word = preg_replace('/[aeiouöü]/u', 'V', $word);
    return $word;
}

// ── Highlight helpers ─────────────────────────────────────────────
/**
 * Wrap the matched portion of a transcript string in <mark>.
 * Uses normalizeTranscript equivalence classes for matching.
 * $contains: if true find anywhere; if false, always starts at 0.
 */
function buildNormPosMap(array $origChars): array {
    // Maps normalized position → original char position.
    // normalizeTranscript may collapse or skip chars (hyphens removed),
    // so we rebuild the map character by character.
    $map    = [];
    $normI  = 0;
    $n      = count($origChars);
    // We feed chars one-by-one through a simplified version of normalizeTranscript
    // to track which original index maps to which normalized index.
    // Use full normalization on each growing prefix and diff.
    $prevLen = 0;
    for ($i = 0; $i < $n; $i++) {
        $partial = implode('', array_slice($origChars, 0, $i + 1));
        $norm    = normalizeTranscript($partial);
        $newLen  = mb_strlen($norm, 'UTF-8');
        for ($j = $prevLen; $j < $newLen; $j++) {
            $map[$j] = $i;
        }
        $prevLen = $newLen;
    }
    return $map;
}

function highlightTranscript(string $original, string $query, bool $contains): string {
    if ($original === '' || $query === '') return h($original);

    $origChars = preg_split('//u', $original, -1, PREG_SPLIT_NO_EMPTY);
    $normOrig  = normalizeTranscript($original);
    $normQuery = normalizeTranscript($query);
    $normLen   = mb_strlen($normQuery, 'UTF-8');

    $normStart = $contains
        ? mb_strpos($normOrig, $normQuery, 0, 'UTF-8')
        : (mb_strpos($normOrig, $normQuery, 0, 'UTF-8') === 0 ? 0 : false);

    if ($normStart === false) {
        return highlightPlain($original, $query, $contains);
    }

    $normEnd = $normStart + $normLen - 1;
    $posMap  = buildNormPosMap($origChars);

    $origStart = $posMap[$normStart] ?? null;
    $origEnd   = $posMap[$normEnd]   ?? null;
    if ($origStart === null || $origEnd === null) return h($original);

    $out = '';
    $inMark = false;
    $n = count($origChars);
    for ($i = 0; $i < $n; $i++) {
        if ($i === $origStart) { $out .= '<mark>'; $inMark = true; }
        $out .= h($origChars[$i]);
        if ($i === $origEnd && $inMark) { $out .= '</mark>'; $inMark = false; }
    }
    if ($inMark) $out .= '</mark>';
    return $out;
}

function highlightPlain(string $original, string $query, bool $contains): string {
    if ($original === '' || $query === '') return h($original);

    $origChars = preg_split('//u', $original, -1, PREG_SPLIT_NO_EMPTY);
    $qLower    = mb_strtolower($query, 'UTF-8');
    $origLower = mb_strtolower($original, 'UTF-8');

    $start = $contains
        ? mb_strpos($origLower, $qLower, 0, 'UTF-8')
        : 0;

    if ($start === false) return h($original);

    $qLen = mb_strlen($qLower, 'UTF-8');
    if (!$contains && mb_substr($origLower, 0, $qLen, 'UTF-8') !== $qLower) return h($original);

    $end = $start + $qLen - 1;
    $out = '';
    $n   = count($origChars);
    for ($i = 0; $i < $n; $i++) {
        if ($i === $start) $out .= '<mark>';
        $out .= h($origChars[$i]);
        if ($i === $end)   $out .= '</mark>';
    }
    return $out;
}

// ── Search ───────────────────────────────────────────────────────
/**
 * Search headwords.
 *
 * $contains = false (default): match only words that BEGIN WITH the query.
 * $contains = true:            match words that CONTAIN the query anywhere.
 *
 * $fuzzy = false (default): exact match (modulo existing equivalence classes).
 * $fuzzy = true:            approximate match — all vowels are treated as
 *                           interchangeable (a/e/i/o/u/ö/ü), so "ibilkü"
 *                           also finds "ibelkü", "abulku", etc.
 *                           Fuzzy mode applies only to transcript fields;
 *                           unicode/cyrillic fields still use exact matching.
 *
 * The trailing-n expansion is kept in begins-exact mode; disabled in all
 * other modes (it would produce too much noise).
 */
function searchWords(string $query, array $roots, bool $contains = false, bool $fuzzy = false): array {
    $query  = trim($query);
    if ($query === '') return [];

    // Trailing-n expansion only in begins-exact mode.
    $queries = [$query];
    if (!$contains && !$fuzzy && mb_strtolower(mb_substr($query, -1, 1, 'UTF-8'), 'UTF-8') === 'n') {
        $queries[] = mb_substr($query, 0, mb_strlen($query, 'UTF-8') - 1, 'UTF-8');
    }

    // Helper: test position against the contains/begins mode.
    $posMatch = $contains
        ? fn($pos): bool => $pos !== false
        : fn($pos): bool => $pos === 0;

    $seen = [];
    $hits = [];

    foreach ($queries as $q) {
        $qLower    = mb_strtolower($q, 'UTF-8');
        $qNorm     = normalizeTranscript($q);
        $qFuzzy    = $fuzzy ? normalizeFuzzy($q) : '';

        foreach ($roots as $item) {
            $ref = $item['ref'];
            if (isset($seen[$ref])) continue;

            $matched = false;

            if ($fuzzy) {
                // Fuzzy mode: vowel-agnostic match on transcript fields only.
                // Unicode / cyrillic fields: still exact-prefix/contains.
                if ($item['unicode'] !== '' && $posMatch(mb_strpos($item['unicode'], $q))) {
                    $matched = true;
                } elseif ($item['cyrillic'] !== '' &&
                    $posMatch(mb_stripos(mb_strtolower($item['cyrillic'], 'UTF-8'), $qLower))) {
                    $matched = true;
                } elseif ($item['transcript'] !== '' &&
                    $posMatch(strpos(normalizeFuzzy($item['transcript']), $qFuzzy))) {
                    $matched = true;
                } elseif ($item['transcript2'] !== '' &&
                    $posMatch(strpos(normalizeFuzzy($item['transcript2']), $qFuzzy))) {
                    $matched = true;
                } else {
                    foreach ($item['variants'] as $v) {
                        $vVal = $v['value'];
                        if ($vVal === '') continue;
                        $vType = $v['type'];
                        if ($vType === 'cyrillic') {
                            if ($posMatch(mb_stripos(mb_strtolower($vVal, 'UTF-8'), $qLower))) {
                                $matched = true; break;
                            }
                        } elseif ($vType === 'unicode') {
                            if ($posMatch(mb_strpos($vVal, $q))) {
                                $matched = true; break;
                            }
                        } else {
                            if ($posMatch(strpos(normalizeFuzzy($vVal), $qFuzzy))) {
                                $matched = true; break;
                            }
                        }
                    }
                }
            } else {
                // Exact mode (original behaviour, extended with $contains).
                if ($item['unicode'] !== '' && $posMatch(mb_strpos($item['unicode'], $q))) {
                    $matched = true;
                } elseif ($item['cyrillic'] !== '' &&
                    $posMatch(mb_stripos(mb_strtolower($item['cyrillic'], 'UTF-8'), $qLower))) {
                    $matched = true;
                } elseif ($item['transcript'] !== '' &&
                    $posMatch(strpos(normalizeTranscript($item['transcript']), $qNorm))) {
                    $matched = true;
                } elseif ($item['transcript2'] !== '' &&
                    $posMatch(strpos(normalizeTranscript($item['transcript2']), $qNorm))) {
                    $matched = true;
                } else {
                    foreach ($item['variants'] as $v) {
                        $vVal = $v['value'];
                        if ($vVal === '') continue;
                        $vType = $v['type'];
                        if ($vType === 'cyrillic') {
                            if ($posMatch(mb_stripos(mb_strtolower($vVal, 'UTF-8'), $qLower))) {
                                $matched = true; break;
                            }
                        } elseif ($vType === 'unicode') {
                            if ($posMatch(mb_strpos($vVal, $q))) {
                                $matched = true; break;
                            }
                        } else {
                            if ($posMatch(strpos(normalizeTranscript($vVal), $qNorm))) {
                                $matched = true; break;
                            }
                        }
                    }
                }
            }

            if ($matched) {
                $hits[]     = $item;
                $seen[$ref] = true;
            }
        }
    }
    return $hits;
}

// ── Result ordering ──────────────────────────────────────────────
// Converts a transcript string into an array of integer rank values,
// one per logical character, following the Mongolian alphabet order:
//   a(1) e(2) i(3) o=u(4) ö=ü(5) n(6) b=p=f(7) q=γ(8) m(9) l(10)
//   s=š(11) t=d(12) č=ǰ medial / ǰ=y initial (13) k=g(14) r(15) h(16)
// Unknown characters get rank 99 (sort to end).
//
// Position-sensitive rules (matching the existing normalizeTranscript logic):
//   • word-initial j / ǰ / y  → rank 13  (same group)
//   • medial      j / ǰ / č   → rank 13  (same group)
//   • word-initial c / č are treated as medial rule does not apply
function transcriptSortKey(string $transcript): array {
    $s     = mb_strtolower(str_replace('-', '', $transcript), 'UTF-8');
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $n     = count($chars);
    $key   = [];

    $back  = ['a','o','u'];
    $front = ['e','i','ö','ü'];

    for ($i = 0; $i < $n; $i++) {
        $ch   = $chars[$i];
        $prev = $i > 0      ? $chars[$i - 1] : '';
        $next = $i < $n - 1 ? $chars[$i + 1] : '';

        switch (true) {
            // Vowels
            case $ch === 'a':                            $key[] =  1; break;
            case $ch === 'e':                            $key[] =  2; break;
            case $ch === 'i':                            $key[] =  3; break;
            case $ch === 'o' || $ch === 'u':             $key[] =  4; break;
            case $ch === 'ö' || $ch === 'ü':             $key[] =  5; break;

            // Consonants
            case $ch === 'n':                            $key[] =  6; break;
            case $ch === 'b' || $ch === 'p' || $ch === 'f': $key[] = 7; break;
            case $ch === 'q' || $ch === 'γ':             $key[] =  8; break;

            // g: back-vowel context → same as q/γ (8); front-vowel → same as k (14)
            case $ch === 'g':
                if (in_array($next, $back, true) || in_array($prev, $back, true)) {
                    $key[] = 8;
                } else {
                    $key[] = 14;
                }
                break;

            case $ch === 'm':                            $key[] =  9; break;
            case $ch === 'l':                            $key[] = 10; break;
            case $ch === 's' || $ch === 'š':             $key[] = 11; break;
            case $ch === 't' || $ch === 'd':             $key[] = 12; break;

            // č / ǰ / j / c / y — all collapse to rank 13; position irrelevant for ordering
            case $ch === 'č' || $ch === 'ǰ'
              || $ch === 'j' || $ch === 'c'
              || $ch === 'y':                            $key[] = 13; break;

            case $ch === 'k':                            $key[] = 14; break;
            case $ch === 'r':                            $key[] = 15; break;
            case $ch === 'h':                            $key[] = 16; break;

            default:                                     $key[] = 99; break;
        }
    }
    return $key;
}

// Compare two sort-key arrays lexicographically.
function compareSortKeys(array $a, array $b): int {
    $len = min(count($a), count($b));
    for ($i = 0; $i < $len; $i++) {
        if ($a[$i] !== $b[$i]) return $a[$i] <=> $b[$i];
    }
    return count($a) <=> count($b);
}

// Sort search results by Mongolian alphabet order on the transcript field.
// Falls back to transcript2 when transcript is empty.
function sortSearchResults(array $hits): array {
    usort($hits, function (array $a, array $b): int {
        $tA = $a['transcript'] !== '' ? $a['transcript'] : $a['transcript2'];
        $tB = $b['transcript'] !== '' ? $b['transcript'] : $b['transcript2'];
        return compareSortKeys(transcriptSortKey($tA), transcriptSortKey($tB));
    });
    return $hits;
}


function sortEntryItems(SimpleXMLElement $entry, array $dicoOrder): array {
    $items = [];
    if (isset($entry->entries->entry_item)) {
        foreach ($entry->entries->entry_item as $it) $items[] = $it;
    }
    // Stable sort: preserve original order within the same dictionary
    $tmp = [];
    foreach ($items as $i => $it) $tmp[] = [$i, $it];
    usort($tmp, function($a, $b) use ($dicoOrder) {
        $ka = strtolower(trim((string)$a[1]->dico));
        $kb = strtolower(trim((string)$b[1]->dico));
        $ia = array_search($ka, $dicoOrder, true); if ($ia === false) $ia = 999;
        $ib = array_search($kb, $dicoOrder, true); if ($ib === false) $ib = 999;
        if ($ia !== $ib) return $ia <=> $ib;
        return $a[0] <=> $b[0]; // preserve doc order within a group
    });
    return array_map(fn($p) => $p[1], $tmp);
}

// ── Helpers ──────────────────────────────────────────────────────
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Dispatch ─────────────────────────────────────────────────────
$query    = isset($_GET['q'])        ? trim((string)$_GET['q'])   : '';
$ref      = isset($_GET['ref'])      ? trim((string)$_GET['ref']) : '';
$contains = isset($_GET['contains']) && $_GET['contains'] === '1';
$fuzzy    = isset($_GET['fuzzy'])    && $_GET['fuzzy']    === '1';
$roots    = loadRoots($ROOT_XML);

$results          = [];
$entryXml         = null;
$entryItemsSorted = [];
$entrySeeAlso     = [];   // array of ['ref'=>, 'unicode'=>, 'transcript'=>, 'cyrillic'=>]
$entryApproximate = [];   // same shape
$entryRelatedTo   = [];   // same shape
$entryDerivatives = [];   // same shape

if ($ref !== '') {
    $entryXml = loadEntry($ref, $XML_DIR);
    if ($entryXml !== null) {
        // Sort the entry's own items by dictionary order
        $entryItemsSorted = sortEntryItems($entryXml, $DICO_ORDER);
    }
    // Merge Tibetan items from tibetan.xml — always, even if no entry XML file exists
    // (some refs appear only as Mahāvyutpatti cross-references, e.g. N0281).
    $tibMap = loadTibetan($TIBETAN_XML);
    foreach (($tibMap[$ref] ?? []) as $t) {
            $defParts = [];
            if ($t['skt'] !== '') $defParts[] = '[skt: ' . $t['skt'] . ']';
            $definition = htmlspecialchars(
                $t['coll'] . ($defParts ? ' — ' . implode(' ', $defParts) : ''),
                ENT_XML1 | ENT_QUOTES, 'UTF-8'
            );
            $unicode  = htmlspecialchars($t['mng'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $unicode2 = htmlspecialchars($t['mng2'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tibEsc   = htmlspecialchars($t['tib'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $pageEsc  = htmlspecialchars($t['page'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $refsXml  = '<refs>';
            foreach ($t['refs'] as $rid) {
                $refsXml .= '<r>' . htmlspecialchars($rid, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</r>';
            }
            $refsXml .= '</refs>';
            $frag = simplexml_load_string(
                "<entry_item><dico>tibetan</dico>" .
                "<unicode>{$unicode}</unicode>" .
                "<unicode2>{$unicode2}</unicode2>" .
                "<tib>{$tibEsc}</tib>" .
                "<page>{$pageEsc}</page>" .
                "<definition>{$definition}</definition>" .
                $refsXml .
                "</entry_item>"
            );
            if ($frag !== false) $entryItemsSorted[] = $frag;
        }

        // Merge lk4 items — Tibetan-Mongolian dictionary entries keyed by Tibetan ref
        $lk4Map = loadLk4($LK4_XML);
        foreach (($lk4Map[$ref] ?? []) as $lk4) {
            $mngEsc  = htmlspecialchars($lk4['mng'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tibEsc  = htmlspecialchars($lk4['tib'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $pageEsc = htmlspecialchars($lk4['page'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $defEsc  = $tibEsc;
            $frag = simplexml_load_string(
                "<entry_item><dico>lk4</dico>" .
                "<unicode>{$mngEsc}</unicode>" .
                "<page>{$pageEsc}</page>" .
                "<definition>{$defEsc}</definition></entry_item>"
            );
            if ($frag !== false) $entryItemsSorted[] = $frag;
        }

        // Merge saran items — same structure as lk4
        $saranMap = loadSaran($SARAN_XML);
        foreach (($saranMap[$ref] ?? []) as $saran) {
            $mngEsc  = htmlspecialchars($saran['mng'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tibEsc  = htmlspecialchars($saran['tib'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $pageEsc = htmlspecialchars($saran['page'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $defEsc  = $tibEsc;
            $frag = simplexml_load_string(
                "<entry_item><dico>saran</dico>" .
                "<unicode>{$mngEsc}</unicode>" .
                "<page>{$pageEsc}</page>" .
                "<definition>{$defEsc}</definition></entry_item>"
            );
            if ($frag !== false) $entryItemsSorted[] = $frag;
        }

        // Merge raghu items — Sanskrit-Mongolian dictionary entries keyed by canonical ref
        $raghuMap = loadRaghu($RAGHU_XML);
        foreach (($raghuMap[$ref] ?? []) as $rg) {
            $mngEsc  = htmlspecialchars($rg['mng'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sktEsc  = htmlspecialchars($rg['skt'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $pageEsc = htmlspecialchars($rg['page'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $frag = simplexml_load_string(
                "<entry_item><dico>raghu</dico>" .
                "<unicode>{$mngEsc}</unicode>" .
                "<page>{$pageEsc}</page>" .
                "<definition>{$sktEsc}</definition></entry_item>"
            );
            if ($frag !== false) $entryItemsSorted[] = $frag;
        }

        // Build see-also / related / derivatives — only possible with an entry XML file
        if ($entryXml !== null) {
            $saRefs = loadSeeAlso($entryXml);
            if ($saRefs) {
                $rootMap = [];
                foreach ($roots as $_r) { $rootMap[$_r['ref']] = $_r; }
                foreach ($saRefs as $saRef) {
                    if (!preg_match('/^[A-Za-z0-9_-]+$/', $saRef)) continue;
                    $info = $rootMap[$saRef] ?? null;
                    $entrySeeAlso[] = [
                        'ref'        => $saRef,
                        'unicode'    => $info['unicode']    ?? '',
                        'transcript' => $info['transcript'] ?? '',
                        'cyrillic'   => $info['cyrillic']   ?? '',
                    ];
                }
            }

            $rootMap = $rootMap ?? (function() use ($roots) {
                $m = []; foreach ($roots as $_r) { $m[$_r['ref']] = $_r; } return $m;
            })();

            foreach (loadApproximate($entryXml) as $apRef) {
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $apRef)) continue;
                $info = $rootMap[$apRef] ?? null;
                $entryApproximate[] = [
                    'ref'        => $apRef,
                    'unicode'    => $info['unicode']    ?? '',
                    'transcript' => $info['transcript'] ?? '',
                    'cyrillic'   => $info['cyrillic']   ?? '',
                ];
            }

            foreach (loadRelatedTo($entryXml) as $rtRef) {
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $rtRef)) continue;
                $info = $rootMap[$rtRef] ?? null;
                $entryRelatedTo[] = [
                    'ref'        => $rtRef,
                    'unicode'    => $info['unicode']    ?? '',
                    'transcript' => $info['transcript'] ?? '',
                    'cyrillic'   => $info['cyrillic']   ?? '',
                ];
            }

            foreach (loadDerivatives($entryXml) as $dvRef) {
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $dvRef)) continue;
                $info = $rootMap[$dvRef] ?? null;
                $entryDerivatives[] = [
                    'ref'        => $dvRef,
                    'unicode'    => $info['unicode']    ?? '',
                    'transcript' => $info['transcript'] ?? '',
                    'cyrillic'   => $info['cyrillic']   ?? '',
                ];
            }
        }
} elseif ($query !== '') {
    $results = sortSearchResults(searchWords($query, $roots, $contains, $fuzzy));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lexicographical resources for the Mongolian Language<?= $query !== '' ? ' · '.h($query) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Noto+Serif:ital,wght@0,400;0,600;1,400&display=swap">
<style>
  :root {
      --paper:     #ffffff;      /* page bg — white */
      --paper-2:   #f2f6fb;      /* surface 2 — masthead, soft fills */
      --ink:       #0f2747;      /* deep navy — primary text */
      --ink-soft:  #51678a;      /* muted slate — secondary text */
      --rule:      #c4d4e6;      /* soft blue-gray — borders, dividers */
      --accent:    #1c4f8b;      /* oxford blue — primary accent */
      --accent-2:  #4a8bc4;      /* lighter blue — for secondary tags */
      --highlight: #dfeaf6;      /* very pale blue — code/highlight bg */
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
      max-width: 1100px; margin: 0 auto; padding: 0 1.5rem;
      display: flex; align-items: center; gap: 1rem; flex-wrap: nowrap;
  }
  .search-group {
      flex: 1; min-width: 0;
      display: flex; align-items: center; gap: .5rem;
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
      flex: 1; min-width: 0;
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

  /* ── Search-mode toggle ── */
  .search-mode {
      display: flex; align-items: center;
      gap: 0; flex-shrink: 0;
      border: 1px solid var(--rule);
      border-radius: 2px; overflow: hidden;
      font-family: "EB Garamond", serif;
      font-size: .78rem; letter-spacing: .04em;
  }
  .search-mode input[type=radio] {
      position: absolute; opacity: 0;
      width: 1px; height: 1px;
      pointer-events: none; margin: 0;
  }
  .search-mode label {
      display: block;
      padding: .42rem .72rem;
      cursor: pointer;
      color: var(--ink-soft);
      background: var(--paper);
      white-space: nowrap;
      transition: background .12s, color .12s;
      user-select: none;
      line-height: 1.2;
  }
  .search-mode label:not(:last-of-type) { border-right: 1px solid var(--rule); }
  .search-mode input[type=radio]:checked + label {
      background: var(--accent); color: #fff;
  }
  .search-mode input[type=radio]:focus-visible + label {
      outline: 2px solid var(--accent); outline-offset: -2px;
  }
  .search-mode .mode-sep {
      width: 1px; background: var(--rule); align-self: stretch;
  }

  /* ── Search-match highlight ── */
  mark {
      background: #fde68a;
      color: inherit;
      border-radius: 2px;
      padding: 0 .05em;
  }

  /* ── Masthead user controls ── */
  .mast-user {
      display: flex; align-items: center; gap: .85rem;
      flex-shrink: 0;
      font-family: "EB Garamond", serif; font-size: .97rem;
  }
  .mast-edit-link {
      display: inline-flex; align-items: center; gap: .25rem;
      background: var(--accent); color: #fff;
      font-family: "EB Garamond", serif; font-weight: 600;
      font-size: .82rem; letter-spacing: .06em; text-transform: uppercase;
      text-decoration: none;
      padding: .35rem .75rem; border-radius: 2px;
      transition: background .15s;
  }
  .mast-edit-link:hover { background: #143d6e; }
  .mast-username {
      color: var(--ink-soft); font-style: italic;
  }
  .mast-logout {
      color: var(--ink-soft); text-decoration: none;
      border-bottom: 1px dotted var(--rule);
      transition: color .12s;
  }
  .mast-logout:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Masthead inline sign-in dropdown ── */
  .mast-login-wrap { position: relative; }
  .mast-signin-btn {
      border: 1px solid var(--rule); background: transparent;
      color: var(--ink-soft);
      font-family: "EB Garamond", serif; font-size: .97rem;
      font-style: italic;
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
      width: 240px;
      background: #fff; border: 1px solid var(--rule);
      border-radius: 3px;
      box-shadow: 0 4px 20px rgba(15,39,71,.12);
      padding: 1.1rem 1.1rem .9rem;
      z-index: 200;
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
      background: var(--paper); border-radius: 2px;
      transition: border-color .12s;
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
      padding: .35rem .6rem; font-size: .85rem; font-style: normal;
      color: #b04545; margin-bottom: .7rem; border-radius: 1px;
  }

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
  html { scroll-behavior: smooth; }
  .dico-section { scroll-margin-top: 1.5rem; }

  .dict-nav ul li a.inert {
      opacity: .35; cursor: default; pointer-events: none;
  }

  /* ── Nav group labels ── */
  .dict-nav-group {
      font-family: "EB Garamond", serif;
      font-size: .63rem; font-weight: 600;
      letter-spacing: .13em; text-transform: uppercase;
      color: var(--ink-soft);
      padding: .55rem .65rem .2rem .75rem;
      margin-top: .35rem;
      opacity: .65;
      line-height: 1;
  }
  .dict-nav ul li:first-child .dict-nav-group { margin-top: 0; }

  /* ── Content shell ── */
  main {
      min-width: 0;
      margin: 2rem 0 4rem;
      padding: 0;
  }

  /* ── Landing ── */
  .landing {
      max-width: 780px;
  }
  .landing h2 {
      font-family: "EB Garamond", serif; font-weight: 500;
      font-size: 1.4rem; margin: 0 0 .6rem; color: var(--ink);
  }
  .landing-intro {
      font-family: "Noto Serif", serif; font-size: .97rem;
      color: var(--ink-soft); line-height: 1.7;
      margin: 0 0 1.6rem;
  }
  .landing-group-label {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .82rem; letter-spacing: .06em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 .55rem;
      padding-bottom: .3rem; border-bottom: 1px solid var(--rule);
  }
  .landing-cards {
      list-style: none; padding: 0; margin: 0 0 1.5rem;
      display: grid; gap: .55rem;
  }
  .landing-card {
      background: #fff; border: 1px solid var(--rule);
      border-radius: 2px; padding: .65rem .9rem;
      display: grid; grid-template-columns: 1fr auto;
      align-items: baseline; gap: .4rem .9rem;
  }
  .landing-card-title {
      font-family: "EB Garamond", serif; font-weight: 600;
      font-size: 1rem; color: var(--ink);
  }
  .landing-card-meta {
      font-size: .77rem; color: var(--ink-soft); font-style: italic;
      grid-column: 1; line-height: 1.35;
  }
  .landing-card-badge {
      font-family: "Courier New", monospace; font-size: .7rem;
      color: var(--ink-soft); border: 1px solid var(--rule);
      padding: 1px 5px; border-radius: 2px; white-space: nowrap;
      align-self: start;
  }
  .landing-footer {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .93rem; color: var(--ink-soft);
      margin-top: .5rem;
      display: flex; align-items: center; gap: .6rem;
  }
  .landing-footer::before {
      content: ''; flex: 1; height: 1px; background: var(--rule);
  }
  .landing-footer-note {
      font-style: italic; margin-bottom: .3rem; font-size: .88rem;
  }
  .landing-footer-note::before { display: none; }

  /* ── Results list ── */
  .results-header {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: 1rem; color: var(--ink-soft);
      border-bottom: 1px solid var(--rule);
      padding-bottom: .5rem; margin-bottom: .75rem;
      display: flex; justify-content: space-between; align-items: baseline;
  }
  .results-header .count { font-size: .85rem; }
  .result-list { list-style: none; padding: 0; margin: 0; }
  .result-list a {
      display: grid;
      grid-template-columns: auto 1fr auto;
      gap: 1.25rem; align-items: start;
      padding: .75rem .25rem; border-bottom: 1px dotted var(--rule);
      color: inherit; text-decoration: none;
      transition: background .12s;
  }
  .result-list a:hover { background: rgba(255,255,255,.55); }
  .result-list .uni {
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
      font-family: "Mongolian Baiti", serif;
      font-size: 1.1rem; line-height: 1.05;
      color: var(--ink);
      min-height: 1.6em;
  }
  .result-list .uni-group {
      display: flex; flex-direction: row; gap: .5rem; align-items: flex-start;
  }
  .result-list .uni-variant {
      color: var(--ink-soft);
      border-left: 1px solid var(--rule);
      padding-left: .5rem;
  }
  .result-list .meta { padding-top: .1rem; }
  .result-list .trans { font-family: "EB Garamond", serif; font-size: 1.15rem; font-weight: 500; }
  .result-list .cyr   { font-family: "Noto Serif", serif; color: var(--ink-soft); }
  .result-list .refid { font-family: "Courier New", monospace; font-size: .78rem; color: var(--ink-soft); padding-top: .2rem; }
  .no-results {
      font-style: italic; color: var(--ink-soft); padding: 1.5rem 0;
  }

  /* ── Refs chips (Mahāvyutpatti cross-refs) ── */
  .refs-row {
      display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .4rem;
  }
  .ref-chip {
      display: inline-block;
      font-family: "Courier New", monospace; font-size: .76rem;
      color: var(--accent); background: var(--highlight);
      border: 1px solid var(--rule); border-radius: 2px; padding: 2px 7px;
      text-decoration: none;
      transition: background .12s, border-color .12s, color .12s;
  }
  .ref-chip:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

  /* ── Entry view ── */
  .backlink {
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .95rem; color: var(--ink-soft); text-decoration: none;
      display: inline-block; margin-bottom: 1.25rem;
  }
  .backlink:hover { color: var(--accent); }

  .entry-head {
      display: flex; gap: 1.6rem; align-items: flex-start;
      border-top: 3px double var(--ink);
      border-bottom: 1px solid var(--rule);
      padding: 1.2rem 0 1.1rem; margin-bottom: 1.5rem;
  }
  .head-uni {
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
      font-family: "Mongolian Baiti", serif;
      font-size: 2.3rem; line-height: 1.05;
      color: var(--ink);
      flex-shrink: 0; min-height: 3em;
  }
  .head-uni-group {
      display: flex; flex-direction: row;
      align-items: flex-start; gap: .6rem;
      flex-shrink: 0;
  }
  .head-uni-variant {
      color: var(--ink-soft);
      border-left: 1px dashed var(--rule);
      padding-left: .5rem;
  }
  .head-body { flex: 1; min-width: 0; padding-top: .3rem; }
  .entry-head .lemma-trans {
      font-family: "EB Garamond", serif;
      font-size: 1.7rem; font-weight: 500; color: var(--ink);
      letter-spacing: .005em;
  }
  .entry-head .lemma-cyr {
      font-family: "Noto Serif", serif; font-size: 1.1rem;
      color: var(--ink-soft); margin-top: .15rem;
  }
  .lemma-variant {
      font-size: .78em; font-weight: 400;
      color: var(--ink-soft);
      border-left: 1px solid var(--rule);
      margin-left: .5em; padding-left: .5em;
  }
  .lemma-variant-tr { font-style: italic; }
  .entry-head .refid {
      font-family: "Courier New", monospace; font-size: .78rem;
      color: var(--ink-soft); margin-top: .5rem;
  }

  .dico-section {
      margin-top: 1.75rem;
  }
  .dico-section-title {
      font-family: "EB Garamond", serif;
      font-size: 1.15rem; font-weight: 600; letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--accent); margin: 0 0 .6rem;
      padding-bottom: .25rem;
      border-bottom: 1px solid var(--rule);
      display: flex; align-items: baseline; justify-content: space-between;
  }
  .dico-section-title .count {
      font-size: .7em; font-weight: 400; letter-spacing: .06em;
      text-transform: lowercase; font-style: italic;
      color: var(--ink-soft);
  }

  .entry-item {
      display: flex; gap: 1rem;
      padding: .8rem 0 .8rem 1rem;
      border-left: 2px solid var(--paper-2);
      margin: .75rem 0;
      transition: border-color .12s;
  }
  .entry-item:hover { border-left-color: var(--accent); }
  .entry-uni {
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
      font-family: "Mongolian Baiti", serif;
      font-size: 1.75rem; line-height: 1.05;
      color: var(--ink);
      flex-shrink: 0; min-height: 2em;
  }

  /* Tibetan/lk4/saran entry: mng as single horizontal string */
  .tib-mng {
      font-family: "Mongolian Baiti", serif;
      font-size: 1.75rem;
      line-height: 1.3;
      color: var(--ink);
      display: inline;
      writing-mode: horizontal-tb;
      -webkit-writing-mode: horizontal-tb;
  }
  .mng-group {
      display: block;
  }
  .tib-mng-empty { opacity: .35; }
  .mng-dash {
      font-family: "EB Garamond", serif;
      font-size: 1.4rem; color: var(--ink-soft);
  }
  .mng2-wrap {
      display: flex; flex-direction: row;
      align-items: baseline; gap: .35rem;
  }
  .mng2-wrap .tib-mng {
      color: var(--ink-soft); font-size: 1.45rem;
  }
  .mng2-label {
      font-family: "EB Garamond", serif;
      font-size: .68rem; font-style: italic; color: var(--ink-soft);
      align-self: center; letter-spacing: .05em; white-space: nowrap;
  }
  .tib-wylie {
      font-family: "EB Garamond", serif;
      font-size: 1.05rem; font-style: italic; color: var(--ink-soft);
  }
  .tib-wylie.tib-unicode {
      font-family: "Noto Serif Tibetan", "Microsoft Himalaya", serif;
      font-size: 1.6rem; line-height: 2; font-style: normal;
  }
  .entry-body { flex: 1; min-width: 0; }
  /* For tibetan/lk4: block layout, each field on its own line */
  .entry-item.tib-entry { display: block; }
  .entry-item.tib-entry .entry-body { flex: none; }

  /* Saran-u Gerel: compact single-line layout */
  .entry-item.saran-entry {
      display: flex; flex-direction: row;
      align-items: baseline; gap: .75rem;
      padding: .45rem 0 .45rem 1rem;
  }
  .entry-item.saran-entry .saran-mng {
      font-family: "Mongolian Baiti", serif;
      font-size: 1.55rem; line-height: 1.3;
      color: var(--ink); flex-shrink: 0;
      writing-mode: horizontal-tb;
      -webkit-writing-mode: horizontal-tb;
  }
  .entry-item.saran-entry .saran-tib {
      flex: 1; min-width: 0;
  }
  .entry-item.saran-entry .saran-tib.tib-unicode {
      font-family: "Noto Serif Tibetan", "Microsoft Himalaya", serif;
      font-size: 2rem; line-height: 2.4;
  }
  .entry-item.tib-entry .entry-body .line {
      display: flex; align-items: baseline; gap: .5rem; flex-wrap: wrap;
      margin-bottom: .25rem;
  }
  .entry-body .line {
      display: flex; align-items: baseline; gap: .5rem; flex-wrap: wrap;
  }
  .entry-body .line .ui     { font-family: "EB Garamond", serif; font-style: italic; color: var(--ink-soft); font-size: .95rem; }
  .entry-body .line .uighur { font-family: "EB Garamond", serif; font-style: italic; color: var(--ink-soft); font-size: .95rem; }
  .entry-body .line .tr     { font-family: "EB Garamond", serif; font-weight: 500; font-size: 1.05rem; }
  .entry-body .line .cyr    { font-family: "Noto Serif", serif; color: var(--ink-soft); font-size: .98rem; }
  .entry-body .line .pg,
  .saran-entry > .pg     {
      font-family: "Courier New", monospace; font-size: .75rem;
      color: var(--ink-soft); margin-left: auto;
      border: 1px solid var(--rule); padding: 1px 6px; border-radius: 2px;
      text-decoration: none; transition: color .12s, border-color .12s, background .12s;
      white-space: nowrap; align-self: center;
  }
  a.pg:hover { color: var(--accent); border-color: var(--accent); background: #fff; }
  .entry-body .def {
      font-family: "Noto Serif", serif; color: var(--ink);
      line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;
  }
  .entry-body .def.tib-unicode {
      font-family: "Noto Serif Tibetan", "Microsoft Himalaya", serif;
      font-size: 2rem; line-height: 2.4;
  }

  .examples {
      margin-top: .65rem; padding: .5rem .9rem;
      background: rgba(255,255,255,.55);
      border-left: 1px dashed var(--rule);
      font-size: .95rem;
  }
  .example {
      padding: .25rem 0; color: var(--ink-soft);
  }
  .example + .example { border-top: 1px dotted var(--paper-2); }
  .example .uni { font-family: "Mongolian Baiti", serif; font-size: 1.4rem; color: var(--ink); line-height: 1.1; }
  .example .tr  { font-family: "EB Garamond", serif; font-style: italic; margin-left: .5em; }
  .example .ui  { font-family: "EB Garamond", serif; font-style: italic; margin-left: .5em; }
  .example .def { color: var(--ink-soft); }
  .example .def::before { content: "— "; color: var(--rule); }

  .alert {
      padding: 1rem 1.25rem; background: #fff;
      border-left: 3px solid var(--accent);
      font-style: italic; color: var(--ink-soft);
  }
  .alert code {
      font-family: "Courier New", monospace; font-style: normal;
      background: var(--highlight); padding: 0 .25em; border-radius: 2px;
  }

  @media (max-width: 680px) {
      .page-shell {
          grid-template-columns: 1fr;
          gap: 0;
          padding: 0 1rem;
      }
      .dict-nav {
          position: static;
          padding-top: 1rem;
          border-bottom: 1px solid var(--rule);
          padding-bottom: .75rem;
          margin-bottom: .25rem;
      }
      .dict-nav ul {
          display: flex; flex-wrap: wrap;
          gap: .1rem .25rem;
          border-left: none;
      }
      .dict-nav ul li a {
          border: 1px solid var(--rule);
          border-radius: 2px;
          padding: .2rem .55rem;
          font-size: .85rem;
          margin-left: 0;
      }
      .dict-nav ul li a.active {
          border-color: var(--accent);
          background: rgba(28,79,139,.08);
      }
      .dict-nav-divider { display: none; }
      main { margin-top: 1.25rem; }
  }
  @media (max-width: 540px) {
      .masthead-inner { gap: 1rem; }
      .soyombo       { height: 54px; }
      .brand-text    { font-size: 1.15rem; }
      .entry-head    { gap: 1rem; }
      .head-uni      { font-size: 1.9rem; }
      .entry-item    { gap: .75rem; padding-left: .75rem; }
      .result-list a { gap: .75rem; }
  }

  /* ── See also ── */
  .see-also-section {
      margin: 1.4rem 0 0;
      padding: .85rem 1rem .9rem;
      background: var(--paper-2);
      border: 1px solid var(--rule);
      border-radius: 2px;
  }
  .see-also-label {
      font-family: "EB Garamond", serif;
      font-size: .78rem; font-weight: 600;
      letter-spacing: .13em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 .6rem;
  }
  .see-also-items {
      display: flex; flex-wrap: wrap; gap: .5rem;
  }
  .see-also-link {
      display: inline-flex; align-items: center; gap: .45rem;
      padding: .28rem .65rem .28rem .5rem;
      background: #fff; border: 1px solid var(--rule);
      border-radius: 2px; text-decoration: none;
      color: var(--ink); transition: border-color .13s, background .13s;
  }
  .see-also-link:hover {
      border-color: var(--accent-2); background: var(--highlight);
  }
  .see-also-link .sa-uni {
      font-family: "Mongolian Baiti", "Noto Serif", serif;
      font-size: 1.3rem; line-height: 1;
      writing-mode: vertical-lr;
  }
  .see-also-link .sa-text {
      display: flex; flex-direction: column; gap: .05rem;
  }
  .see-also-link .sa-tr  { font-style: italic; color: var(--ink); font-size: .92rem; }
  .see-also-link .sa-cyr { color: var(--ink-soft); font-size: .85rem; }
  .see-also-link .sa-ref { font-family: "Courier New", monospace; font-size: .75rem; color: var(--accent); }

  /* ── Approximate section ── */
  .approximate-section {
      margin: .9rem 0;
      padding: .7rem .9rem;
      background: #fef9ec;
      border: 1px solid #f0d790;
      border-radius: 3px;
  }
  .approximate-label {
      font-family: "EB Garamond", serif;
      font-size: .78rem; font-weight: 600;
      letter-spacing: .1em; text-transform: uppercase;
      color: #8a6d00; margin-bottom: .5rem;
  }
  .approximate-items { display: flex; flex-wrap: wrap; gap: .5rem; }
  .approximate-link {
      display: inline-flex; align-items: center; gap: .45rem;
      padding: .28rem .7rem;
      background: #fff8dc; border: 1px solid #e8c840;
      border-radius: 3px; text-decoration: none; color: inherit;
      transition: background .12s, border-color .12s;
  }
  .approximate-link:hover { background: #fff3b0; border-color: #c8a800; }
  .approximate-link .sa-uni  {
      font-size: 1.25rem; line-height: 1;
      writing-mode: vertical-lr;
      -webkit-writing-mode: vertical-lr;
  }
  .approximate-link .sa-text { display: flex; flex-direction: column; gap: .05rem; }
  .approximate-link .sa-tr  { font-style: italic; color: var(--ink); font-size: .92rem; }
  .approximate-link .sa-cyr { color: var(--ink-soft); font-size: .85rem; }
  .approximate-link .sa-ref { font-family: "Courier New", monospace; font-size: .75rem; color: #8a6d00; }

  /* ── Related to / Derivatives two-column section ── */
  .rel-deriv-section {
      margin: 1rem 0 0;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .7rem;
  }
  @media (max-width: 600px) {
      .rel-deriv-section { grid-template-columns: 1fr; }
  }
  .rel-col {
      padding: .75rem .9rem .8rem;
      background: var(--paper-2);
      border: 1px solid var(--rule);
      border-radius: 2px;
  }
  .rel-col-label {
      font-family: "EB Garamond", serif;
      font-size: .78rem; font-weight: 600;
      letter-spacing: .13em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 .6rem;
  }
  .rel-items {
      display: flex; flex-wrap: wrap; gap: .5rem;
  }
  .rel-link {
      display: inline-flex; align-items: center; gap: .45rem;
      padding: .28rem .65rem .28rem .5rem;
      background: #fff; border: 1px solid var(--rule);
      border-radius: 2px; text-decoration: none;
      color: var(--ink); transition: border-color .13s, background .13s;
  }
  .rel-link:hover {
      border-color: var(--accent-2); background: var(--highlight);
  }
  .rel-link .sa-uni {
      font-family: "Mongolian Baiti", "Noto Serif", serif;
      font-size: 1.3rem; line-height: 1;
      writing-mode: vertical-lr;
  }
  .rel-link .sa-text {
      display: flex; flex-direction: column; gap: .05rem;
  }
  .rel-link .sa-tr  { font-style: italic; color: var(--ink); font-size: .92rem; }
  .rel-link .sa-cyr { color: var(--ink-soft); font-size: .85rem; }
  .rel-link .sa-ref { font-family: "Courier New", monospace; font-size: .75rem; color: var(--accent); }
  .rel-empty {
      font-family: "EB Garamond", serif;
      font-style: italic; color: var(--ink-soft); font-size: .9rem;
  }

  /* ── Word links graph button ── */
  .links-graph-btn {
      display: inline-flex; align-items: center; gap: .35em;
      margin-top: .75rem;
      padding: .3rem .85rem;
      background: transparent;
      border: 1px solid var(--accent-2);
      border-radius: 2px;
      color: var(--accent);
      font-family: "EB Garamond", serif;
      font-size: .92rem; font-style: italic;
      text-decoration: none;
      transition: background .12s, color .12s, border-color .12s;
  }
  .links-graph-btn:hover {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
  }

  /* ── Dictionary info popup ── */
  .dict-nav ul li {
      position: relative;
  }
  .dict-nav ul li a {
      display: flex; align-items: center; justify-content: space-between;
  }
  .dico-info-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 1.1em; height: 1.1em; flex-shrink: 0;
      background: none; border: 1px solid var(--rule);
      border-radius: 50%; cursor: pointer;
      font-size: .65rem; font-family: "EB Garamond", serif;
      color: var(--ink-soft); line-height: 1;
      margin-left: .3em;
      transition: color .12s, border-color .12s, background .12s;
      pointer-events: auto;
  }
  .dico-info-btn:hover,
  .dico-info-btn.open {
      color: var(--accent); border-color: var(--accent);
      background: rgba(28,79,139,.07);
  }
  .dico-popup {
      display: none;
      position: absolute; left: calc(100% + .6rem); top: 0;
      width: 320px; z-index: 300;
      background: #fff; border: 1px solid var(--rule);
      border-radius: 3px;
      box-shadow: 0 6px 28px rgba(15,39,71,.14);
      padding: 1.1rem 1.2rem 1rem;
  }
  .dico-popup.open { display: block; }
  .dico-popup-title {
      font-family: "EB Garamond", serif; font-weight: 600;
      font-size: 1.05rem; color: var(--ink);
      margin: 0 0 .35rem; letter-spacing: .01em;
  }
  .dico-popup-meta {
      font-size: .78rem; color: var(--ink-soft); font-style: italic;
      margin-bottom: .6rem; line-height: 1.4;
  }
  .dico-popup-body {
      font-size: .84rem; color: var(--ink-soft);
      line-height: 1.55; font-family: "Noto Serif", serif;
  }
  .dico-popup-close {
      position: absolute; top: .5rem; right: .6rem;
      background: none; border: none; cursor: pointer;
      font-size: .9rem; color: var(--ink-soft); line-height: 1;
      padding: .1rem .3rem;
      transition: color .12s;
  }
  .dico-popup-close:hover { color: var(--accent); }
  @media (max-width: 900px) {
      .dico-popup {
          left: auto; right: calc(100% + .6rem);
      }
  }
  @media (max-width: 680px) {
      .dico-popup {
          position: fixed; left: 50%; top: 50%;
          transform: translate(-50%, -50%);
          right: auto; width: 90vw; max-width: 340px;
      }
  }

  /* ── Masthead help / info buttons ── */
  .mast-icon-btn {
      border: 1px solid var(--rule); background: transparent;
      color: var(--ink-soft);
      font-family: "EB Garamond", serif; font-size: .97rem;
      padding: .3rem .6rem; cursor: pointer; border-radius: 2px;
      line-height: 1; transition: color .12s, border-color .12s;
      flex-shrink: 0;
  }
  .mast-icon-btn:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Full-screen modals ── */
  .modal-backdrop {
      display: none;
      position: fixed; inset: 0; z-index: 800;
      background: rgba(12,32,60,.45);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
  }
  .modal-backdrop.open { display: flex; align-items: center; justify-content: center; }
  .modal-box {
      background: #fff; border: 1px solid var(--rule);
      border-radius: 4px;
      box-shadow: 0 12px 48px rgba(15,39,71,.22);
      padding: 2rem 2.2rem 1.8rem;
      width: 90vw; max-width: 620px; max-height: 85vh;
      overflow-y: auto; position: relative;
      font-family: "Noto Serif", serif;
  }
  .modal-close {
      position: absolute; top: .8rem; right: 1rem;
      background: none; border: none; cursor: pointer;
      font-size: 1.1rem; color: var(--ink-soft); line-height: 1;
      padding: .2rem .4rem; transition: color .12s;
  }
  .modal-close:hover { color: var(--accent); }
  .modal-title {
      font-family: "EB Garamond", serif; font-weight: 600;
      font-size: 1.25rem; color: var(--ink);
      margin: 0 0 1.1rem; letter-spacing: .01em;
      padding-right: 2rem;
  }
  .modal-lead {
      font-size: .95rem; color: var(--ink); line-height: 1.65;
      margin: 0 0 1rem;
  }
  /* Search-help modal */
  .modal-eq-list {
      list-style: none; margin: .9rem 0 1.2rem; padding: 0;
      display: grid; gap: .5rem;
  }
  .modal-eq-list li {
      display: flex; align-items: baseline; gap: .7rem;
      font-size: .88rem; line-height: 1.55; color: var(--ink-soft);
      border-bottom: 1px dotted var(--rule); padding-bottom: .45rem;
  }
  .modal-eq-list li:last-child { border-bottom: none; }
  .modal-eq-rule {
      font-family: "Courier New", monospace; font-size: .9rem;
      color: var(--accent); flex-shrink: 0; min-width: 9rem;
  }
  .modal-eq-note { flex: 1; }
  .modal-example {
      background: var(--paper-2); border: 1px solid var(--rule);
      border-radius: 2px; padding: .6rem .9rem;
      font-size: .87rem; color: var(--ink-soft); line-height: 1.6;
  }
  .modal-example code {
      font-family: "Courier New", monospace; color: var(--accent);
      font-size: .9rem;
  }
  /* Resources modal */
  .modal-resources-list {
      list-style: none; margin: .4rem 0 0; padding: 0;
      display: grid; gap: 1.1rem;
  }
  .modal-res-item { border-left: 3px solid var(--rule); padding-left: .9rem; }
  .modal-res-item:hover { border-left-color: var(--accent); }
  .modal-res-title {
      font-family: "EB Garamond", serif; font-weight: 600;
      font-size: 1.05rem; color: var(--ink); margin: 0 0 .15rem;
  }
  .modal-res-meta {
      font-size: .78rem; color: var(--ink-soft); font-style: italic;
      margin-bottom: .35rem; line-height: 1.4;
  }
  .modal-res-body {
      font-size: .84rem; color: var(--ink-soft); line-height: 1.55;
  }
  .modal-res-divider {
      margin: .4rem 0 .6rem;
      font-family: "EB Garamond", serif; font-style: italic;
      font-size: .78rem; color: var(--ink-soft); letter-spacing: .04em;
      text-transform: uppercase;
  }

  /* ── Tibetan dictionary link & modal ── */
  .tib-dict-btn {
      display: inline-block;
      font-size: .72rem; line-height: 1;
      padding: .18rem .42rem;
      border: 1px solid var(--rule);
      border-radius: 2px;
      color: var(--ink-soft);
      background: transparent;
      cursor: pointer;
      vertical-align: middle;
      margin-left: .45rem;
      font-family: "EB Garamond", serif;
      transition: color .12s, border-color .12s;
      text-decoration: none;
  }
  .tib-dict-btn:hover { color: var(--accent); border-color: var(--accent); }

  /* ── Google Translate button ── */
  .translate-btn {
      display: inline-block;
      font-size: .72rem; line-height: 1;
      padding: .18rem .42rem;
      border: 1px solid var(--rule);
      border-radius: 2px;
      color: var(--ink-soft);
      background: transparent;
      cursor: pointer;
      vertical-align: middle;
      margin-left: .45rem;
      font-family: "EB Garamond", serif;
      transition: color .12s, border-color .12s;
      text-decoration: none;
  }
  .translate-btn:hover {
      color: var(--accent); border-color: var(--accent);
  }

  /* ── Translation modal ── */
  #modal-translate .modal-box {
      width: 80vw;
      max-width: 80vw;
  }
  .trans-source-label {
      font-family: "EB Garamond", serif; font-size: .72rem;
      font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 .35rem;
  }
  .trans-source-text {
      font-family: "Noto Serif", serif; font-size: .95rem;
      color: var(--ink-soft); line-height: 1.6;
      border-left: 2px solid var(--rule); padding-left: .75rem;
      margin-bottom: 1.1rem; white-space: pre-wrap; word-wrap: break-word;
  }
  .trans-result-label {
      font-family: "EB Garamond", serif; font-size: .72rem;
      font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 .35rem;
  }
  .trans-result-text {
      font-family: "Noto Serif", serif; font-size: 1rem;
      color: var(--ink); line-height: 1.65;
      white-space: pre-wrap; word-wrap: break-word;
  }
  .trans-attribution {
      font-size: .72rem; color: var(--ink-soft); font-style: italic;
      margin-top: 1rem; text-align: right;
  }
  .trans-spinner {
      display: inline-block; width: .85em; height: .85em;
      border: 2px solid var(--rule); border-top-color: var(--accent);
      border-radius: 50%; animation: spin .7s linear infinite;
      vertical-align: middle; margin-right: .35em;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .trans-error { color: #c0392b; font-style: italic; }

  /* Tibetan dict modal — resizable & draggable */
  #modal-tib-dict {
      /* backdrop: transparent click-sink, not a flex centering parent */
      background: transparent;
      pointer-events: none;          /* let clicks pass through the backdrop itself */
  }
  #modal-tib-dict.open { display: block; } /* override the flex from .modal-backdrop.open */
  #modal-tib-dict .modal-box {
      pointer-events: all;
      position: fixed;
      /* default position / size — centered, 90 × 80 % of viewport */
      width: 90vw; height: 80vh;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      min-width: 320px; min-height: 240px;
      max-width: 100vw; max-height: 100vh;
      padding: 0;
      display: flex; flex-direction: column;
      resize: both; overflow: hidden;
      box-shadow: 0 12px 48px rgba(15,39,71,.28);
  }
  /* drag handle = the title bar */
  #modal-tib-dict .tib-dict-titlebar {
      display: flex; align-items: center;
      padding: .6rem 1rem .5rem;
      border-bottom: 1px solid var(--rule);
      cursor: move; user-select: none; flex-shrink: 0;
      background: #fff; border-radius: 4px 4px 0 0;
  }
  #modal-tib-dict .modal-title {
      margin: 0; flex: 1;
      font-size: 1.05rem;
  }
  #modal-tib-dict .modal-close {
      position: static; /* override absolute positioning */
      margin-left: .5rem;
  }
  #modal-tib-dict iframe {
      flex: 1;
      border: none;
      border-top: 1px solid var(--rule);
      width: 100%; min-height: 0;
  }
</style>
</head>
<body>

<header class="masthead">
  <div class="masthead-inner">
    <a class="brand" href="?">
      <img class="soyombo" src="soyombo.png" alt="" width="36" height="68">
      <span class="brand-text">Lexicographical Resources<span class="brand-sub">for the Mongolian Language</span></span>
    </a>
    <div class="search-group">
      <form class="search-form" method="get" action="" role="search">
        <input type="search" name="q" value="<?= h($query) ?>"
               placeholder="transcript, ᠮᠣᠩᠭᠣᠯ, or кирилл&hellip;"
               autocomplete="off" spellcheck="false"
               <?= ($ref === '' && $query === '') ? 'autofocus' : '' ?>>
        <button type="submit">Search</button>
      </form>

      <div class="search-mode" title="Match words that begin with or contain the search string"
           id="mode-contains">
        <input type="radio" name="contains" id="sm-begins" value="0"
               <?= !$contains ? 'checked' : '' ?>>
        <label for="sm-begins">begins</label>
        <input type="radio" name="contains" id="sm-contains" value="1"
               <?= $contains ? 'checked' : '' ?>>
        <label for="sm-contains">contains</label>
      </div>

      <div class="search-mode" title="Approximate: all vowels treated as equivalent (a/e/i/o/u/ö/ü)"
           id="mode-fuzzy">
        <input type="radio" name="fuzzy" id="sm-exact" value="0"
               <?= !$fuzzy ? 'checked' : '' ?>>
        <label for="sm-exact">exact</label>
        <input type="radio" name="fuzzy" id="sm-fuzzy" value="1"
               <?= $fuzzy ? 'checked' : '' ?>>
        <label for="sm-fuzzy">approx</label>
      </div>
    </div>

    <div class="mast-user">
      <button class="mast-icon-btn" id="btn-search-help"
              aria-label="Search help" title="How to search"
              onclick="document.getElementById('modal-search-help').classList.add('open')">?</button>
      <button class="mast-icon-btn" id="btn-resources"
              aria-label="About the resources" title="About the resources"
              onclick="document.getElementById('modal-resources').classList.add('open')">ℹ</button>
      <?php if ($isLoggedIn): ?>
        <?php if ($ref !== ''): ?>
          <a class="mast-edit-link" href="editor/edit.php?ref=<?= urlencode($ref) ?>" title="Edit this entry">✎ Edit</a>
        <?php endif; ?>
        <span class="mast-username"><?= h($authUser) ?></span>
        <a class="mast-logout" href="?logout=1<?= $query !== '' ? '&q='.urlencode($query) : '' ?><?= $ref !== '' ? '&ref='.urlencode($ref) : '' ?>">Sign out</a>
      <?php else: ?>
        <div class="mast-login-wrap">
          <button class="mast-signin-btn" id="signin-toggle" aria-expanded="false" aria-controls="signin-dropdown">Sign in</button>
          <div class="signin-dropdown" id="signin-dropdown" hidden>
            <?php if ($loginError !== ''): ?>
              <div class="signin-error"><?= h($loginError) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= h('index.php' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">
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
// Determine which dico keys are active in the current entry (for highlighting)
$activeDicos = [];
if ($entryXml !== null) {
    foreach ($entryItemsSorted as $it) {
        $activeDicos[strtolower(trim((string)$it->dico))] = true;
    }
}
?>

<div class="page-shell">

  <nav class="dict-nav" aria-label="Dictionaries">
    <p class="dict-nav-title">Dictionaries</p>
    <?php
    // Groups: label => array of dico keys
    $NAV_GROUPS = [
        'Western'          => ['lessing','kowalewski','schmidt','boberg','bawden','vietze'],
        'Chinese'          => ['chinese','chinese2'],
        'Russian'          => ['lamrd'],
        'Mongolian'        => ['cevel'],
        'Tibetan/Sanskrit' => ['tibetan','lk4','saran','raghu'],
        'Dialectal'        => ['mostaert'],
    ];
    ?>
    <ul>
      <?php foreach ($NAV_GROUPS as $groupLabel => $groupKeys): ?>
        <li><div class="dict-nav-group"><?= h($groupLabel) ?></div></li>
        <?php foreach ($groupKeys as $dk):
            if (!in_array($dk, $DICO_ORDER, true)) continue;
            $label    = $DICO_LABELS[$dk] ?? ucfirst($dk);
            $isActive = isset($activeDicos[$dk]);
            if ($entryXml !== null && $isActive) {
                $navHref = '#dico-' . $dk;
            } elseif ($entryXml !== null) {
                $navHref = '#';
            } else {
                $navHref = '?dico=' . urlencode($dk) . ($query !== '' ? '&q=' . urlencode($query) : '');
            }
            $isInert = ($entryXml !== null && !$isActive);
        ?>
        <li>
          <a href="<?= h($navHref) ?>"
             class="<?= $isActive ? 'active' : ($isInert ? 'inert' : '') ?>"
             <?= $isInert ? 'tabindex="-1" aria-disabled="true"' : '' ?>
             title="<?= h($label) ?>">
            <?= h($label) ?>
            <?php if (isset($DICO_INFO[$dk])): ?>
              <button type="button"
                      class="dico-info-btn"
                      data-dico="<?= h($dk) ?>"
                      aria-label="About <?= h($label) ?>"
                      onclick="event.preventDefault(); event.stopPropagation(); toggleDicoPopup(this, '<?= h($dk) ?>')">i</button>
            <?php endif; ?>
          </a>
          <?php if (isset($DICO_INFO[$dk])): $info = $DICO_INFO[$dk]; ?>
            <div class="dico-popup" id="popup-<?= h($dk) ?>" role="tooltip" aria-hidden="true">
              <button class="dico-popup-close" onclick="closeDicoPopup('<?= h($dk) ?>')" aria-label="Close">✕</button>
              <div class="dico-popup-title"><?= h($info['title']) ?></div>
              <div class="dico-popup-meta"><?= h($info['meta']) ?></div>
              <div class="dico-popup-body"><?= h($info['body']) ?></div>
            </div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </ul>
	    <br><br><p class="dict-nav-title">Grammar</p>
		<ul>
			<li><a href="gramm/gramm.php" target="_blank">🕮</a>
		</ul>
	    <br><br><p class="dict-nav-title">Tibetan</p>
		<ul>
			<li><a href="tib.php" target="_blank">Search tibetan entries</a>
		</ul>

  </nav>

<main>

<?php if ($ref !== '' && $entryXml === null && !$entryItemsSorted): ?>

  <div class="alert">
    Entry <code><?= h($ref) ?></code> was not found.
  </div>

<?php elseif ($ref !== '' && ($entryXml !== null || $entryItemsSorted)):
    // Head fields: prefer entry XML, fall back to root index (for Mv-only refs like N0281)
    $hUni = $hTr = $hCyr = '';
    $hVariants = [];
    foreach ($roots as $_r) {
        if ($_r['ref'] === $ref) {
            $hUni      = $_r['unicode'];
            $hTr       = $_r['transcript'];
            $hCyr      = $_r['cyrillic'];
            $hVariants = $_r['variants'] ?? [];
            break;
        }
    }
    if ($entryXml !== null) {
        $head = $entryXml->head;
        if ((string)$head->unicode    !== '') $hUni = (string)$head->unicode;
        if ((string)$head->transcript !== '') $hTr  = (string)$head->transcript;
        if ((string)$head->cyrillic   !== '') $hCyr = (string)$head->cyrillic;
    }
?>

  <a class="backlink" href="?q=<?= urlencode($query) ?><?= $contains ? '&amp;contains=1' : '' ?><?= $fuzzy ? '&amp;fuzzy=1' : '' ?>">← back to results</a>

  <section class="entry-head">
    <?php
      // Collect all unicode forms: main + unicode variants
      $uniVariants = array_filter($hVariants, fn($v) => $v['type'] === 'unicode');
      $trVariants  = array_filter($hVariants, fn($v) => $v['type'] === 'transcript');
      $cyrVariants = array_filter($hVariants, fn($v) => $v['type'] === 'cyrillic');
    ?>
    <?php if ($hUni !== '' || $uniVariants): ?>
      <div class="head-uni-group">
        <?php if ($hUni !== ''): ?>
          <div class="head-uni"><?= h($hUni) ?></div>
        <?php endif; ?>
        <?php foreach ($uniVariants as $v): ?>
          <div class="head-uni head-uni-variant" title="Variant"><?= h($v['value']) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="head-body">
      <?php if ($hTr !== ''): ?>
        <div class="lemma-trans"><?= h($hTr) ?>
          <?php foreach ($trVariants as $v): ?>
            <span class="lemma-variant lemma-variant-tr"><?= h($v['value']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($hCyr !== ''): ?>
        <div class="lemma-cyr"><?= h($hCyr) ?>
          <?php foreach ($cyrVariants as $v): ?>
            <span class="lemma-variant lemma-variant-cyr"><?= h($v['value']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="refid"><?= h((string)$entryXml['ref'] ?: $ref) ?></div>
    </div>
  </section>

  <?php if ($entrySeeAlso): ?>
  <div class="see-also-section">
    <div class="see-also-label">See also</div>
    <div class="see-also-items">
      <?php foreach ($entrySeeAlso as $sa): ?>
        <a class="see-also-link" href="?ref=<?= urlencode($sa['ref']) ?><?= $query !== '' ? '&amp;q='.urlencode($query) : '' ?>">
          <?php if ($sa['unicode'] !== ''): ?>
            <span class="sa-uni"><?= h($sa['unicode']) ?></span>
          <?php endif; ?>
          <span class="sa-text">
            <?php if ($sa['transcript'] !== ''): ?>
              <span class="sa-tr"><?= h($sa['transcript']) ?></span>
            <?php endif; ?>
            <?php if ($sa['cyrillic'] !== ''): ?>
              <span class="sa-cyr"><?= h($sa['cyrillic']) ?></span>
            <?php endif; ?>
            <span class="sa-ref"><?= h($sa['ref']) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($entryApproximate): ?>
  <div class="approximate-section">
    <div class="approximate-label">Approximate forms</div>
    <div class="approximate-items">
      <?php foreach ($entryApproximate as $ap): ?>
        <a class="approximate-link" href="?ref=<?= urlencode($ap['ref']) ?><?= $query !== '' ? '&amp;q='.urlencode($query) : '' ?><?= $contains ? '&amp;contains=1' : '' ?><?= $fuzzy ? '&amp;fuzzy=1' : '' ?>">
          <?php if ($ap['unicode'] !== ''): ?>
            <span class="sa-uni"><?= h($ap['unicode']) ?></span>
          <?php endif; ?>
          <span class="sa-text">
            <?php if ($ap['transcript'] !== ''): ?>
              <span class="sa-tr"><?= h($ap['transcript']) ?></span>
            <?php endif; ?>
            <?php if ($ap['cyrillic'] !== ''): ?>
              <span class="sa-cyr"><?= h($ap['cyrillic']) ?></span>
            <?php endif; ?>
            <span class="sa-ref"><?= h($ap['ref']) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($entryRelatedTo || $entryDerivatives): ?>
  <div class="rel-deriv-section">
    <div class="rel-col">
      <div class="rel-col-label">Related to</div>
      <?php if ($entryRelatedTo): ?>
      <div class="rel-items">
        <?php foreach ($entryRelatedTo as $rt): ?>
          <a class="rel-link" href="?ref=<?= urlencode($rt['ref']) ?><?= $query !== '' ? '&amp;q='.urlencode($query) : '' ?>">
            <?php if ($rt['unicode'] !== ''): ?>
              <span class="sa-uni"><?= h($rt['unicode']) ?></span>
            <?php endif; ?>
            <span class="sa-text">
              <?php if ($rt['transcript'] !== ''): ?>
                <span class="sa-tr"><?= h($rt['transcript']) ?></span>
              <?php endif; ?>
              <?php if ($rt['cyrillic'] !== ''): ?>
                <span class="sa-cyr"><?= h($rt['cyrillic']) ?></span>
              <?php endif; ?>
              <span class="sa-ref"><?= h($rt['ref']) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <span class="rel-empty">None.</span>
      <?php endif; ?>
    </div>
    <div class="rel-col">
      <div class="rel-col-label">Derivatives</div>
      <?php if ($entryDerivatives): ?>
      <div class="rel-items">
        <?php foreach ($entryDerivatives as $dv): ?>
          <a class="rel-link" href="?ref=<?= urlencode($dv['ref']) ?><?= $query !== '' ? '&amp;q='.urlencode($query) : '' ?>">
            <?php if ($dv['unicode'] !== ''): ?>
              <span class="sa-uni"><?= h($dv['unicode']) ?></span>
            <?php endif; ?>
            <span class="sa-text">
              <?php if ($dv['transcript'] !== ''): ?>
                <span class="sa-tr"><?= h($dv['transcript']) ?></span>
              <?php endif; ?>
              <?php if ($dv['cyrillic'] !== ''): ?>
                <span class="sa-cyr"><?= h($dv['cyrillic']) ?></span>
              <?php endif; ?>
              <span class="sa-ref"><?= h($dv['ref']) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <span class="rel-empty">None.</span>
      <?php endif; ?>
    </div>
  </div>
  <a class="links-graph-btn" href="link.html?id=<?= urlencode($ref) ?>">⇢ Word links graph</a>
  <?php endif; ?>

  <?php
    // Group sorted items by dictionary, preserving the desired order.
    $groups = [];
    foreach ($entryItemsSorted as $it) {
        $k = strtolower(trim((string)$it->dico));
        $groups[$k][] = $it;
    }
    $ordered = [];
    foreach ($DICO_ORDER as $k) {
        if (isset($groups[$k])) { $ordered[$k] = $groups[$k]; unset($groups[$k]); }
    }
    foreach ($groups as $k => $v) $ordered[$k] = $v;   // any unknown dicos appended at the end
  ?>

  <?php foreach ($ordered as $dicoKey => $items): ?>
    <section class="dico-section" id="dico-<?= h($dicoKey) ?>">
      <h2 class="dico-section-title">
        <span><?= h($DICO_LABELS[$dicoKey] ?? ucfirst($dicoKey)) ?></span>
        <span class="count"><?= count($items) ?> <?= count($items) === 1 ? 'entry' : 'entries' ?></span>
      </h2>
      <?php foreach ($items as $it):
          $iUni  = (string)$it->unicode;
          $iUni2 = (string)$it->unicode2;
          $iTib  = (string)$it->tib;
          $iTr   = (string)$it->transcript;
          $iCyr  = (string)$it->cyrillic;
          $iDef  = (string)$it->definition;
          $iPg   = (string)$it->page;
      ?>
        <?php if ($dicoKey === 'raghu'): ?>
        <div class="entry-item saran-entry">
          <span class="saran-mng"><?= $iUni !== '' ? h($iUni) : '<span style="opacity:.35">—</span>' ?></span>
          <span class="saran-tib" style="font-family:'EB Garamond',serif;font-style:italic;font-size:1.05rem;color:var(--ink-soft)"><?= h($iDef) ?></span>
          <?php if ($iPg !== '' && $iPg !== '0'): ?>
          <a class="pg"
             href="img.php?dic=raghu&amp;id=<?= urlencode($iPg) ?>"
             target="_blank" rel="noopener">p.&nbsp;<?= h($iPg) ?></a>
          <?php endif; ?>
        </div>
        <?php elseif ($dicoKey === 'saran'): ?>
        <?php $iPgSaran = rtrim($iPg, 'ab'); ?>
        <div class="entry-item saran-entry">
          <span class="saran-mng"><?= $iUni !== '' ? h($iUni) : '<span style="opacity:.35">—</span>' ?></span>
          <span class="saran-tib def" data-ewts="<?= h($iDef) ?>"><?= h($iDef) ?></span>
          <?php if ($iDef !== ''): ?>
          <button class="tib-dict-btn"
                  data-ewts="<?= h($iDef) ?>"
                  onclick="openTibDict(this.dataset.ewts)"
                  title="Look up in Tibetan dictionary (Steinert)">🔍 dict</button>
          <?php endif; ?>
          <?php if ($iPg !== '' && $iPg !== '0'): ?>
          <a class="pg"
             href="img.php?dic=saran&amp;id=<?= urlencode($iPgSaran) ?>"
             target="_blank" rel="noopener">p.&nbsp;<?= h($iPg) ?></a>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="entry-item<?php if ($dicoKey === 'tibetan' || $dicoKey === 'lk4') echo ' tib-entry'; ?>">
          <?php if ($dicoKey === 'tibetan' || $dicoKey === 'lk4'): ?>
            <div class="mng-group">
              <?php if ($iUni !== ''): ?>
              <span class="tib-mng"><?= h($iUni) ?></span>
              <?php else: ?>
              <span class="tib-mng tib-mng-empty"><span class="mng-dash">—</span></span>
              <?php endif; ?>
              <?php if ($dicoKey === 'tibetan' && $iUni2 !== ''): ?>
              <div class="mng2-wrap">
                <span class="mng2-label">alt.</span>
                <span class="tib-mng"><?= h($iUni2) ?></span>
              </div>
              <?php endif; ?>
            </div><!-- /mng-group -->
          <?php else: ?>
            <?php if ($iUni !== ''): ?>
              <div class="entry-uni"><?= h($iUni) ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="entry-body">
            <?php if ($dicoKey === 'tibetan' && $iTib !== ''): ?>
              <div class="line">
                <span class="tib-wylie" data-ewts="<?= h($iTib) ?>"><?= h($iTib) ?></span>
                <button class="tib-dict-btn"
                        data-ewts="<?= h($iTib) ?>"
                        onclick="openTibDict(this.dataset.ewts)"
                        title="Look up in Tibetan dictionary (Steinert)">🔍 dict</button>
              </div>
            <?php endif; ?>
            <div class="line">
              <?php if ($iTr  !== ''): ?><span class="tr"><?= h($iTr) ?></span><?php endif; ?>
              <?php if ($iCyr !== ''): ?><span class="cyr"><?= h($iCyr) ?></span><?php endif; ?>
              <?php if ($iPg  !== '' && $iPg !== '0'): ?>
                <?php if ($dicoKey === 'lk4'): ?>
                <a class="pg"
                   href="img.php?dic=lk4&amp;id=<?= urlencode($iPg) ?>"
                   target="_blank" rel="noopener">p.&nbsp;<?= h($iPg) ?></a>
                <?php elseif ($dicoKey === 'tibetan'): ?>
                <a class="pg"
                   href="img.php?dic=Mahavyutpatti&amp;id=<?= urlencode($iPg) ?>"
                   target="_blank" rel="noopener">p.&nbsp;<?= h($iPg) ?></a>
                <?php else: ?>
                <a class="pg"
                   href="img.php?dic=<?= urlencode((string)$it->dico) ?>&amp;id=<?= urlencode($iPg) ?>"
                   target="_blank" rel="noopener">p.&nbsp;<?= h($iPg) ?></a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php if ($iDef !== ''):
                $defOut = $iDef;
                if ($dicoKey === 'chinese') {
                    // Each circled-digit sense marker (①-⑳) starts a new line.
                    $defOut = preg_replace('/([\x{2460}-\x{2473}])/u', "\n$1", $defOut);
                    $defOut = ltrim($defOut, "\n");
                }
            ?>
              <div class="def<?= ($dicoKey === 'lk4') ? ' lk4-tib-def' : '' ?>"
                   <?= ($dicoKey === 'lk4') ? 'data-ewts="' . h($iDef) . '"' : '' ?>><?= h($defOut) ?></div>
              <?php if ($dicoKey === 'lk4'): ?>
              <button class="tib-dict-btn"
                      data-ewts="<?= h($iDef) ?>"
                      onclick="openTibDict(this.dataset.ewts)"
                      title="Look up in Tibetan dictionary (Steinert)">🔍 dict</button>
              <?php endif; ?>
              <?php if (in_array($dicoKey, ['chinese','chinese2'], true)): ?>
              <button class="translate-btn"
                      data-text="<?= h($iDef) ?>"
                      data-lang="zh-CN"
                      onclick="openTranslate(this)"
                      title="Google Translate (Chinese → English)">🌐 translate</button>
              <?php elseif ($dicoKey === 'lamrd'): ?>
              <button class="translate-btn"
                      data-text="<?= h($iDef) ?>"
                      data-lang="ru"
                      onclick="openTranslate(this)"
                      title="Google Translate (Russian → English)">🌐 translate</button>
              <?php elseif ($dicoKey === 'cevel'): ?>
              <button class="translate-btn"
                      data-text="<?= h($iDef) ?>"
                      data-lang="mn"
                      onclick="openTranslate(this)"
                      title="Google Translate (Mongolian → English)">🌐 translate</button>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($it->examples->example) && count($it->examples->example) > 0): ?>
              <div class="examples">
                <?php foreach ($it->examples->example as $ex):
                    $eUni = (string)$ex->unicode;
                    $eTr  = (string)$ex->transcript;
                    $eDef = (string)$ex->definition;
                ?>
                  <div class="example">
                    <?php if ($eUni !== ''): ?><span class="uni"><?= h($eUni) ?></span><?php endif; ?>
                    <?php if ($eDef !== ''): ?><span class="def"><?= h(trim($eDef)) ?></span><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; // end if saran / else ?>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

<?php elseif ($query !== ''): ?>

  <div class="results-header">
    <span>Results for <em>&ldquo;<?= h($query) ?>&rdquo;</em>
      <small style="font-weight:400;opacity:.7;font-size:.85em;">
        (<?= $contains ? 'containing' : 'beginning with' ?><?= $fuzzy ? ', approx.' : '' ?>)
      </small>
    </span>
    <span class="count"><?= count($results) ?> <?= count($results) === 1 ? 'match' : 'matches' ?></span>
  </div>

  <?php if (!$results): ?>
    <p class="no-results">No words <?= $contains ? 'contain' : 'begin with' ?> this string<?= $fuzzy ? ' (approximate vowel match)' : '' ?>.</p>
  <?php else: ?>
    <ul class="result-list">
      <?php foreach ($results as $r): ?>
        <?php
          // Collect extra unicode forms from variants (exclude the primary unicode)
          $uniVariants = [];
          $cyrVariants = [];
          foreach ($r['variants'] as $v) {
              if ($v['type'] === 'unicode' && $v['value'] !== '' && $v['value'] !== $r['unicode']) {
                  $uniVariants[] = $v['value'];
              } elseif ($v['type'] === 'cyrillic' && $v['value'] !== '' && $v['value'] !== $r['cyrillic']) {
                  $cyrVariants[] = $v['value'];
              }
          }
        ?>
        <li>
          <a href="?ref=<?= urlencode($r['ref']) ?>&amp;q=<?= urlencode($query) ?><?= $contains ? '&amp;contains=1' : '' ?><?= $fuzzy ? '&amp;fuzzy=1' : '' ?>">
            <span class="uni-group">
              <span class="uni" style="font-size: 1.6rem;"><?= $contains ? highlightPlain($r['unicode'], $query, true) : h($r['unicode']) ?></span>
              <?php foreach ($uniVariants as $uv): ?>
                <span class="uni uni-variant" style="font-size: 1.6rem;" title="variant"><?= $contains ? highlightPlain($uv, $query, true) : h($uv) ?></span>
              <?php endforeach; ?>
            </span>
            <span class="meta">
              <span class="trans"><?= $contains || $fuzzy ? highlightTranscript($r['transcript'], $query, $contains) : h($r['transcript']) ?></span>
              <?php if ($r['cyrillic'] !== '' || $cyrVariants): ?>
                &nbsp;&middot;&nbsp;<span class="cyr"><?= h($r['cyrillic']) ?><?php foreach ($cyrVariants as $cv): ?><span class="lemma-variant"><?= h($cv) ?></span><?php endforeach; ?></span>
              <?php endif; ?>
            </span>
            <span class="refid"><?= h($r['ref']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

<?php else: ?>

  <div class="landing">
    <h2>Lexicographical Resources for the Mongolian Language</h2>
    <p class="landing-intro">
      A cross-dictionary search interface for classical written Mongolian, covering the principal
      Western scholarly dictionaries alongside Asian reference works and Tibetan–Mongolian Buddhist
      glossaries. Entries are keyed by a shared identifier, allowing parallel consultation of all
      sources for any headword. 
	  <br>The grammars of Poppe and Schmidt are accessible and fully searchable.
      <br>A reversed search engine has been added for Tibetan entries.
      <br>Use the <strong>?</strong> button above to learn about search options,
      or <strong>ℹ</strong> for detailed descriptions of each resource.
    </p>

    <div class="landing-group-label">Western dictionaries</div>
    <ul class="landing-cards">
      <li class="landing-card">
        <span class="landing-card-title">Lessing — <em>Mongolian-English Dictionary</em></span>
        <span class="landing-card-badge">Eng</span>
        <span class="landing-card-meta">Ferdinand D. Lessing · University of California Press, 1960 · The standard Western reference for classical written Mongolian</span>
      </li>
      <li class="landing-card">
        <span class="landing-card-title">Kovalevski — <em>Dictionnaire Mongol-Russe-Français</em></span>
        <span class="landing-card-badge">Rus / Fr</span>
        <span class="landing-card-meta">Osip M. Kovalevski · Kazan, 1844–1849 · The most comprehensive 19th-century Mongolian dictionary, ~25,000 headwords</span>
      </li>
      <li class="landing-card">
        <span class="landing-card-title">Schmidt — <em>Mongolisch-Deutsches Wörterbuch</em></span>
        <span class="landing-card-badge">Ger</span>
        <span class="landing-card-meta">Isaak J. Schmidt · St. Petersburg, 1835</span>
      </li>
      <li class="landing-card">
        <span class="landing-card-title">Boberg · Bawden · Vietze</span>
        <span class="landing-card-badge">Eng / Ger</span>
        <span class="landing-card-meta">Further Western dictionaries covering classical and modern Mongolian</span>
      </li>
    </ul>

    <div class="landing-group-label">Asian dictionaries</div>
    <ul class="landing-cards">
      <?php foreach (['chinese','chinese2','lamrd'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="landing-card">
        <span class="landing-card-title"><?= h($DICO_LABELS[$dk]) ?> — <em><?= h($info['title']) ?></em></span>
        <span class="landing-card-meta"><?= h($info['meta']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="landing-group-label">Mongolian dictionaries</div>
    <ul class="landing-cards">
      <?php foreach (['cevel'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="landing-card">
        <span class="landing-card-title"><?= h($DICO_LABELS[$dk]) ?> — <em><?= h($info['title']) ?></em></span>
        <span class="landing-card-badge">Mng</span>
        <span class="landing-card-meta"><?= h($info['meta']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="landing-group-label">Tibetan–Mongolian resources</div>
    <ul class="landing-cards">
      <?php foreach (['tibetan','lk4','saran'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="landing-card">
        <span class="landing-card-title"><?= h($DICO_LABELS[$dk]) ?> — <em><?= h($info['title']) ?></em></span>
        <span class="landing-card-badge">Tib–Mng</span>
        <span class="landing-card-meta"><?= h($info['meta']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="landing-group-label">Sanskrit–Mongolian</div>
    <ul class="landing-cards">
      <?php foreach (['raghu'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="landing-card">
        <span class="landing-card-title"><?= h($DICO_LABELS[$dk]) ?> — <em><?= h($info['title']) ?></em></span>
        <span class="landing-card-badge">Skt–Mng</span>
        <span class="landing-card-meta"><?= h($info['meta']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="landing-group-label">Grammars</div>
    <ul class="landing-cards">
      <li class="landing-card">
        <span class="landing-card-title">Poppe — <em>Grammar of Written Mongolian</em></span>
        <span class="landing-card-badge">Eng</span>
        <span class="landing-card-meta">Nicholas Poppe · Harrassowitz, 1954 · The standard reference grammar of classical written Mongolian</span>
      </li>
      <li class="landing-card">
        <span class="landing-card-title">Schmidt — <em>Grammatik der mongolischen Sprache</em></span>
        <span class="landing-card-badge">Ger</span>
        <span class="landing-card-meta">Isaak J. Schmidt · St. Petersburg, 1831</span>
      </li>
    </ul>

    <div class="landing-group-label">Tibetan search</div>
    <ul class="landing-cards">
      <li class="landing-card">
        <span class="landing-card-title"><a href="tib.php" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">Search Tibetan entries ↗</a></span>
        <span class="landing-card-meta">Search across the Tibetan-keyed entries from the Mahāvyutpatti, Lokesh Chandra, and Saran-u Gerel resources directly by Tibetan script or Wylie transliteration</span>
      </li>
    </ul>

    <p class="landing-footer landing-footer-note">Further resources will be added step by step.</p>
    <p class="landing-footer landing-footer-note">Contact: Bruno Lainé — <a href="mailto:brunogml@gmail.com" style="color:inherit">brunogml@gmail.com</a></p>
    <p class="landing-footer"><?= count($roots) ?> headwords indexed</p>
  </div>

<?php endif; ?>

</main>

</div><!-- /.page-shell -->

<script>
// ── Search-mode radio buttons ─────────────────────────────────────
(function () {
  // When any mode radio changes, add its value as a hidden input in
  // the real search form and submit, keeping the current query.
  const searchForm = document.querySelector('form.search-form');
  if (!searchForm) return;

  function resubmitWithMode() {
    const containsVal = document.querySelector('input[name="contains"]:checked')?.value ?? '0';
    const fuzzyVal    = document.querySelector('input[name="fuzzy"]:checked')?.value   ?? '0';

    // Remove any previously injected hidden inputs
    searchForm.querySelectorAll('input[data-mode]').forEach(el => el.remove());

    const mk = (name, val) => {
      const el = document.createElement('input');
      el.type = 'hidden'; el.name = name; el.value = val;
      el.setAttribute('data-mode', '1');
      searchForm.appendChild(el);
    };
    mk('contains', containsVal);
    mk('fuzzy',    fuzzyVal);
    searchForm.submit();
  }

  document.querySelectorAll('#mode-contains input, #mode-fuzzy input').forEach(radio => {
    radio.addEventListener('change', resubmitWithMode);
  });
})();

(function () {
  const sections = document.querySelectorAll('.dico-section[id]');
  if (!sections.length) return;
  const navLinks = document.querySelectorAll('.dict-nav a[href^="#dico-"]');
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

// ── Sign-in dropdown toggle ──────────────────────────────────────
(function () {
  const toggle   = document.getElementById('signin-toggle');
  const dropdown = document.getElementById('signin-dropdown');
  if (!toggle || !dropdown) return;

  // Open/close on button click
  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    const open = dropdown.hasAttribute('hidden');
    dropdown.toggleAttribute('hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      const firstInput = dropdown.querySelector('input');
      if (firstInput) firstInput.focus();
    }
  });

  // Close when clicking outside
  document.addEventListener('click', function (e) {
    if (!dropdown.hasAttribute('hidden') && !dropdown.contains(e.target)) {
      dropdown.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });

  // Close on Escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !dropdown.hasAttribute('hidden')) {
      dropdown.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    }
  });

  // If there was a login error, open the dropdown automatically
  <?php if ($loginError !== ''): ?>
  dropdown.removeAttribute('hidden');
  toggle.setAttribute('aria-expanded', 'true');
  <?php endif; ?>
})();

// ── Dictionary info popups ───────────────────────────────────────
function closeDicoPopup(dk) {
  const popup = document.getElementById('popup-' + dk);
  const btn   = document.querySelector('.dico-info-btn[data-dico="' + dk + '"]');
  if (popup) { popup.classList.remove('open'); popup.setAttribute('aria-hidden', 'true'); }
  if (btn)   { btn.classList.remove('open'); }
}

function closeAllDicoPopups(except) {
  document.querySelectorAll('.dico-popup.open').forEach(p => {
    if (p.id !== 'popup-' + except) {
      p.classList.remove('open'); p.setAttribute('aria-hidden', 'true');
    }
  });
  document.querySelectorAll('.dico-info-btn.open').forEach(b => {
    if (b.dataset.dico !== except) b.classList.remove('open');
  });
}

function toggleDicoPopup(btn, dk) {
  const popup = document.getElementById('popup-' + dk);
  if (!popup) return;
  const isOpen = popup.classList.contains('open');
  closeAllDicoPopups(null);
  if (!isOpen) {
    popup.classList.add('open'); popup.setAttribute('aria-hidden', 'false');
    btn.classList.add('open');
  }
}

document.addEventListener('click', function (e) {
  if (!e.target.closest('.dict-nav')) closeAllDicoPopups(null);
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeAllDicoPopups(null);
});
</script>

<!-- ── Tibetan dictionary modal (Steinert) ── -->
<!-- ── Google Translation modal ── -->
<div class="modal-backdrop" id="modal-translate" role="dialog" aria-modal="true" aria-labelledby="modal-translate-title">
  <div class="modal-box">
    <button class="modal-close" aria-label="Close" onclick="closeTranslate()">✕</button>
    <h2 class="modal-title" id="modal-translate-title">Translation <span id="trans-lang-label" style="font-weight:400;font-style:italic;font-size:.9em"></span></h2>
    <div class="trans-source-label">Original</div>
    <div class="trans-source-text" id="trans-source-text"></div>
    <div class="trans-result-label">English (Google Translate)</div>
    <div class="trans-result-text" id="trans-result-text"></div>
    <div class="trans-attribution">Powered by Google Translate</div>
  </div>
</div>

<div class="modal-backdrop" id="modal-tib-dict" role="dialog" aria-modal="true" aria-labelledby="modal-tib-dict-title">
  <div class="modal-box" id="tib-dict-box">
    <div class="tib-dict-titlebar" id="tib-dict-titlebar">
      <h2 class="modal-title" id="modal-tib-dict-title">Tibetan Dictionary (Steinert application) <span id="tib-dict-term" style="font-style:italic;font-weight:400"></span></h2>
      <button class="modal-close" aria-label="Close" onclick="closeTibDict()">✕</button>
    </div>
    <iframe id="tib-dict-frame" src="about:blank" title="Tibetan dictionary (Steinert)" allowfullscreen></iframe>
  </div>
</div>

<!-- ── Search-help modal ── -->
<div class="modal-backdrop" id="modal-search-help" role="dialog" aria-modal="true" aria-labelledby="modal-search-help-title">
  <div class="modal-box">
    <button class="modal-close" aria-label="Close" onclick="document.getElementById('modal-search-help').classList.remove('open')">✕</button>
    <h2 class="modal-title" id="modal-search-help-title">How to search</h2>
    <p class="modal-lead">
      Search matches the <strong>beginning</strong> of the transcript, traditional script, or Cyrillic fields.
      Transcript search is tolerant of the following equivalences — one spelling finds them all:
    </p>
    <ul class="modal-eq-list">
      <li>
        <span class="modal-eq-rule">o ⇔ u &nbsp;|&nbsp; ü ⇔ ö</span>
        <span class="modal-eq-note">Rounded vowels are interchangeable</span>
      </li>
      <li>
        <span class="modal-eq-rule">γ ⇔ g ⇔ q</span>
        <span class="modal-eq-note">In back-vowel context (a, o, u)</span>
      </li>
      <li>
        <span class="modal-eq-rule">g ⇔ k</span>
        <span class="modal-eq-note">In front-vowel context (e, i, ö, ü)</span>
      </li>
      <li>
        <span class="modal-eq-rule">t ⇔ d</span>
        <span class="modal-eq-note">Dental stops</span>
      </li>
      <li>
        <span class="modal-eq-rule">b ⇔ p</span>
        <span class="modal-eq-note">Labial stops</span>
      </li>
      <li>
        <span class="modal-eq-rule">j ⇔ y / j ⇔ c</span>
        <span class="modal-eq-note">Word-initially j ⇔ y; word-medially j ⇔ c</span>
      </li>
      <li>
        <span class="modal-eq-rule">- (hyphen)</span>
        <span class="modal-eq-note">Ignored — <code>aqa</code> finds <code>aq-a</code> and vice versa</span>
      </li>
      <li>
        <span class="modal-eq-rule">trailing n</span>
        <span class="modal-eq-note">Optional — <code>altan</code> also finds words beginning with <code>alta</code> and its equivalents</span>
      </li>
    </ul>
    <div class="modal-example">
      Example: searching for <code>qutug</code> will also surface <code>γodoq</code> and <code>qutuγ</code>.
    </div>
    <h3 style="margin:1.2rem 0 .4rem;font-size:1rem;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft);">Search modes</h3>
    <ul class="modal-eq-list">
      <li>
        <span class="modal-eq-rule">begins</span>
        <span class="modal-eq-note">Match only words whose transcript/script <em>begins with</em> the query (default)</span>
      </li>
      <li>
        <span class="modal-eq-rule">contains</span>
        <span class="modal-eq-note">Match words that <em>contain</em> the query anywhere</span>
      </li>
      <li>
        <span class="modal-eq-rule">exact</span>
        <span class="modal-eq-note">Standard matching with the equivalence classes above (default)</span>
      </li>
      <li>
        <span class="modal-eq-rule">approx</span>
        <span class="modal-eq-note">Approximate vowel match — all vowels (a/e/i/o/u/ö/ü) are treated as equivalent, so <code>ibilkü</code> also finds <code>ibelkü</code>, <code>abulku</code>, etc.</span>
      </li>
    </ul>
  </div>
</div>

<!-- ── Resources modal ── -->
<div class="modal-backdrop" id="modal-resources" role="dialog" aria-modal="true" aria-labelledby="modal-resources-title">
  <div class="modal-box">
    <button class="modal-close" aria-label="Close" onclick="document.getElementById('modal-resources').classList.remove('open')">✕</button>
    <h2 class="modal-title" id="modal-resources-title">Lexicographical Resources for the Mongolian Language</h2>
    <p class="modal-lead">
      This platform brings together the principal Western and Asian dictionaries of classical written Mongolian,
      together with Tibetan-Mongolian Buddhist glossaries, searchable in a single interface.
      Entries are keyed by a shared reference identifier, allowing cross-dictionary comparison for any headword.
    </p>

    <div class="modal-res-divider">Western dictionaries</div>
    <ul class="modal-resources-list">
      <?php foreach (['lessing','kowalewski','schmidt','boberg','bawden','vietze'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="modal-res-item">
        <div class="modal-res-title"><?= h($info['title']) ?></div>
        <div class="modal-res-meta"><?= h($info['meta']) ?></div>
        <div class="modal-res-body"><?= h($info['body']) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="modal-res-divider" style="margin-top:1.4rem">Asian dictionaries</div>
    <ul class="modal-resources-list">
      <?php foreach (['chinese','chinese2','lamrd'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="modal-res-item">
        <div class="modal-res-title"><?= h($info['title']) ?></div>
        <div class="modal-res-meta"><?= h($info['meta']) ?></div>
        <div class="modal-res-body"><?= h($info['body']) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="modal-res-divider" style="margin-top:1.4rem">Mongolian dictionaries</div>
    <ul class="modal-resources-list">
      <?php foreach (['cevel'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="modal-res-item">
        <div class="modal-res-title"><?= h($info['title']) ?></div>
        <div class="modal-res-meta"><?= h($info['meta']) ?></div>
        <div class="modal-res-body"><?= h($info['body']) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="modal-res-divider" style="margin-top:1.4rem">Sanskrit–Mongolian</div>
    <ul class="modal-resources-list">
      <?php foreach (['raghu'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="modal-res-item">
        <div class="modal-res-title"><?= h($info['title']) ?></div>
        <div class="modal-res-meta"><?= h($info['meta']) ?></div>
        <div class="modal-res-body"><?= h($info['body']) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="modal-res-divider" style="margin-top:1.4rem">Tibetan–Mongolian resources</div>
    <ul class="modal-resources-list">
      <?php foreach (['tibetan','lk4','saran'] as $dk):
          if (!isset($DICO_INFO[$dk])) continue;
          $info = $DICO_INFO[$dk]; ?>
      <li class="modal-res-item">
        <div class="modal-res-title"><?= h($info['title']) ?></div>
        <div class="modal-res-meta"><?= h($info['meta']) ?></div>
        <div class="modal-res-body"><?= h($info['body']) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<script>
// ── Google Translate modal ───────────────────────────────────────
function closeTranslate() {
  document.getElementById('modal-translate').classList.remove('open');
}

function openTranslate(btn) {
  var text = btn.dataset.text || '';
  var lang = btn.dataset.lang || 'zh-CN';  // source language
  var langLabel = lang === 'ru' ? '(Russian → English)' : lang === 'mn' ? '(Mongolian → English)' : '(Chinese → English)';

  document.getElementById('trans-lang-label').textContent = langLabel;
  document.getElementById('trans-source-text').textContent = text;
  document.getElementById('trans-result-text').innerHTML =
      '<span class="trans-spinner"></span> Translating…';
  document.getElementById('modal-translate').classList.add('open');

  // Use the free Google Translate API (no key required for this endpoint)
  var url = 'https://translate.googleapis.com/translate_a/single' +
            '?client=gtx&sl=' + encodeURIComponent(lang) +
            '&tl=en&dt=t&q=' + encodeURIComponent(text);

  fetch(url)
    .then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function (data) {
      // data[0] is an array of [translated, original, ...] segments
      var translated = '';
      if (Array.isArray(data[0])) {
        data[0].forEach(function (seg) {
          if (seg && seg[0]) translated += seg[0];
        });
      }
      document.getElementById('trans-result-text').textContent =
          translated || '(no translation returned)';
    })
    .catch(function (err) {
      document.getElementById('trans-result-text').innerHTML =
          '<span class="trans-error">Translation failed: ' +
          err.message + '</span>';
    });
}

// ── Tibetan dictionary modal (Steinert) ──────────────────────────
function openTibDict(ewts) {
  var btn = event && event.currentTarget ? event.currentTarget : null;
  var displayTerm = ewts;
  if (btn) {
    var prev = btn.previousElementSibling;
    if (prev && (prev.classList.contains('tib-wylie') ||
                 prev.classList.contains('saran-tib') ||
                 prev.classList.contains('lk4-tib-def'))) {
      displayTerm = prev.textContent.trim() || ewts;
    }
  }
  var url = 'https://dictionary.christian-steinert.de/#/search/tib/' +
            encodeURIComponent(ewts) + '?activeTerm=' + encodeURIComponent(ewts);
  document.getElementById('tib-dict-frame').src = url;
  document.getElementById('tib-dict-term').textContent = '— ' + displayTerm;

  var box = document.getElementById('tib-dict-box');
  // Reset to centered default on every open
  box.style.transform = '';
  box.style.width  = '90vw';
  box.style.height = '80vh';
  box.style.top    = '';
  box.style.left   = '';
  document.getElementById('modal-tib-dict').classList.add('open');
  // Resolve the CSS transform to px so drag works immediately after open
  requestAnimationFrame(function () {
    var r = box.getBoundingClientRect();
    box.style.left      = r.left + 'px';
    box.style.top       = r.top  + 'px';
    box.style.width     = r.width  + 'px';
    box.style.height    = r.height + 'px';
    box.style.transform = 'none';
  });
}
function closeTibDict() {
  document.getElementById('modal-tib-dict').classList.remove('open');
  document.getElementById('tib-dict-frame').src = 'about:blank';
}

// ── Drag-to-move for the Tibetan dict modal ───────────────────────
(function () {
  var handle = document.getElementById('tib-dict-titlebar');
  var box    = document.getElementById('tib-dict-box');
  if (!handle || !box) return;

  var startX, startY, startL, startT;

  handle.addEventListener('mousedown', function (e) {
    if (e.button !== 0) return;
    // Resolve any remaining transform before dragging
    if (box.style.transform !== 'none') {
      var r = box.getBoundingClientRect();
      box.style.left = r.left + 'px'; box.style.top = r.top + 'px';
      box.style.width = r.width + 'px'; box.style.height = r.height + 'px';
      box.style.transform = 'none';
    }
    startX = e.clientX; startY = e.clientY;
    startL = parseInt(box.style.left, 10) || box.getBoundingClientRect().left;
    startT = parseInt(box.style.top,  10) || box.getBoundingClientRect().top;
    // Disable iframe pointer events during drag so it doesn't swallow mousemove
    document.getElementById('tib-dict-frame').style.pointerEvents = 'none';
    e.preventDefault();

    function onMove(e) {
      box.style.left = Math.max(0, startL + e.clientX - startX) + 'px';
      box.style.top  = Math.max(0, startT + e.clientY - startY) + 'px';
    }
    function onUp() {
      document.getElementById('tib-dict-frame').style.pointerEvents = '';
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup',   onUp);
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup',   onUp);
  });

  // Touch drag support
  handle.addEventListener('touchstart', function (e) {
    var t = e.touches[0];
    if (box.style.transform !== 'none') {
      var r = box.getBoundingClientRect();
      box.style.left = r.left + 'px'; box.style.top = r.top + 'px';
      box.style.width = r.width + 'px'; box.style.height = r.height + 'px';
      box.style.transform = 'none';
    }
    startX = t.clientX; startY = t.clientY;
    startL = parseInt(box.style.left, 10) || box.getBoundingClientRect().left;
    startT = parseInt(box.style.top,  10) || box.getBoundingClientRect().top;
    document.getElementById('tib-dict-frame').style.pointerEvents = 'none';

    function onMove(e) {
      var t = e.touches[0];
      box.style.left = Math.max(0, startL + t.clientX - startX) + 'px';
      box.style.top  = Math.max(0, startT + t.clientY - startY) + 'px';
    }
    function onEnd() {
      document.getElementById('tib-dict-frame').style.pointerEvents = '';
      document.removeEventListener('touchmove', onMove);
      document.removeEventListener('touchend',  onEnd);
    }
    document.addEventListener('touchmove', onMove, { passive: true });
    document.addEventListener('touchend',  onEnd);
  }, { passive: true });
})();

// Close modals on backdrop click or Escape
(function () {
  var modals = ['modal-tib-dict', 'modal-search-help', 'modal-resources', 'modal-translate'];
  modals.forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function (e) {
      if (e.target === el) {
        el.classList.remove('open');
        if (id === 'modal-tib-dict') {
          document.getElementById('tib-dict-frame').src = 'about:blank';
        }
      }
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      modals.forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('open');
      });
      document.getElementById('tib-dict-frame').src = 'about:blank';
    }
  });
})();
</script>

<script src="EwtsConverter.js"></script>
<script>
(function () {
  if (typeof jsEWTS === 'undefined') return;
  // lk4: Tibetan in .def
  var lk4Sec = document.getElementById('dico-lk4');
  if (lk4Sec) {
    lk4Sec.querySelectorAll('.def').forEach(function (el) {
      var ewts = el.textContent.trim();
      if (!ewts) return;
      var uni = jsEWTS.fromWylie(ewts);
      if (uni) { el.textContent = uni; el.classList.add('tib-unicode'); }
    });
  }
  // saran: Tibetan in .saran-tib (which also carries .def)
  var saranSec = document.getElementById('dico-saran');
  if (saranSec) {
    saranSec.querySelectorAll('.saran-tib').forEach(function (el) {
      var ewts = el.textContent.trim();
      if (!ewts) return;
      var uni = jsEWTS.fromWylie(ewts);
      if (uni) { el.textContent = uni; el.classList.add('tib-unicode'); }
    });
  }
  // tibetan (Mv): Tibetan in .tib-wylie
  var mvSec = document.getElementById('dico-tibetan');
  if (mvSec) {
    mvSec.querySelectorAll('.tib-wylie').forEach(function (el) {
      var ewts = el.textContent.trim();
      if (!ewts) return;
      var uni = jsEWTS.fromWylie(ewts);
      if (uni) {
        el.textContent = uni;
        el.classList.add('tib-unicode');
      }
    });
  }
})();
</script>
</body>
</html>