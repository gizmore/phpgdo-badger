(() => {
    const setup = (preview) => {
        const form = preview.closest('form');
        if (!form) return;
        const update = () => {
            try {
                const read = (name) => {
                    const field = form.elements.namedItem('bb_' + name);
                    const value = field && field.value;
                    if (typeof value !== 'string' || !/^\d+$/.test(value)) throw new Error('invalid');
                    const number = BigInt(value);
                    if (number > 4294967295n) throw new Error('invalid');
                    return number;
                };
                const rate = (name) => {
                    const value = preview.dataset[name];
                    if (!/^\d+$/.test(value)) throw new Error('invalid');
                    return BigInt(value);
                };
                const xp = read('xp');
                const image = read('has_image');
                if (image > 1n) throw new Error('invalid');
                const cost = rate('badge') + (xp > 0n ? xp - 1n : 0n) * rate('xp') +
                    read('hp') * rate('hp') + read('str') * rate('str') +
                    read('int') * rate('int') + image * rate('image');
                if (cost > BigInt(preview.dataset.max)) throw new Error('invalid');
                preview.textContent = preview.dataset.label.replace('%s', cost.toString());
            } catch (_) {
                preview.textContent = preview.dataset.invalid;
            }
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
    };
    const init = () => {
        document.querySelectorAll('.badger-price-preview').forEach(setup);
        if (window.jQuery && window.GDO && window.GDO.effects && window.GDO.effects.discoFlash) {
            document.querySelectorAll('.badger-faq-heading').forEach((heading) => {
                const effects = window.GDO.effects;
                effects.discoFlash(window.jQuery(heading), heading.dataset.themeColor).then(() => {
                    if (effects.explode) effects.explode(window.jQuery(heading));
                });
            });
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
