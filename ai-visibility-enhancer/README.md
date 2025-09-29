# AI Visibility Enhancer

A WordPress plugin that improves how AI assistants and crawlers consume your content. It introduces AI-ready summaries, schema.org JSON-LD, and a dedicated REST endpoint designed for conversational agents like ChatGPT, Gemini, Claude, and others.

## Key Features
- **AI meta toolbox** – Editors can provide handcrafted AI summaries, keyword hints, and target audience descriptors for each post.
- **Structured data output** – Automatically injects JSON-LD markup and AI-centric `<meta>` tags with caching for fast responses.
- **REST exposure** – Ships with `/wp-json/ai-visibility/v1/content/<id>` endpoint returning clean, deduplicated payloads.
- **Admin controls** – Settings screen to tweak summary length, toggle keyword exposure, and tune cache lifetime.
- **Extensibility hooks** – Filters for schema type and payload modifications.

## Installation
1. Copy the `ai-visibility-enhancer` directory into your WordPress installation under `wp-content/plugins/`.
2. Activate **AI Visibility Enhancer** from the WordPress admin dashboard.
3. Visit **Settings → AI Visibility Enhancer** to configure global defaults.

## Usage
- Edit any public post type and fill out the **AI Visibility** meta box with tailored summaries, keywords, and audience notes.
- AI crawlers can query `https://example.com/wp-json/ai-visibility/v1/content/{POST_ID}` to obtain structured information.
- The plugin caches schema output and REST responses. Adjust cache lifetime in settings or disable caching entirely.

## Filters
- `aive_schema_type` – Change the default schema.org type. Receives the default type and the post object.
- `aive_schema_payload` – Modify the final JSON-LD array before it is rendered.

## Requirements
- WordPress 5.8 or newer.
- PHP 7.4 or newer.

## Development
The code follows the WordPress Coding Standards (tabs for indentation, escaped output functions, and nonces for saving meta). Use `wp i18n make-pot` to regenerate translation templates if you localize strings.
