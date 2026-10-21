"""Build local, double-clickable galleries for the optional images/.temp libraries.

Run: python scripts/build-image-galleries.py
The generated pages stay alongside the ignored image libraries.
"""

from collections import defaultdict
from html import escape
from pathlib import Path
from urllib.parse import quote


ROOT = Path(__file__).resolve().parents[1] / "images" / ".temp"
PICTO = ROOT / "2D Icons-PictoIconPack01" / "Icons" / "PictoIcon_64"
FANTASY = ROOT / "6000FantasyIcons"

STYLE = """
* { box-sizing: border-box; }
html { color-scheme: dark; background: #000; color: #fff; }
html.light { color-scheme: light; background: #fff; color: #000; }
body { margin: 0; font: 14px/1.4 'Segoe UI', sans-serif; }
header { position: sticky; top: 0; z-index: 1; display: flex; align-items: center;
  flex-wrap: wrap; gap: 10px 24px; padding: 10px 16px; background: inherit;
  border-bottom: 1px solid #888; }
body, header { background: inherit; color: inherit; }
h1 { margin: 0; font-size: 20px; }
nav { display: flex; gap: 16px; }
a { color: inherit; text-underline-offset: 3px; }
button { margin-left: auto; padding: 7px 12px; font: inherit; cursor: pointer;
  background: #fff; color: #000; border: 1px solid #888; border-radius: 4px; }
.light button { background: #000; color: #fff; }
.search { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; }
input[type="search"] { width: min(240px, 60vw); padding: 7px 10px; font: inherit;
  background: inherit; color: inherit; border: 1px solid #888; border-radius: 4px; }
#search-count { font-size: 12px; }
:focus-visible { outline: 3px solid #408bff; outline-offset: 3px; }
main { padding: 12px 16px 32px; }
.summary { margin: 0 0 16px; }
section { margin-bottom: 22px; }
h2 { margin: 0 0 10px; padding-bottom: 5px; font-size: 16px;
  overflow-wrap: anywhere; border-bottom: 1px solid #888; }
.count { font-size: 13px; font-weight: normal; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: 12px 6px; }
figure { margin: 0; min-width: 0; text-align: center; }
figure a { display: flex; height: 68px; align-items: center; justify-content: center; }
img { width: 64px; height: 64px; object-fit: contain; }
figcaption { margin-top: 3px; font-size: 11px; line-height: 1.25; overflow-wrap: anywhere; }
.libraries { display: grid; gap: 20px; max-width: 620px; }
.libraries a { font-size: 20px; }
"""

TOGGLE = """
const button = document.querySelector('#background-toggle');
button.addEventListener('click', () => {
  const light = document.documentElement.classList.toggle('light');
  button.textContent = light ? 'Use black background' : 'Use white background';
  button.setAttribute('aria-pressed', String(light));
});
"""

SEARCH = """
const search = document.querySelector('#icon-search');
const searchCount = document.querySelector('#search-count');
const empty = document.querySelector('#no-results');
const groups = Array.from(document.querySelectorAll('main section'), section => ({
  section,
  icons: Array.from(section.querySelectorAll('figure'), figure => ({
    figure, name: figure.querySelector('figcaption').textContent.toLowerCase()
  }))
}));
const total = groups.reduce((sum, group) => sum + group.icons.length, 0);
function filterIcons() {
  const query = search.value.trim().toLowerCase();
  let visible = 0;
  for (const group of groups) {
    let matches = 0;
    for (const icon of group.icons) {
      icon.figure.hidden = !icon.name.includes(query);
      if (!icon.figure.hidden) matches++;
    }
    group.section.hidden = matches === 0;
    visible += matches;
  }
  searchCount.textContent = `${visible.toLocaleString()} of ${total.toLocaleString()} icons`;
  empty.hidden = visible !== 0;
}
search.addEventListener('input', filterIcons);
filterIcons();
"""


def page(title, content, toggle=True):
    control = ('<button id="background-toggle" type="button" aria-pressed="false">'
               'Use white background</button>') if toggle else ''
    search = ('<div class="search"><label for="icon-search">Search icons</label>'
              '<input id="icon-search" type="search" placeholder="Filter by name" '
              'autocomplete="off" aria-controls="gallery">'
              '<span id="search-count" role="status" aria-live="polite"></span></div>') if toggle else ''
    script = f"<script>{TOGGLE}\n{SEARCH}</script>" if toggle else ''
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{escape(title)} | Image libraries</title>
<style>{STYLE}</style>
</head>
<body>
<header>
<h1>{escape(title)}</h1>
<nav aria-label="Image libraries">
<a href="index.html">Libraries</a>
<a href="pictoicon.html">PictoIcon</a>
<a href="fantasy-icons.html">Fantasy icons</a>
</nav>
{search}
{control}
</header>
<main id="gallery">{content}</main>
{script}
</body>
</html>
'''


def gallery(source, title, output, strip_prefix=""):
    if not source.is_dir():
        raise FileNotFoundError(f"Image library not found: {source}")
    folders = defaultdict(list)
    for path in sorted(source.rglob('*'), key=lambda p: p.as_posix().casefold()):
        if path.is_file() and path.suffix.lower() == '.png':
            folders[path.parent.relative_to(source)].append(path)

    count = sum(map(len, folders.values()))
    sections = [f'<p class="summary">{count:,} icons. Click an image to open the original.</p>',
                '<p id="no-results" hidden>No icons match your search.</p>']
    for folder, paths in folders.items():
        heading = folder.as_posix() if folder != Path('.') else title
        sections.append(f'<section><h2>{escape(heading)} '
                        f'<span class="count">({len(paths):,})</span></h2><div class="grid">')
        for path in paths:
            name = path.stem
            if strip_prefix and name.casefold().startswith(strip_prefix.casefold()):
                name = name[len(strip_prefix):]
            url = escape(quote(path.relative_to(ROOT).as_posix(), safe='/'), quote=True)
            label = escape(name, quote=True)
            sections.append(f'<figure><a href="{url}" title="{label}">'
                            f'<img src="{url}" alt="{label}" width="64" height="64" '
                            f'loading="lazy" decoding="async"></a>'
                            f'<figcaption>{label}</figcaption></figure>')
        sections.append('</div></section>')

    (ROOT / output).write_text(page(title, '\n'.join(sections)), encoding='utf-8')
    print(f'{output}: {count:,} icons in {len(folders):,} folders')
    return count


def main():
    picto_count = gallery(PICTO, 'PictoIcon 64', 'pictoicon.html', 'Icon_PictoIcon_')
    fantasy_count = gallery(FANTASY, '6000 Fantasy Icons', 'fantasy-icons.html')
    content = f'''<div class="libraries">
<p>Choose a library. Each gallery shows the entire collection on one page.</p>
<div><a href="pictoicon.html">PictoIcon 64</a><p>{picto_count:,} white icons on black.</p></div>
<div><a href="fantasy-icons.html">6000 Fantasy Icons</a><p>{fantasy_count:,} icons grouped by folder, with a black/white background toggle.</p></div>
</div>'''
    (ROOT / 'index.html').write_text(page('Image libraries', content, toggle=False), encoding='utf-8')
    print(f'Open {ROOT / "index.html"}')


if __name__ == '__main__':
    main()
