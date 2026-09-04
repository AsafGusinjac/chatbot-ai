/**
 * dstore chat widget — vanilla JS, no dependencies, no build step.
 *
 * Usage:
 *   <link rel="stylesheet" href="/public/widget.css">
 *   <script src="/public/widget.js" data-endpoint="/endpoint/chat.php" defer></script>
 *
 * Security note: every piece of server or user text is inserted with
 * textContent, never innerHTML. Chat content is untrusted by definition —
 * innerHTML here would be an XSS hole on every page the widget appears on.
 */
(function () {
    'use strict';

    // ---- Text. Translate these to Bosnian before going live. ----------------
    var TEXT = {
        title:        'Dstore AI asistent',
        launcherAria: 'Open chat',
        closeAria:    'Close chat',
        resetLabel:   'New chat',
        resetAria:    'Start a new conversation',
        minimizeAria: 'Minimize chat',
        placeholder:  'Ask a question…',
        send:         'Send',
        greeting:     'Hi! I can help with questions about dstore — delivery, returns, payment and more. What can I do for you?',
        networkError: 'Could not reach the assistant. Please check your connection and try again.',
        genericError: 'Something went wrong. Please try again.',
        feedbackTitle: 'How would you rate this conversation?',
        feedbackPlaceholder: 'What was good, or what problem did you notice?',
        feedbackSubmit: 'Send rating',
        feedbackSkip: 'Skip'
    };

    // The real D-Store wordmark, used as a small avatar next to every bot
    // reply so it is obvious the message came from the store's assistant,
    // not the visitor. Static, developer-provided markup — same trust level
    // as the launcher icon below, never touches user input.
    var LOGO_SVG = '<svg viewBox="0 0 422 165" aria-hidden="true">'
        + '<path d="M360.04,74.53c.63-5.62,2.29-9.96,5.02-13.04,2.71-3.07,6.4-4.61,11.04-4.61,2.85,0,5.28.46,7.29,1.41,2,.94,3.67,2.21,5.02,3.81,1.33,1.6,2.34,3.48,3,5.61.67,2.15,1.09,4.42,1.28,6.82h-32.65ZM404.79,66.11c-1.2-4.28-3.03-8.02-5.48-11.24-2.45-3.22-5.54-5.76-9.24-7.63-3.7-1.87-8.04-2.81-13.03-2.81-4.55,0-8.72.8-12.51,2.41-3.8,1.59-7.04,3.94-9.71,7.02-2.68,3.07-4.77,6.89-6.29,11.44-1.52,4.55-2.27,9.81-2.27,15.78s.8,10.98,2.4,15.52c1.61,4.55,3.79,8.39,6.56,11.51,2.76,3.12,6,5.48,9.7,7.09,3.71,1.61,7.6,2.41,11.71,2.41,5.35,0,9.95-.8,13.79-2.41,3.83-1.61,7.31-3.88,10.43-6.82l-8.16-8.83c-2.32,1.78-4.71,3.17-7.16,4.15-2.45.98-5.15,1.47-8.09,1.47-2.14,0-4.19-.38-6.15-1.13-1.96-.77-3.75-1.92-5.36-3.48-1.6-1.56-2.93-3.55-4.01-5.95-1.07-2.42-1.79-5.31-2.14-8.7h46.42c.09-.71.18-1.63.27-2.74.09-1.11.14-2.3.14-3.55,0-4.73-.6-9.23-1.81-13.52ZM338.9,47.37c-1.43-.97-3.06-1.71-4.88-2.2-1.84-.49-4.17-.74-7.03-.74-3.48,0-6.63.78-9.43,2.34-2.81,1.57-5.02,3.5-6.62,5.82v-6.69h-13.92v70.24h13.92v-40.14c0-5.97,1.21-10.48,3.62-13.51s5.79-4.56,10.17-4.56c2.31,0,4.29.22,5.88.68,1.61.45,3.16,1.07,4.68,1.87l3.61-13.12ZM264.91,90.19c-.81,2.85-1.96,5.34-3.48,7.42-1.52,2.1-3.35,3.78-5.48,5.02-2.15,1.25-4.51,1.87-7.1,1.87-5.27,0-9.5-2.03-12.71-6.09-3.22-4.06-4.83-9.96-4.83-17.72,0-6.96,1.5-12.56,4.49-16.79,2.99-4.24,7.11-6.36,12.38-6.36,5.62,0,10.01,2.12,13.17,6.36,3.17,4.23,4.76,9.96,4.76,17.18,0,3.22-.41,6.25-1.21,9.1ZM277.68,65.77c-1.56-4.5-3.74-8.31-6.55-11.44-2.81-3.12-6.14-5.55-9.97-7.28-3.83-1.75-7.98-2.62-12.44-2.62s-8.59.82-12.37,2.47c-3.79,1.65-7.09,4.06-9.91,7.23-2.81,3.16-5.01,7.02-6.62,11.57-1.6,4.55-2.41,9.67-2.41,15.38s.78,10.53,2.34,14.99c1.56,4.46,3.72,8.3,6.49,11.5,2.77,3.22,6.02,5.7,9.77,7.43,3.74,1.74,7.84,2.62,12.3,2.62s8.74-.83,12.58-2.48c3.83-1.65,7.18-4.06,10.03-7.22,2.86-3.16,5.09-7.04,6.69-11.64,1.6-4.6,2.41-9.79,2.41-15.59,0-5.44-.78-10.41-2.35-14.92ZM204.44,102.23c-1.26.67-2.81,1.29-4.69,1.85-1.88.55-3.71.82-5.49.82-2.59,0-4.43-.57-5.55-1.73-1.12-1.15-1.67-3.36-1.67-6.65v-37.91h17.4v-12.71h-17.4v-27.3l-13.52,7.23v20.07h-10.03v12.71h10.03v40.45c0,6.31,1.41,10.99,4.22,14.01,2.8,3.03,6.66,4.55,11.57,4.55,2.77,0,5.29-.29,7.56-.87,2.27-.59,4.26-1.36,5.96-2.35l1.6-12.16ZM151.45,88.45c-.98-2.32-2.47-4.35-4.48-6.09-2-1.74-4.48-3.3-7.42-4.69-2.95-1.37-6.38-2.82-10.3-4.34-2.68-1.07-4.84-2.03-6.49-2.87-1.64-.85-2.95-1.68-3.94-2.47-.99-.81-1.66-1.59-2.01-2.35-.35-.75-.53-1.58-.53-2.47,0-1.88.76-3.42,2.27-4.62,1.51-1.21,3.97-1.8,7.36-1.8s6.55.49,9.5,1.48c2.94.98,5.84,2.49,8.69,4.54l6.02-10.57c-3.12-2.32-6.76-4.19-10.91-5.62-4.14-1.43-8.44-2.14-12.91-2.14-3.48,0-6.69.41-9.64,1.21-2.94.8-5.49,2.01-7.63,3.62-2.14,1.6-3.79,3.62-4.95,6.02-1.17,2.41-1.74,5.17-1.74,8.29,0,2.77.35,5.2,1.07,7.29.71,2.09,1.89,3.99,3.55,5.69,1.64,1.69,3.78,3.27,6.42,4.75,2.63,1.46,5.81,2.92,9.56,4.35,5.09,1.97,9.07,3.75,11.98,5.34,2.9,1.62,4.34,3.75,4.34,6.43,0,5.17-4.28,7.76-12.85,7.76-3.3,0-6.71-.57-10.23-1.74-3.52-1.16-6.94-2.9-10.23-5.22l-6.29,10.44c3.57,2.59,7.73,4.73,12.5,6.43,4.77,1.69,9.57,2.55,14.38,2.55,3.48,0,6.79-.36,9.97-1.06,3.17-.73,5.96-1.89,8.37-3.49,2.41-1.6,4.34-3.73,5.82-6.36,1.46-2.63,2.2-5.92,2.2-9.84,0-3.3-.49-6.1-1.48-8.42Z" fill="#f26529"/>'
        + '<path d="M60.89,96.34c-1.78,2.31-4.05,4.39-6.8,6.22-2.76,1.83-5.77,2.74-9.07,2.74-5.05,0-8.94-2.01-11.66-6.02-2.7-4.01-4.06-10.48-4.06-19.39,0-7.5,1.29-13.1,3.87-16.8,2.58-3.7,6.34-5.55,11.32-5.55,1.69,0,3.35.29,4.99.87,1.64.59,3.18,1.33,4.6,2.21,1.42.89,2.71,1.87,3.86,2.94,1.15,1.07,2.13,2.14,2.94,3.21v29.57ZM74.4,116.54V19l-13.65,7.09v26.76c-1.96-2.14-4.51-4.01-7.63-5.62-3.13-1.6-6.48-2.41-10.04-2.41s-7.16.72-10.51,2.14c-3.34,1.44-6.29,3.6-8.84,6.55-2.53,2.95-4.56,6.65-6.08,11.11-1.52,4.45-2.27,9.69-2.27,15.65,0,6.69.69,12.43,2.07,17.2,1.39,4.78,3.3,8.68,5.76,11.71,2.46,3.03,5.32,5.27,8.63,6.69,3.3,1.43,6.82,2.15,10.57,2.15s7.09-.79,10.03-2.35c2.94-1.56,5.71-3.5,8.3-5.81v6.69h13.65Z" fill="#f26529"/>'
        + '<polyline points="86.25 8.94 90.26 8.94 90.26 156.06 86.25 156.06 86.25 8.94" fill="#f26529" fill-rule="evenodd"/>'
        + '<g>'
        + '<path d="M108.08,153.81c-1.09,0-2.05-.13-2.89-.39-.84-.25-1.57-.66-2.19-1.21-.6-.54-1.08-1.21-1.4-2.02-.33-.81-.49-1.76-.49-2.84,0-1.61.26-3.12.76-4.51.51-1.4,1.21-2.62,2.14-3.68.9-1.03,2-1.84,3.31-2.44,1.32-.6,2.73-.9,4.25-.9,1,0,1.95.14,2.84.42.9.28,1.67.61,2.35.99l-.63,3.13h-.16c-.21-.18-.46-.4-.77-.64-.31-.25-.67-.48-1.09-.69-.42-.22-.89-.41-1.4-.56-.51-.15-1.1-.22-1.76-.22-1.99,0-3.61.84-4.87,2.52-1.26,1.68-1.88,3.72-1.88,6.13,0,1.44.37,2.55,1.12,3.33.74.78,1.8,1.17,3.17,1.17.65,0,1.29-.09,1.94-.27.65-.18,1.2-.38,1.65-.6.5-.23.97-.48,1.4-.75.44-.26.75-.46.94-.59h.16l-.61,3.16c-.89.4-1.84.75-2.83,1.04-1,.29-2.01.44-3.04.44Z" fill="#f26529"/>'
        + '<path d="M129.78,153.82c-2.38,0-4.25-.56-5.6-1.68-1.36-1.12-2.03-2.76-2.03-4.92,0-3.15.99-5.85,2.98-8.1,1.99-2.25,4.45-3.38,7.4-3.38,1.94,0,3.43.49,4.47,1.46,1.04.97,1.56,2.33,1.56,4.09,0,.3-.05.8-.14,1.46-.08.67-.23,1.44-.42,2.33h-12.68c-.06.3-.1.59-.13.88-.03.29-.04.55-.04.8,0,1.45.45,2.59,1.32,3.42.89.81,2.14,1.23,3.76,1.23,1.13,0,2.29-.22,3.49-.66,1.2-.44,2.2-.94,3.01-1.49h.17l-.63,3.12c-.51.19-.97.36-1.38.51-.42.15-.93.31-1.57.47-.64.15-1.2.27-1.69.35-.49.08-1.1.12-1.85.12ZM135.56,142.95c.05-.3.08-.55.11-.76.02-.2.03-.43.03-.68,0-1.1-.3-1.95-.9-2.57-.59-.62-1.56-.93-2.88-.93-1.46,0-2.76.45-3.88,1.35-1.13.91-1.9,2.1-2.3,3.58h9.83Z" fill="#f26529"/>'
        + '<path d="M163.07,140.15c0,.24-.02.59-.07,1-.04.42-.11.79-.19,1.11l-2.58,11.16h-2.88l2.25-9.78c.12-.55.21-1.02.28-1.43.07-.4.1-.8.1-1.21,0-.86-.21-1.51-.64-1.94-.43-.44-1.2-.65-2.31-.65-.78,0-1.63.21-2.58.64-.94.43-1.86.95-2.75,1.55l-2.97,12.83h-2.89l3.98-17.2h2.89l-.44,1.9c1.1-.77,2.13-1.36,3.08-1.77.96-.41,1.95-.62,2.96-.62,1.5,0,2.67.38,3.5,1.14.84.75,1.26,1.84,1.26,3.27Z" fill="#f26529"/>'
        + '<path d="M185.05,136.22l-.53,2.34h-5.98l-1.84,7.94c-.1.41-.2.87-.27,1.38-.08.52-.13.93-.13,1.24,0,.74.19,1.31.57,1.67.38.36,1.09.55,2.12.55.42,0,.92-.08,1.5-.21.58-.15.97-.26,1.17-.34h.15l-.54,2.48c-.57.15-1.19.26-1.84.35-.66.1-1.23.14-1.73.14-1.42,0-2.51-.3-3.29-.93-.78-.62-1.16-1.6-1.16-2.95,0-.33.02-.65.07-.98.05-.32.11-.69.19-1.1l2.14-9.23h-1.95l.53-2.34h1.97l1.15-4.95h2.91l-1.15,4.95h5.96Z" fill="#f26529"/>'
        + '<path d="M200.92,151.59c-.28.17-.65.4-1.13.7-.48.31-.98.56-1.46.78-.52.24-1.11.44-1.76.59-.65.16-1.4.24-2.24.24-1.36,0-2.47-.4-3.33-1.19-.85-.8-1.27-1.84-1.27-3.12,0-1.37.29-2.52.9-3.46.6-.94,1.49-1.71,2.67-2.3,1.16-.58,2.56-1.01,4.2-1.27,1.64-.27,3.5-.42,5.59-.49.06-.28.13-.53.16-.74.04-.21.06-.43.06-.66,0-.5-.1-.89-.29-1.2-.2-.3-.47-.55-.83-.74-.37-.18-.78-.3-1.26-.37-.48-.07-1.01-.1-1.58-.1-.87,0-1.87.15-2.99.43-1.12.28-2.03.57-2.73.84h-.15l.57-2.9c.58-.15,1.45-.33,2.57-.53,1.13-.19,2.21-.29,3.25-.29,2.14,0,3.73.34,4.8,1.01,1.07.66,1.61,1.72,1.61,3.17,0,.28-.03.57-.07.89-.05.31-.1.61-.17.88l-2.68,11.67h-2.88l.43-1.83ZM202.6,144.36c-1.59.06-2.99.18-4.22.36-1.22.18-2.24.44-3.04.78-.83.34-1.46.8-1.9,1.38-.43.58-.65,1.31-.65,2.16,0,.74.25,1.3.77,1.7.5.39,1.28.58,2.34.58.93,0,1.88-.21,2.85-.62.97-.41,1.87-.92,2.71-1.5l1.14-4.84Z" fill="#f26529"/>'
        + '<path d="M228.09,139.27h-.14c-.42-.1-.8-.18-1.15-.22-.35-.05-.78-.07-1.29-.07-.97,0-1.92.22-2.87.65-.95.44-1.86.97-2.73,1.57l-2.79,12.22h-2.93l3.93-17.2h2.92l-.58,2.54c1.37-.95,2.52-1.61,3.48-1.99.95-.37,1.87-.55,2.73-.55.5,0,.87.01,1.09.03.23.03.56.07.99.15l-.66,2.87Z" fill="#f26529"/>'
        + '<path d="M262.87,136.22l-.52,2.34h-5.98l-1.85,7.94c-.1.41-.19.87-.27,1.38-.08.52-.13.93-.13,1.24,0,.74.2,1.31.57,1.67.38.36,1.08.55,2.12.55.42,0,.92-.08,1.49-.21.59-.15.98-.26,1.18-.34h.16l-.55,2.48c-.57.15-1.19.26-1.84.35-.65.1-1.23.14-1.74.14-1.42,0-2.51-.3-3.29-.93-.78-.62-1.16-1.6-1.16-2.95,0-.33.02-.65.07-.98.05-.32.11-.69.2-1.1l2.14-9.23h-1.96l.53-2.34h1.96l1.15-4.95h2.91l-1.16,4.95h5.97Z" fill="#f26529"/>'
        + '<path d="M275.98,153.82c-2.37,0-4.24-.56-5.6-1.68-1.35-1.12-2.02-2.76-2.02-4.92,0-3.15,1-5.85,2.98-8.1,1.99-2.25,4.45-3.38,7.38-3.38,1.96,0,3.44.49,4.48,1.46,1.04.97,1.56,2.33,1.56,4.09,0,.3-.05.8-.13,1.46-.09.67-.23,1.44-.43,2.33h-12.68c-.06.3-.11.59-.13.88-.02.29-.04.55-.04.8,0,1.45.44,2.59,1.32,3.42.88.81,2.13,1.23,3.76,1.23,1.13,0,2.3-.22,3.49-.66,1.2-.44,2.2-.94,3.01-1.49h.17l-.63,3.12c-.51.19-.97.36-1.38.51-.41.15-.94.31-1.57.47-.64.15-1.2.27-1.7.35-.49.08-1.11.12-1.85.12ZM281.77,142.95c.06-.3.09-.55.11-.76.02-.2.03-.43.03-.68,0-1.1-.3-1.95-.9-2.57-.6-.62-1.57-.93-2.89-.93-1.45,0-2.75.45-3.89,1.35-1.13.91-1.88,2.1-2.29,3.58h9.82Z" fill="#f26529"/>'
        + '<path d="M309.24,140.15c0,.24-.02.59-.08,1-.03.42-.1.79-.18,1.11l-2.59,11.16h-2.88l2.26-9.78c.13-.55.22-1.02.28-1.43.06-.4.1-.8.1-1.21,0-.86-.21-1.51-.64-1.94-.42-.44-1.2-.65-2.31-.65-.78,0-1.64.21-2.59.64-.94.43-1.86.95-2.76,1.55l-2.96,12.82h-2.89l5.52-23.96h2.89l-2.01,8.67c1.1-.78,2.13-1.36,3.1-1.77.96-.41,1.95-.62,2.97-.62,1.5,0,2.67.38,3.5,1.14.84.75,1.26,1.84,1.26,3.27Z" fill="#f26529"/>'
        + '<path d="M334.51,140.15c0,.24-.02.59-.07,1-.04.42-.11.79-.19,1.11l-2.58,11.16h-2.88l2.25-9.78c.12-.55.21-1.02.28-1.43.07-.4.1-.8.1-1.21,0-.86-.21-1.51-.64-1.94-.43-.44-1.2-.65-2.31-.65-.78,0-1.64.21-2.59.64-.94.43-1.86.95-2.75,1.55l-2.96,12.83h-2.9l3.98-17.2h2.89l-.44,1.9c1.1-.77,2.13-1.36,3.09-1.77.95-.41,1.94-.62,2.96-.62,1.5,0,2.67.38,3.51,1.14.84.75,1.26,1.84,1.26,3.27Z" fill="#f26529"/>'
        + '<path d="M349.42,136.22l-3.98,17.2h-2.9l3.98-17.2h2.9ZM350.99,130.33l-.7,3h-3.28l.69-3h3.28Z" fill="#f26529"/>'
        + '<path d="M373.03,153.42h-3.5l-5.51-7.56-2.49,2.02-1.27,5.54h-2.92l5.54-23.96h2.93l-3.56,15.39,10.06-8.62h3.82l-9.81,8.13,6.71,9.06Z" fill="#f26529"/>'
        + '<path d="M388.52,153.82c-2.38,0-4.25-.56-5.6-1.68-1.35-1.12-2.03-2.76-2.03-4.92,0-3.15,1-5.85,2.99-8.1,1.99-2.25,4.45-3.38,7.38-3.38,1.95,0,3.44.49,4.49,1.46,1.03.97,1.55,2.33,1.55,4.09,0,.3-.04.8-.13,1.46-.08.67-.23,1.44-.42,2.33h-12.69c-.06.3-.1.59-.13.88-.02.29-.04.55-.04.8,0,1.45.44,2.59,1.33,3.42.88.81,2.13,1.23,3.76,1.23,1.12,0,2.29-.22,3.49-.66,1.2-.44,2.2-.94,3.01-1.49h.17l-.64,3.12c-.51.19-.97.36-1.38.51-.41.15-.93.31-1.57.47-.64.15-1.2.27-1.69.35-.49.08-1.11.12-1.85.12ZM394.31,142.95c.05-.3.09-.55.11-.76.02-.2.03-.43.03-.68,0-1.1-.3-1.95-.91-2.57-.6-.62-1.56-.93-2.89-.93-1.46,0-2.75.45-3.88,1.35-1.13.91-1.9,2.1-2.29,3.58h9.83Z" fill="#f26529"/>'
        + '</g>'
        + '</svg>';

    // Header icon buttons — plain geometric glyphs, static/trusted markup.
    // Speech-bubble-with-tail outline plus a "+" inside, for "new chat" -
    // reads the same silhouette as a chat/message icon while still signalling
    // "start a new one".
    var ICON_RESET = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        + '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'
        + '<path d="M11 8v5M8.5 10.5h5"/>'
        + '</svg>';
    var ICON_EXPAND = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm16 0v6h-6v-2h4v-4h2z"/></svg>';
    var ICON_COLLAPSE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h2v6H5V7h4V3zm6 0h2v4h4v2h-6V3zM5 15h6v6H9v-4H5v-2zm10 6v-6h6v2h-4v4h-2z"/></svg>';
    var ICON_MINIMIZE = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path></svg>';

    // Launcher icon swaps between the chat bubble (closed) and an X
    // (open) - the standard pattern for a chat widget toggle button.
    var ICON_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>';
    var ICON_CLOSE_X = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.3 5.71 12 12.01l-6.3-6.3-1.41 1.41 6.3 6.3-6.3 6.3 1.41 1.41 6.3-6.3 6.3 6.3 1.41-1.41-6.3-6.3 6.3-6.3z"/></svg>';

    var script   = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');
        return all[all.length - 1];
    })();

    var ENDPOINT = (script && script.getAttribute('data-endpoint')) || '/endpoint/chat.php';
    var CURRENCY = (script && script.getAttribute('data-currency')) || 'KM';
    // URL of a logo image, used as the message avatar instead of the
    // built-in D-Store wordmark. Empty string = use the built-in one.
    var LOGO_URL = (script && script.getAttribute('data-logo')) || '';
    var THEME = normalizeTheme(script && script.getAttribute('data-theme'));
    var MAX_LEN  = 1500;

    function normalizeTheme(theme) {
        theme = String(theme || '').toLowerCase();
        return theme === 'light' ? 'light' : 'dark';
    }

    function setTheme(theme) {
        THEME = normalizeTheme(theme);
        if (typeof els !== 'undefined' && els.root) {
            els.root.setAttribute('data-theme', THEME);
        }
    }

    // Per-visitor identity pushed by the site via DstoreChat('identify', {...})
    // - see embed.js's docblock for the full command-queue pattern (this file
    // mirrors it). Sent along with every request from then on.
    var visitorInfo = {
        customerId: '',
        customerName: '',
        isWholesaleCustomer: false
    };

    function applyIdentify(payload) {
        if (typeof payload.customerId === 'string') {
            visitorInfo.customerId = payload.customerId;
        }
        if (typeof payload.customerName === 'string') {
            visitorInfo.customerName = payload.customerName;
        }
        if (typeof payload.isWholesaleCustomer === 'boolean') {
            visitorInfo.isWholesaleCustomer = payload.isWholesaleCustomer;
        }
        if (payload.logo) {
            // addMessage() reads LOGO_URL fresh on every render, so this
            // takes effect on the next message with no further work.
            LOGO_URL = payload.logo;
        }
        if (payload.theme) {
            setTheme(payload.theme);
        }
    }

    (function bootDstoreChat() {
        var queued = (window.DstoreChat && window.DstoreChat.q) || [];
        window.DstoreChat = function (cmd, payload) {
            if (cmd === 'identify') {
                applyIdentify(payload || {});
            } else if (cmd === 'theme' || cmd === 'setTheme') {
                setTheme(payload);
            }
        };
        for (var i = 0; i < queued.length; i++) {
            window.DstoreChat.apply(null, queued[i]);
        }
    })();

    var els = {};
    var busy = false;
    var userTurns = 0;
    var feedbackShown = false;
    var feedbackSent = false;

    // ---- Build the DOM ------------------------------------------------------

    function build() {
        var root = document.createElement('div');
        root.className = 'dsc-root';
        root.setAttribute('data-theme', THEME);

        // Panel
        var panel = document.createElement('div');
        panel.className = 'dsc-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', TEXT.title);
        panel.hidden = true;

        var header = document.createElement('div');
        header.className = 'dsc-header';

        var title = document.createElement('div');
        title.className = 'dsc-title';
        title.textContent = TEXT.title;

        var actions = document.createElement('div');
        actions.className = 'dsc-header-actions';

        var minimizeBtn = document.createElement('button');
        minimizeBtn.type = 'button';
        minimizeBtn.className = 'dsc-icon-btn dsc-icon-btn-minimize';
        minimizeBtn.innerHTML = ICON_MINIMIZE;
        minimizeBtn.title = TEXT.minimizeAria;
        minimizeBtn.setAttribute('aria-label', TEXT.minimizeAria);

        var resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'dsc-icon-btn';
        resetBtn.innerHTML = ICON_RESET;
        resetBtn.title = TEXT.resetLabel;
        resetBtn.setAttribute('aria-label', TEXT.resetAria);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'dsc-icon-btn';
        closeBtn.textContent = '✕';
        closeBtn.title = TEXT.closeAria;
        closeBtn.setAttribute('aria-label', TEXT.closeAria);

        actions.appendChild(minimizeBtn);
        actions.appendChild(resetBtn);
        actions.appendChild(closeBtn);
        header.appendChild(title);
        header.appendChild(actions);

        var messages = document.createElement('div');
        messages.className = 'dsc-messages';
        messages.setAttribute('role', 'log');
        messages.setAttribute('aria-live', 'polite');
        messages.setAttribute('aria-atomic', 'false');

        var form = document.createElement('form');
        form.className = 'dsc-form';

        var input = document.createElement('textarea');
        input.className = 'dsc-input';
        input.rows = 1;
        input.placeholder = TEXT.placeholder;
        input.maxLength = MAX_LEN;
        input.setAttribute('aria-label', TEXT.placeholder);

        var send = document.createElement('button');
        send.type = 'submit';
        send.className = 'dsc-send';
        send.textContent = TEXT.send;

        form.appendChild(input);
        form.appendChild(send);

        panel.appendChild(header);
        panel.appendChild(messages);
        panel.appendChild(form);

        // Launcher
        var launcher = document.createElement('button');
        launcher.type = 'button';
        launcher.className = 'dsc-launcher';
        launcher.setAttribute('aria-label', TEXT.launcherAria);
        launcher.setAttribute('aria-expanded', 'false');
        launcher.innerHTML = ICON_CHAT;

        root.appendChild(panel);
        root.appendChild(launcher);
        document.body.appendChild(root);

        els = {
            root: root, panel: panel, messages: messages,
            form: form, input: input, send: send,
            launcher: launcher, closeBtn: closeBtn, resetBtn: resetBtn,
            minimizeBtn: minimizeBtn
        };
    }

    // ---- Rendering ----------------------------------------------------------

    function addMessage(text, kind) {
        var row = document.createElement('div');
        row.className = 'dsc-row dsc-row-' + kind;

        if (kind !== 'user') {
            var avatar = document.createElement('div');
            avatar.className = 'dsc-avatar';
            if (LOGO_URL) {
                var logoImg = document.createElement('img');
                logoImg.src = LOGO_URL;
                logoImg.alt = '';
                avatar.appendChild(logoImg);
            } else {
                avatar.innerHTML = LOGO_SVG;
            }
            row.appendChild(avatar);
        }

        var el = document.createElement('div');
        el.className = 'dsc-msg dsc-msg-' + kind;
        renderMessageText(el, text);
        row.appendChild(el);

        els.messages.appendChild(row);
        scrollToEnd();
        return el;
    }

    /**
     * Clickable option chips, shown under a bot reply that asks the customer
     * to pick from a short list ("Antene" has several real subtypes) — one
     * tap sends that option as the next message instead of retyping it.
     *
     * @param {{label:string,query:string}[]} options The chip's visible text
     *        (label) and the message actually sent when tapped (query) are
     *        not always the same string — a bare brand name ("Samsung")
     *        loses the product type it was answering for once it comes back
     *        as its own message, so the server bundles the two together.
     */
    function addQuickReplies(options) {
        if (!options || !options.length) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'dsc-qreplies';

        options.forEach(function (option) {
            var label = option && option.label ? String(option.label) : '';
            var query = option && option.query ? String(option.query) : label;
            if (!label) {
                return;
            }

            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'dsc-chip';
            chip.textContent = label;
            chip.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                // All chips in this set are spent once one is picked - keeps
                // the customer from firing the same clarification twice.
                Array.prototype.forEach.call(wrap.querySelectorAll('.dsc-chip'), function (b) {
                    b.disabled = true;
                });
                send(query);
            });
            wrap.appendChild(chip);
        });

        els.messages.appendChild(wrap);
        scrollToEnd();
    }

    function addBrandChoices(options) {
        if (!options || !options.length) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'dsc-brand-choices';

        options.forEach(function (option) {
            var label = option && option.label ? String(option.label) : '';
            var query = option && option.query ? String(option.query) : label;
            if (!label) {
                return;
            }

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'dsc-brand-card';

            var fallback = document.createElement('div');
            fallback.className = 'dsc-brand-initial';
            fallback.textContent = label.charAt(0).toUpperCase();

            if (option.image) {
                var img = document.createElement('img');
                img.src = String(option.image);
                img.alt = label;
                img.loading = 'lazy';
                img.addEventListener('error', function () {
                    img.replaceWith(fallback);
                });
                card.appendChild(img);
            } else {
                card.appendChild(fallback);
            }

            var name = document.createElement('div');
            name.className = 'dsc-brand-label';
            name.textContent = label;
            card.appendChild(name);

            if (option.products) {
                var count = document.createElement('div');
                count.className = 'dsc-brand-count';
                count.textContent = option.products + ' artikala';
                card.appendChild(count);
            }

            card.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                Array.prototype.forEach.call(wrap.querySelectorAll('.dsc-brand-card'), function (b) {
                    b.disabled = true;
                });
                send(query);
            });

            wrap.appendChild(card);
        });

        els.messages.appendChild(wrap);
        scrollToEnd();
    }

    function compactText(lines) {
        return lines.join('\n').replace(/(?:\n\s*){3,}/g, '\n\n').trim();
    }

    function defaultProductIntro(products) {
        var hasAction = false;
        (products || []).forEach(function (p) {
            if (p && p.is_action) {
                hasAction = true;
            }
        });

        return hasAction
            ? 'Evo nekoliko akcijskih ponuda iz našeg asortimana koje bi vas mogle zanimati:'
            : 'Evo nekoliko prijedloga iz našeg asortimana:';
    }

    function splitReplyForCards(text, products) {
        var s = String(text || '');
        if (!products || !products.length) {
            return { before: s, after: '' };
        }

        var lines = s.split(/\r?\n/);
        var before = [];
        var after = [];
        var inClosing = false;

        lines.forEach(function (line) {
            var trimmed = line.trim();
            if (!inClosing && trimmed.indexOf('Preporučujem da pogledate detalje') === 0) {
                inClosing = true;
            }

            if (inClosing) {
                after.push(line);
                return;
            }

            if (/^(?:[•*-]|\d+[.)])\s+/.test(trimmed)) {
                return;
            }

            before.push(line);
        });

        var beforeText = compactText(before);
        var afterText = compactText(after);
        if (!beforeText && afterText) {
            beforeText = defaultProductIntro(products);
        }

        return { before: beforeText || s, after: afterText };
    }

    function addProductMessage(text, products, moreUrl) {
        var parts = splitReplyForCards(text, products);
        var el = addMessage(parts.before, 'bot');

        renderCards(products, el);

        if (parts.after) {
            var after = document.createElement('div');
            after.className = 'dsc-after-cards';
            renderMessageText(after, parts.after);
            el.appendChild(after);
        }

        if (moreUrl) {
            var more = document.createElement('a');
            more.className = 'dsc-more-link';
            more.href = moreUrl;
            more.target = '_blank';
            more.rel = 'noopener';
            more.textContent = 'Prikaži više';
            el.appendChild(more);
        }

        scrollToEnd();

        return el;
    }

    function renderMessageText(el, text) {
        // Never use innerHTML — chat content is untrusted. URLs are converted
        // to anchors by splitting into text nodes plus safe <a> elements.
        var s = String(text || '');
        var re = /https?:\/\/[^\s<>()]+/g;
        var last = 0;
        var match;

        while ((match = re.exec(s)) !== null) {
            if (match.index > last) {
                el.appendChild(document.createTextNode(s.slice(last, match.index)));
            }

            var url = match[0];
            var trailing = '';
            while (/[.,!?;:]$/.test(url)) {
                trailing = url.slice(-1) + trailing;
                url = url.slice(0, -1);
            }

            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = url;
            el.appendChild(a);

            if (trailing) {
                el.appendChild(document.createTextNode(trailing));
            }

            last = match.index + match[0].length;
        }

        if (last < s.length) {
            el.appendChild(document.createTextNode(s.slice(last)));
        }
    }

    function showTyping() {
        var el = document.createElement('div');
        el.className = 'dsc-typing';
        el.setAttribute('aria-label', 'Assistant is typing');
        for (var i = 0; i < 3; i++) {
            el.appendChild(document.createElement('span'));
        }
        els.messages.appendChild(el);
        scrollToEnd();
        return el;
    }

    function shouldAskFeedback() {
        return userTurns > 0 && !feedbackShown && !feedbackSent;
    }

    function showFeedback(afterDone) {
        if (!shouldAskFeedback()) {
            if (afterDone) { afterDone(); }
            return;
        }

        feedbackShown = true;

        var box = document.createElement('div');
        box.className = 'dsc-feedback';

        var title = document.createElement('div');
        title.className = 'dsc-feedback-title';
        title.textContent = TEXT.feedbackTitle;
        box.appendChild(title);

        var rating = 0;
        var stars = document.createElement('div');
        stars.className = 'dsc-feedback-stars';

        function paintStars() {
            Array.prototype.forEach.call(stars.querySelectorAll('.dsc-feedback-star'), function (btn) {
                var value = parseInt(btn.getAttribute('data-rating'), 10);
                btn.classList.toggle('dsc-active', value <= rating);
            });
        }

        for (var i = 1; i <= 5; i++) {
            var star = document.createElement('button');
            star.type = 'button';
            star.className = 'dsc-feedback-star';
            star.textContent = '★';
            star.setAttribute('data-rating', String(i));
            star.setAttribute('aria-label', i + ' / 5');
            star.addEventListener('click', function () {
                rating = parseInt(this.getAttribute('data-rating'), 10) || 0;
                submit.disabled = rating < 1;
                paintStars();
            });
            stars.appendChild(star);
        }
        box.appendChild(stars);

        var note = document.createElement('textarea');
        note.maxLength = 1000;
        note.placeholder = TEXT.feedbackPlaceholder;
        box.appendChild(note);

        var actions = document.createElement('div');
        actions.className = 'dsc-feedback-actions';

        var skip = document.createElement('button');
        skip.type = 'button';
        skip.className = 'dsc-feedback-skip';
        skip.textContent = TEXT.feedbackSkip;

        var submit = document.createElement('button');
        submit.type = 'button';
        submit.className = 'dsc-feedback-submit';
        submit.textContent = TEXT.feedbackSubmit;
        submit.disabled = true;

        skip.addEventListener('click', function () {
            box.remove();
            if (afterDone) { afterDone(); }
        });

        submit.addEventListener('click', function () {
            if (rating < 1 || feedbackSent) {
                return;
            }
            submit.disabled = true;
            skip.disabled = true;
            fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(withVisitorInfo({
                    feedback: true,
                    rating: rating,
                    comment: note.value.trim(),
                    page_url: window.location.href
                }))
            }).then(function () {
                feedbackSent = true;
                box.remove();
                if (afterDone) { afterDone(); }
            }).catch(function () {
                submit.disabled = false;
                skip.disabled = false;
                addMessage(TEXT.networkError, 'error');
            });
        });

        actions.appendChild(skip);
        actions.appendChild(submit);
        box.appendChild(actions);

        els.messages.appendChild(box);
        scrollToEnd();
    }

    function money(v) {
        return Number(v).toFixed(2).replace('.', ',') + ' ' + CURRENCY;
    }

    function addToCart(product, btn) {
        var shop = window.webshop;

        if (shop && typeof shop.cart_add === 'function') {
            try {
                shop.cart_add(product.id, 1, null);
                btn.textContent = 'Dodano ✓';
                btn.disabled = true;
                return;
            } catch (e) {
                // Fall back to opening the product page below.
            }
        }

        if (product.url) {
            window.open(product.url, '_blank', 'noopener');
        }
    }

    function cartIcon() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');

        [
            'M6 6h15l-1.5 8.5a2 2 0 0 1-2 1.5H9a2 2 0 0 1-2-1.7L5.2 3H3',
            'M9 20h.01',
            'M18 20h.01'
        ].forEach(function (d) {
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        });

        return svg;
    }

    function renderCards(products, parent) {
        var wrap = document.createElement('div');
        wrap.className = 'dsc-cards';

        products.forEach(function (p) {
            var card = document.createElement('div');
            card.className = 'dsc-card';

            var fallback = document.createElement('div');
            fallback.className = 'dsc-card-placeholder';
            fallback.textContent = 'Nema slike';

            if (p.image) {
                var img = document.createElement('img');
                img.src = p.image;
                img.alt = p.name || '';
                img.loading = 'lazy';
                img.addEventListener('error', function () { img.replaceWith(fallback); });

                if (p.url) {
                    var imgLink = document.createElement('a');
                    imgLink.className = 'dsc-img-link';
                    imgLink.href = p.url;
                    imgLink.target = '_blank';
                    imgLink.rel = 'noopener';
                    imgLink.appendChild(img);
                    card.appendChild(imgLink);
                } else {
                    card.appendChild(img);
                }
            } else {
                card.appendChild(fallback);
            }

            var info = document.createElement('div');
            info.className = 'dsc-card-info';

            var name = document.createElement(p.url ? 'a' : 'div');
            name.className = 'dsc-card-name';
            name.textContent = p.name || '';
            if (p.url) {
                name.href = p.url;
                name.target = '_blank';
                name.rel = 'noopener';
            }
            info.appendChild(name);

            if (p.is_action) {
                var badge = document.createElement('div');
                badge.className = 'dsc-badge';
                badge.textContent = p.discount_percent
                    ? ('Akcija -' + Math.round(p.discount_percent) + '%')
                    : 'Akcija';
                info.appendChild(badge);
            }

            if (p.is_action && p.price_before) {
                var oldPrice = document.createElement('div');
                oldPrice.className = 'dsc-card-old-price';
                oldPrice.textContent = money(p.price_before);
                info.appendChild(oldPrice);
            }

            var price = document.createElement('div');
            price.className = 'dsc-card-price';
            price.textContent = (p.price === null || p.price === undefined)
                ? 'Cijena na upit'
                : money(p.price);
            info.appendChild(price);

            if (!p.in_stock) {
                var stock = document.createElement('div');
                stock.className = 'dsc-card-out';
                stock.textContent = 'Trenutno nije na stanju';
                info.appendChild(stock);
            }

            var row = document.createElement('div');
            row.className = 'dsc-card-row';

            if (p.in_stock) {
                var buy = document.createElement('button');
                buy.type = 'button';
                buy.className = 'dsc-card-buy';
                buy.title = 'Dodaj u korpu';
                buy.setAttribute('aria-label', 'Dodaj u korpu');
                buy.appendChild(cartIcon());
                buy.addEventListener('click', function () { addToCart(p, buy); });
                row.appendChild(buy);
            }

            if (p.url) {
                var link = document.createElement('a');
                link.className = 'dsc-card-link';
                link.href = p.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = 'Detalji';
                row.appendChild(link);
            }

            if (row.childNodes.length) {
                info.appendChild(row);
            }

            card.appendChild(info);
            wrap.appendChild(card);
        });

        if (parent) {
            parent.className += ' dsc-has-cards';
            parent.appendChild(wrap);
        } else {
            els.messages.appendChild(wrap);
        }
        scrollToEnd();
    }

    function scrollToEnd() {
        els.messages.scrollTop = els.messages.scrollHeight;
    }

    function setBusy(state) {
        busy = state;
        els.send.disabled = state;
        els.input.disabled = state;
    }

    // ---- Networking ---------------------------------------------------------

    function withVisitorInfo(body) {
        if (visitorInfo.customerId) {
            body.customer_id = visitorInfo.customerId;
        }
        if (visitorInfo.customerName) {
            body.customer_name = visitorInfo.customerName;
        }
        if (visitorInfo.isWholesaleCustomer) {
            body.wholesale_hint = true;
        }
        return body;
    }

    function send(text) {
        userTurns++;
        addMessage(text, 'user');
        setBusy(true);
        var typing = showTyping();

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',          // carries the PHP session cookie
            body: JSON.stringify(withVisitorInfo({ message: text }))
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                }).catch(function () {
                    return { ok: false, data: {} };
                });
            })
            .then(function (result) {
                typing.remove();
                // The server knows this deployment's real currency; prefer it
                // over the data-currency script attribute (which the
                // embedding page can simply forget to set).
                if (result.data && result.data.currency) {
                    CURRENCY = result.data.currency;
                }
                if (result.data && result.data.reply) {
                    if (result.data.products && result.data.products.length) {
                        addProductMessage(result.data.reply, result.data.products, result.data.more_url);
                    } else {
                        addMessage(result.data.reply, 'bot');
                    }
                    addQuickReplies(result.data.quick_replies);
                    addBrandChoices(result.data.brand_choices);
                } else if (result.data && result.data.error) {
                    addMessage(result.data.error, 'error');
                } else {
                    addMessage(TEXT.genericError, 'error');
                }
            })
            .catch(function () {
                typing.remove();
                addMessage(TEXT.networkError, 'error');
            })
            .then(function () {
                setBusy(false);
                els.input.focus();
            });
    }

    function reset() {
        if (shouldAskFeedback()) {
            showFeedback(reset);
            return;
        }
        els.messages.textContent = '';
        userTurns = 0;
        feedbackShown = false;
        feedbackSent = false;
        addMessage(TEXT.greeting, 'bot');
        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(withVisitorInfo({ reset: true }))
        }).catch(function () { /* clearing the view is the part that matters */ });
    }

    // ---- Wiring -------------------------------------------------------------

    var CLOSE_ANIM_MS = 200;

    function open() {
        els.panel.hidden = false;
        // Force layout before adding the class so the panel actually
        // animates in from its closed (opacity:0/translated) state instead
        // of just appearing.
        void els.panel.offsetHeight;
        els.panel.classList.add('dsc-open');
        els.launcher.innerHTML = ICON_CLOSE_X;
        els.launcher.setAttribute('aria-label', TEXT.closeAria);
        els.launcher.setAttribute('aria-expanded', 'true');
        els.input.focus();
        scrollToEnd();
    }

    function close(force) {
        if (force !== true && shouldAskFeedback()) {
            showFeedback(function () { close(true); });
            return;
        }
        els.panel.classList.remove('dsc-open');
        els.launcher.innerHTML = ICON_CHAT;
        els.launcher.setAttribute('aria-label', TEXT.launcherAria);
        els.launcher.setAttribute('aria-expanded', 'false');
        els.launcher.focus();
        window.setTimeout(function () {
            if (!els.panel.classList.contains('dsc-open')) {
                els.panel.hidden = true;
            }
        }, CLOSE_ANIM_MS);
    }

    function init() {
        build();

        addMessage(TEXT.greeting, 'bot');

        els.launcher.addEventListener('click', function () {
            if (els.panel.hidden) { open(); } else { close(); }
        });

        els.closeBtn.addEventListener('click', function () { close(); });
        els.resetBtn.addEventListener('click', reset);

        els.minimizeBtn.addEventListener('click', function () {
            close(true);
            els.launcher.focus();
        });

        els.form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = els.input.value.trim();
            if (!text || busy) { return; }
            els.input.value = '';
            els.input.style.height = 'auto';
            send(text);
        });

        // Enter sends, Shift+Enter makes a new line.
        els.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                els.form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        // Grow the textarea with its content.
        els.input.addEventListener('input', function () {
            els.input.style.height = 'auto';
            els.input.style.height = Math.min(els.input.scrollHeight, 110) + 'px';
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !els.panel.hidden) { close(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
