# An Tâm Nam Chùa: Project Plan

Three.js · pixelated, hand-painted ("crayon") 3D · live multiplayer · day and night cycle · candle offerings paid by QR code

---

## 1. Feasibility verdict

| Question | Answer |
|---|---|
| Can a walkable 3D temple like the concept art run in a browser with Three.js? | **Yes.** It needs real art production (a modular building kit plus props). Code alone won't get there. |
| Can the look be "realistic but pixelated 3D"? | **Yes.** Use realistic lighting, sound, smoke and behaviour, then render at about 1/3 resolution with a pixel, palette and outline post-process. The pixel filter also hides missing detail, which keeps the asset budget affordable. |
| Can 10,000 people be online at once? | **Yes, if they are spread across many copies of the courtyard.** No browser can draw 10,000 avatars, and nobody needs to see them all. Each person sees the roughly 60 to 150 people in their own copy ("shard"). The 10,000 total shows up as a live counter, as smoke density and as crowd noise. |
| Monks, kids and dogs walking around without server cost? | **Yes.** The NPCs run on each visitor's machine from a shared random seed and the server clock, so everyone in a shard sees the same monk at the same spot, and nothing extra goes over the network. |
| Candle purchase by QR code? | **Yes.** Use a VietQR (Napas 247) dynamic QR code plus a payment webhook from PayOS, SePay or Casso. The PHP endpoint marks the order paid and the candle lights up live. |
| Your PHP/MySQL/MongoDB/JS stack? | **Yes.** PHP with **OpenSwoole** (or Workerman) handles long-lived WebSocket connections. Plain PHP-FPM cannot. |

**Biggest risks:**
1. Art volume and cost, the largest item by far.
2. Mobile GPU performance with smoke and night lights.
3. Cultural accuracy: monastic ranks, robes and rituals. Get a monk or temple adviser.
4. Moderation of names and chat.
5. Legal and financial transparency for donations. Partner with a real temple.

---

## 2. Research and analysis

### 2.1 What "temple-like" means here (content checklist)
- **Layout** (from your top-down map): Tam quan gate → brick courtyard → incense urn (lư hương) and bell tower (chuông) → Chánh điện (main hall) on a raised platform → side halls (Tổ đường, Giảng đường for dharma talks, Nhà khách for guests) → lotus ponds and bonsai gardens → stone paths at the edges.
- **Rituals a visitor performs:** chắp tay (palms joined), xá (bow), quỳ (kneel), lạy (full prostration, ngũ thể đầu địa), ngồi thiền (sit in meditation), thắp nhang (light incense), cắm nhang (place the incense in the urn), thỉnh chuông (ring the bell), dâng hoa or mâm quả (offer flowers or a fruit tray), thắp nến (light a candle), xin xăm (draw a fortune stick, optional and very popular), viết lời nguyện (write a prayer or wish).
- **People:**
  - Monastic ranks for monks: Hòa thượng, Thượng tọa, Đại đức, Sa di. For nuns: Ni trưởng, Ni sư, Sư cô, Sa di ni.
  - Lay Buddhists in áo tràng (gray or brown).
  - Elders, families, tourists, students.
  - Kids, either running or calm.
  - Temple dogs (chó chùa) sleeping on the steps.
  - Robe colours and who preaches when should be **checked with a monastic adviser**.
- **Sounds:**
  - Đại hồng chung (great bell), on the real schedule around 4:00 and 18:00.
  - Mõ (wooden fish) and chuông gia trì (small bell) during chanting.
  - Chanting: "Nam mô A Di Đà Phật", Bát Nhã.
  - Footsteps that change with the surface: brick, stone, wood or grass.
  - Wind chimes. Birds by day, crickets and frogs at night.
  - Distant motorbikes, kids laughing, dogs barking, the crackle of incense.

### 2.2 Making it look like the concept art
- Set a **fixed 3/4 "diorama" camera**, like the first image, with an orbit or follow option. It makes the scene read clearly, limits what has to be drawn and matches the art.
- **Render pipeline:**
  1. Draw the scene into a WebGLRenderTarget at 1/2 to 1/4 resolution with nearest-neighbour upscaling.
  2. Apply palette quantization with a hand-picked palette of 48 to 64 colours (orange tiles, brick red, jade, gold, lotus pink).
  3. Add edge outlines from depth and normals.
  4. Add ordered dithering.
  5. Add a light paper or crayon grain overlay.
  6. Finish with bloom on emissive lanterns and candles.
