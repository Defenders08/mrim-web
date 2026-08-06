(() => {
    'use strict';

    console.log("SMILES START");

    const SMILE_PATH = '/res/';

    const smileMap = {
        400: 'angel.gif',
401: 'bad.gif',
402: 'biggrin.gif',
403: 'blum.gif',
404: 'blush.gif',
405: 'boian.png',
406: 'cray.gif',
407: 'crazy.gif',
408: 'dance.gif',
409: 'diablo.gif',
410: 'dirol.gif',
411: 'drinks.gif',
412: 'fool.gif',
413: 'give_rose.gif',
414: 'good.gif',
415: 'kiss_mini.gif',
416: 'kut.png',
417: 'man_in_love.gif',
418: 'mocking.gif',
419: 'music.gif',
420: 'nea.gif',
421: 'pardon.gif',
422: 'rofl.gif',
423: 'rolleyes.gif',
424: 'sad.gif',
425: 'scratch_one-s_head.gif',
426: 'shok.gif',
427: 'shout.gif',
428: 'smile.gif',
429: 'unknw.gif',
430: 'wacko2.gif',
431: 'wink.gif',
432: 'yahoo.gif'
    };

    // Export smile map and path globally for the smile picker UI
    window.smileMap = smileMap;
    window.SMILE_PATH = SMILE_PATH;

    // Smile Cache Manager using localStorage and Data URLs
    const SMILE_CACHE_KEY = 'mrim_smiles_cache_v1';
    let smileCache = {};

    try {
        const stored = localStorage.getItem(SMILE_CACHE_KEY);
        if (stored) smileCache = JSON.parse(stored);
    } catch (e) {
        smileCache = {};
    }

    function saveSmileCache() {
        try {
            localStorage.setItem(SMILE_CACHE_KEY, JSON.stringify(smileCache));
        } catch (e) {
            console.warn('Smiles cache storage limit reached:', e);
        }
    }

    async function cacheSmile(id, file) {
        if (!id || !file || smileCache[id]) return;
        try {
            const resp = await fetch(`${SMILE_PATH}${file}`);
            if (!resp.ok) return;
            const blob = await resp.blob();
            const reader = new FileReader();
            reader.onloadend = () => {
                if (reader.result) {
                    smileCache[id] = reader.result;
                    saveSmileCache();
                }
            };
            reader.readAsDataURL(blob);
        } catch (e) {
            // Ignore fetch error
        }
    }

    function preloadAllSmiles() {
        Object.keys(smileMap).forEach(id => {
            if (!smileCache[id]) {
                cacheSmile(id, smileMap[id]);
            }
        });
    }

    window.getSmileSrc = function(id) {
        if (smileCache[id]) {
            return smileCache[id];
        }
        const file = smileMap[id];
        if (file) {
            cacheSmile(id, file);
            return `${SMILE_PATH}${file}`;
        }
        return '';
    };

    window.preloadAllSmiles = preloadAllSmiles;

    // Trigger preloading immediately
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        preloadAllSmiles();
    } else {
        window.addEventListener('DOMContentLoaded', preloadAllSmiles);
    }

    const smileRegex = /<SMILE>\s*id=(\d+)\s+alt='([^']*)'\s*<\/SMILE>/gi;


    function replaceSmiles(element) {
        if (!element) return;

        const walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT
        );

        const nodes = [];

        while (walker.nextNode()) {
            if (walker.currentNode.nodeValue && walker.currentNode.nodeValue.includes('<SMILE>')) {
                nodes.push(walker.currentNode);
            }
        }

        nodes.forEach(node => {
            const text = node.nodeValue;
            const container = document.createDocumentFragment();
            let lastIndex = 0;
            let match;
            smileRegex.lastIndex = 0;

            while ((match = smileRegex.exec(text)) !== null) {
                if (match.index > lastIndex) {
                    container.appendChild(document.createTextNode(text.substring(lastIndex, match.index)));
                }

                const id = match[1];
                const alt = match[2] || '';
                const file = smileMap[id];

                if (file) {
                    const img = document.createElement('img');
                    img.className = 'mrim-smile';
                    img.src = window.getSmileSrc(id);
                    img.alt = alt;
                    img.title = alt;
                    img.draggable = false;
                    container.appendChild(img);
                } else {
                    container.appendChild(document.createTextNode(alt || match[0]));
                }

                lastIndex = smileRegex.lastIndex;
            }

            if (lastIndex < text.length) {
                container.appendChild(document.createTextNode(text.substring(lastIndex)));
            }

            node.replaceWith(container);
        });
    }


    function scanChat() {
        const chat = document.getElementById('chat-history');

        if (chat) {
            replaceSmiles(chat);
        }
    }


    const observer = new MutationObserver(() => {
        scanChat();
    });


    window.addEventListener('load', () => {


        const chat = document.getElementById('chat-history');

        if (!chat) {
            console.warn("SMILES: chat-history not found");
            return;
        }

        scanChat();

        observer.observe(chat, {
            childList: true,
            subtree: true
        });

        console.log("SMILES observer started");

    });


})();