#!/usr/bin/env python3
"""
Build the Social Lab site.

Takes the page bodies in pages/, wraps them in templates/base.html, expands any
{{include ...}} partials, and writes plain HTML into dist/ ready to upload to
SiteGround. Python standard library only — nothing to install.

    python3 build.py               build into dist/
    python3 build.py --serve       build, then serve dist/ on http://localhost:8000
    python3 build.py --base=/preview --out=dist-preview
                                   build for a subfolder, into its own directory

Deploy builds go to their own --out directory so dist/ always stays as the
local preview expects it: a deploy can never leave localhost unstyled.
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


FIELDS = {
    "name":     ('<label for="{id}-name">Name <span class="req">*</span></label>'
                 '<input type="text" id="{id}-name" name="name" autocomplete="name" required>'
                 '<span class="field__error">Please tell us your name.</span>'),
    "email":    ('<label for="{id}-email">Email <span class="req">*</span></label>'
                 '<input type="email" id="{id}-email" name="email" autocomplete="email" required>'
                 '<span class="field__error">Please enter a valid email address.</span>'),
    "phone":    ('<label for="{id}-phone">Phone <span class="req">*</span></label>'
                 '<input type="tel" id="{id}-phone" name="phone" autocomplete="tel" required>'
                 '<span class="field__error">Please enter a contact number.</span>'),
    "business_type": ('<label for="{id}-business">Business type</label>'
                 '<select id="{id}-business" name="business_type"><option value="">Select…</option>'
                 '<option>Real estate agent</option><option>Real estate agency / principal</option>'
                 '<option>Buyers agent</option><option>Construction / builder / developer</option>'
                 '<option>Property adjacent business</option><option>Other</option></select>'),
    "business_name": ('<label for="{id}-business-name">Agency / business name</label>'
                 '<input type="text" id="{id}-business-name" name="business_name" autocomplete="organization">'),
    "area":     ('<label for="{id}-area">Core suburb / area</label>'
                 '<input type="text" id="{id}-area" name="area" placeholder="e.g. Palm Beach, Gold Coast">'),
    "address":  ('<label for="{id}-address">Property details / brief</label>'
                 '<textarea id="{id}-address" name="address" placeholder="Address, property type, and what you need shot."></textarea>'),
    "timing":   ('<label for="{id}-timing">Ideal day and time</label>'
                 '<input type="text" id="{id}-timing" name="timing" placeholder="e.g. Thursday afternoon, or twilight this week">'),
    "message":  ('<label for="{id}-message">Tell us a bit more</label>'
                 '<textarea id="{id}-message" name="message" placeholder="Where you are at now, and what you are trying to achieve."></textarea>'),
}

FULL_WIDTH = {"address", "message", "interest", "timing"}


def render_form(attrs: dict[str, str]) -> str:
    """Render the enquiry form with just the fields this page asks for."""
    fid = attrs.get("id", "enquiry")
    source = attrs.get("source", "Website enquiry")
    wanted = [f.strip() for f in attrs.get(
        "fields", "name|email|phone|business_type|business_name|area|message").split("|") if f.strip()]

    blocks = []
    for key in wanted:
        if key == "interest":
            options = "".join(
                f"<option>{html.escape(o.strip())}</option>"
                for o in attrs.get("interest_options", "").split("|") if o.strip())
            if not options:
                continue
            label = attrs.get("interest_label", "What can we help with?")
            blocks.append(
                f'      <div class="field field--full">\n'
                f'        <label for="{fid}-interest">{html.escape(label)}</label>\n'
                f'        <select id="{fid}-interest" name="interest"><option value="">Select…</option>{options}</select>\n'
                f'      </div>')
            continue
        if key not in FIELDS:
            continue
        css = "field field--full" if key in FULL_WIDTH else "field"
        blocks.append(f'      <div class="{css}">\n        {FIELDS[key].format(id=fid)}\n      </div>')

    note = attrs.get("note", "")
    note_html = f'      <p class="form-note">{html.escape(note)}</p>\n' if note else ""

    return f"""<div class="form-panel" id="form-{fid}">
  <form class="enquiry-form" method="post" action="/api/enquiry.php" novalidate data-source="{html.escape(source)}">
    <input type="hidden" name="source" value="{html.escape(source)}">
    <input type="hidden" name="elapsed" value="0" data-elapsed>
    <input type="hidden" name="page" value="" data-page>
    <div class="form-hp" aria-hidden="true">
      <label for="{fid}-company">Company (leave blank)</label>
      <input type="text" id="{fid}-company" name="company" tabindex="-1" autocomplete="off">
    </div>

    <div class="form-grid">
{chr(10).join(blocks)}
    </div>

    <div class="form-foot">
{note_html}      <button class="btn btn--rust btn--lg" type="submit">{html.escape(attrs.get("submit", "Send enquiry"))}{ARROW}</button>
    </div>

    <div class="form-status" role="alert"></div>
  </form>

  <div class="form-success">
    <div class="tick">✓</div>
    <h3 class="h3">{html.escape(attrs.get("success", "Enquiry received."))}</h3>
    <p class="lede" style="margin: 12px auto 0;">{html.escape(attrs.get("success_body", "Thanks, we have your details. We will be in touch shortly."))}</p>
  </div>
</div>
"""


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

def apply_base(markup: str, base: str) -> str:
    """Rewrite root-relative links so the site can live in a subfolder."""
    if not base:
        return markup
    return re.sub(r'(href|src|action)="/(?!/)', rf'\1="{base}/', markup)


def build(base: str = "", out: Path | None = None) -> None:
    dist = out or DIST
    if dist.exists():
        shutil.rmtree(dist)
    dist.mkdir(parents=True)

    shell = (TEMPLATES / "base.html").read_text()

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

        html_out = shell
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
            "site_url": SITE["site_url"],
        }
        for key, value in replacements.items():
            html_out = html_out.replace("{{" + key + "}}", value)

        if out_path == "/":
            target = dist / "index.html"
        elif out_path.endswith("/"):
            target = dist / out_path.strip("/") / "index.html"
        else:  # e.g. /404.html
            target = dist / out_path.lstrip("/")

        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(apply_base(html_out, base))
        print(f"  built {out_path:<28} → {target.relative_to(ROOT)}")

    # Static files, the PHP form endpoint and server config.
    shutil.copytree(ROOT / "assets", dist / "assets")
    shutil.copytree(ROOT / "api", dist / "api")
    if base:
        # A staging copy: no canonical redirects, and keep it out of Google.
        (dist / ".htaccess").write_text(
            "ErrorDocument 404 " + base + "/404.html\n"
            "Options -Indexes\n"
            '<FilesMatch "\\.(jsonl|log)$">\n  Require all denied\n</FilesMatch>\n'
        )
        (dist / "robots.txt").write_text("User-agent: *\nDisallow: /\n")
    else:
        for extra in ("static/.htaccess", "static/robots.txt", "static/sitemap.xml"):
            source = ROOT / extra
            if source.exists():
                shutil.copy(source, dist / Path(extra).name)

    print(f"\n✓ Built {len(pages)} pages into {dist.name}/" + (f" (base {base})" if base else ""))


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
    base_arg = next((a.split("=", 1)[1] for a in sys.argv if a.startswith("--base=")), "")
    out_arg = next((a.split("=", 1)[1] for a in sys.argv if a.startswith("--out=")), "")
    build(base_arg.rstrip("/"), Path(out_arg) if out_arg else None)
    if "--serve" in sys.argv:
        serve()
