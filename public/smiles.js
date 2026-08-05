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
                    img.src = `${SMILE_PATH}${file}`;
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