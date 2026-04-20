# tm-news — Trojmiasto News Aggregator

WordPress-плагин для сайта [trojmiasto.online](https://trojmiasto.online).
Собирает свежие польскоязычные новости по теме Труймяста (Гданьск, Гдыня,
Сопот), кластеризует их по инфоповодам, оценивает «горячесть» и с помощью
LLM готовит короткие рерайты на русский и украинский — в виде черновиков
CPT `tm_news_digest`, которые редактор утверждает вручную.

## Архитектура пайплайна

```
 [RSS источники] → Fetcher → items ──┐
                                      ├─ Clusterer (Jaccard по заголовкам)
                                      ↓
                                   clusters
                                      ↓
                                   Scorer (Σ weight × recency × topic_match)
                                      ↓
                                   Publisher → Translator (OpenAI)
                                      ↓
                                   черновики в tm_news_digest
```

Таблицы: `wp_tm_news_items`, `wp_tm_news_clusters`. CPT: `tm_news_digest`.
Планировщик: `wp_schedule_event( 'hourly', 'tm_news_cron_tick' )`.

## Формирование «горячей» ленты

1. **Fetch** — RSS всех включённых источников, последние 48 часов, до 25
   айтемов с фида.
2. **Normalize** — канонический URL (+ хеш), нормализованный заголовок
   (lowercase, без польских стоп-слов).
3. **Cluster** — для каждого нового айтема Jaccard на токенах заголовка
   против открытых кластеров за 48 ч. Порог слияния ≥ 0.35.
4. **Score** — для каждого кластера:
   `Σ source_weight(src_i) × exp(-age_i/τ) × topic_match`.
   τ и список ключевых слов — в админке.
5. **Publish** — топ-K кластеров со score ≥ `min_score` → Translator →
   черновик в CPT `tm_news_digest`.

## Юридическая рамка

Плагин намеренно не перепечатывает статьи источников. В пост попадают
только:

- **рерайт своими словами** в 2–3 предложениях (на русском и украинском);
- **жирная ссылка на оригинал** и название издания.

Это соответствует практике агрегатора (Google News и т.п.) и польской
реализации ст. 15 EU DSM Directive (2024).

## Установка

```bash
git clone git@github.com:valentinych/tm-news.git wp-content/plugins/tm-news
```

Затем в WP:

```bash
wp plugin activate tm-news
```

## Настройка

`Инструменты → News aggregator`:

- OpenAI API key и модель (по умолчанию `gpt-4o-mini`).
- Список источников (ключ, URL, вес 0..1, enabled).
- Ключевые слова темы (по одному на строку).
- τ recency, top K, min score.
- Рубрика (WordPress category) для черновиков.
- Кнопка «Запустить пайплайн сейчас» + dry-run.

## WP-CLI

```bash
wp tm-news fetch           # только тянуть RSS
wp tm-news cluster         # только кластеризовать необработанные
wp tm-news score           # только пересчитать score
wp tm-news publish --dry-run
wp tm-news run             # весь пайплайн целиком
wp tm-news status
```

## Системный cron

Плагин регистрирует WP-cron на hourly. На проде, где WP-cron отключён,
добавь системный cron, который триггерит WP-cron (см. основной репо
`trojmiasto`, `scripts/wp-cron.sh`).

## Разработка

PHP 8.1+, WP 6.4+. Без composer-зависимостей, только WordPress core
(SimplePie для RSS идёт с ядром).

## Лицензия

MIT.
