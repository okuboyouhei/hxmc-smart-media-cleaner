# HXMC — Smart Media Cleaner (AI reference)

6th plugin in the HX Series (WAHX stack). v0.3.0.

## What it does
1. **Usage scan** — per-attachment reference detection cached in `_hxmc_usage` meta. Surfaces: `_thumbnail_id`, post_content (path-without-extension LIKE, so all sizes + webp twins match; plus `wp-image-{ID}`), other postmeta, options. Read-only; v0.1 never deletes.
2. **Rename** — non-ASCII filename → ascii slug (`[a-z0-9-_]`, default suggestion `img-{ID}`). Renames main + all intermediate sizes on disk, updates `_wp_attached_file` / attachment metadata / post_name, rewrites URLs (posts + non-serialized postmeta), registers 302 fallback. GUID untouched.
3. **WebP** — GD or Imagick, local only. Quality = compression (option `hxmc_webp_quality`, default 82). Originals kept. Generates twins for all sizes, rewrites URLs, registers 302 fallback. Refuses: webp/avif/svg, animated GIF.
4. **Non-WebP browser fallback (v0.1.1)** — `class-hxmc-htaccess.php` writes Accept-header rules to `uploads/.htaccess` via `insert_with_markers()` (marker `HXMC WebP Fallback`), IfModule-guarded, on activation when Apache/LiteSpeed. Legacy Accept → internal rewrite to sibling .jpg/.jpeg/.png/.gif with forced Content-Type; `Vary: Accept` appended on .webp URIs (SetEnvIf) so caches split correctly. Verified E2E on real Apache 2.4 (curl Accept matrix). Nginx: read-only `nginx_snippet()` shown in admin (map + error_page 418 + try_files pattern). Uninstall removes the block.

## Architecture
- `hxmc-smart-media-cleaner.php` — bootstrap + 404-only `template_redirect` fallback (302, `wp_redirect` intentional for cross-host media)
- `includes/class-hxmc-db.php` — `wp_hxmc_url_map` (old_path stored **decoded, path-only** → survives host differences; unique md5 hash; upsert)
- `includes/class-hxmc-scanner.php` / `class-hxmc-renamer.php` / `class-hxmc-converter.php` — logic, no UI
- `includes/class-hxmc-admin.php` — Media submenu, Alpine UI
- `includes/class-hxmc-ajax.php` — admin-only endpoints (manage_options + nonce): list / scan / scan_ids / rename / convert / quality

## Compression (v0.2.0)
`class-hxmc-compressor.php` — in-place re-encode, same filename (= the overwrite mode; URLs unchanged, no DB rewrite, no redirect entry). JPEG: quality knob (shared `hxmc_webp_quality`); Imagick path strips EXIF. PNG: lossless zlib-9 only (no quantizer in GD/Imagick — honest scoping). Overwrite ONLY when smaller (`reencode_smaller()` via `.hxmc-tmp` + rename). No backups by design; confirm dialog states lossy/irreversible. Meta `_hxmc_compressed`. Refuses GIF/SVG/WebP.

## Replace (v0.3.0)
`class-hxmc-replacer.php` — same-filename overwrite (multipart AJAX). Flow: mime match check → webp reset (delete twins, rewrite content .webp→orig, 302 stragglers) → delete old size files → copy over main → `wp_generate_attachment_metadata` → compression meta reset → cache-bust `?v={ts}` on main + per-size (old size name → matching new size key, else main; 302 for renamed sizes). Sentinel pattern in `bust()` because MySQL REPLACE has no lookahead (protect `url?v=` refs before versioning bare `url`). Meta `_hxmc_replaced` {version, count}. Note: stored content keeps only src in modern WP (render-time srcset from metadata self-heals after regeneration); explicit srcset in stored content is healed by the per-size rewrite. CLI test harness caveat: user 0 + kses strips srcset on insert — set current user in tests.

## Bridge hooks (HXMD listens here)
- `do_action( 'hxmc_after_rename', $attachment_id, $old_url, $new_url, $pairs )`
- `do_action( 'hxmc_after_convert', $attachment_id, $generated_files, $quality, $saved_bytes )`
- `do_action( 'hxmc_after_compress', $attachment_id, $files_done, $skipped, $quality, $saved_bytes )`
- `do_action( 'hxmc_after_replace', $attachment_id, $version, $new_meta )`
Fired from logic classes after success (not from DB layer).

## Lessons applied (inherited from HXFE/HXSE/HXRV/HXMD/HXSR)
1. Alpine depends on plugin JS (`hxmc-admin` → dependency of `hxmc-alpine`), both defer
2. dbDelta: `PRIMARY KEY  (id)` two spaces, no DATETIME default, `hxmc_db_version` upgrade
3. `%i` placeholders for table names
4. `esc_attr( wp_json_encode() )` for attribute JSON (n/a yet — kept for reference)
5. GD: `imagepalettetotruecolor()` before processing palette PNGs/GIFs
6. `$wpdb->suppress_errors()` around expected-duplicate inserts (AJAX JSON survival)
7. No inline styles — all `.hxmc-*` classes
8. Query params plugin-prefixed (`hxmc_s`), never `s`/`post_type`
9. `wp_redirect` intentional + phpcs:ignore with reason
10. Author URI = profiles.wordpress.org (no zenn.dev)
11. Errors rendered near action + `scrollIntoView`

## New lessons from HXMC development
12. **Direct SQL REPLACE on posts/postmeta must invalidate caches**: collect affected IDs first, then `clean_post_cache()` + `wp_cache_delete(id, 'post_meta')` after UPDATE. Same-request reads and persistent object caches (Redis) both break otherwise.
13. **Serialized meta is skipped on rewrite** (`meta_value NOT LIKE 'a:%'`) — string replace corrupts length prefixes; the 302 fallback covers those references.
14. Redirect map stores **decoded path only** — browsers send Japanese filenames percent-encoded; `normalize_path()` rawurldecodes both at write and lookup time.

## New lessons (v0.1.1)
15. `insert_with_markers()` needs `wp-admin/includes/misc.php` outside admin context.
16. `Vary: Accept` must be scoped to .webp URIs (`SetEnvIf` + `Header append ... env=`) — a blanket Vary on all uploads would fragment CDN caches for no reason.

## Roadmap
- v0.2: bulk rename/convert, HXMD bridge on the HXMD side, quarantine folder for unused images (explicit safeguards), `hxmc_after_delete` hook
- Not planned: external compression APIs, 301s, front-end asset injection
