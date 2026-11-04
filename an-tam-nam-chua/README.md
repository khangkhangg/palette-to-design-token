# An Tâm Nam Chùa

A Vietnamese mountain temple you can walk through in the browser: pixel-style 3D, day and night, seven halls to enter and pray in, monks, visitors, kids and a temple dog.

## Run it

Needs PHP 8.1 or newer. Three.js and the fonts load from public CDNs, so there is no build step.

```bash
php -S localhost:8000 -t an-tam-nam-chua/public
```

Then open http://localhost:8000.

## Layout

```
an-tam-nam-chua/
├── config.php          site settings (temple name, asset version)
├── public/
│   ├── index.php       page shell
│   ├── css/temple.css  panels, labels, bubbles
│   └── js/temple.js    the whole scene: world, halls, people, sound, controls
└── docs/PLAN.md        plan, phases and the feature checklist
```

## Where it stands

Phases 1 to 4 of `docs/PLAN.md` are mostly built, as one browser-side scene. Everyone you see is computed in your own browser. The visitor count is a placeholder, and leaves and gifts are saved only in your browser.

Next is Phase 5: an OpenSwoole server so real visitors see each other, then MySQL and MongoDB for accounts, blessings and candle orders. After that, `public/js/temple.js` should be split into the modules listed in the plan.
