(function () {
    const EFFECT_SYMBOLS = {
        snow: ['*', '\u2744'],
        hearts: ['\u2661', '\u2764'],
        stars: ['\u2726', '\u2727', '.'],
        sakura: ['\u273F', '\u2740', '\u2741'],
        leaves: ['\u2766', '\u2739', '\u273D'],
        custom: ['\u2726'],
    };

    const CONFETTI_COLORS = ['#7B61FF', '#F39C12', '#27AE60', '#E94D7B', '#2F80ED', '#FFD166'];
    const LEAF_COLORS = ['#2f8f46', '#4fae5b', '#7abf48', '#1f6f3e', '#9bcf5a'];
    const COUNTS = { low: 24, medium: 44, high: 72 };
    const SIZE_MULTIPLIERS = { small: 0.75, medium: 1, large: 1.35 };
    const PARTICLE_EFFECTS = new Set(['snow', 'confetti', 'hearts', 'sakura', 'leaves', 'custom']);
    const STATIC_EFFECTS = new Set(['stars', 'sunrays', 'wind']);

    function splitSymbols(raw) {
        const parts = String(raw || '').split(/[\s,]+/).map(part => part.trim()).filter(Boolean);
        return parts.length ? parts.slice(0, 8) : [];
    }

    function splitEffectTokens(raw) {
        return String(raw || 'none')
            .toLowerCase()
            .split(/[+,\s]+/)
            .map(part => part.trim())
            .filter(Boolean);
    }

    function parseEffect(raw) {
        const effects = [];
        let windDirection = 'none';
        let windAffectsParticles = true;
        const windIgnoredEffects = new Set();

        const addEffect = effect => {
            if (!effect || effect === 'none' || effect === 'off' || effect === 'auto') return;
            if (!effects.includes(effect)) effects.push(effect);
        };

        splitEffectTokens(raw).forEach(token => {
            if (token === 'forest') {
                addEffect('leaves');
                addEffect('sunrays');
                addEffect('wind');
                windDirection = windDirection === 'none' ? 'right' : windDirection;
                return;
            }
            if (token === 'wind-left' || token === 'wind:left') {
                addEffect('wind');
                windDirection = 'left';
                return;
            }
            if (token === 'wind-right' || token === 'wind:right' || token === 'wind') {
                addEffect('wind');
                windDirection = 'right';
                return;
            }
            if (token === 'wind-passive' || token === 'wind-static') {
                windAffectsParticles = false;
                return;
            }
            if (token.startsWith('wind-ignore-')) {
                windIgnoredEffects.add(token.replace('wind-ignore-', ''));
                return;
            }
            if (PARTICLE_EFFECTS.has(token) || STATIC_EFFECTS.has(token)) {
                addEffect(token);
            }
        });

        if (effects.includes('wind') && windDirection === 'none') {
            windDirection = 'right';
        }

        return {
            effects,
            windDirection,
            windAffectsParticles,
            windIgnoredEffects,
        };
    }

    let activeConfig = null;
    let previewTimer = null;

    function currentConfig() {
        const body = document.body;
        const folderEffect = body.dataset.folderEffect || 'none';
        const siteEffect = body.dataset.pageEffect || 'none';
        const effect = folderEffect !== 'none' ? folderEffect : siteEffect;
        const usesFolder = folderEffect !== 'none';
        const symbols = usesFolder
            ? body.dataset.folderEffectSymbols || ''
            : body.dataset.pageEffectSymbols || '';

        return {
            effect,
            symbols: splitSymbols(symbols),
            intensity: usesFolder ? (body.dataset.folderEffectIntensity || body.dataset.pageEffectIntensity || 'medium') : (body.dataset.pageEffectIntensity || 'medium'),
            size: usesFolder ? (body.dataset.folderEffectSize || body.dataset.pageEffectSize || 'medium') : (body.dataset.pageEffectSize || 'medium'),
            layer: usesFolder ? (body.dataset.folderEffectLayer || body.dataset.pageEffectLayer || 'under') : (body.dataset.pageEffectLayer || 'under'),
        };
    }

    function targetRoot() {
        return document.querySelector('.content-view') || document.querySelector('.main-content') || document.body;
    }

    function ensureLayer(effect) {
        const root = targetRoot();
        let backgroundLayer = root.querySelector(':scope > .oc-folder-bg-layer');
        if (root.classList.contains('has-folder-background')) {
            root.classList.add('has-page-effect-layer');
            if (!backgroundLayer) {
                backgroundLayer = document.createElement('div');
                backgroundLayer.className = 'oc-folder-bg-layer';
                root.prepend(backgroundLayer);
            }
        }

        let layer = root.querySelector(':scope > .oc-page-effects');
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'oc-page-effects';
            if (backgroundLayer) {
                backgroundLayer.after(layer);
            } else {
                root.prepend(layer);
            }
        }
        layer.dataset.effect = effect;
        return layer;
    }

    function rand(min, max) {
        return Math.random() * (max - min) + min;
    }

    function particleContent(effect, symbols) {
        if (effect === 'custom' && symbols.length) {
            return symbols[Math.floor(Math.random() * symbols.length)];
        }
        const list = symbols.length && effect === 'custom' ? symbols : (EFFECT_SYMBOLS[effect] || EFFECT_SYMBOLS.custom);
        return list[Math.floor(Math.random() * list.length)];
    }

    function particleColor(effect, index) {
        if (effect === 'confetti') return CONFETTI_COLORS[index % CONFETTI_COLORS.length];
        if (effect === 'hearts') return 'rgba(233, 77, 123, 0.9)';
        if (effect === 'stars') return 'rgba(245, 250, 255, 0.98)';
        if (effect === 'sakura') return index % 3 === 0 ? 'rgba(255, 214, 232, 0.96)' : 'rgba(255, 174, 209, 0.92)';
        if (effect === 'leaves') return LEAF_COLORS[index % LEAF_COLORS.length];
        return 'rgba(255, 255, 255, 0.86)';
    }

    function layerForEffect(effect) {
        const layer = document.createElement('div');
        layer.className = 'oc-page-effect-layer';
        layer.dataset.effect = effect;
        return layer;
    }

    function renderParticles(effectLayer, effect, config, wind) {
        const baseCount = COUNTS[config.intensity] || COUNTS.medium;
        const count = effect === 'leaves' ? Math.round(baseCount * 0.75) : baseCount;
        const sizeMultiplier = SIZE_MULTIPLIERS[config.size] || SIZE_MULTIPLIERS.medium;
        const windSign = wind.direction === 'left' ? -1 : 1;
        const windActive = wind.direction !== 'none'
            && wind.affectsParticles
            && !wind.ignoredEffects?.has?.(effect);
        const fragment = document.createDocumentFragment();

        for (let i = 0; i < count; i++) {
            const particle = document.createElement('span');
            particle.className = 'oc-effect-particle';
            particle.classList.add(`oc-effect-${effect}`);
            particle.textContent = effect === 'confetti' || effect === 'leaves' ? '' : particleContent(effect, config.symbols);
            particle.style.setProperty('--x', `${rand(0, 100)}%`);
            particle.style.setProperty('--y', `${rand(2, 96)}%`);
            const maxSize = effect === 'confetti' ? 12 : (effect === 'stars' ? 24 : (effect === 'sakura' ? 22 : (effect === 'leaves' ? 28 : 20)));
            particle.style.setProperty('--size', `${rand(7, maxSize) * sizeMultiplier}px`);
            particle.style.setProperty('--duration', `${effect === 'sakura' || effect === 'leaves' ? rand(10, 22) : rand(8, 18)}s`);
            particle.style.setProperty('--delay', `${rand(-18, 0)}s`);
            particle.style.setProperty('--drift', `${effect === 'sakura' || effect === 'leaves' ? rand(-120, 110) : rand(-80, 80)}px`);
            const windDrift = windActive ? rand(120, 260) * windSign : 0;
            particle.style.setProperty('--wind-drift', `${windDrift}px`);
            particle.style.setProperty('--wind-drift-mid', `${windDrift * 0.68}px`);
            particle.style.setProperty('--wind-sway', `${windActive ? rand(16, 42) * windSign : 0}px`);
            particle.style.setProperty('--sway', `${rand(18, 70)}px`);
            particle.style.setProperty('--spin', `${effect === 'sakura' || effect === 'leaves' ? rand(240, 900) : rand(-360, 520)}deg`);
            particle.style.setProperty('--opacity', `${effect === 'leaves' ? rand(0.42, 0.84) : rand(0.36, 0.9)}`);
            particle.style.setProperty('--effect-color', particleColor(effect, i));
            fragment.appendChild(particle);
        }

        effectLayer.replaceChildren(fragment);
    }

    function renderStars(effectLayer, config) {
        const count = Math.round((COUNTS[config.intensity] || COUNTS.medium) * 0.7);
        const sizeMultiplier = SIZE_MULTIPLIERS[config.size] || SIZE_MULTIPLIERS.medium;
        const fragment = document.createDocumentFragment();
        for (let i = 0; i < count; i++) {
            const particle = document.createElement('span');
            particle.className = 'oc-effect-particle oc-effect-star';
            particle.textContent = particleContent('stars', []);
            particle.style.setProperty('--x', `${rand(0, 100)}%`);
            particle.style.setProperty('--y', `${rand(2, 96)}%`);
            particle.style.setProperty('--size', `${rand(7, 24) * sizeMultiplier}px`);
            particle.style.setProperty('--duration', `${rand(2.8, 7.2)}s`);
            particle.style.setProperty('--delay', `${rand(-7, 0)}s`);
            particle.style.setProperty('--opacity', `${rand(0.36, 0.9)}`);
            particle.style.setProperty('--effect-color', particleColor('stars', i));
            fragment.appendChild(particle);
        }
        effectLayer.replaceChildren(fragment);
    }

    function renderEffect(layer, config) {
        const parsed = parseEffect(config.effect);
        layer.replaceChildren();
        layer.dataset.effect = config.effect;
        layer.dataset.size = config.size;
        layer.dataset.intensity = config.intensity;
        layer.dataset.wind = parsed.windDirection;
        layer.dataset.windAffects = parsed.windAffectsParticles ? '1' : '0';

        const wind = {
            direction: parsed.windDirection,
            affectsParticles: parsed.windAffectsParticles,
            ignoredEffects: parsed.windIgnoredEffects,
        };

        parsed.effects.forEach(effect => {
            const effectLayer = layerForEffect(effect);
            if (effect === 'sunrays' || effect === 'wind') {
                layer.appendChild(effectLayer);
                return;
            }
            if (effect === 'stars') {
                renderStars(effectLayer, config);
            } else {
                renderParticles(effectLayer, effect, config, wind);
            }
            layer.appendChild(effectLayer);
        });
    }

    function boot() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const config = currentConfig();
        activeConfig = config;
        const parsed = parseEffect(config.effect);
        if (parsed.effects.length === 0) return;

        const layer = ensureLayer(config.effect);
        layer.classList.toggle('is-effect-over', config.layer === 'over');
        renderEffect(layer, config);
    }

    function preview(config, duration = 3200) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const effect = String(config?.effect || 'none');
        const parsed = parseEffect(effect);
        if (parsed.effects.length === 0) return;

        window.clearTimeout(previewTimer);
        const merged = {
            effect,
            symbols: splitSymbols(config?.symbols || ''),
            intensity: config?.intensity || 'medium',
            size: config?.size || 'medium',
            layer: config?.layer || 'over',
        };
        const layer = ensureLayer(merged.effect);
        layer.classList.toggle('is-effect-over', merged.layer === 'over');
        renderEffect(layer, merged);

        previewTimer = window.setTimeout(() => {
            layer.replaceChildren();
            layer.classList.remove('is-effect-over');
            if (activeConfig && parseEffect(activeConfig.effect).effects.length > 0) {
                const restoreLayer = ensureLayer(activeConfig.effect);
                restoreLayer.classList.toggle('is-effect-over', activeConfig.layer === 'over');
                renderEffect(restoreLayer, activeConfig);
            }
        }, duration);
    }

    window.OCPageEffects = { preview, parseEffect };

    document.addEventListener('DOMContentLoaded', boot);
})();
