# VAD — Brand Identity

**Client:** Vlaams expertisecentrum Alcohol en andere Drugs (vad.be)
**Aesthetic:** Belgian NGO institutional. Structural, geometric, flat. No decoration earns its space.

## Palette

| Token | Hex | Use |
|---|---|---|
| Primary | `#2F4A6D` | Deep navy. Headings, body text, primary buttons, footer. |
| Primary hover | `#233854` | Darker navy on button hover. |
| Accent | `#C96858` | Terracotta / dusty coral. Active states, CTAs, link highlights. Used sparingly. |
| Primary subtle | `#EAF2F2` | Ice blue. Hero panels, soft section bg. |
| Surface alt | `#F8F7F2` | Warm off-white. Lifted panels. |
| Success | `#558264` | Muted forest. Sits next to navy without clashing. |
| Warning | `#C9913A` | Warm ochre. |
| Error | `#A83826` | Brick red. |

## Typography

- **Headings:** Montserrat 600 (semi-bold). No bold (700) on headings. No letter-spacing.
- **Body / UI:** Nunito Sans 400 regular, 700 on buttons.
- **Heading scale:** h1 ~42px, h2 ~32px, h3 ~24px. All same navy color, no separate heading color.

## Visual rules

- **No radius anywhere.** All `border-radius` is 0. VAD's geometry is the brand.
- **Diagonal sliced hero** — `linear-gradient(120deg, #EAF2F2 70%, #F8F7F2 70%)`. The one decorative motif.
- **Buttons** — square, navy bg + white text. Bold weight (700). No icons unless functional.
- **Cards** — flat white, square, single thin border. No gradients, no rounded thumbnails.
- **Active nav** — text turns terracotta, underline bar in terracotta. Otherwise links stay navy.
- **Photography** — documentary, people-focused. No illustrations.

## What NOT to do

- No rounded corners. Ever.
- No drop shadows on resting elements (only on hover/elevated states).
- No pills, no gradients on cards, no blob backgrounds.
- Don't use terracotta on body text. It's an accent — keep it scarce.
- Don't introduce serif fonts. VAD has none.

## LearnDash specifics

Per `gotcha_learndash_css_skin`: 5 layers covered in `client.css`:
1. `:root` token overrides for `--ld-color-*` (hex format)
2. Hardcoded `#235af3` selector overrides (LD ships ~50 rules at exact specificity)
3. `learndash-front` + `learndash-ld30-modern` enqueued as deps (so our tokens win)
4. Focus-sidebar styling at `:root` scope (lives outside `.learndash-wrapper`)
5. Focus-mode background layers — 5 hardcoded `background:#fff` instances overridden to `surface-alt`

## Logo

`assets/logo.svg` — sourced from vad.be/content/uploads/2024/03/logo_small.svg. Navy square with white "VAD" wordmark. No tagline in the mark.

## Source

Live extraction from vad.be on 2026-05-19 via browser-user agent. Diagonal hero gradient (CSS class `--sliced__right` on vad.be) is the single layout signature worth replicating.
