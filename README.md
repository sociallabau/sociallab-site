# sociallab.com.au

The Social Lab website — static HTML built by a small Python script, hosted on SiteGround.
Replaces the old Framer site.

## Structure

```
pages/            page content (front matter + body HTML) — edit these
templates/        base.html wraps every page; form.html is the enquiry form
assets/           css, js, images
api/              enquiry.php — the form handler (the only PHP on the site)
static/           .htaccess, robots.txt, sitemap.xml
build.py          assembles everything into dist/
dist/             build output, uploaded to SiteGround (not committed)
```

## Working on it

```bash
python3 build.py --serve
```

Builds into `dist/` and serves it at http://localhost:8000. Re-run after each change.
Nothing to install — Python 3 ships with macOS.

To change page copy, edit the matching file in `pages/`. To change the look, edit
`assets/css/site.css`. Brand colours are the tokens at the top of that file
(cream `#eeedeb`, ink `#1c1a17`, rust `#ba5227`, sand `#cfc8b7`).

Note: the enquiry forms need PHP, so they don't submit in local preview — they work
once uploaded to SiteGround.

## Deploying

Push to `main` and GitHub Actions builds the site and rsyncs `dist/` to SiteGround.
The four repository secrets it needs are listed at the top of
`.github/workflows/deploy.yml`.

First deploy, done once by hand:

1. In SiteGround Site Tools → Devs → SFTP, create an SFTP user and download its key.
2. Add the four secrets to GitHub (Settings → Secrets and variables → Actions).
3. Copy `api/config.local.example.php` to `api/config.local.php` **on the server**,
   fill in the real values, and leave it there — deploys will not overwrite it.
4. Check `storage/` is writable; the form handler keeps a backup copy of every lead there.

## Going live on the domain

The site can be staged on a SiteGround temporary URL while Framer is still serving
sociallab.com.au. When it's approved:

1. Point the domain's DNS at SiteGround (A record, or their nameservers).
2. Turn on the free Let's Encrypt SSL certificate in Site Tools → Security.
3. Cancel the Framer subscription.

`static/.htaccess` already forces HTTPS, strips `www`, and 301-redirects the old
Framer paths (`/media`, `/brand`, `/podcast`, …) to the new pages.

## Leads

Every enquiry:

1. is emailed to the addresses in `config.local.php`,
2. is POSTed to the Growth Hub webhook when `growth_hub_url` is set (off for
   now; see `docs/growth-hub-lead-endpoint.ts.txt` for the endpoint it expects),
3. triggers an automatic reply to the enquirer — and for rate card requests, that
   reply contains the rate card link,
4. is appended to `storage/leads.jsonl` as a backup.

Spam protection: honeypot field, submit-timing check, and a five-per-hour limit per IP.
