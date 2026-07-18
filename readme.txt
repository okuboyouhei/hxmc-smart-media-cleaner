=== HXMC — Smart Media Cleaner ===
Contributors: youheiokubo
Tags: media, webp, cleanup, rename, compression
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Code-first media library cleanup: find unreferenced images, rename non-ASCII filenames safely, convert to WebP. No external services.

== Description ==

HXMC keeps your media library tidy with three focused tools — and an honest scope for each.

**1. Usage scan (read-only)**
Detects references to each image in featured images, post content (all intermediate sizes covered), custom fields, and widgets/options. Images with no detected reference are flagged as "No reference found" — deliberately *not* "unused", because the scanner cannot see theme/CSS hardcoded URLs, serialized page-builder data, or external sites. This version never deletes anything. The list is there to inform your own decision.

**2. Safe rename for non-ASCII filenames**
Japanese (or any non-ASCII) filenames cause percent-encoded URL mess and occasional server-side trouble. HXMC renames the original file and every intermediate size on disk, rewrites URLs in post content and custom fields, and registers a 302 fallback redirect for the old URL — so nothing breaks even where a reference could not be rewritten. Redirects fire only on 404, costing nothing on normal requests. GUIDs are left untouched, per WordPress convention.

**3. WebP conversion with built-in compression**
Generates `.webp` twins for the original and all sizes using GD or Imagick — entirely on your server, no external API. Compression is simply the WebP quality setting (default 82): one knob, not two features. Originals are kept on disk as insurance, references are rewritten to `.webp`, and old URLs get the same 302 fallback. Already-WebP files, SVGs, and animated GIFs are honestly refused rather than silently mangled.

For the ~2% of browsers without WebP support, HXMC writes Accept-header fallback rules to `uploads/.htaccess` (Apache/LiteSpeed): requests for `.webp` from non-supporting browsers are transparently served the kept original, with `Vary: Accept` so caches stay correct. On Nginx, where plugins cannot write server config, the admin page shows an equivalent copy-paste snippet instead.

**4. In-place compression (overwrite mode)**
Re-encodes JPEG (quality knob, shared with WebP — one quality concept) and losslessly recompresses PNG, overwriting the same filename so URLs never change and no database rewrite is needed. Files are overwritten *only when the result is smaller*; otherwise the original bytes are kept. This is lossy for JPEG and irreversible — the confirm dialog says so plainly, and no backups are made (doubling disk usage would contradict the point of a cleanup plugin). JPEG metadata (EXIF) is stripped as part of the diet when Imagick is available.

**5. Media replace (same filename, fresh cache)**
Upload a new file over an existing attachment: the filename — and therefore every URL — stays identical, thumbnails are regenerated (old size files are cleaned up, stale size references in content are healed), and references get a `?v={timestamp}` cache-busting parameter so browsers and CDNs fetch the new bytes immediately. WebP twins and compression state are reset, with content references rewritten back and 302 entries covering stragglers. Same MIME type only — keeping a `.jpg` name on PNG bytes would be a lie.

**Design principles (HX Series)**
* Admin-only. Zero JavaScript, zero markup, zero cookies added to your visitors' pages. The only front-end footprint is a 404-time redirect lookup.
* No external services. Image processing is local GD/Imagick.
* 302 only — never 301 — so redirects are never cached into permanence.
* Fires `hxmc_after_rename` and `hxmc_after_convert` actions for integration (e.g. HXMD — Markdown Log Manager).

== Frequently Asked Questions ==

= Why doesn't it delete unused images? =
Because "no reference found" is not proof of non-use. Deletion may come later behind explicit safeguards; v0.1 is read-only by design.

= Does it modify my original images? =
No. Rename moves files (content intact); WebP conversion creates new files alongside the originals.

= Is compression reversible? =
No. JPEG recompression is lossy and originals are overwritten (only when smaller). If you need the pristine originals, keep your own backups before compressing. WebP conversion, by contrast, always keeps originals.

= What happens in browsers without WebP support? =
On Apache/LiteSpeed, nothing — the fallback rules serve the kept original automatically. On Nginx, add the snippet shown on the admin page to your server config. Without it, non-supporting browsers (IE11, Safari 13 and older) would fail to load converted images.

= What about serialized data (page builders)? =
String replacement inside serialized values corrupts them, so HXMC skips serialized rows on purpose. The 302 fallback redirect covers those references instead.

== Changelog ==

= 0.3.9 =
* Fix: WebP twins are now served for metadata-driven output too (featured images, theme templates, render-time srcset). Previously only URLs stored in content were rewritten, so themes calling wp_get_attachment_image() kept serving originals.

= 0.3.8 =
* Packaging: distribution ZIP now excludes repository-only files (CLAUDE.md); ai-reference.md and llms.txt carry synced version headers.

= 0.3.7 =
* Bundled ai-reference.md and llms.txt (AI-facing documentation, series convention).

= 0.3.6 =
* Plugin Check: annotated the read-only use of core's big_image_size_threshold filter.

= 0.3.5 =
* Admin screen restyled with the HXMC deep-purple identity (#4608AD) via --hxmc-* design tokens.

= 0.3.4 =
* Replace now refuses images over the big-image threshold (default 2560px) instead of letting WordPress silently rename them to -scaled files, which would break the kept URLs.

= 0.3.3 =
* Plugin Check: tmp_name handling restructured (sanitize + unslash + is_uploaded_file as the authoritative validation).

= 0.3.2 =
* After a replace, WordPress-generated URLs (media edit screen, library grid/modal, render-time srcset) also carry ?v=, so every admin view shows the new image without a hard refresh.

= 0.3.1 =
* Fix: admin list thumbnails now carry the replace version (?v=), so a second replace with identical dimensions is visibly fresh instead of showing the browser-cached old thumbnail. Version counter can no longer collide within the same second.

= 0.3.0 =
* Media replace: overwrite an attachment with a new file of the same type, keeping the filename. Regenerates sizes, resets WebP/compression state, heals stale size URLs, appends ?v= cache busting. New `hxmc_after_replace` hook.

= 0.2.0 =
* In-place compression: JPEG quality re-encode + PNG lossless recompress, overwrite only when smaller, EXIF stripped via Imagick. New `hxmc_after_compress` hook.

= 0.1.2 =
* Each row now links to the attachment edit screen, so acting on "No reference found" images (including deletion, by you, with WordPress core) is one click away.

= 0.1.1 =
* WebP fallback for non-supporting browsers: Accept-header rules in uploads/.htaccess (Apache/LiteSpeed) + Vary: Accept; Nginx snippet on the admin page.

= 0.1.0 =
* Initial release: usage scan, non-ASCII rename with URL rewrite + 302 fallback, WebP conversion (GD/Imagick), HXMD-ready hooks.