- **Lighting:**
  - Bake lightmaps in Blender for the day and night sets and blend between them by time of day.
  - Use one real-time sun or moon light that casts shadows.
  - Turn on at most about 8 real point lights at once: the nearest candles and lanterns. Every other light source glows through emissive materials plus bloom.
- **Smoke:** use GPU particles (instanced quads with soft-particle depth fade, curl-noise drift and alpha-tested pixel sprites).
  - Spawn rate = incense sticks lit in the shard over the last 10 minutes + a global crowd factor.
  - Near the urn, use a localized volumetric fog box. Light shafts from the hall doors use a cheap screen-space god-ray pass.

### 2.3 How to get "great detail" assets (three ways to source art)
| Method | Quality | Speed | Cost | Use for |
|---|---|---|---|---|
| A. Hand-modelled in Blender (modular kit: roof segments, đầu đao curved eaves, columns, walls) | ★★★★★ | slow | $$$ | Buildings and the gate |
| B. AI image-to-3D (Tripo, Meshy, Hunyuan3D, Rodin) from your concept sheets, then retopology and cleanup | ★★★☆ | fast | $ | Props: bell, urn, lion statues, bonsai, altar, cushions |
| C. Voxel (MagicaVoxel) | ★★★ (stylized) | fastest | $ | Prototype, filler props, dogs |

**Recommendation:** A for architecture, B for props, and the pixel post-process to make them look like one style. Export as GLB with meshopt compression and KTX2 textures.

### 2.4 Scaling to 10,000 concurrent visitors

```
                  ┌────────── CDN (GLB, KTX2, audio, JS) ──────────┐
Browser ──HTTPS──▶ Nginx ─▶ PHP-FPM (site, auth, avatar, orders, webhooks) ─▶ MySQL
   │                                                         └────────────▶ MongoDB
   └──WSS──▶ LB (sticky) ─▶ Realtime nodes (OpenSwoole) × N ◀──▶ Redis (presence, pub/sub, counters)
                               each node: many shards (≤150 users each)
```
- **Shards:** the courtyard has copies of 150 people each (10,000 people ≈ 70 shards). Prayer rooms are smaller copies of about 30 people. Friends can join the same shard through a link.
- **Interest management:** inside a shard, send each player only the people within about 40 m, capped at the 60 nearest.
- **Bandwidth per player position:**
  - The packet is 8 bytes: id 2 B, x and z as int16 4 B, facing 1 B, pose or animation 1 B.
  - The server sends updates 10 times per second, and the client interpolates between them.
  - Per visitor: 60 × 8 B × 10 = **about 5 KB/s**.
  - For 10,000 visitors: about **50 MB/s, roughly 400 Mbps**, spread over 4 to 8 nodes. That is ordinary hardware.
- **Rendering crowds:** use GPU-instanced skinned avatars with vertex animation textures (VAT), so about 150 characters draw in a handful of draw calls. Avatars beyond 25 m become pixel billboards.
- **Global feel:** Redis keeps global counters (people online, incense lit today, candles burning). These drive the counter, the ambient crowd murmur volume and the base smoke level.
- **Load testing:** use k6 or Artillery WebSocket bots with 10,000 simulated walkers before launch.

### 2.5 Day and night
- Compute the sun and moon position with **SunCalc**, using the **visitor's local time**. Use a default location (Hà Nội) if they don't share theirs. Lighting is drawn on each visitor's machine, so people in the same shard can see different times of day, and that is fine.
- **Night:** the lanterns light up, candles matter (candle purchases show up here), fireflies appear over the ponds and the sounds switch to crickets and frogs.
- **Special days:** Rằm and Mùng 1 (full and new moon, lunar calendar) bring bigger crowds, more smoke and chanting events.

