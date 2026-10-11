# MailerHive

Beehiiv- and Ghost-style newsletter publishing for WordPress and [MailerPress](https://mailerpress.com/): a public archive of issues, a clean reading page, and one-click email drafts.

## Features

- **Issue pages** at `/newsletter/issue/{slug}/`, an archive at `/newsletter/issue/` and an RSS feed. The URL base is configurable.
- **A clean reading page.** Each issue renders as the email it was, with its own header and footer, light/dark mode that follows the reader's system (with a toggle), reading time, previous/next navigation and a signup box. Theme styles, scripts and hooks are kept off these pages; WordPress core and other plugins keep working.
- **One-click MailerPress drafts.** Publishing an issue (or clicking *Create MailerPress draft*) creates a draft campaign addressed to the lists you choose, with Unsubscribe and Manage subscription links. MailerHive never schedules or sends; you review and send in MailerPress.
- **Mirroring.** Newsletters written directly in MailerPress publish as issue pages once sent to a mirrored list. MailerPress "view in browser" links redirect to the issue page.
- **Substack import**, with images copied into the media library and links between imported issues rewritten.
- **Branding and settings**: logo (with a dark-mode variant), avatar, byline, sender line, accent colors, header and footer links, custom CSS, nofollow for external links, Rybbit analytics.
- **`[mailerhive_issues limit="6"]`** shortcode to list recent issues anywhere.

### Optional modules (off by default)

- **Consent policy** for Core Forms → MailerPress signups. Only registered forms with an exact consent disclosure can add contacts; opt-outs and suppression tags are respected; consent evidence (form, time, version, disclosure) is saved on the contact. Unregistered forms fail closed.
- **FluentCart lifecycle tags**: purchase, refund and abandoned-checkout tags, plus product-category interest tags.
- **Rich paste** in the MailerPress editor for Google Docs and block-editor content.

## Requirements

- WordPress 6.6+, PHP 8.1+
- MailerPress (and MailerPress Pro for some features) for email drafts and mirroring. The archive and reading pages work without it.
- Core Forms for the signup form and the consent module (optional).

## Installation

1. Download the latest release zip, or clone this repo into `wp-content/plugins/mailerhive`.
2. Activate **MailerHive**.
3. Open **Newsletter → Settings**: set the newsletter name, sender, signup form and lists.
4. Write an issue under **Newsletter → Write New Issue**.

## WP-CLI

```
wp mailerhive import-substack <slug>... [--publication=<subdomain>] [--title=<title>] [--slug=<slug>] [--dry-run]
wp mailerhive import-campaign <campaign_id> [--title=<title>] [--slug=<slug>]
wp mailerhive relink
wp mailerhive sync
wp mailerhive seed
```

## Building a release

```
bin/build-zip.sh
```

Creates `dist/mailerhive-{version}.zip` from the committed tree (development files are excluded via `.gitattributes`).

## License

GPL-2.0-or-later. Icons are from [Tabler Icons](https://tabler.io/icons) (MIT), see `assets/icons/LICENSE`.
