# HXMC — Smart Media Cleaner: AI Reference

Machine-oriented reference for AI agents working with this plugin. Human docs: readme.txt. Development log: CLAUDE.md.

## Identity
- Version: 0.3.11
- Slug / Text Domain: `hxmc-smart-media-cleaner`
- Series: HX Series #6 (WAHX stack: WordPress + Alpine.js + htmx + HX plugins)
- Philosophy: code-first, subtraction design, honest scoping, no external services, GPL.

## Capabilities
| Feature | Entry point | Effect | Reversible? |
|---|---|---|---|
| Usage scan | `HXMC_Scanner::scan( $id )` | Caches reference info in `_hxmc_usage` | read-only |
| Rename | `HXMC_Renamer::rename( $id, $slug )` | Renames file + all sizes, rewrites URLs, 302 map | yes (rename again) |
| WebP convert | `HXMC_Converter::convert( $id, $quality )` | Creates .webp twins, rewrites URLs, keeps originals | yes (originals kept) |
| Compress | `HXMC_Compressor::compress( $id, $quality )` | In-place re-encode, overwrite only when smaller | NO (lossy for JPEG) |
| Replace | `HXMC_Replacer::replace( $id, $tmp_path )` | Same-filename overwrite, sizes regen, ?v= cache bust | NO |

All mutating operations are admin-only (`manage_options` + nonce) in the UI layer; the classes above assume caller-side authorization.

## Actions (integration surface)
- `hxmc_after_rename( $attachment_id, $old_url, $new_url, $pairs )`
- `hxmc_after_convert( $attachment_id, $generated_files, $quality, $saved_bytes )`
- `hxmc_after_compress( $attachment_id, $files_done, $skipped, $quality, $saved_bytes )`
- `hxmc_after_replace( $attachment_id, $version, $new_meta )`

## Data
- Table `{prefix}hxmc_url_map`: old_path (decoded, path-only) → new_url; 302 served only on 404 of /uploads/ paths.
- Postmeta: `_hxmc_usage`, `_hxmc_webp`, `_hxmc_compressed`, `_hxmc_replaced`.
- Option: `hxmc_webp_quality` (1–100, default 82; shared by WebP and JPEG compression).

## Invariants an agent must not break
1. Filenames written by Rename are `[a-z0-9-_]` ascii; GUIDs are never modified.
2. Replace keeps the exact filename; images over `big_image_size_threshold` are refused (WP would create -scaled and change `_wp_attached_file`).
3. Serialized meta values are never string-replaced; the 302 map is the fallback for those references.
4. Redirects are 302, never 301.
5. `uploads/.htaccess` block is bounded by `# BEGIN HXMC WebP Fallback` / `# END` markers; edit only via `HXMC_Htaccess`.

## Honest limits
- Usage scan proves presence of references, never absence (theme/CSS hardcoded URLs, external sites invisible).
- PNG compression is lossless only (no quantizer in GD/Imagick).
- Nginx cannot be configured by the plugin; fallback snippet is display-only.
