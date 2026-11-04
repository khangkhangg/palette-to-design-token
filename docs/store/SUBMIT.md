# Submitting Site to Design Token 0.3.0 to the Chrome Web Store

A field-by-field guide for the Chrome Web Store Developer Dashboard, in the order the dashboard shows the tabs and
fields. The item is already registered: its id is `jknkphgicofbpibajdcjncfdkbbehbgn`.

All paths are relative to the repository root. Text in code blocks is the exact copy to paste.

## 1. Package

Upload `extension/site-to-design-token-0.3.0.zip`.

- This is the Store build: its manifest has no `key` field (the Web Store assigns the id). Do not upload
  `site-to-design-token-0.1.0-direct.zip` or any `-direct` zip; those carry the key and are for loading unpacked.
- Rebuild it with `npm run package` in `extension/` if the source has changed since 2026-10-17
  (`npm run package:check` verifies it).
- Version 0.3.0. The title and the short description (summary) come from the package's `_locales/<lang>/messages.json`
  and cannot be edited in the dashboard. They match the "Title" and "Short description" sections of each
  `listing.<lang>.md`.

## 2. Store listing

The dashboard edits one language at a time; pick the language in the selector at the top of the tab. Fill English first.

### Description

English: paste the whole "Long description" section of `docs/store/listing.en.md` (from "Site to Design Token reads any
web page..." down to the end of the Refunds paragraph, before "## Single purpose"). Paste it as plain text; the dashboard
keeps line breaks.

Other languages: paste the long description section of the matching file, everything between that heading and
`## Links`:

| Dashboard language | File | Section |
|---|---|---|
| Chinese (Simplified) | `docs/store/listing.zh_CN.md` | 详细说明 |
| Hindi | `docs/store/listing.hi.md` | विस्तृत विवरण |
| Spanish | `docs/store/listing.es.md` | Descripción larga |
| Arabic | `docs/store/listing.ar.md` | الوصف التفصيلي |
| French | `docs/store/listing.fr.md` | Description détaillée |
| Bengali | `docs/store/listing.bn.md` | বিস্তারিত বিবরণ |

Use "Spanish" (es), not "Spanish (Latin America)", so the text matches the package's `_locales/es`.

### Category

```
Developer Tools
```

### Language

Default language: English. Then add the six others: Chinese (Simplified), Hindi, Spanish, Arabic, French, Bengali. The
package already declares all seven (`default_locale: en`, `_locales/{en,zh_CN,hi,es,ar,fr,bn}`).

### Store icon

`docs/store/store-icon-128.png` (128x128, the mark at 96x96 with 16px of transparent padding, as the store asks).

### Global promo video

Leave empty.

### Screenshots

Five per language, 1280x800, uploaded in this order:

| Language | Files |
|---|---|
| English | `docs/store/screenshots/1-popup-colours.png`, `2-contrast.png`, `3-side-panel-light.png`, `5-export.png`, `6-ai-insights.png` |
| Chinese (Simplified) | `docs/store/screenshots/zh_CN/1-popup-colours.png`, `2-contrast.png`, `3-side-panel-light.png`, `5-export.png`, `6-ai-insights.png` |
| Hindi | `docs/store/screenshots/hi/` same five files |
| Spanish | `docs/store/screenshots/es/` same five files |
| Arabic | `docs/store/screenshots/ar/` same five files |
| French | `docs/store/screenshots/fr/` same five files |
| Bengali | `docs/store/screenshots/bn/` same five files |

`4-side-panel-dark.png` is for the landing page only; do not upload it. Regenerate the shots with
`npm run screenshots -- --all-langs` in `extension/`.

### Small promo tile

`docs/store/promo-440x280.png` (440x280).

### Marquee promo tile

`docs/store/promo-marquee-1400x560.png` (1400x560).

Both tiles, and the store icon, are made by `npm run promo` in `extension/`. They are English; set them on the English
listing (the other languages fall back to them).

### Official URL

Optional. Leave it as "None" unless vietnamcos.com is verified in Google Search Console for this developer account.

### Homepage URL

```
https://vietnamcos.com/palette/
```

### Support URL

```
https://vietnamcos.com/palette/#faq
```

That is the FAQ section of the landing page (anchor `faq`); the same page also shows the support address, support@vietnamcos.com.

### Mature content

No.

## 3. Privacy

### Single purpose description

```
Extract a website's colours, typography, spacing, radius, shadows and contrast ratios and export them as design tokens.
```

### Permission justifications

activeTab:

```
Reads the styles of the current tab only after the user clicks the extension icon, with no standing access to any site.
```

scripting:

```
Injects the extraction script into that tab to read computed styles and colours.
```

storage:

```
Saves the user's settings, recent scans and the Pro licence key on the device.
```

sidePanel:

```
Shows the full design system and the export screen in Chrome's side panel.
```

There is no host permission field to fill: the extension requests no host permissions. If the dashboard asks about
`externally_connectable`, or a reviewer asks why the manifest has it, use:

```
externally_connectable (https://vietnamcos.com/palette/* and https://www.vietnamcos.com/palette/*): lets the checkout page on that site hand the purchased licence key to the extension, so Pro unlocks without copy and paste.
```

### Remote code

No, I am not using remote code.

```
All JavaScript is bundled in the package. The extension calls the vietnamcos.com API for licence checks and, only when the user presses Analyse, for AI insights; those responses are JSON data and are never executed.
```

(The dashboard shows the justification box only when you answer Yes; keep the text above in case a reviewer asks.)

### Data usage

From the "Data disclosure" section of `docs/store/listing.en.md`. Tick these data types:

- Personally identifiable information: Yes (the buyer's email address, only when buying Pro or Pro + AI on
  vietnamcos.com; used to deliver and resend licence keys and for billing). The dashboard lists email address under this
  type, not under Personal communications.
- Health information: No.
- Financial and payment information: No (Stripe handles payment on vietnamcos.com; card details never reach us).
- Authentication information: Yes (the licence key, to check that Pro or Pro + AI is active).
- Personal communications: No.
- Location: No.
- Web history: Yes (only the address of the page the user analyses with Pro + AI, without its query string; nothing is
  sent unless the user presses Analyse).
- User activity: No.
- Website content: Yes (the page title and description and a summary of the design, sent only when the user presses
  Analyse).

Tick all three certifications:

- I do not sell or transfer user data to third parties, outside of the approved use cases.
- I do not use or transfer user data for purposes that are unrelated to my item's single purpose.
- I do not use or transfer user data to determine creditworthiness or for lending purposes.

### Privacy policy URL

```
https://vietnamcos.com/palette/privacy
```

## 4. Distribution

- Payments: Free of charge. Tick "Contains in-app purchases": Pro (one-time $1.49) and Pro + AI ($3.99 a month) are
  bought on vietnamcos.com by card, Apple Pay or Google Pay (processed by Stripe), not through the Chrome Web Store.
- Visibility: Public.
- Distribution: All regions.

## 5. Test instructions (optional)

The extension works without an account, so reviewers can test the free features straight away. If you want them to see
Pro, give them a licence key here:

```
Click the toolbar icon on any website to see its colours, type, spacing and contrast. The side panel button opens the full design system. Copy design system and copying any export are free; downloading export files (and the .zip) needs Pro: open Settings, Enter license key, and paste <KEY>. AI insights need a Pro + AI key and send the page address, title, description and a design summary to vietnamcos.com only when Analyse is pressed.
```

## Before you press Submit

- [ ] Stripe is connected in Live mode on vietnamcos.com (/palette/admin › Settings), so the Buy buttons on the checkout page work.
- [ ] If Pro + AI shows "Coming soon" on the plan sheet and checkout, that is fine to submit: the free features and Pro
      work, and Pro + AI turns on by itself once the server's Stripe price exists.
- [ ] The store id `jknkphgicofbpibajdcjncfdkbbehbgn` is already registered on the server (the `extension_ids`
      setting, which allows that extension's origin to call the licence and analyse API). Nothing to change there.
- [ ] https://vietnamcos.com/palette/, https://vietnamcos.com/palette/privacy and https://vietnamcos.com/palette/terms
      all load.
- [ ] Uploaded `extension/site-to-design-token-0.3.0.zip` (no `key` in its manifest).
- [ ] Every language has its description and five screenshots; English has the icon and both promo tiles.
- [ ] Privacy tab: single purpose, four permission justifications, remote code No, data types ticked, three
      certifications, privacy policy URL.
