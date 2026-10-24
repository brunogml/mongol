# Mongolian Dictionary — Local Static Version

Three PHP files converted to standalone HTML/JS that run in any browser
without a server (or with `python -m http.server`).

## Files

| File | Replaces | Purpose |
|------|----------|---------|
| `index.html` | `index.php` | Main search + entry view |
| `tib.html` | `tib.php` | Tibetan reverse search |
| `img.html` | `img.php` | OpenSeadragon image viewer |

## Folder structure (same as server)

```
your-dict-folder/
├── index.html          ← drop here
├── tib.html
├── img.html
├── README.md
├── soyombo.png
├── EwtsConverter.js
├── xml/
│   ├── root.xml
│   ├── Mv.xml
│   ├── lk4.xml
│   ├── saran.xml
│   └── {letter}/
│       └── {REF}.xml   (individual entry files)
├── img/
│   ├── lk4/
│   ├── saran/
│   ├── Lessing/
│   ├── Kovalevski1/  Kovalevski2/  Kovalevski3/
│   ├── Boberg1/  Boberg2/
│   ├── Schmidt/
│   ├── Chinese/
│   ├── Bawden/
│   ├── Mostaert/
│   ├── Vietze/
│   └── Mahavyutpatti/
└── seadragon/
    ├── openseadragon.min.js
    └── images/          (OSD icon sprites)
```

## How to run

### Option A — Python local server (recommended)

```bash
cd your-dict-folder
python3 -m http.server 8080
# then open http://localhost:8080/index.html
```

Needed because browsers block `fetch()` of local `file://` URLs.

### Option B — VS Code Live Server extension

Install "Live Server" (Ritwick Dey), right-click `index.html` → Open with Live Server.

### Option C — Direct file:// (Chrome only)

Launch Chrome with:
```
chrome --allow-file-access-from-files index.html
```

## What changed from PHP

- **No login / session** — the auth system is removed (no `login.txt`).
  The edit link and sign-in widget are gone.
- **SPA navigation** — `index.html` is a single page; search and entry
  views are rendered in-place with `history.pushState`.  
  Back/Forward buttons work normally.
- **XML loaded client-side** — `root.xml`, `Mv.xml`, `lk4.xml`,
  `saran.xml` are fetched with `fetch()` on first load.
  Individual entry files (`xml/a/A0001.xml` etc.) are fetched on demand.
- **img.html** — uses URL params `?dic=NAME&id=PAGE` exactly like
  `img.php`; all dictionary-specific offset/padding logic is ported to JS.
- **tib.html** — same search logic as `tib.php`, purely client-side.
- **EwtsConverter.js** — Wylie→Unicode conversion works the same way;
  make sure the file is present alongside the HTML files.
- **Grammar link** points to `gramm/gramm.html` instead of `gramm/gramm.php`.