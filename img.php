<?php
$dic = $_GET['dic'];
$p   = $_GET['id'];

// Detect whether the seadragon folder is one level up (server) or local (WAMP)
$sdPath = is_dir('../seadragon') ? '../seadragon' : 'seadragon';

// ---------- ID arithmetic (prev / next) ----------
if (strcmp($dic, "lk4") == 0) {
    $pNum    = (int)$p;
    $p1      = str_pad($pNum - 1, 4, '0', STR_PAD_LEFT);
    $p2      = str_pad($pNum + 1, 4, '0', STR_PAD_LEFT);
    $pDisplay = $pNum;
} elseif (strcmp($dic, "saran") == 0) {
    // IDs are n-1 or n-2; sequence: 1-1, 1-2, 2-1, 2-2, 3-1, 3-2, ...
    list($pMaj, $pMin) = explode('-', $p);
    $pMaj = (int)$pMaj;
    $pMin = (int)$pMin;
    if ($pMin == 1) {
        $p1 = ($pMaj - 1) . '-2';
        $p2 = $pMaj . '-2';
    } else {
        $p1 = $pMaj . '-1';
        $p2 = ($pMaj + 1) . '-1';
    }
    $pDisplay = $p;
} else {
    $p1      = $p - 1;
    $p2      = $p + 1;
    $pDisplay = $p;
}

// ---------- Navigation header ----------
echo '<p align=center><font size=+2>';
echo '<a href="img.php?dic=' . $dic . '&id=' . $p1 . '">← ' . $p1 . '</a>';
echo ' - ' . $pDisplay . ' - ';
echo '<a href="img.php?dic=' . $dic . '&id=' . $p2 . '">' . $p2 . ' →</a>';
echo '</font></p>';

// ---------- Helper: emit the OpenSeadragon viewer ----------
function osd_viewer($imgUrl, $sdPath) {
    echo '<div id="openseadragon1" style="width: 95%; height:1000px; border:1px solid #08088a;"></div>';
    echo '
<script src="' . $sdPath . '/openseadragon.min.js"></script>
<script type="text/javascript">
    OpenSeadragon({
        id:           "openseadragon1",
        prefixUrl:    "' . $sdPath . '/images/",
        maxZoomLevel: 5.0,
        tileSources: {
            type:          "image",
            url:           "' . $imgUrl . '",
            buildPyramid:  false,
            maxZoomLevel:  10.0
        }
    });
</script>';
}

// ---------- Per-dictionary image path logic ----------

if (strcmp($dic, "Lessing") == 0) {
    $link = $p + ($p < 1158 ? 12 : 9);
    $link = str_pad($link, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Lessing/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "Kowalewski") == 0) {
    if ($p < 595)                      { $link = $p + 18;    $bobv = 1; }
    elseif ($p < 1546)                 { $link = $p - 590;   $bobv = 2; }
    else                               { $link = $p - 1542;  $bobv = 3; }
    $link = str_pad($link, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Kovalevski' . $bobv . '/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "Boberg") == 0) {
    if ($p < 218)                      { $link = $p + 44;  $bobv = 1; }
    elseif ($p < 769)                  { $link = $p + 46;  $bobv = 1; }
    elseif ($p < 796)                  { $link = $p - 763; $bobv = 2; }
    elseif ($p < 1458)                 { $link = $p - 761; $bobv = 2; }
    else                               { $link = $p - 762; $bobv = 2; }
    $link = str_pad($link, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Boberg' . $bobv . '/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "Schmidt") == 0) {
    if ($p < 90)                       { $link = $p + 11; }
    elseif ($p < 94)                   { $link = $p + 13; }
    elseif ($p < 158)                  { $link = $p + 15; }
    elseif ($p < 328)                  { $link = $p + 17; }
    else                               { $link = $p + 19; }
    $link = str_pad($link, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Schmidt/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "Chinese") == 0) {
    $link = str_pad($p, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Chinese/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "Bawden") == 0) {
    $link = str_pad($p, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Bawden/' . $link . '.tif', $sdPath);
}

if (strcmp($dic, "Mostaert") == 0) {
    $link = str_pad($p, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Mostaert/' . $link . '.png', $sdPath);
}

if (strcmp($dic, "lk4") == 0) {
    $link = str_pad((int)$p, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/lk4/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "Vietze") == 0) {
    $link = str_pad($p, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Vietze/' . $link . '.png', $sdPath);
}

if (strcmp($dic, "Mahavyutpatti") == 0) {
    $link = str_pad((int)$p, 3, '0', STR_PAD_LEFT);
    osd_viewer('img/Mahavyutpatti/' . $link . '.jpg', $sdPath);
}

if (strcmp($dic, "saran") == 0) {
    osd_viewer('img/saran/' . $p . '.jpg', $sdPath);
}

if (strcmp($dic, "cevel") == 0) {
    $link = str_pad((int)$p, 4, '0', STR_PAD_LEFT);
    osd_viewer('img/Cevel/' . $link . '.jpg', $sdPath);
}