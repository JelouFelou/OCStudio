(function () {
    const EFFECT_SYMBOLS = {
        snow: ['*', '\u2744'],
        hearts: ['\u2661', '\u2764'],
        stars: ['\u2726', '\u2727', '.'],
        sakura: ['\u273F', '\u2740', '\u2741'],
        custom: ['\u2726'],
    };

    const CONFETTI_COLORS = ['#7B61FF', '#F39C12', '#27AE60', '#E94D7B', '#2F80ED', '#FFD166'];
    const COUNTS = { low: 24, medium: 44, high: 72 };
    const SIZE_MULTIPLIERS = { small: 0.75, medium: 1, large: 1.35 };

    function splitSymbols(raw) {
        const parts = String(raw || '').split(/[\s,]+/).map(part => part.trim()).filter(Boolean);
        return parts.length ? parts.slice(0, 8) : [];
    }

    let activeConfig = null;
    let previewTimer = null;

    function currentConfig() {
        const body = document.body;
        const folderEffect = body.dataset.folderEffect || 'none';
        const siteEffect = body.dataset.pageEffect || 'none';
        const effect = folderEffect !== 'none' ? folderEffect : siteEffect;
        const usesFolder = folderEffect !== 'none';
        const symbols = folderEffect !== 'none'
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
        const list = symbols.length ? symbols : (EFFECT_SYMBOLS[effect] || EFFECT_SYMBOLS.custom);
        return list[Math.floor(Math.random() * list.length)];
    }

    function particleColor(effect, index) {
        if (effect === 'confetti') return CONFETTI_COLORS[index % CONFETTI_COLORS.length];
        if (effect === 'hearts') return 'rgba(233, 77, 123, 0.9)';
        if (effect === 'stars') return 'rgba(245, 250, 255, 0.98)';
        if (effect === 'sakura') return index % 3 === 0 ? 'rgba(255, 214, 232, 0.96)' : 'rgba(255, 174, 209, 0.92)';
        return 'rgba(255, 255, 255, 0.86)';
    }

    function renderParticles(layer, config) {
        const count = COUNTS[config.intensity] || COUNTS.medium;
        const sizeMultiplier = SIZE_MULTIPLIERS[config.size] || SIZE_MULTIPLIERS.medium;
        const fragment = document.createDocumentFragment();

        for (let i = 0; i < count; i++) {
            const particle = document.createElement('span');
            particle.className = 'oc-effect-particle';
            if (config.effect === 'stars') {
                particle.classList.add('oc-effect-star');
            } else if (config.effect === 'sakura') {
                particle.classList.add('oc-effect-sakura');
            }
            particle.textContent = config.effect === 'confetti' ? '' : particleContent(config.effect, config.symbols);
            particle.style.setProperty('--x', `${rand(0, 100)}%`);
            particle.style.setProperty('--y', `${rand(2, 96)}%`);
            const maxSize = config.effect === 'confetti' ? 12 : (config.effect === 'stars' ? 24 : (config.effect === 'sakura' ? 22 : 20));
            particle.style.setProperty('--size', `${rand(7, maxSize) * sizeMultiplier}px`);
            particle.style.setProperty('--duration', `${config.effect === 'stars' ? rand(2.8, 7.2) : (config.effect === 'sakura' ? rand(10, 22) : rand(8, 18))}s`);
            particle.style.setProperty('--delay', `${config.effect === 'stars' ? rand(-7, 0) : rand(-18, 0)}s`);
            particle.style.setProperty('--drift', `${config.effect === 'sakura' ? rand(-150, 110) : rand(-80, 80)}px`);
            particle.style.setProperty('--sway', `${rand(18, 70)}px`);
            particle.style.setProperty('--spin', `${config.effect === 'sakura' ? rand(240, 900) : rand(-360, 520)}deg`);
            particle.style.setProperty('--opacity', `${rand(0.36, 0.9)}`);
            particle.style.setProperty('--effect-color', particleColor(config.effect, i));
            fragment.appendChild(particle);
        }

        layer.replaceChildren(fragment);
    }

    function boot() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const config = currentConfig();
        activeConfig = config;
        if (!config.effect || config.effect === 'none' || config.effect === 'off') return;

        const layer = ensureLayer(config.effect);
        layer.classList.toggle('is-effect-over', config.layer === 'over');
        layer.dataset.size = config.size;
        layer.dataset.intensity = config.intensity;
        if (config.effect === 'sunrays') {
            layer.replaceChildren();
            return;
        }

        renderParticles(layer, config);
    }

    function preview(config, duration = 3200) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const effect = String(config?.effect || 'none');
        if (!effect || effect === 'none' || effect === 'off') return;

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
        layer.dataset.effect = merged.effect;
        layer.dataset.size = merged.size;
        layer.dataset.intensity = merged.intensity;

        if (merged.effect === 'sunrays') {
            layer.replaceChildren();
        } else {
            renderParticles(layer, merged);
        }

        previewTimer = window.setTimeout(() => {
            layer.replaceChildren();
            layer.classList.remove('is-effect-over');
            if (activeConfig && activeConfig.effect && activeConfig.effect !== 'none' && activeConfig.effect !== 'off') {
                const restoreLayer = ensureLayer(activeConfig.effect);
                restoreLayer.classList.toggle('is-effect-over', activeConfig.layer === 'over');
                restoreLayer.dataset.effect = activeConfig.effect;
                if (activeConfig.effect === 'sunrays') {
                    restoreLayer.replaceChildren();
                } else {
                    renderParticles(restoreLayer, activeConfig);
                }
            }
        }, duration);
    }

    window.OCPageEffects = { preview };

    document.addEventListener('DOMContentLoaded', boot);
})();
