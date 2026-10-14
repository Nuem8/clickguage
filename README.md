<p align="center">
  <img src="ClickGauge_badge.png" alt="Clickgauge" width="160">
</p>

# Clickgauge

A measuring instrument for the search snippet. [NueM8](https://nuem8.com) built Clickgauge to test a new title and meta description against Google Search Console, and to restore the previous wording when the reading falls.

## Description

Clickgauge watches pages that already receive impressions and sit near the bottom of the first page of results, or that receive impressions with a weak click-through rate. From the page and its real queries, an assistant drafts a title of 60 characters or fewer and a description of 155 characters or fewer.

The draft stays on the bench until you approve it. Auto-apply is closed unless you open that valve yourself. After the waiting period, a daily reading checks Search Console again. If position and click-through rate have worsened, the previous title and description are restored.

The new wording is written into the SEO plugin already on the site: Yoast SEO, Rank Math, SEOPress, or All in One SEO. When none of those is active, Clickgauge supplies its own title and description. The body of the page is left as written.

Clickgauge is published by [NueM8](https://nuem8.com).

## Installation

1. Copy the `clickguage` folder into `wp-content/plugins/`.
2. Activate Clickgauge.
3. In Google Cloud, create an OAuth client with the Search Console read-only scope and add the redirect URI shown on the settings screen.
4. Add an OpenAI-compatible API key, connect Search Console, and run Find opportunities.

WordPress 6.0 or newer. PHP 7.4 or newer.

## License

Copyright [NueM8](https://nuem8.com).

Clickgauge is free software licensed under the [GNU General Public License, version 2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html). You may use, study, share, and change it under the terms of that license.
