# Site to Design Token — Chrome Web Store listing (English)

## Title

Site to Design Token

## Short description

Turn any website into design tokens: colours, type, spacing, radius, shadows and contrast. Export CSS, Tailwind, Figma and JSON.

## Long description

Site to Design Token reads any web page and turns what it finds into a design system you can reuse: colours sorted into roles, type styles, a spacing scale, corner radii, shadows and WCAG contrast results. Click the toolbar icon, read the result, copy what you need.

What it does
- Finds every colour on the page and assigns roles: primary, accent, surface, text and border.
- Lists the fonts and the type scale, with size, weight and line height.
- Detects the spacing base (4 or 8 px) and the radius and shadow values in use.
- Checks text and background pairs against WCAG AA and AAA and shows which ones fail.
- Opens the full design system in the side panel, in a light or dark theme.
- Available in 7 languages: English, Chinese (Simplified), Hindi, Spanish, Arabic (right-to-left), French and Bengali.

Free and Pro

Free
- Colours with roles, and copy any single value.
- Copy hex list.
- View type, spacing, radius and shadows.
- Contrast pass and fail results.
- Saved sites: the last 3.

Pro, a one-time $1.49 unlock
- Everything in Free.
- Export to CSS, Tailwind, Figma variables, JSON, DESIGN.md for AI coding tools, or a zip of all of them.
- Suggested contrast fixes.
- Saved sites: unlimited.

Pro + AI, $3.99 a month
- Everything in Pro.
- AI insights: press Analyse on a site to get a verdict, what its colours mean, and similar and competitor sites found with a web search. Up to 100 analyses a month.
- Billed monthly through PayPal. Cancel any time; Pro and AI stay on until the end of the month you paid for.

Privacy

Scanning happens on your device. The extension reads the colours and styles of the page you choose, only when you click its icon, and sends nothing about the pages you scan anywhere, unless you use Pro + AI and press Analyse (see below). Apart from that, the only network request is the optional Pro licence check, which sends your licence key.

AI insights (Pro + AI) run only when you press Analyse. The extension then sends vietnamcos.com the address of the page (without its query string), its title, its description and a summary of the design. Email addresses, links (anything starting with http, https or www, or containing a path), @handles and long digit runs such as phone numbers are removed from the title and description first; other words in the title are sent as they are. We run a web search with Brave Search and ask DeepSeek, an AI provider, to write the analysis. Read the full policy: https://vietnamcos.com/palette/privacy

Refunds

All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.

Pro + AI is also final: there are no refunds for the current or past months. Cancel any time and it stays on until the end of the month you paid for.

## Single purpose

Extract a website's colours, typography, spacing, radius, shadows and contrast ratios and export them as design tokens.

## Permissions justification

- activeTab: reads the styles of the current tab only after the user clicks the extension icon, with no standing access to any site.
- scripting: injects the extraction script into that tab to read computed styles and colours.
- storage: saves the user's settings, recent scans and the Pro licence key on the device.
- sidePanel: shows the full design system and the export screen in Chrome's side panel.
- externally_connectable (https://vietnamcos.com/palette/* and https://www.vietnamcos.com/palette/*): lets the checkout page on that site hand the purchased licence key to the extension, so Pro unlocks without copy and paste.

## Pricing note

Free to install and use. Pro is a one-time $1.49 unlock and Pro + AI is a subscription at $3.99 a month. Both are paid through PayPal on vietnamcos.com, not through the Chrome Web Store. Pro + AI can be cancelled any time from https://vietnamcos.com/palette/account.

## Data disclosure

For the Privacy practices tab. Declare these data types as collected. The first two exist only for the optional Pro + AI feature and are sent only when the user presses Analyse.

- Website content: the page title and description, and a summary of the design (colours, fonts, sizes, spacing). Used to write the AI analysis. On our server, before anything else happens, email addresses, links (anything starting with http, https or www, or containing a path), @handles and long digit runs such as phone numbers are removed from the title and description. Other words in the title are sent as they are.
- Web history: the address of the analysed page, without its query string. Used to look up similar and competitor sites and to cache the result for 7 days. It is user-initiated: nothing is sent unless the user presses Analyse.
- Authentication information: the licence key, to check that Pro or Pro + AI is active.
- Personal communications/contact: the buyer's email address, only when buying Pro or Pro + AI on vietnamcos.com, used to deliver and resend licence keys and for billing (sent via Resend; payment via PayPal).

Third parties that process the analysis request on our behalf: Brave Search (web search) and DeepSeek (AI provider).

Not collected: passwords, form contents, browsing history (only the address of the page the user analyses is sent, see Web history), or page content beyond what the user sends with Analyse (the title and description may contain whatever the site put there). Also not collected: health, financial or payment information (PayPal handles payments on the website), location, or user activity such as clicks and keystrokes.

Certifications to tick: the data is not sold to third parties outside the approved use cases; it is not used or transferred for purposes unrelated to the item's single purpose; it is not used or transferred to determine creditworthiness or for lending.

## Screenshots

The store takes five. Upload 1-popup-colours, 2-contrast, 3-side-panel-light, 5-export and 6-ai-insights from docs/store/screenshots. 4-side-panel-dark is used on the landing page only.

## Links

- Homepage URL: https://vietnamcos.com/palette/
- Privacy policy URL: https://vietnamcos.com/palette/privacy
- Terms of service URL: https://vietnamcos.com/palette/terms
