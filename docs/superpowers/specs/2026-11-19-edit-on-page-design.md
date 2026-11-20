# Edit on page — design

Date: 2026-11-19. Status: built in this change (phase 1); phase 2 is proposed, not started.
Mockup: https://claude.ai/artifact/5Pgms6p74JiUEkNCHWcYuZ (clickable; the build follows it).

## Goal

Let a user adjust a scanned site live on the page, VisBug-style, before exporting, so the export
is the design system they want rather than exactly what the site shipped.

## Owner decisions

- Edits work at two levels: the **token** (everything using it repaints) and the **single element**.
- The user can also **rewrite text**, **align** an element horizontally and vertically, and
  **drag and drop** it to a new place.
- Build it as mocked up.

## Calls made while building (not owner decisions; change any of them)

| Topic | Call | Why |
| --- | --- | --- |
| One-off element edits | Stay out of the export until saved as a token ("Save as new token" or "Update <token>") | The export is a token set; a single button's colour is not a token until the user says so |
| Text, alignment, position | Listed in DESIGN.md under "Layout adjustments"; other formats ignore them | They are not tokens, and DESIGN.md is the file written for AI coding tools |
| Plan | Editing is free; export keeps today's Free/Pro rules | Edits only pay off at export, which is already the Pro step |
| Persistence | Edits live while the side panel is open. A reload or navigation keeps token changes (replayed on the next "Edit on page") and clears one-off and page edits. Re-scan clears everything | The scan must read the page as the site made it; element edits cannot be re-found on a new DOM |
| Closing the panel | Puts the page back exactly and removes the editor | No surprise leftovers on someone's tab |
| Editable tokens | Colours, type sizes, spacing, radius. Not shadows, font families or the "full" radius | Those need different controls; see phase 2 |
| Where | Side panel only (the popup closes when the page is clicked) | |

## Approaches considered

1. **Token-first with element overrides (built).** The page editor works out which token each
   element's style uses; a token edit sets one CSS variable and points the users' inline styles
   at it. Element edits are literal inline styles layered on top. Exports stay exact.
2. **Element-first, then re-scan.** Free-form edits, then scan the edited page. Familiar, but one
   edited button barely moves an area-weighted summary, so exports surprise people.
3. **Embed VisBug itself.** Its tools do not know about tokens, it would be the largest script we
   inject, and it cannot report edits back for export.

## How it works

```
side panel (Preact)                      page (edit.js, injected on demand, activeTab)
  App.tsx ── openEditor() ── port "sdt-edit" ──► entry.ts ─► createEditor(document)
  EditView.tsx   commands ─────────────────────►   index: element style → token (same matching as the scan)
                 ◄───────────── state (selected element, uses, edits)
  applyEdits(tokens, edits) ─► SystemView / ExportScreen (+ layoutNotes → DESIGN.md)
```

- The page editor owns all edit state and reports a snapshot after every change; the panel renders
  it and derives the edited tokens with a pure function. One source of truth.
- Style priority on an element: alignment, then one-off, then its token's variable, then the
  page's own style. Every write remembers what was there; the style attribute text is restored
  exactly.
- No new permissions: `edit.js` is injected with the existing `scripting` + `activeTab`.
- Page text that reaches DESIGN.md is made inert (`cleanText`), like font names already are.

## File structure

```
extension/
  vite.edit.config.ts            builds edit.js (IIFE), like extract.js
  src/edit/
    types.ts                     token specs, edits, panel ⇄ page messages (no DOM)
    apply.ts                     tokenSpecs(), applyEdits(), counts (pure)
    notes.ts                     layoutNotes(): page edits → DESIGN.md sentences (pure)
    editor.ts                    the on-page editor: index, repaint, one-offs, text, align, drag, undo
    overlay.ts                   hover/selection outlines, drop line (closed shadow root)
    entry.ts                     edit.js entry: one editor per port, destroyed on disconnect
    client.ts                    side panel end: inject + connect + send
  src/ui/sidepanel/EditView.tsx  inspector, "Your changes", editable token lists
  src/ui/sidepanel/App.tsx       toggle, session lifecycle, edited tokens → views
  src/export/designmd.ts         "Layout adjustments" section (ExportOptions.layout)
  src/i18n/*.json                `edit.*` strings, 7 languages
  test/unit/edit/*.test.ts       apply/notes and editor (jsdom)
  test/unit/ui-edit.test.tsx     panel behaviour against a fake page editor
  test/e2e/edit.spec.ts          real Chrome: repaint, one-off, text/align/drag, restore
```

## Feature checklist

Phase 1 — built and tested in this change:

- [x] "Edit on page" toggle in the side panel header; off keeps changes, stops picking
- [x] Hover outline and label, click to pick, Escape to deselect; page links do not navigate while picking
- [x] Inspector: the tokens behind the picked element's text colour, background, border, fill, text size, radius, padding, gap
- [x] Token edit repaints every element using the token; usage counts; hover a token to outline its users
- [x] Every token editable directly from the panel without picking
- [x] One-off element edit; "Save as new token"; "Update <token>"; styles with no matching token
- [x] Rewrite text: double-click on the page, or the Text field
- [x] Horizontal and vertical alignment (vertical disabled where the layout gives it nothing to act on)
- [x] Drag and drop to reorder or move; like elements reorder instead of nesting
- [x] "Your changes" with per-change Undo and Reset all
- [x] Exports use the edited tokens; contrast pairs are re-graded for changed colours; line heights scale with type sizes
- [x] DESIGN.md "Layout adjustments" for text, alignment and position edits
- [x] Page restored exactly when the panel closes; token changes survive a reload
- [x] 7 languages, RTL-safe styles
- [x] Store permission justification updated (docs/store/SUBMIT.md)
- [x] Version 0.4.0; landing page (vietnamcos.com/palette) gains the feature card, an Edit on page section with a screenshot, and a plans row, in 7 languages. Deploy steps: server/DEPLOY.md section 6e

Phase 2 — proposed, not built:

- [ ] Save edits per site so they survive closing the panel
- [ ] Shadows and font families as editable tokens
- [ ] Margin rows in the inspector; resize handles
- [ ] Keyboard: arrow-key nudge for spacing, Ctrl+Z / Ctrl+Shift+Z history
- [ ] Hover/focus states (inline styles currently freeze the resting state on edited elements)
- [ ] Touch dragging
- [ ] Chrome Web Store listing copy for the new mode (7 languages)
- [ ] Decide Free vs Pro for editing itself

## Prompts for phase 2

- "Persist Edit on page changes per site in chrome.storage and replay them when the panel reopens on that site."
- "Make shadows and font families editable tokens in Edit on page, with controls that fit each."
- "Add margin rows and drag-to-resize handles to the Edit on page inspector."
- "Add undo/redo history (Ctrl+Z, Ctrl+Shift+Z) and arrow-key nudging to Edit on page."
- "Make token edits apply to hover and focus states, not only the resting state."
- "Update the Chrome Web Store listing copy in all 7 languages for Edit on page."
- "Gate <these parts> of Edit on page behind Pro."

## Known limits

- Up to 4,000 visible elements are indexed (the scan's own cap); later elements can still be picked and edited one-off.
- Semi-transparent colours repaint as the opaque token colour.
- Pages that re-render their DOM (React and similar) can undo a move or text edit on their next render.
- Only the top frame is edited; cross-origin iframes are out of reach.