### 2.6 Candle by QR code
1. The visitor clicks a candle stand and chooses a size and a wish. PHP creates the order `NEN-8F3K2` in MySQL with status `pending`.
2. The site shows a VietQR code (bank, account, amount, memo = `NEN-8F3K2`).
3. The payment gateway (PayOS, SePay or Casso) sends a webhook to `/api/payment/webhook.php`. PHP checks the signature, matches the memo and amount, and sets the order to `paid`.
4. PHP publishes `candle.lit` on Redis. The realtime node tells the shard. The candle appears with the visitor's name and burns for 24 hours (also stored in MongoDB).
5. Free alternative for people who don't pay: a "virtual incense" stick.

**Legal note:** donations should go to a real partner temple's account, with a public record of what was received.

---

## 3. Three ways to build the whole project

| | **Method 1: all PHP (recommended)** | Method 2: hybrid | Method 3: game engine |
|---|---|---|---|
| Client | Three.js (ES modules and import maps, or Vite) | Three.js | Unity WebGL or PlayCanvas |
| Realtime server | PHP 8.3 + **OpenSwoole** WebSocket | Node + Colyseus or uWebSockets.js | Photon, Unity Netcode |
| Web, API, payments | PHP | PHP | PHP |
| Data | MySQL (users, orders), MongoDB (avatars, wishes, chat, events), Redis | same | same |
| Pros | One language, your stack, OpenSwoole handles 10k+ connections per box | Colyseus has rooms and state sync built in | Editor tooling |
| Cons | Fewer ready-made multiplayer helpers | Two languages | 20–50 MB download, weak on mobile web, licensing |

**Recommendation: Method 1.** Keep the binary network protocol portable, so the realtime server could later be swapped for Method 2 without touching the client.

---

## 4. Phases

| Phase | Goal | Done when |
|---|---|---|
| **P0 Pre-production** | Art bible, palette, map blockout from the top-down concept, asset list, cultural adviser | Signed-off style frame |
| **P1 Walkable temple (single player)** | Graybox → art pass, WASD movement, collision, camera | Walk from the gate to the main hall at 60 fps on a laptop |
| **P2 Look and feel** | Pixel and crayon pipeline, baked lighting, day and night, ambient audio, smoke | A day-to-night screen recording looks like the concept |
| **P3 Character and rituals** | Character creator, postures and actions, keyboard controls, footstep sounds | All postures are playable |
| **P4 Living temple** | NPC monks by rank, visitors, kids, dogs, preaching events, bell schedule | 30+ NPCs, deterministic across clients |
| **P5 Multiplayer** | Names, shards, live avatars, chat bubbles, emotes | 150 bots in one shard play smoothly |
| **P6 Prayer rooms** | Join a hall, chanting sessions, wishes wall, crowd-driven smoke | A room joins and leaves cleanly |
| **P7 Candle by QR code** | VietQR orders, webhook, live candle | A real payment of 2,000₫ lights a candle |
| **P8 Scale** | Redis, multiple nodes, k6 tests at 10,000, CDN, performance tiers | 10,000 bots, p95 latency under 150 ms |
| **P9 Launch polish** | Mobile touch controls, moderation, analytics, accessibility, Vietnamese and English text | Public launch |

---

## 5. File structure

