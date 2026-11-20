# Site to Design Token Import (Figma plugin)

Reads the `figma-variables.json` that the Site to Design Token extension exports and creates, in the open Figma file:

- a variable collection `Site to Design Token · <site>` with one mode, `Light`: `color/*`, `font/*`, `space/*`, `radius/*` and
  the type scale as numbers (`text/h1/size`, `text/h1/line-height`, `text/h1/weight`);
- a text style per type step (`text/h1`, `text/body`, …);
- an effect style per shadow (`shadow/sm`, …).

Importing the same site again updates what is there. A variable that already exists with a different type is left alone.
If a site's font is not available in your Figma, the text style uses Inter and the plugin says so.

## Install (development)

Figma desktop app → Plugins → Development → **Import plugin from manifest…** → choose `manifest.json` in this folder.
Then run Plugins → Development → **Site to Design Token Import**.

There is no build step: `code.js` and `ui.html` are what Figma runs. The plugin makes no network requests.

## Publish

Plugins → Development → the plugin's menu → **Publish**. Figma assigns the real plugin id at that point; replace the
placeholder `id` in `manifest.json` with it.

## Tests

`extension/test/unit/figma-plugin.test.ts` runs `readFile` and `apply` against a stand-in for the Figma API, using a real
export. It checks the plugin's logic, not Figma itself: the plugin has not been run inside Figma by its author.
