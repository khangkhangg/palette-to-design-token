# Design system — brewline.co

Use only these tokens for UI.
Do not invent new colours, fonts or spacing.

## Colour roles

| Role | Token | Hex | Use for |
| --- | --- | --- | --- |
| primary | `--color-primary` | #1F5E4B | links, primary buttons, nav |
| primary | `--color-primary-2` | #2E7A63 | links, primary buttons, nav |
| accent | `--color-accent` | #E0763A | highlights, badges, secondary CTAs |
| surface | `--color-surface` | #FFFDF8 | page and card backgrounds |
| surface | `--color-surface-2` | #F3EEE4 | page and card backgrounds |
| text | `--color-text` | #1B1A17 | body copy and headings |
| text | `--color-text-2` | #5C574E | body copy and headings |
| border | `--color-border` | #E4DDD0 | dividers and outlines |

## Typography

- Body font: "DM Sans", sans-serif
- Heading font: Fraunces, serif
- display: Fraunces, serif 600, 48px/56px
- h1: "DM Sans", sans-serif 600, 24px/32px
- body: "DM Sans", sans-serif 400, 16px/26px
- small: "DM Sans", sans-serif 500, 13px/18px

## Spacing

- `--space-1`: 4px
- `--space-2`: 8px
- `--space-3`: 12px
- `--space-4`: 16px
- `--space-5`: 24px
- `--space-6`: 32px
- `--space-7`: 48px

## Radius

- `--radius-sm`: 4px
- `--radius-md`: 10px
- `--radius-full`: 9999px

## Shadows

- `--shadow-sm`: 0 1px 2px rgba(0, 0, 0, 0.5)
- `--shadow-md`: 0 6px 16px rgba(0, 0, 0, 0.6)

## Contrast rules

- Do not put #FFFFFF text on #E0763A (2.9:1). Use #FFFFFF on #B4501A.