```
chua-ao/
├── public/                         # web root (Nginx)
│   ├── index.php                   # landing + name entry
│   ├── temple.php                  # game shell
│   ├── api/
│   │   ├── auth.php                # guest/name session
│   │   ├── avatar.php              # save/load character (Mongo)
│   │   ├── order.php               # create candle order + VietQR
│   │   ├── payment_webhook.php     # PayOS/SePay/Casso
│   │   ├── wishes.php              # prayer wishes wall
│   │   └── stats.php               # global counters
│   ├── assets/
│   │   ├── models/                 # *.glb (meshopt) – buildings/, props/, characters/, npc/, animals/
│   │   ├── textures/               # *.ktx2, lightmaps_day/, lightmaps_night/
│   │   ├── audio/                  # ambience/, sfx/, chant/, bell/, footsteps/
│   │   └── ui/                     # icons, fonts, paper grain
│   ├── css/
│   │   ├── main.css
│   │   ├── hud.css
│   │   └── creator.css
│   └── js/
│       ├── main.js                 # boot, loader, quality tier
│       ├── core/        { renderer.js, loop.js, input.js, camera.js, quality.js, assets.js }
│       ├── render/      { pixelPass.js, paletteQuantize.glsl, outline.glsl, dither.glsl, paperGrain.glsl, bloom.js, godrays.js }
│       ├── world/       { temple.js, colliders.js, navmesh.js, zones.js, daynight.js, sky.js, lanterns.js, water.js, foliage.js }
│       ├── fx/          { smoke.js, incense.js, candles.js, fireflies.js, petals.js }
│       ├── audio/       { audioEngine.js, ambience.js, footsteps.js, bellSchedule.js, chant.js }
│       ├── avatar/      { avatar.js, customizer.js, outfits.js, animations.js, vatCrowd.js, nameplate.js }
│       ├── actions/     { postures.js, incenseAction.js, bellAction.js, offering.js, fortune.js }
│       ├── npc/         { npcManager.js, seededRng.js, monk.js, visitor.js, kid.js, dog.js, preachEvent.js, schedules.js }
│       ├── net/         { socket.js, protocol.js, interpolation.js, shardClient.js }
│       └── ui/          { hud.js, chat.js, creatorUI.js, candleQR.js, roomPicker.js, wishWall.js, settings.js }
├── realtime/                       # OpenSwoole server
│   ├── server.php
│   ├── src/ { ShardManager.php, Shard.php, Player.php, Protocol.php, InterestGrid.php, RedisBus.php, RateLimiter.php, NameFilter.php }
│   └── config.php
├── app/                            # shared PHP (PSR-4)
│   ├── Db/ { Mysql.php, Mongo.php, Redis.php }
│   ├── Payment/ { VietQR.php, PayOSWebhook.php, SePayWebhook.php }
│   └── Moderation/ { Profanity.php, wordlists/vi.txt, en.txt }
├── sql/ { 001_schema.sql }         # users, candle_orders, payments, reports
├── mongo/ { indexes.js }           # avatars, wishes, chat_logs, events
├── art-source/                     # .blend, voxel, concept images (git-lfs)
├── tools/ { bake_lightmaps.py, export_glb.sh, ktx2_convert.sh, vat_bake.py }
├── tests/ { load/k6_walkers.js, php/ }
├── docker-compose.yml              # nginx, php-fpm, openswoole, mysql, mongo, redis
└── README.md
```

---

## 6. Detailed feature list and checklist

Use this as the tracker by ticking boxes as features ship.

### P0 Pre-production
- [x] F01 Art bible: palette (48–64 colours), pixel size, outline style, crayon grain reference
- [x] F02 Map blockout matching the top-down concept (gate, courtyard, main hall, 4 side halls, 3 ponds, gardens)
- [ ] F03 Asset list with polygon and texture budgets
- [ ] F04 Cultural review: monastic ranks, robes, ritual order, sounds

### P1 Walkable temple
- [x] F05 Three.js boot, asset loader with progress bar, quality tiers (low, mid, high)
- [x] F06 Modular building kit (roof, eaves, columns, walls, steps), Tam quan gate, main hall, 4 side halls
- [x] F07 Props: urn, bell tower, lion statues, stone lanterns, bonsai, frangipani trees, palms, benches, lotus ponds
- [x] F08 Keyboard movement (WASD or arrows, Shift to walk slowly), collision, navmesh
- [x] F09 Camera: 3/4 diorama follow plus orbit (Q/E), zoom with the wheel
- [ ] F10 Zones: courtyard, hall interior (walking only, no running), gardens

### P2 Look and feel
- [x] F11 Low-resolution pixel render with nearest-neighbour upscale
- [x] F12 Palette quantization, outlines, dithering, paper and crayon grain
- [ ] F13 Baked day and night lightmaps with blending
- [x] F14 Day and night from SunCalc and local time, sky gradient, stars, moon
- [x] F15 Lanterns and candles: emissive + bloom, 8 nearest real lights
- [x] F16 Incense smoke GPU particles + density driven by the crowd
- [ ] F17 Light shafts from the hall doors, haze inside the hall
- [x] F18 Ambience by time and zone (birds, wind chimes, crickets, frogs, distant city)
- [x] F19 Footsteps by surface, 3D positional audio
- [ ] F20 Đại hồng chung bell schedule (around 4:00 and 18:00) + random small bells

