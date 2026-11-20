// Site to Design Token Import: a Figma plugin that reads figma-variables.json (exported by the Site to Design Token
// browser extension) and creates a variable collection, text styles and shadow (effect) styles from it.
// Plain JavaScript with no build step. Importing the same site again updates what is there instead of duplicating it.
var OWN = 'com.vietnamcos.site-to-design-token';
var WEIGHT_STYLE = { 100: 'Thin', 200: 'Extra Light', 300: 'Light', 400: 'Regular', 500: 'Medium', 600: 'Semi Bold', 700: 'Bold', 800: 'Extra Bold', 900: 'Black' };
var VARIABLE_TYPE = { color: 'COLOR', number: 'FLOAT', string: 'STRING' };

/** "#1F5E4B" or "#abc" as Figma's 0..1 channels; null when it is not a hex colour. */
function hexToRgb(hex) {
  var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex).trim());
  if (!m) return null;
  var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
  var n = parseInt(h, 16);
  return { r: ((n >> 16) & 255) / 255, g: ((n >> 8) & 255) / 255, b: (n & 255) / 255 };
}

/** The nearest named weight: 450 reads as Regular, 650 as Bold. */
function weightStyle(weight) {
  var w = Math.min(900, Math.max(100, Math.round(Number(weight) / 100) * 100)) || 400;
  return WEIGHT_STYLE[w];
}

/**
 * What a file asks for, checked and flattened: { site, variables: [{ name, type, value }], textStyles, shadowStyles,
 * skipped: [reason] }. Throws when the text is not a Site to Design Token Figma export.
 */
function readFile(text) {
  var tree;
  try { tree = JSON.parse(text); } catch (e) { throw new Error('That is not a JSON file.'); }
  if (!tree || typeof tree !== 'object' || Array.isArray(tree)) throw new Error('That is not a Site to Design Token export.');
  var own = (tree.$extensions && tree.$extensions[OWN]) || {};
  var plan = { site: typeof own.site === 'string' ? own.site : '', variables: [], textStyles: [], shadowStyles: [], skipped: [] };

  (function walk(node, path) {
    Object.keys(node).forEach(function (key) {
      if (key.charAt(0) === '$') return;
      var child = node[key];
      if (!child || typeof child !== 'object') return;
      var name = path ? path + '/' + key : key;
      if (!('$value' in child)) return walk(child, name);
      var type = VARIABLE_TYPE[child.$type];
      var value = child.$value;
      if (type === 'COLOR') {
        var rgb = hexToRgb(value);
        if (!rgb) return plan.skipped.push(name + ': not a hex colour');
        value = { r: rgb.r, g: rgb.g, b: rgb.b, a: 1 };
      } else if (type === 'FLOAT') {
        if (typeof value !== 'number' || !isFinite(value)) return plan.skipped.push(name + ': not a number');
      } else if (type === 'STRING') {
        if (typeof value !== 'string') return plan.skipped.push(name + ': not text');
      } else return plan.skipped.push(name + ': unknown type');
      plan.variables.push({ name: name, type: type, value: value });
    });
  })(tree, '');

  (Array.isArray(own.textStyles) ? own.textStyles : []).forEach(function (s) {
    if (!s || typeof s.name !== 'string' || typeof s.fontFamily !== 'string' || !(s.fontSize > 0)) return;
    plan.textStyles.push({ name: s.name, fontFamily: s.fontFamily, fontWeight: Number(s.fontWeight) || 400, fontSize: s.fontSize, lineHeight: s.lineHeight > 0 ? s.lineHeight : null });
  });
  (Array.isArray(own.shadowStyles) ? own.shadowStyles : []).forEach(function (s) {
    if (!s || typeof s.name !== 'string' || !Array.isArray(s.layers)) return;
    var layers = [];
    for (var i = 0; i < s.layers.length; i++) {
      var l = s.layers[i];
      var rgb = l && hexToRgb(l.color);
      if (!rgb) return plan.skipped.push('shadow/' + s.name + ': not a hex colour');
      layers.push({ r: rgb.r, g: rgb.g, b: rgb.b, a: typeof l.opacity === 'number' ? l.opacity : 1, x: Number(l.offsetX) || 0, y: Number(l.offsetY) || 0, blur: Math.max(0, Number(l.blur) || 0), spread: Number(l.spread) || 0 });
    }
    if (layers.length) plan.shadowStyles.push({ name: s.name, layers: layers });
  });

  if (!plan.variables.length && !plan.textStyles.length && !plan.shadowStyles.length) throw new Error('That file has no tokens in it. Export "Figma" from Site to Design Token and choose that file.');
  return plan;
}

