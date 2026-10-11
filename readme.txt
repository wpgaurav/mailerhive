=== MailerHive ===
Contributors: wpgaurav
Tags: newsletter, mailerpress, email archive, substack import, beehiiv
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Beehiiv- and Ghost-style newsletter publishing for MailerPress: a public archive of issues, a clean reading page, and one-click email drafts.

== Description ==

MailerHive turns WordPress plus MailerPress into a publication.

* **Issue pages** at `/newsletter/issue/{slug}/` with an archive and RSS feed. The URL base is configurable.
* **A clean reading page.** Each issue renders as the email it was, with its own header and footer, light/dark mode that follows the reader's system, reading time, previous/next navigation and a signup box. Your theme's styles and scripts are kept off these pages; other plugins keep working.
* **One-click MailerPress drafts.** Publish an issue and MailerHive creates a draft campaign in MailerPress, addressed to the lists you choose, with Unsubscribe and Manage subscription links. Nothing is ever scheduled or sent by MailerHive; you review and send in MailerPress.
* **Mirroring.** Newsletters you write directly in MailerPress publish as issue pages once sent. "View in browser" links go to the issue page.
* **Import from Substack**, including images, which are copied into your media library.
* **Nofollow for external links** inside issues (optional), Rybbit analytics support, custom CSS, logo, avatar, byline and accent colors.

Optional modules (off by default):

* **Consent policy** for Core Forms → MailerPress signups: only reviewed forms with an exact consent disclosure can add contacts; opt-outs and suppression tags are always respected; consent evidence is stored on the contact.
* **FluentCart lifecycle tags**: purchase, refund and abandoned-checkout tags, plus category-based interest tags.
* **Rich paste** in the MailerPress editor for Google Docs and block-editor content.
* **Paid subscriptions** sold through FluentCart: tiers ordered lowest to highest, each with its own MailerPress list. Recurring plans follow the FluentCart subscription (renewals, failed payments with a grace period, cancellations that run to the end of the paid period, pauses, refunds); one-time plans give a fixed term that stacks on renewal, or lifetime access. Paid issues show a preview and the plans; members read in full after signing in or through an emailed one-time reading link. Drafts for paid issues go to the matching tier lists. Turning the module on starts you with a Free tier (your existing lists and signup form) and a Paid tier; "Create FluentCart product" on any tier builds a published product with monthly, yearly and optional lifetime prices and maps them in one click. Includes a Paid members screen with manual grants, `[mailerhive_plans]` and `[mailerhive_account]`.

MailerHive works without MailerPress for the archive and reading pages; email features need MailerPress.

== Installation ==

1. Upload the `mailerhive` folder to `/wp-content/plugins/` and activate it.
2. Go to **Newsletter → Settings** and set your newsletter name, sender, signup form and lists.
3. Write your first issue under **Newsletter → Write New Issue**.

== Frequently Asked Questions ==

= Does MailerHive send email? =

No. It creates draft campaigns in MailerPress. Sending, unsubscribe handling and List-Unsubscribe headers stay with MailerPress.

= Will my theme's design show on issue pages? =

No, by design. Issue pages use MailerHive's own templates so they read like the email. You can turn theme isolation off under Settings → Design.

= Can I import from Substack? =

Yes, from Settings → Tools or with `wp mailerhive import-substack <slug>...`.

== WP-CLI ==

    wp mailerhive import-substack <slug>... [--publication=<subdomain>] [--title=<title>] [--slug=<slug>] [--dry-run]
    wp mailerhive import-campaign <campaign_id> [--title=<title>] [--slug=<slug>]
    wp mailerhive relink
    wp mailerhive sync
    wp mailerhive seed
    wp mailerhive paid members|reconcile|sweep|grant|revoke|access [<args>]

== Changelog ==

= 1.4.1 =
* Plan prices follow GT Extensions for FluentCart multi-currency: pages show the store currency, then each visitor's own price (price book or exchange rate), matching checkout. Fixes ₹5 shown for a $5 plan.
* Redesigned Paid tab: tier ladder, tier cards, a product panel with currency-aware price fields, and cleaner plan rows.

= 1.4.0 =
* Paid subscriptions module: sell tiered newsletter access through FluentCart, with default Free and Paid tiers, one-click FluentCart products, subscription, fixed-term and lifetime plans, a paywall with plans and reading links, tier lists and tags in MailerPress, a Paid members screen, and `wp mailerhive paid`.

= 1.3.0 =
* License tab: activate a free license for automatic updates from gauravtiwari.org. Keys are encrypted and bound to the site.
* Automatic updates through the normal WordPress plugin screen, with package host and plugin-header checks.

= 1.2.0 =
* Newsletter page in the MailerHive design: signup card, recent issues, editable content card, FAQ accordions.

= 1.1.0 =
* Icons throughout the reading page, centered sender with a larger avatar, reading time.
* Compact mobile header and tighter mobile gutters.
* Optional modules with their own settings; generic defaults for new sites; translation-ready strings.

= 1.0.0 =
* First release.

== Credits ==

Icons: Tabler Icons (MIT), bundled in `assets/icons`.