### P3 Character and rituals
- [ ] F21 Name entry and guest session (name filter)
- [x] F22 Character creator: body (adult, elder, child; male, female), face, hair (including shaved)
- [x] F23 Clothing: áo dài, áo tràng, áo bà ba, modern; colour pickers per slot
- [x] F24 Accessories: tràng hạt (prayer beads), nón lá, bag, flowers, mâm quả, incense bundle
- [x] F25 Postures: stand, chắp tay, xá, quỳ, lạy (full prostration), ngồi thiền
- [x] F26 Keyboard actions: E interact, 1–6 postures, F light incense, B ring bell, O offer, Enter chat, M map, Tab people list
- [x] F27 Light incense → place in the urn (animation + sound + smoke)
- [x] F28 Ring the bell (bell swing + rich decaying tone heard across the courtyard)
- [ ] F29 Xin xăm fortune sticks (shake, a stick falls, read the verse)

### P4 Living temple (NPCs)
- [ ] F30 Seeded deterministic NPC system synced to server time
- [x] F31 Monks by rank (robe and colour per adviser), walking routes, greeting gestures
- [x] F32 Scheduled preaching in Giảng đường (audio + speech bubbles + monks gathering)
- [x] F33 Chanting sessions in the main hall (mõ, chuông, chant loop)
- [x] F34 Visitor NPCs: elders, families, tourists taking photos, peaceful prayers
- [x] F35 Kids: running, chasing, splashing at the pond, a parent calling them
- [x] F36 Dogs: sleep on the steps, wander, bark occasionally, rarely seen

### P5 Multiplayer
- [ ] F37 OpenSwoole WebSocket server, binary protocol, 10 updates per second
- [ ] F38 Shards (150 people), automatic assignment, invite links that join a friend's shard
- [ ] F39 Interest grid (nearest 60), client interpolation
- [ ] F40 Live avatars with nameplates, synced postures and actions
- [ ] F41 Chat bubbles + emotes, rate limits, mute and report
- [ ] F42 VAT instanced crowd rendering + billboards for distant people

### P6 Prayer rooms
- [ ] F43 Room picker: main hall, Quan Âm shrine, ancestor hall, meditation room
- [ ] F44 Room instances (about 30 people) with cushion slots and kneeling sync
- [ ] F45 Group chanting with a shared audio timeline (synced to the server clock)
- [ ] F46 Wish wall: write a prayer, hang it, others can see it (Mongo, moderated)
- [ ] F47 Smoke and haze density rise with occupancy

### P7 Candle by QR code
- [ ] F48 Candle stand UI: size, wish, name
- [ ] F49 MySQL order + VietQR generator (amount + memo)
- [ ] F50 Payment webhook (PayOS, SePay or Casso), signature check, idempotency
- [ ] F51 Live candle lights in the shard, burns 24 hours, glows at night
- [ ] F52 Receipt and donation transparency page

### P8 Scale
- [ ] F53 Redis presence, pub/sub, global counters
- [ ] F54 Several realtime nodes behind a sticky load balancer, shard directory
- [ ] F55 CDN for assets, meshopt and KTX2, lazy-loaded halls
- [ ] F56 k6 load test with 10,000 bots, monitoring (Prometheus or Grafana)
- [ ] F57 Automatic quality downgrade (FPS watchdog)

### P9 Launch
- [ ] F58 Mobile touch joystick + action buttons
- [ ] F59 Vietnamese and English text
- [ ] F60 Settings: volume per channel, quality, reduced motion
- [ ] F61 Moderation dashboard (PHP admin)
- [ ] F62 Analytics (visits, rituals done, candles)

---

## 6b. Built so far (not in the original list)
- [x] Seven walk-in halls with stairs, each with its own Buddha (Thích Ca, Quan Âm, A Di Đà, Địa Tạng, Di Lặc, Dược Sư, Đản Sanh)
- [x] Eye view (scroll in or V), switches on automatically inside a hall
- [x] Prayer bubbles when kneeling or praying inside a hall, with each hall's own invocation
- [x] Talking with a monk when you walk into one (bubbles and a 4–7 s countdown)
- [x] Bodhi leaves and temple gifts for good deeds (saved in the browser for now)
- [x] Sweeping fallen leaves and giving the temple dog water (G)
- [x] Mountain terrain with forest and mist around the temple terrace
- [x] Incense smoke setting: Off, A little, Full

