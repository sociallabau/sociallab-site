#!/usr/bin/env python3
"""
Build the Social Lab site.

Takes the page bodies in pages/, wraps them in templates/base.html, expands any
{{include ...}} partials, and writes plain HTML into dist/ ready to upload to
SiteGround. Python standard library only — nothing to install.

    python3 build.py           build into dist/
    python3 build.py --serve   build, then serve dist/ on http://localhost:8000
"""

from __future__ import annotations

import html
import http.server
import re
import shutil
import socketserver
import sys
from pathlib import Path

ROOT = Path(__file__).parent
PAGES = ROOT / "pages"
TEMPLATES = ROOT / "templates"
DIST = ROOT / "dist"

SITE = {
    "site_name": "Social Lab",
    "site_url": "https://sociallab.com.au",
    "email": "digital@sociallab.com.au",
    "phone": "0459 224 408",
    "phone_link": "+61459224408",
    "instagram": "https://www.instagram.com/sociallabau/",
}

NAV = [
    ("ecosystem", "The Ecosystem", "/ecosystem/"),
    ("property", "Property Marketing", "/property-marketing/"),
    ("results", "Results", "/#results"),
    ("contact", "Contact", "/contact/"),
]

ARROW = (
    '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true">'
    '<path d="M1 8h13M9 3l5 5-5 5" stroke="currentColor" stroke-width="1.6" '
    'stroke-linecap="round" stroke-linejoin="round"/></svg>'
)


# --------------------------------------------------------------- front matter

def parse_page(text: str) -> tuple[dict[str, str], str]:
    """Split a page file into its `key: value` front matter and its body."""
    meta: dict[str, str] = {}
    if text.startswith("---"):
        _, raw, body = text.split("---", 2)
        for line in raw.strip().splitlines():
            if ":" in line:
                key, value = line.split(":", 1)
                meta[key.strip()] = value.strip()
        return meta, body.lstrip("\n")
    return meta, text


# -------------------------------------------------------------------- partials

ATTR_RE = re.compile(r'(\w+)="([^"]*)"')
INCLUDE_RE = re.compile(r"\{\{include\s+([\w.-]+)([^}]*)\}\}")


def render_form(attrs: dict[str, str]) -> str:
    """Render the enquiry form partial with the options this page needs."""
    template = (TEMPLATES / "form.html").read_text()

    fid = attrs.get("id", "enquiry")

    # interest_options="A|B|C"
    options = "\n".join(
        f"          <option>{html.escape(option.strip())}</option>"
        for option in attrs.get("interest_options", "").split("|")
        if option.strip()
    )

    # extra="address:Property address|timing:When do you need it"
    extras = []
    for item in attrs.get("extra", "").split("|"):
        if ":" not in item:
            continue
        name, label = item.split(":", 1)
        extras.append(
            f"""      <div class="field">
        <label for="{fid}-{name.strip()}">{html.escape(label.strip())}</label>
        <input type="text" id="{fid}-{name.strip()}" name="{name.strip()}">
      </div>"""
        )

    values = {
        "id": fid,
        "source": attrs.get("source", "Website enquiry"),
        "submit": attrs.get("submit", "Send enquiry"),
        "interest_label": attrs.get("interest_label", "What can we help with?"),
        "interest_options": options,
        "extra_fields": "\n".join(extras),
        "note": attrs.get(
            "note",
            "We reply within one business day. You will hear from Elijah or Chloe — "
            "not an automated sales sequence.",
        ),
        "success": attrs.get("success", "Enquiry received."),
        "success_body": attrs.get(
            "success_body",
            "Thanks — we have your details and will be in touch within one business day.",
        ),
        "arrow": ARROW,
    }

    for key, value in values.items():
        template = template.replace("{{" + key + "}}", value)
    return template


def expand_includes(body: str) -> str:
    def replace(match: re.Match[str]) -> str:
        name = match.group(1)
        attrs = dict(ATTR_RE.findall(match.group(2)))
        if name == "form.html":
            return render_form(attrs)
        partial = (TEMPLATES / name).read_text()
        for key, value in attrs.items():
            partial = partial.replace("{{" + key + "}}", value)
        return partial

    return INCLUDE_RE.sub(replace, body)


# ----------------------------------------------------------------------- build

def build() -> None:
    if DIST.exists():
        shutil.rmtree(DIST)
    DIST.mkdir(parents=True)

    base = (TEMPLATES / "base.html").read_text()

    # Cache-bust CSS/JS using file size, so browsers pick up every change.
    css_v = (ROOT / "assets/css/site.css").stat().st_size
    js_v = (ROOT / "assets/js/site.js").stat().st_size

    pages = sorted(PAGES.glob("*.html"))
    for page_file in pages:
        meta, body = parse_page(page_file.read_text())
        body = expand_includes(body)

        nav_links = "\n".join(
            f'        <a href="{href}"{" class=\"is-active\"" if meta.get("section") == key else ""}>{label}</a>'
            for key, label, href in NAV
        )
        mobile_links = "\n".join(
            f'  <a href="{href}">{label}</a>' for _, label, href in NAV
        )

        out_path = meta.get("path", "/" + page_file.stem + "/")
        canonical = SITE["site_url"] + out_path

        html_out = base
        replacements = {
            "title": meta.get("title", "Social Lab"),
            "description": meta.get("description", ""),
            "canonical": canonical,
            "og_image": SITE["site_url"] + meta.get("og_image", "/assets/img/ecosystem/img3501.jpg"),
            "nav": nav_links,
            "mobile_nav": mobile_links,
            "content": body,
            "css_v": str(css_v),
            "js_v": str(js_v),
            "instagram": SITE["instagram"],
            "phone_link": SITE["phone_link"],
            "email": SITE["email"],
            "site_url": SITE["site_url"],
        }
        for key, value in replacements.items():
            html_out = html_out.replace("{{" + key + "}}", value)

        if out_path == "/":
            target = DIST / "index.html"
        elif out_path.endswith("/"):
            target = DIST / out_path.strip("/") / "index.html"
        else:  # e.g. /404.html
            target = DIST / out_path.lstrip("/")

        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(html_out)
        print(f"  built {out_path:<28} → {target.relative_to(ROOT)}")

    # Static files, the PHP form endpoint and server config.
    shutil.copytree(ROOT / "assets", DIST / "assets")
    shutil.copytree(ROOT / "api", DIST / "api")
    for extra in ("static/.htaccess", "static/robots.txt", "static/sitemap.xml"):
        source = ROOT / extra
        if source.exists():
            shutil.copy(source, DIST / Path(extra).name)

    print(f"\n✓ Built {len(pages)} pages into dist/")


def serve(port: int = 8000) -> None:
    class Handler(http.server.SimpleHTTPRequestHandler):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, directory=str(DIST), **kwargs)

        def log_message(self, *args):  # quieter output
            pass

    with socketserver.TCPServer(("", port), Handler) as httpd:
        print(f"→ Preview running at http://localhost:{port}  (ctrl+c to stop)")
        httpd.serve_forever()


if __name__ == "__main__":
    build()
    if "--serve" in sys.argv:
        serve()
