"""Extract background-free map avatars from the Paint.NET sources.

Each card's .pdn keeps the figure on its top layer over a painted background.
This saves that top layer, trimmed to the figure, as <name>_nobg.png beside the
.pdn, then points each gameData.json card whose image has a matching _nobg.png
at it via avatarImage.

    pip install pypdn pillow
    python scripts/extract-avatars.py            # skip PNGs that already exist
    python scripts/extract-avatars.py --force    # re-extract every PNG
"""
import json
import re
import sys
from pathlib import Path

import pypdn
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
GAME_DATA = ROOT / "data" / "gameData.json"


def extract(force: bool) -> None:
    for source in sorted((ROOT / "images").rglob("*.pdn")):
        target = source.with_name(source.stem + "_nobg.png")
        if target.exists() and not force:
            continue
        document = pypdn.read(str(source))
        figure = document.layers[-1]
        if not (figure.image[..., 3] == 0).any():
            print(f"skipped {source.relative_to(ROOT)}: top layer has no transparency")
            continue
        # Trim the empty margin so map tokens can scale the figure itself to fill its hex.
        avatar = Image.fromarray(figure.image, "RGBA")
        avatar.crop(avatar.getchannel("A").getbbox()).save(target, optimize=True)
        print(f"wrote {target.relative_to(ROOT)}")


def avatar_for(image: str) -> str | None:
    path = re.sub(r"(_card)?\.png$", "_nobg.png", image)
    return path if path != image and (ROOT / path.lstrip("/")).exists() else None


def link_game_data() -> None:
    text = GAME_DATA.read_text(encoding="utf-8")
    pattern = re.compile(r'^(\s*)"image": "([^"]*)",(\r?\n)(?!\s*"avatarImage")', re.MULTILINE)

    def add_avatar(match: re.Match) -> str:
        indent, image, newline = match.groups()
        avatar = avatar_for(image)
        if avatar is None:
            return match.group(0)
        print(f"linked {avatar}")
        return f'{match.group(0)}{indent}"avatarImage": "{avatar}",{newline}'

    updated = pattern.sub(add_avatar, text)
    json.loads(updated)
    if updated != text:
        GAME_DATA.write_text(updated, encoding="utf-8", newline="")


if __name__ == "__main__":
    extract(force="--force" in sys.argv)
    link_game_data()