Partly done: F10 (zones only switch the view and toasts), F20 (the bell rings at random, not on the real schedule), F21 (name entry exists, no server session or name filter yet), F29 not started, F30 (NPCs are random per browser, not yet synced to server time).

## 7. Prompts per feature

Copy one prompt into a session in order. Each assumes the file structure in section 5.

**P0**
- **F01** "Create `docs/art-bible.md` and `public/js/render/palette.js` with a 56-colour palette for a Vietnamese pagoda (orange roof tiles, brick red, gold, jade, lotus pink, monk-robe browns, night blues). Export it as a DataTexture for a quantize shader."
- **F02** "Build a graybox temple in `public/js/world/temple.js` using boxes, matching this layout: gate at the south, a 60×40 m brick courtyard, the main hall on a 1.2 m platform to the north, 2 side halls on each side, 3 lotus ponds, gardens on both edges. Add colliders."
- **F03/F04** "Write `docs/assets.md` listing every model with triangle and texture budgets, and `docs/culture.md` with a checklist of ranks, robes and rituals for an adviser to confirm."

**P1**
- **F05** "Create `public/js/main.js` and `core/*`: a Three.js renderer, a loading screen, a quality tier picked by GPU tier, and a fixed-step loop."
- **F06** "Write a Blender Python script `tools/build_roof.py` that makes the curved Vietnamese roof with upturned đầu đao eaves from parameters, and export the GLB kit pieces."
- **F07** "Load the prop GLBs and place them from `world/layout.json`. Use InstancedMesh for repeated bonsai, lanterns and trees."
- **F08** "Implement WASD or arrow movement with Shift to walk slowly, capsule collision against `colliders.js`, and a navmesh using three-pathfinding."
- **F09** "Implement a 3/4 diorama follow camera with damping, Q/E to rotate in 45° steps, and wheel zoom limits."
- **F10** "Add zones: inside the hall, force walking speed and lower the footstep volume. Show the zone name in the HUD when entering."

**P2**
- **F11–F12** "Implement `render/pixelPass.js`: render to a 1/3 resolution target with nearest-neighbour upscaling, then a quantize-to-palette pass, a depth and normal outline pass, Bayer dithering and a paper-grain overlay. Add toggles."
- **F13** "Write `tools/bake_lightmaps.py` for Blender to bake day and night lightmaps to UV2. In Three.js, blend them with a `dayFactor` uniform."
- **F14** "Implement `world/daynight.js` with SunCalc, local time and a default of Hà Nội. Drive the sun light, sky gradient, stars, moon, fog colour and `dayFactor`. Add a debug time slider."
- **F15** "Implement `world/lanterns.js`: emissive lanterns and candles switch on at dusk, the nearest 8 become real PointLights, and the rest rely on bloom."
- **F16** "Implement `fx/smoke.js`: instanced pixel smoke sprites with curl-noise drift and a soft depth fade. `setDensity(n)` takes the number of incense sticks lit in the last 10 minutes. Cap at 4,000 particles on high and 800 on low."
- **F17** "Add light shafts at the main hall doors and a haze volume inside the hall."
- **F18–F19** "Implement `audio/audioEngine.js` with Web Audio: channels (ambience, sfx, chant, voice), crossfading ambience by time and zone, and positional footsteps by surface material."
- **F20** "Implement `audio/bellSchedule.js`: ring the great bell 108 times starting around 4:00 and 18:00 local time (a short version is configurable), heard everywhere and louder near the bell tower."

**P3**
- **F21** "Create `index.php` with name entry, `api/auth.php` (guest token in MySQL) and `NameFilter` for Vietnamese and English profanity."
- **F22–F24** "Build `avatar/customizer.js` and `ui/creatorUI.js`: one shared skeleton, swappable GLB meshes per slot (body, hair, top, bottom, headwear, accessory), and per-slot colour with a material colour override. Save to `api/avatar.php` in MongoDB."
- **F25–F26** "Implement `actions/postures.js` with the states stand, chắp tay, xá, quỳ, lạy and thiền, with transitions. Map keys 1–6. Add `core/input.js` with rebindable keys."
- **F27** "Implement light incense: walk to the urn, play the animation, a match-strike sound, a glowing tip, then place it in the urn and add +1 to the shard's incense count."
- **F28** "Implement ringing the bell: the striker swings, the bell sways, and a layered long-decay bell sample plays as positional audio."
- **F29** "Implement xin xăm: shake the cup (sound plus camera shake), a random stick falls, and its verse (VI and EN) shows from `data/xam.json`."

