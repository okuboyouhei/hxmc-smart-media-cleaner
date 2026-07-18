# HXMC — Smart Media Cleaner

Code-first media library cleanup for WordPress. The 6th plugin in the **HX Series** ([WAHX stack](https://zenn.dev/youheiokubo): WordPress + Alpine.js + htmx + HX plugins).

メディアライブラリの整理整頓を、引き算の設計で。使用状況スキャン(読み取り専用)・日本語ファイル名の安全なリネーム・WebP変換(非対応ブラウザへの自動フォールバック付き)・その場圧縮・同名差し替え(3層キャッシュバスト)の5機能。外部サービス不使用、訪問者側JSゼロ。

## Features

1. **Usage scan (read-only)** — detects references in featured images, post content (all intermediate sizes), custom fields, and options. Honestly labeled "No reference found", never "unused": theme/CSS hardcoded URLs and external sites are invisible to any scanner. Nothing is ever deleted.
2. **Safe rename** — non-ASCII filenames → ascii slugs. Renames every size on disk, rewrites URLs in content and meta, and registers a 302 fallback that fires only on 404. GUIDs untouched.
3. **WebP conversion** — local GD/Imagick only, one quality knob (= compression). Originals kept. Accept-header fallback rules in `uploads/.htaccess` serve originals to the ~2% of browsers without WebP support; Nginx users get a copy-paste snippet.
4. **In-place compression** — JPEG quality re-encode (+ EXIF strip via Imagick), PNG lossless recompress. Overwrites the same filename **only when the result is smaller**. Lossy and irreversible, and the confirm dialog says so.
5. **Media replace** — overwrite an attachment with a new same-type file, keeping the filename. Sizes regenerate, WebP/compression state resets, and references get `?v=` cache busting across content, admin thumbnails, and core-generated URLs.

## Integration

Four actions for logging/automation (e.g. [HXMD](https://github.com/okuboyouhei/hxmd-markdown-log-manager)):

```php
do_action( 'hxmc_after_rename',   $attachment_id, $old_url, $new_url, $pairs );
do_action( 'hxmc_after_convert',  $attachment_id, $generated_files, $quality, $saved_bytes );
do_action( 'hxmc_after_compress', $attachment_id, $files_done, $skipped, $quality, $saved_bytes );
do_action( 'hxmc_after_replace',  $attachment_id, $version, $new_meta );
```

## AI-ready

Ships with `CLAUDE.md`, `ai-reference.md`, and `llms.txt` so AI agents can operate the plugin safely — including the invariants they must not break.

## Requirements

WordPress 6.2+, PHP 7.4+. WebP conversion needs GD with WebP support or Imagick.

## License

GPL-2.0-or-later.