/** Loads the site's font at the weight asked for, then at Regular, then Inter. Returns the font used. */
async function loadFont(figma, family, weight) {
  var tries = [{ family: family, style: weightStyle(weight) }, { family: family, style: 'Regular' }, { family: 'Inter', style: weightStyle(weight) }, { family: 'Inter', style: 'Regular' }];
  for (var i = 0; i < tries.length; i++) {
    try { await figma.loadFontAsync(tries[i]); return tries[i]; } catch (e) { /* not installed in this Figma: try the next */ }
  }
  return null;
}

/** Creates or updates everything in the plan. Returns counts and notes for the user. */
async function apply(figma, plan) {
  var result = { collection: 'Site to Design Token' + (plan.site ? ' · ' + plan.site : ''), variables: 0, textStyles: 0, shadowStyles: 0, notes: plan.skipped.slice() };

  if (plan.variables.length) {
    var collections = await figma.variables.getLocalVariableCollectionsAsync();
    var collection = collections.filter(function (c) { return c.name === result.collection; })[0];
    if (!collection) {
      collection = figma.variables.createVariableCollection(result.collection);
      collection.renameMode(collection.modes[0].modeId, 'Light');
    }
    var modeId = collection.modes[0].modeId;
    var byName = {};
    (await figma.variables.getLocalVariablesAsync()).forEach(function (v) { if (v.variableCollectionId === collection.id) byName[v.name] = v; });
    plan.variables.forEach(function (want) {
      var variable = byName[want.name];
      if (variable && variable.resolvedType !== want.type) return result.notes.push(want.name + ': already exists with another type, left as it is');
      if (!variable) variable = figma.variables.createVariable(want.name, collection, want.type);
      variable.setValueForMode(modeId, want.value);
      result.variables++;
    });
  }

  if (plan.textStyles.length) {
    var textStyles = await figma.getLocalTextStylesAsync();
    for (var i = 0; i < plan.textStyles.length; i++) {
      var t = plan.textStyles[i];
      var font = await loadFont(figma, t.fontFamily, t.fontWeight);
      if (!font) { result.notes.push('text/' + t.name + ': no font could be loaded'); continue; }
      if (font.family !== t.fontFamily) result.notes.push('text/' + t.name + ': ' + t.fontFamily + ' is not available in this Figma, used ' + font.family);
      var name = 'text/' + t.name;
      var style = textStyles.filter(function (s) { return s.name === name; })[0] || figma.createTextStyle();
      style.name = name;
      style.fontName = font;
      style.fontSize = t.fontSize;
      style.lineHeight = t.lineHeight ? { unit: 'PIXELS', value: t.lineHeight } : { unit: 'AUTO' };
      result.textStyles++;
    }
  }

  if (plan.shadowStyles.length) {
    var effectStyles = await figma.getLocalEffectStylesAsync();
    plan.shadowStyles.forEach(function (s) {
      var name = 'shadow/' + s.name;
      var style = effectStyles.filter(function (e) { return e.name === name; })[0] || figma.createEffectStyle();
      style.name = name;
      style.effects = s.layers.map(function (l) {
        return { type: 'DROP_SHADOW', color: { r: l.r, g: l.g, b: l.b, a: l.a }, offset: { x: l.x, y: l.y }, radius: l.blur, spread: l.spread, visible: true, blendMode: 'NORMAL' };
      });
      result.shadowStyles++;
    });
  }
  return result;
}

if (typeof figma !== 'undefined') {
  figma.showUI(__html__, { width: 360, height: 440, themeColors: true });
  figma.ui.onmessage = function (msg) {
    if (!msg || msg.type !== 'import') return;
    var plan;
    try { plan = readFile(String(msg.text)); } catch (e) { return figma.ui.postMessage({ type: 'error', message: e.message }); }
    apply(figma, plan).then(
      function (result) { figma.ui.postMessage({ type: 'done', result: result }); },
      function (e) { figma.ui.postMessage({ type: 'error', message: 'Figma refused the import: ' + (e && e.message ? e.message : e) }); }
    );
  };
}
if (typeof module !== 'undefined') module.exports = { hexToRgb: hexToRgb, weightStyle: weightStyle, readFile: readFile, apply: apply };
