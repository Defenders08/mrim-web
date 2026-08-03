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
429: 'unknow.gif',
430: 'wacko2.gif',
431: 'wink.gif',
432: 'yahoo.gif'
    };

    const smileRegex = /<SMILE>\s*id=(\d+)\s+alt='([^']*)'\s*<\/SMILE>/gi;


    function replaceSmiles(element) {
        if (!element) return;

        const walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT
        );

        const nodes = [];

        while (walker.nextNode()) {
            if (walker.currentNode.nodeValue.includes('<SMILE>')) {
                nodes.push(walker.currentNode);
            }
        }

        nodes.forEach(node => {

            const span = document.createElement('span');

            span.innerHTML = node.nodeValue.replace(
                smileRegex,
                (match, id, alt) => {

                    const file = smileMap[id];

                    if (!file) {
                        return alt;
                    }

                    return `<img class="mrim-smile" src="${SMILE_PATH}${file}" alt="${alt}" title="${alt}" draggable="false">`;
                }
            );

            node.replaceWith(span);
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