=== Trojmiasto News Aggregator ===
Contributors: trojmiasto-online
Tags: news, rss, aggregator, llm, translation
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT

Собирает новости из польских RSS-источников о Труймясте, кластеризует
инфоповоды, оценивает горячесть и с помощью LLM готовит короткие рерайты
на русский и украинский в виде черновиков для CPT tm_news_digest.

== Description ==

См. README.md в репозитории: https://github.com/valentinych/tm-news

== Changelog ==

= 0.1.0 =
* Первый рабочий релиз: fetcher + clusterer + scorer + OpenAI-translator +
  publisher (draft) + admin UI + WP-CLI.
