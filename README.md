# MailerHive

Newsletter publishing for gauravtiwari.org on top of MailerPress Pro.

- **Issues** (`mh_issue`) at `/newsletter/issue/{slug}/`, archive at `/newsletter/issue/`, feed at `/newsletter/issue/feed/`.
- **Reading shell**: issue pages, the archive and MailerPress subscription pages render through MailerHive's own templates with a dedicated header and footer. Theme styles, scripts and hooks are detached on those requests; core and every plugin keep working (Functionalities fonts, Rank Math, Core Forms, Perfmatters). The Rybbit tag is printed by MailerHive.
- **External links** in issues get `rel="nofollow noopener"`.
- **MailerPress bridge**: `/mp-email/{id}-{slug}/` "view in browser" links 301 to the issue; issues become MailerPress draft campaigns in one click; newsletters composed in MailerPress and sent to a mirrored list publish as issues. MailerPress owns sending, unsubscribe/manage links and List-Unsubscribe. MailerHive never sends or schedules.
- **Shortcode** `[mailerhive_issues limit="6"]` lists recent issues.
- **Moved modules**: Core Forms MailerPress consent policy, FluentCart lifecycle classification, MailerPress rich paste (formerly site MU plugins).

## Writing a new issue

1. Newsletter → Write New Issue. Add a subtitle in *Issue details*.
2. Publish. The page goes live at `/newsletter/issue/{slug}/`.
3. With **one-click drafts** on (Newsletter → Settings → MailerPress → *Turn on automatic drafts*), publishing creates a MailerPress draft campaign addressed to the chosen lists. Or click *Create MailerPress draft* in the issue's sidebar. Drafts are never scheduled or sent; review and send in MailerPress → Campaigns. Every draft carries `%UNSUB_LINK%` and `%MANAGE_SUB_LINK%`.

## WP-CLI

```
wp mailerhive import-substack <slug>... [--title=<title>] [--dry-run]
wp mailerhive import-campaign <campaign_id> [--title=<title>] [--slug=<slug>]
wp mailerhive relink
wp mailerhive sync
wp mailerhive seed
```