**P4**
- **F30** "Implement `npc/npcManager.js`: a seeded RNG plus server time decide each NPC's position on its route, so every client sees the same thing. Use the same VAT crowd renderer as players."
- **F31** "Add monks by rank with routes between the halls and gestures to visitors who come near. Config in `npc/schedules.js`."
- **F32–F33** "Add the preaching event in Giảng đường every 2 real hours and chanting in the main hall at fixed times: NPC monks gather, mõ and chant audio play, and subtitle bubbles appear."
- **F34–F36** "Add visitor, kid and dog NPCs with behaviours: kids run and chase near the pond, elders pray slowly, dogs sleep on the steps and rarely walk around. Use a steering and avoidance helper."

**P5**
- **F37** "Create `realtime/server.php` with OpenSwoole WebSocket and `Protocol.php` with an 8-byte binary state packet. Send to clients 10 times per second."
- **F38–F39** "Implement `ShardManager` (150 per shard, join by invite code) and `InterestGrid` (5 m cells, nearest 60). Client side: `net/shardClient.js` and `interpolation.js` with 100 ms buffered interpolation."
- **F40** "Sync avatar appearance (outfit hash fetched once), posture and action events, and nameplates that fade with distance."
- **F41** "Add chat bubbles, emotes, rate limits, mute and report."
- **F42** "Implement `avatar/vatCrowd.js`: bake animations to a vertex animation texture with `tools/vat_bake.py`, draw remote avatars instanced, and switch to billboards beyond 25 m."

**P6**
- **F43–F44** "Add a room picker and room shards of 30 with cushion slots; kneeling or prostrating snaps the avatar to a cushion."
- **F45** "Run group chanting on the server clock so everyone hears the same verse at the same time, with a join-in button."
- **F46** "Build the wish wall: `api/wishes.php` in MongoDB, moderation queue, paper tags hung on a tree or wall in 3D."
- **F47** "Drive the room's haze and smoke from occupancy plus incense."

**P7**
- **F48–F49** "Implement `ui/candleQR.js` and `api/order.php`: create a `candle_orders` row and return a VietQR image URL (bank BIN, account, amount, memo `NEN-xxxxx`). Poll or listen on the socket for the paid status."
- **F50** "Implement `api/payment_webhook.php` for PayOS (and an adapter for SePay): verify the signature, make it idempotent on the transaction id, match memo and amount, set paid, and publish to Redis `candle.lit`."
- **F51** "When `candle.lit` arrives, spawn the candle at the stand with a flicker shader and the name tag, persist it for 24 hours and restore it on join."
- **F52** "Build a public donations page listing totals per day."

**P8–P9**
- **F53–F54** "Add Redis pub/sub between realtime nodes, a shard directory, and global counters pushed every 5 seconds."
- **F55** "Write `tools/export_glb.sh` and `ktx2_convert.sh` (gltfpack and toktx). Lazy-load hall interiors."
- **F56** "Write `tests/load/k6_walkers.js`: 10,000 WebSocket bots walking random routes, reporting p95 round-trip time and messages per second."
- **F57** "Add an FPS watchdog: drop the smoke cap, pixel resolution, shadow and light count when FPS stays below 40 for 3 seconds."
- **F58–F62** "Add the mobile joystick, i18n VI and EN, a settings panel, a PHP moderation admin, and analytics events."

---

## 8. Performance budget (mid-range phone)
- Under 150 draw calls, under 400k triangles visible, under 8 real lights, smoke at most 800 particles
- First load under 25 MB (courtyard), halls loaded later
- Network: about 5 KB/s down, under 1 KB/s up

## 9. Suggested first step
Build a **P1 + P2 vertical slice**: the graybox courtyard, the pixel pipeline, day and night, smoke at the urn, and the bell sound. It proves the look in about 1–2 weeks of work, before spending on art.
