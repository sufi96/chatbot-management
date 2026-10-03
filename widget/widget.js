(function () {
    "use strict";

    // Locate the script tag to read configuration attributes
    var scriptTag = document.currentScript || (function () {
        var scripts = document.getElementsByTagName("script");
        for (var i = scripts.length - 1; i >= 0; i--) {
            if (scripts[i].getAttribute("data-bot-id")) {
                return scripts[i];
            }
        }
        return null;
    })();

    if (!scriptTag) {
        console.error("[ChatbotWidget] Script tag with data-bot-id not found.");
        return;
    }

    var botId = scriptTag.getAttribute("data-bot-id");
    if (!botId) {
        console.error("[ChatbotWidget] data-bot-id attribute is required.");
        return;
    }

    // Determine API host URL (defaults to script host or localhost:8000)
    var scriptSrc = scriptTag.src || "";
    var defaultApiHost = "http://localhost:8000";
    if (scriptSrc.indexOf("http") === 0) {
        var parser = document.createElement("a");
        parser.href = scriptSrc;
        defaultApiHost = parser.protocol + "//" + parser.host;
    }
    var apiHost = scriptTag.getAttribute("data-api-host") || defaultApiHost;
    apiHost = apiHost.replace(/\/+$/, "");

    // Unique session ID per browser tab/session
    var sessionKey = "cb_session_" + botId;
    function newSessionId() {
        return "sess_" + Math.random().toString(36).substring(2, 11) + Date.now().toString(36);
    }
    var sessionId = sessionStorage.getItem(sessionKey);
    if (!sessionId) {
        sessionId = newSessionId();
        sessionStorage.setItem(sessionKey, sessionId);
    }

    // Initial state
    var isOpen = false;
    var isStreaming = false;
    var messageHistory = [];
    var botConfig = {
        title: "AI Assistant",
        greeting: "Hello! How can I help you today?",
        primaryColor: "#1f2937",
        position: "bottom-right",
        launcherIconUrl: "",
        launcherSize: 60,
        closeIconUrl: "",
        closeShape: "circle",
        closeSize: 52,
        botAvatarUrl: ""
    };

    // Default avatar glyph. Drawn inline so the widget pulls no external assets.
    var DEFAULT_AVATAR_SVG = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" ' +
        'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<rect x="4" y="8" width="16" height="12" rx="3"></rect>' +
        '<path d="M12 8V5"></path><circle cx="12" cy="3.5" r="1.5"></circle>' +
        '<path d="M9 13h.01M15 13h.01M9.5 16.5h5"></path></svg>';

    // Create Container and Shadow DOM to completely isolate styles
    var hostContainer = document.createElement("div");
    hostContainer.id = "chatbot-widget-container";
    // Hidden until the config says the bot is live, so a switched-off bot never flashes a default box.
    hostContainer.style.display = "none";
    document.body.appendChild(hostContainer);

    var shadowRoot = hostContainer.attachShadow({ mode: "open" });

    // Stylesheet for Shadow DOM
    var styleSheet = document.createElement("style");
    styleSheet.textContent = `
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .widget-wrapper {
            position: fixed;
            z-index: 999999;
            display: flex;
            flex-direction: column;
            bottom: 24px;
            right: 24px;
        }

        .widget-wrapper.position-bottom-left {
            right: auto;
            left: 24px;
        }

        /* Launcher Button.
           Size comes from the profile. A cutout launcher takes its height from
           --launcher-size and may be up to 1.4x as wide, so tall artwork such
           as a person renders at the height you asked for instead of being
           squeezed into a fixed box. */
        .launcher-btn {
            width: var(--launcher-size, 60px);
            height: var(--launcher-size, 60px);
            border-radius: 50%;
            background-color: var(--primary-color, #1f2937);
            color: #ffffff;
            border: none;
            outline: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.16s ease, box-shadow 0.16s ease;
            position: relative;
            overflow: hidden;
        }

        .launcher-btn:hover {
            transform: scale(1.04);
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.24);
        }

        .launcher-btn:active {
            transform: scale(0.97);
        }

        .launcher-icon {
            width: 28px;
            height: 28px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform 0.3s ease, opacity 0.2s ease;
        }

        .launcher-custom-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        /* Launcher Shape Variants */
        .launcher-btn.shape-transparent-fit {
            background: transparent !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            min-width: 32px;
            max-width: calc(var(--launcher-size, 60px) * 1.4);
            max-height: var(--launcher-size, 60px);
            overflow: visible !important;
        }

        .launcher-btn.shape-transparent-fit .launcher-custom-img {
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            max-height: var(--launcher-size, 60px);
            max-width: calc(var(--launcher-size, 60px) * 1.4);
            object-fit: contain !important;
            filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.28));
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.25s ease;
        }

        .launcher-btn.shape-transparent-fit:hover .launcher-custom-img {
            transform: scale(1.08);
            filter: drop-shadow(0 8px 24px rgba(0, 0, 0, 0.38));
        }

        .launcher-btn.shape-transparent-fit .launcher-icon-chat {
            color: var(--primary-color, #1f2937);
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.2));
        }

        /* --- Close state ---------------------------------------------------
           While the panel is open the same button acts as the close control,
           so it takes the close shape, size and artwork, not the launcher's. */
        .widget-open .launcher-btn {
            width: var(--close-size, 52px) !important;
            height: var(--close-size, 52px) !important;
            min-width: 0 !important;
            max-width: var(--close-size, 52px) !important;
            max-height: var(--close-size, 52px) !important;
            overflow: hidden !important;
        }

        .widget-open .launcher-btn.close-shape-circle {
            background-color: var(--primary-color, #1f2937) !important;
            border: none !important;
            border-radius: 50% !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.18) !important;
        }

        .widget-open .launcher-btn.close-shape-circle-transparent {
            background: transparent !important;
            border: 2px solid var(--primary-color, #1f2937) !important;
            border-radius: 50% !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12) !important;
        }

        .widget-open .launcher-btn.close-shape-circle-transparent .launcher-icon-close {
            color: var(--primary-color, #1f2937);
        }

        .widget-open .launcher-btn.close-shape-transparent-fit {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            width: auto !important;
            height: auto !important;
            max-width: calc(var(--close-size, 52px) * 1.4) !important;
            max-height: var(--close-size, 52px) !important;
            overflow: visible !important;
        }

        .widget-open .launcher-btn.close-shape-transparent-fit .launcher-icon-close {
            color: var(--primary-color, #1f2937);
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.2));
        }

        .close-custom-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .close-shape-transparent-fit .close-custom-img {
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            max-height: var(--close-size, 52px);
            max-width: calc(var(--close-size, 52px) * 1.4);
            object-fit: contain !important;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.26));
        }

        .launcher-btn.shape-circle-transparent {
            background: transparent !important;
            border: 2.5px solid var(--primary-color, #1f2937) !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.14) !important;
        }

        .launcher-btn.shape-circle-transparent .launcher-icon-chat {
            color: var(--primary-color, #1f2937);
        }

        .launcher-icon-close {
            width: 26px;
            height: 26px;
        }

        #launcher-inner,
        #close-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }

        #close-inner { display: none; }

        .widget-open #launcher-inner { display: none; }
        .widget-open #close-inner { display: flex; }

        /* Chat Panel */
        .chat-panel {
            position: absolute;
            bottom: 76px;
            right: 0;
            width: 380px;
            height: 570px;
            max-width: calc(100vw - 32px);
            max-height: calc(100vh - 110px);
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 10px 34px rgba(15, 23, 42, 0.14), 0 2px 8px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
            transform: translateY(20px) scale(0.96);
            transform-origin: bottom right;
            transition: opacity 0.25s ease, transform 0.25s cubic-bezier(0.16, 1, 0.3, 1),
                        width 0.28s cubic-bezier(0.16, 1, 0.3, 1),
                        height 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(0,0,0,0.08);
        }

        .position-bottom-left .chat-panel {
            right: auto;
            left: 0;
            transform-origin: bottom left;
        }

        .widget-open .chat-panel {
            opacity: 1;
            pointer-events: all;
            transform: translateY(0) scale(1);
        }

        /* Expanded reading mode. The panel is pinned to its corner, so
           growing it extends away from that corner: left and up on the
           default bottom-right placement, right and up on bottom-left.
           The caps keep a margin on every side, so the page behind stays
           visible rather than the panel going full screen. */
        .widget-wrapper.expanded .chat-panel {
            width: 75vw;
            min-width: 380px;
            height: calc(100vh - 124px);
            max-width: calc(100vw - 48px);
            max-height: calc(100vh - 124px);
        }

        /* Dims and blurs whatever is behind an expanded panel. It lives in
           the widget's shadow root, so the host page's own markup is never
           touched, and it sits behind the panel via a negative z-index
           inside the wrapper's own stacking context. */
        .chat-backdrop {
            position: fixed;
            inset: 0;
            z-index: -1;
            background: rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.28s ease;
        }

        .widget-wrapper.expanded .chat-backdrop {
            opacity: 1;
            pointer-events: auto;
        }

        /* One button, two glyphs: outward arrows to expand, inward to collapse. */
        #btn-expand .icon-collapse { display: none; }
        .widget-wrapper.expanded #btn-expand .icon-expand { display: none; }
        .widget-wrapper.expanded #btn-expand .icon-collapse { display: block; }

        /* In-panel confirmation. It covers the panel only, never the host
           page, and answers in the bot's own colour. */
        .chat-confirm {
            position: absolute;
            inset: 0;
            z-index: 5;
            display: flex;
            align-items: flex-end;
            padding: 12px;
            background: rgba(15, 23, 42, 0.32);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.18s ease;
        }
        .chat-confirm.open { opacity: 1; pointer-events: auto; }
        .chat-confirm-card {
            width: 100%;
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
            transform: translateY(12px);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .chat-confirm.open .chat-confirm-card { transform: translateY(0); }
        .chat-confirm-title {
            margin: 0 0 4px;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }
        .chat-confirm-text {
            margin: 0 0 14px;
            font-size: 13px;
            line-height: 1.45;
            color: #475569;
        }
        .chat-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; }
        .chat-confirm-actions button {
            font: inherit;
            font-size: 13px;
            font-weight: 500;
            border-radius: 8px;
            padding: 7px 14px;
            cursor: pointer;
        }
        .chat-confirm-cancel {
            background: #ffffff;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .chat-confirm-cancel:hover { background: #f8fafc; }
        .chat-confirm-ok {
            background: var(--primary-color, #1f2937);
            color: #ffffff;
            border: 1px solid transparent;
        }
        .chat-confirm-ok:hover { opacity: 0.9; }
        .chat-confirm-actions button:focus-visible {
            outline: 2px solid var(--primary-color, #1f2937);
            outline-offset: 2px;
        }

        /* Header */
        /* Everything drawn on the header takes --header-text: the title, the
           status line and the icons. A light header picture needs a dark one. */
        .chat-header {
            background: var(--primary-color, #1f2937);
            color: var(--header-text, #ffffff);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top-left-radius: 14px;
            border-top-right-radius: 14px;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            overflow: hidden;
            flex-shrink: 0;
            border: 1.5px solid rgba(255, 255, 255, 0.4);
        }

        .avatar-circle.shape-transparent-fit {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            max-width: 58px;
            max-height: 44px;
            overflow: visible !important;
        }

        .avatar-circle.shape-transparent-fit img {
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            max-height: 40px;
            max-width: 54px;
            object-fit: contain !important;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,0.25));
        }

        .avatar-circle.shape-circle-transparent {
            background: transparent !important;
            border: 1.5px solid rgba(255, 255, 255, 0.75) !important;
        }

        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .header-text h3 {
            font-size: 15px;
            font-weight: 600;
            line-height: 1.2;
            letter-spacing: -0.01em;
        }

        /* Its own green pill, so it reads the same on any header colour or
           picture and ignores the header text colour. */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1;
            color: #15803D;
            background: #DCFCE7;
            border-radius: 999px;
            padding: 3px 8px;
            margin-top: 3px;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #16A34A;
        }

        /* Switched off with a message: a small notice opens instead of the chat. */
        .offline-box {
            position: absolute;
            bottom: 76px;
            right: 0;
            width: 300px;
            max-width: calc(100vw - 32px);
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.15);
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px) scale(0.96);
            transform-origin: bottom right;
            transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .position-bottom-left .offline-box { right: auto; left: 0; transform-origin: bottom left; }
        .is-offline.widget-open .offline-box { opacity: 1; pointer-events: all; transform: none; }
        .is-offline .chat-panel, .is-offline .chat-backdrop { display: none; }
        /* Each part has its own colour and picture, set as --off-* variables from offline_style. */
        .offline-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 14px 16px 12px;
            border-bottom: 1px solid #F1F5F9;
            background: var(--off-header-image, none) center / cover no-repeat, var(--off-header-bg, #ffffff);
        }
        .offline-who { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .offline-avatar {
            position: relative;
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--primary-color) 10%, #ffffff);
            color: var(--primary-color);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05), 0 0 0 1px color-mix(in srgb, var(--primary-color) 15%, transparent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
        }
        .offline-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
        .offline-avatar.shape-circle { border-radius: 50%; background: var(--primary-color); color: #ffffff; box-shadow: none; }
        .offline-avatar.shape-circle-transparent { border-radius: 50%; background: transparent; box-shadow: 0 0 0 2px var(--primary-color); }
        .offline-avatar.shape-transparent-fit { border-radius: 0; background: transparent; box-shadow: none; }
        .offline-avatar.shape-transparent-fit img { object-fit: contain; }
        .offline-avatar::after {
            content: "";
            position: absolute;
            top: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #EF4444;
            box-shadow: 0 0 0 2px #ffffff;
        }
        .offline-title { display: block; font-size: 13px; font-weight: 700; letter-spacing: -0.01em; color: var(--off-header-text, #0F172A); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .offline-subtitle { display: block; font-size: 11px; line-height: 1; color: var(--off-header-text, #0F172A); opacity: 0.65; margin-top: 3px; }
        .offline-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #B91C1C;
            background: #FEF2F2;
            border: 1px solid rgba(254, 202, 202, 0.8);
            border-radius: 999px;
            padding: 4px 10px;
            flex-shrink: 0;
        }
        .offline-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: #EF4444; animation: offline-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes offline-pulse { 50% { opacity: 0.5; } }
        @media (prefers-reduced-motion: reduce) { .offline-badge::before { animation: none; } }
        .offline-text {
            font-size: 13px;
            line-height: 1.625;
            color: var(--off-body-text, #475569);
            white-space: pre-line;
            margin: 0;
            padding: 12px 16px 14px;
            background: var(--off-body-image, none) center / cover no-repeat, var(--off-body-bg, #ffffff);
        }
        .offline-hours {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--off-footer-text, #94A3B8);
            padding: 8px 16px 10px;
            border-top: 1px solid #F1F5F9;
            background: var(--off-footer-image, none) center / cover no-repeat, var(--off-footer-bg, #ffffff);
        }
        .offline-box [hidden] { display: none; }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-icon-btn {
            background: none;
            border: none;
            color: var(--header-text, #ffffff);
            opacity: 0.85;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.15s ease, background-color 0.15s ease;
        }

        .action-icon-btn:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.18);
            background: color-mix(in srgb, currentColor 16%, transparent);
        }

        /* Messages Area */
        .chat-messages {
            flex: 1;
            padding: 20px 18px;
            overflow-y: auto;
            background: #FAFAFA;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .message-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
        }

        .message-row.user {
            justify-content: flex-end;
        }

        .message-row.bot {
            justify-content: flex-start;
        }

        .message-content-wrapper {
            display: flex;
            flex-direction: column;
            max-width: 82%;
            gap: 4px;
        }

        .message-row.user .message-content-wrapper {
            align-items: flex-end;
        }

        .message-row.bot .message-content-wrapper {
            align-items: flex-start;
        }

        .bot-mini-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--primary-color, #1f2937);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            overflow: hidden;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .bot-mini-avatar.shape-transparent-fit {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            max-width: 44px;
            max-height: 38px;
            overflow: visible !important;
        }

        .bot-mini-avatar.shape-transparent-fit img {
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            max-height: 34px;
            max-width: 40px;
            object-fit: contain !important;
            filter: drop-shadow(0 2px 5px rgba(0,0,0,0.2));
        }

        .bot-mini-avatar.shape-circle-transparent {
            background: transparent !important;
            border: 1.5px solid var(--primary-color, #1f2937) !important;
            color: var(--primary-color, #1f2937);
        }

        .bot-mini-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .message-bubble {
            padding: 9px 13px;
            border-radius: 12px;
            font-size: 13.5px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }

        /* Dynamic Thinking Box */
        .thinking-box {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 2px 2px;
            color: #52525B;
            font-size: 13px;
            font-weight: 500;
        }

        .thinking-pulse-ring {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid var(--primary-color, #1f2937);
            border-top-color: transparent;
            border-radius: 50%;
            animation: thinkingSpin 0.85s linear infinite;
            flex-shrink: 0;
        }

        .thinking-text {
            display: inline-block;
            transition: opacity 0.22s ease, transform 0.22s ease;
        }

        .thinking-text.fade-out {
            opacity: 0;
            transform: translateY(-4px);
        }

        .thinking-text.fade-in {
            opacity: 1;
            transform: translateY(0);
        }

        @keyframes thinkingSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .message-row.user .message-bubble {
            background: var(--primary-color, #1f2937);
            color: #ffffff;
            border-top-right-radius: 3px;
        }

        .message-row.bot .message-bubble {
            background: #ffffff;
            color: #18181B;
            border-top-left-radius: 3px;
            border: 1px solid #E4E4E7;
        }

        /* Formatted answers. A support reply arrives as Markdown, so the
           bubble has to carry lists, code and tables without bursting the
           width of the chat panel. */
        .message-bubble.rendered {
            white-space: normal;
        }

        .message-bubble.rendered > *:first-child { margin-top: 0; }
        .message-bubble.rendered > *:last-child { margin-bottom: 0; }

        .message-bubble.rendered p { margin: 0 0 8px; }

        .message-bubble.rendered ul,
        .message-bubble.rendered ol {
            margin: 0 0 8px;
            padding-left: 20px;
        }

        .message-bubble.rendered li { margin: 2px 0; }

        .message-bubble.rendered li > ul,
        .message-bubble.rendered li > ol { margin: 2px 0 0; }

        .message-bubble.rendered h1,
        .message-bubble.rendered h2,
        .message-bubble.rendered h3,
        .message-bubble.rendered h4,
        .message-bubble.rendered h5,
        .message-bubble.rendered h6 {
            margin: 10px 0 6px;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.35;
        }

        .message-bubble.rendered a {
            color: var(--primary-color, #1f2937);
            text-decoration: underline;
        }

        .message-row.user .message-bubble.rendered a { color: #ffffff; }

        .message-bubble.rendered code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            background: #F4F4F5;
            border: 1px solid #E4E4E7;
            border-radius: 4px;
            padding: 1px 4px;
        }

        .message-bubble.rendered pre {
            margin: 0 0 8px;
            padding: 9px 11px;
            background: #18181B;
            border-radius: 8px;
            overflow-x: auto;
        }

        .message-bubble.rendered pre code {
            background: none;
            border: 0;
            padding: 0;
            color: #F4F4F5;
            font-size: 12px;
            line-height: 1.5;
            white-space: pre;
        }

        .message-bubble.rendered blockquote {
            margin: 0 0 8px;
            padding: 2px 0 2px 10px;
            border-left: 3px solid #E4E4E7;
            color: #52525B;
        }

        .message-bubble.rendered hr {
            border: 0;
            border-top: 1px solid #E4E4E7;
            margin: 10px 0;
        }

        /* A wide table scrolls inside the bubble instead of widening it. */
        .message-bubble.rendered .md-table {
            margin: 0 0 8px;
            overflow-x: auto;
            max-width: 100%;
        }

        .message-bubble.rendered table {
            border-collapse: collapse;
            font-size: 12px;
            width: 100%;
            table-layout: auto;
        }

        /* Cells wrap. A comparison table should get taller, not force the
           reader sideways. The scroll container is still there for a table
           too wide to fit even once wrapped. */
        .message-bubble.rendered th,
        .message-bubble.rendered td {
            border: 1px solid #E4E4E7;
            padding: 5px 8px;
            text-align: left;
            vertical-align: top;
            white-space: normal;
            overflow-wrap: anywhere;
            min-width: 84px;
        }

        .message-bubble.rendered th {
            background: #FAFAFA;
            font-weight: 600;
        }

        .message-time {
            font-size: 11px;
            color: #A1A1AA;
            margin-top: 3px;
            padding: 0 4px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* What an answer cost, tucked beside its timestamp. */
        .meta-info {
            position: relative;
            display: inline-flex;
            align-items: center;
            color: #A1A1AA;
            cursor: help;
        }

        .meta-info:hover,
        .meta-info:focus-visible { color: #71717A; }

        .meta-tip {
            position: absolute;
            bottom: calc(100% + 6px);
            left: 0;
            background: #27272A;
            color: #FAFAFA;
            font-size: 11px;
            line-height: 1.55;
            white-space: nowrap;
            padding: 6px 9px;
            border-radius: 7px;
            opacity: 0;
            pointer-events: none;
            transform: translateY(3px);
            transition: opacity 0.15s ease, transform 0.15s ease;
            z-index: 5;
        }

        .meta-tip .meta-row { display: block; }

        .meta-info:hover .meta-tip,
        .meta-info:focus-visible .meta-tip {
            opacity: 1;
            transform: translateY(0);
        }

        /* What an answer drew on. Titles only: a visitor never sees chunk
           contents or internal ids. */
        .message-sources {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .source-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            line-height: 1.4;
            padding: 2px 7px;
            border-radius: 999px;
            border: 1px solid #E4E4E7;
            background: #FAFAFA;
            color: #52525B;
            max-width: 100%;
            overflow: hidden;
        }

        /* Which of the three answered, said in a glyph rather than a word:
           the row is already tight, and the titles never name their origin. */
        .source-chip-mark {
            display: inline-flex;
            flex-shrink: 0;
            opacity: 0.7;
        }

        .source-chip-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        a.source-chip {
            text-decoration: none;
            cursor: pointer;
        }

        a.source-chip:hover {
            border-color: #A1A1AA;
            color: #27272A;
        }

        .sources-label {
            font-size: 10.5px;
            color: #A1A1AA;
            margin-right: 2px;
            align-self: center;
        }

        /* A reasoning model's narration, folded above the answer it produced.
           It sits open while thinking is all there is to see, then folds away
           the moment the answer starts, so the answer is what leads. */
        .message-thinking {
            margin-bottom: 6px;
            max-width: 100%;
            border: 1px solid #E4E4E7;
            border-radius: 10px;
            background: #FAFAFA;
            overflow: hidden;
        }

        .thinking-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            padding: 6px 10px;
            border: 0;
            background: transparent;
            font-family: inherit;
            font-size: 11px;
            color: #71717A;
            text-align: left;
            cursor: pointer;
        }

        .thinking-toggle:hover {
            color: #3F3F46;
        }

        .thinking-chevron {
            flex: none;
            width: 10px;
            height: 10px;
            transition: transform 0.15s ease;
        }

        .message-thinking.open .thinking-chevron {
            transform: rotate(90deg);
        }

        .thinking-body {
            display: none;
            padding: 0 10px 8px;
            font-size: 11.5px;
            line-height: 1.55;
            color: #52525B;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 220px;
            overflow-y: auto;
        }

        .message-thinking.open .thinking-body {
            display: block;
        }

        /* Typing Dots */
        .typing-indicator {
            display: none;
            padding: 12px 16px;
            background: #ffffff;
            border-radius: 12px;
            border-bottom-left-radius: 3px;
            border: 1px solid #E4E4E7;
            align-self: flex-start;
            gap: 5px;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            margin-left: 36px;
        }

        .typing-indicator.active {
            display: inline-flex;
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #A1A1AA;
            animation: blink 1.3s infinite both;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes blink {
            0%, 80%, 100% { opacity: 0.2; transform: scale(0.8); }
            40% { opacity: 1; transform: scale(1.1); }
        }

        /* Footer & Input */
        .chat-footer {
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #E4E4E7;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .input-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            background: #F4F4F5;
            border-radius: 10px;
            padding: 6px 12px;
            border: 1px solid #E4E4E7;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .input-row:focus-within {
            border-color: var(--primary-color, #1f2937);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(24, 24, 27, 0.10);
        }

        .chat-input {
            flex: 1;
            border: none;
            outline: none;
            background: transparent;
            font-size: 14px;
            line-height: 1.45;
            max-height: 96px;
            resize: none;
            color: #18181B;
            padding: 6px 2px;
        }

        .send-btn {
            background: var(--primary-color, #1f2937);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: opacity 0.2s ease, transform 0.15s ease;
            flex-shrink: 0;
            margin-bottom: 2px;
        }

        .send-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .send-btn:not(:disabled):hover {
            filter: brightness(1.12);
        }

        .powered-by {
            font-size: 10.5px;
            line-height: 1.4;
            text-align: center;
            color: #A1A1AA;
        }

        /* Pinned to the bottom of the conversation, just above the message
           box. Messages are inserted before the typing indicator, so this
           stays last; sticky keeps it in view while the list scrolls. */
        .chat-disclaimer {
            margin-top: auto;
            margin-bottom: -12px;
            position: sticky;
            bottom: -14px;
            align-self: center;
            max-width: 100%;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.85);
            -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
            font-size: 10.5px;
            line-height: 1.4;
            text-align: center;
            color: #71717A;
            z-index: 3;
        }

        .powered-by sup {
            font-size: 0.7em;
            line-height: 0;
        }

        /* --- Cutout in a circle ---------------------------------------------
           The picture rises out of a circle: the circle sits behind it, and
           the picture is clipped to a box as wide as the circle whose bottom
           is the circle's lower half, so the body stays inside while the head
           comes out of the top. The ring variant draws its lower arc again
           in front, so the picture looks like it sits in the ring. */
        .cutout {
            position: relative;
            overflow: visible !important;
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .cutout::before,
        .cutout.cutout-ring::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 50%;
            pointer-events: none;
        }

        .cutout::before {
            background: var(--cut-fill, var(--primary-color, #1f2937));
            box-shadow: var(--cut-shadow, none);
            z-index: 0;
        }

        .cutout.cutout-ring::before {
            background: transparent;
            border: var(--cut-line-width, 2px) solid var(--cut-line, var(--primary-color, #1f2937));
        }

        .cutout.cutout-ring::after {
            border: var(--cut-line-width, 2px) solid var(--cut-line, var(--primary-color, #1f2937));
            clip-path: inset(50% 0 0 0);
            z-index: 2;
        }

        .cutout > * {
            position: relative;
            z-index: 1;
        }

        .cutout.cutout-circle { color: #ffffff; }
        .cutout.cutout-ring { color: var(--cut-line, var(--primary-color, #1f2937)); }

        .cutout > .cutout-clip {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 135%;
            overflow: hidden;
            /* Large radii are scaled down to half the width, which makes the
               bottom edge exactly the circle's lower half. */
            border-radius: 0 0 999px 999px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }

        .cutout-clip img {
            display: block;
            width: auto !important;
            height: auto !important;
            max-width: 100% !important;
            max-height: 100% !important;
            object-fit: contain !important;
            border-radius: 0 !important;
            filter: none !important;
        }

        .widget-wrapper:not(.widget-open) .launcher-btn.shape-cutout,
        .widget-open .launcher-btn.close-shape-cutout-circle,
        .widget-open .launcher-btn.close-shape-cutout-ring {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            overflow: visible !important;
        }

        #launcher-inner.cutout,
        #close-inner.cutout {
            --cut-shadow: 0 4px 14px rgba(15, 23, 42, 0.18);
        }

        .avatar-circle.cutout {
            --cut-fill: rgba(255, 255, 255, 0.2);
            --cut-line: rgba(255, 255, 255, 0.75);
            --cut-line-width: 1.5px;
        }

        .bot-mini-avatar.cutout {
            --cut-line-width: 1.5px;
        }


        /* Voice. Everything here stays hidden unless the bot speaks or
           listens, so a bot without voice looks exactly as before. */
        #btn-voice, .listen-btn, .mic-btn, .voice-tick { display: none; }
        .widget-wrapper.voice-on .voice-tick { display: inline-flex; }

        /* The visitor's own switch for hearing answers, in plain sight above
           the message box. The same choice as the voice menu's tick. */
        .voice-tick {
            align-self: flex-end;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #52525B;
            cursor: pointer;
            user-select: none;
        }
        .voice-tick svg { color: #A1A1AA; }
        .voice-tick:has(input:checked) svg { color: var(--primary-color, #1f2937); }

        /* A standard on/off switch: a pill whose knob slides across. */
        .voice-switch {
            -webkit-appearance: none;
            appearance: none;
            position: relative;
            width: 32px;
            height: 18px;
            margin: 0;
            border-radius: 999px;
            background: #D4D4D8;
            cursor: pointer;
            flex-shrink: 0;
            transition: background-color 0.18s ease;
        }
        .voice-switch::after {
            content: "";
            position: absolute;
            top: 2px;
            left: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
            transition: transform 0.18s ease;
        }
        .voice-switch:checked { background: var(--primary-color, #1f2937); }
        .voice-switch:checked::after { transform: translateX(14px); }
        .voice-switch:focus-visible { outline: 2px solid var(--primary-color, #1f2937); outline-offset: 2px; }
        .widget-wrapper.voice-on #btn-voice { display: flex; }
        .widget-wrapper.voice-on .listen-btn { display: inline-flex; }
        .widget-wrapper.voice-listen .mic-btn { display: flex; }
        .widget-wrapper.voice-speaking #btn-voice { opacity: 1; background: color-mix(in srgb, currentColor 22%, transparent); }

        .listen-btn {
            background: none;
            border: none;
            padding: 0 2px;
            color: #A1A1AA;
            cursor: pointer;
            align-items: center;
            border-radius: 4px;
        }
        .listen-btn:hover, .listen-btn.playing { color: var(--primary-color, #1f2937); }

        .mic-btn {
            background: none;
            border: none;
            color: #71717A;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            margin-bottom: 2px;
        }
        .mic-btn:hover { color: var(--primary-color, #1f2937); background: #F4F4F5; }
        .mic-btn.recording { color: #ffffff; background: #DC2626; animation: mic-pulse 1.2s ease-in-out infinite; }
        @keyframes mic-pulse { 50% { box-shadow: 0 0 0 5px rgba(220, 38, 38, 0.2); } }

        .voice-menu {
            position: absolute;
            top: 64px;
            right: 12px;
            z-index: 20;
            width: 240px;
            background: #ffffff;
            color: #18181B;
            border: 1px solid #E4E4E7;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.16);
            padding: 12px;
            font-size: 13px;
            display: none;
        }
        .voice-menu.open { display: block; }
        .voice-menu-label { font-size: 11px; font-weight: 600; color: #71717A; text-transform: uppercase; letter-spacing: 0.04em; margin: 10px 0 6px; }
        .voice-menu-toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; }
        .voice-segment { display: flex; border: 1px solid #E4E4E7; border-radius: 8px; overflow: hidden; }
        .voice-segment button {
            flex: 1;
            border: none;
            background: #ffffff;
            padding: 6px 4px;
            font-size: 12px;
            cursor: pointer;
            color: #3F3F46;
        }
        .voice-segment button + button { border-left: 1px solid #E4E4E7; }
        .voice-segment button[aria-pressed="true"] { background: var(--primary-color, #1f2937); color: #ffffff; }
        .voice-menu-note { font-size: 11.5px; color: #71717A; margin-top: 8px; line-height: 1.4; }
        .voice-try {
            margin-top: 10px;
            width: 100%;
            border: 1px solid #E4E4E7;
            background: #FAFAFA;
            border-radius: 8px;
            padding: 6px;
            font-size: 12px;
            cursor: pointer;
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
            }
            .launcher-btn:hover,
            .launcher-btn:active { transform: none; }
        }
    `;
    shadowRoot.appendChild(styleSheet);

    // Build DOM structure inside Shadow Root
    var wrapper = document.createElement("div");
    wrapper.className = "widget-wrapper";

    wrapper.innerHTML = `
        <div class="chat-backdrop" id="chat-backdrop"></div>
        <div class="chat-panel" id="chat-panel">
            <div class="chat-header">
                <div class="header-info">
                    <div class="avatar-circle" id="header-avatar">${DEFAULT_AVATAR_SVG}</div>
                    <div class="header-text">
                        <h3 id="bot-title">AI Assistant</h3>
                        <div class="status-badge">
                            <span class="status-dot"></span> Online
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="action-icon-btn" id="btn-voice" title="Voice" aria-label="Voice settings" aria-haspopup="true" aria-expanded="false">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
                    </button>
                    <button class="action-icon-btn" id="btn-clear" title="Clear conversation">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
                    </button>
                    <button class="action-icon-btn" id="btn-expand" title="Expand chat" aria-label="Expand chat" aria-expanded="false">
                        <svg class="icon-expand" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>
                        <svg class="icon-collapse" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>
                    </button>
                    <button class="action-icon-btn" id="btn-close-header" title="Close chat">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
            </div>

            <div class="voice-menu" id="voice-menu" role="dialog" aria-label="Voice">
                <label class="voice-menu-toggle"><input type="checkbox" id="voice-autoplay"> Read answers aloud</label>
                <div class="voice-menu-label">Language</div>
                <div class="voice-segment" data-pref="language">
                    <button type="button" data-value="auto">Auto</button>
                    <button type="button" data-value="en">English</button>
                    <button type="button" data-value="ms">Melayu</button>
                </div>
                <div class="voice-menu-label">Voice</div>
                <div class="voice-segment" data-pref="gender">
                    <button type="button" data-value="female">Female</button>
                    <button type="button" data-value="male">Male</button>
                </div>
                <div class="voice-menu-note" id="voice-note"></div>
                <button type="button" class="voice-try" id="voice-try">Try this voice</button>
            </div>

            <div class="chat-messages" id="chat-messages">
                <!-- Initial greeting injected here -->
                <div class="typing-indicator" id="typing-indicator">
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                </div>
                <div class="chat-disclaimer">AI can make mistakes. Please verify important information.</div>
            </div>

            <div class="chat-footer">
                <label class="voice-tick" title="Hear the bot read its answers">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
                    <span>Read answers aloud</span>
                    <input type="checkbox" role="switch" class="voice-switch" id="voice-tick">
                </label>
                <div class="input-row">
                    <button class="mic-btn" id="mic-btn" type="button" title="Speak your question" aria-label="Speak your question">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M5 10v1a7 7 0 0 0 14 0v-1"></path><line x1="12" y1="18" x2="12" y2="22"></line></svg>
                    </button>
                    <textarea class="chat-input" id="chat-input" rows="1" placeholder="Type a message..."></textarea>
                    <button class="send-btn" id="send-btn" disabled>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>
                </div>
                <div class="powered-by">Powered by C<sup>4</sup></div>
            </div>

            <div class="chat-confirm" id="chat-confirm" role="alertdialog" aria-modal="true"
                 aria-labelledby="chat-confirm-title" aria-describedby="chat-confirm-text" aria-hidden="true">
                <div class="chat-confirm-card">
                    <p class="chat-confirm-title" id="chat-confirm-title">Clear this conversation?</p>
                    <p class="chat-confirm-text" id="chat-confirm-text">The messages so far are removed from this window and the chat starts over.</p>
                    <div class="chat-confirm-actions">
                        <button type="button" class="chat-confirm-cancel" id="chat-confirm-cancel">Cancel</button>
                        <button type="button" class="chat-confirm-ok" id="chat-confirm-ok">Clear</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="offline-box" id="offline-box" role="status">
            <div class="offline-head">
                <div class="offline-who">
                    <div class="offline-avatar" id="offline-avatar"></div>
                    <div style="min-width: 0;">
                        <span class="offline-title" id="offline-title"></span>
                        <span class="offline-subtitle" id="offline-subtitle"></span>
                    </div>
                </div>
                <span class="offline-badge">Offline</span>
            </div>
            <p class="offline-text" id="offline-text"></p>
            <div class="offline-hours" id="offline-hours"></div>
        </div>

        <button class="launcher-btn" id="launcher-btn" aria-label="Open Chat">
            <span id="launcher-inner">
                <svg class="launcher-icon launcher-icon-chat" viewBox="0 0 24 24">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </span>
            <span id="close-inner">
                <svg class="launcher-icon launcher-icon-close" viewBox="0 0 24 24">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </span>
        </button>
    `;
    shadowRoot.appendChild(wrapper);

    // Element references
    var launcherBtn = shadowRoot.getElementById("launcher-btn");
    var launcherInner = shadowRoot.getElementById("launcher-inner");
    var closeInner = shadowRoot.getElementById("close-inner");
    var headerAvatar = shadowRoot.getElementById("header-avatar");
    var chatHeader = shadowRoot.querySelector(".chat-header");
    var btnCloseHeader = shadowRoot.getElementById("btn-close-header");
    var btnClear = shadowRoot.getElementById("btn-clear");
    var btnExpand = shadowRoot.getElementById("btn-expand");
    var chatBackdrop = shadowRoot.getElementById("chat-backdrop");
    var chatMessages = shadowRoot.getElementById("chat-messages");
    var chatInput = shadowRoot.getElementById("chat-input");
    var sendBtn = shadowRoot.getElementById("send-btn");
    var typingIndicator = shadowRoot.getElementById("typing-indicator");
    var botTitleEl = shadowRoot.getElementById("bot-title");
    var chatConfirm = shadowRoot.getElementById("chat-confirm");
    var chatConfirmOk = shadowRoot.getElementById("chat-confirm-ok");
    var chatConfirmCancel = shadowRoot.getElementById("chat-confirm-cancel");
    var btnVoice = shadowRoot.getElementById("btn-voice");
    var voiceMenu = shadowRoot.getElementById("voice-menu");
    var micBtn = shadowRoot.getElementById("mic-btn");

    function updateColors(hex) {
        if (!hex) return;
        wrapper.style.setProperty("--primary-color", hex);
    }

    function cssUrl(url) {
        return 'url("' + String(url).replace(/["\\\n]/g, "\\$&") + '")';
    }

    function hexToRgb(hex) {
        var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex || "").trim());
        return m ? parseInt(m[1], 16) + ", " + parseInt(m[2], 16) + ", " + parseInt(m[3], 16) : null;
    }

    /**
     * Background layers for a picture shown at an opacity over a colour. A
     * wash of the colour on top of the picture looks the same as the picture
     * faded over it, and works on a scrolling area where a faded layer would not.
     */
    function pictureLayers(url, color, opacity) {
        var layers = [];
        var rgb = hexToRgb(color);
        var wash = (100 - opacity) / 100;
        if (rgb && wash > 0) {
            layers.push("linear-gradient(rgba(" + rgb + ", " + wash + "), rgba(" + rgb + ", " + wash + "))");
        }
        layers.push(cssUrl(url));
        return layers.join(", ");
    }

    function readOpacity(value) {
        var n = parseInt(value, 10);
        return isNaN(n) ? 100 : Math.max(0, Math.min(100, n));
    }

    function isCutout(shape) {
        return shape === "cutout_circle" || shape === "cutout_ring";
    }

    /**
     * Puts a picture into el. A cutout shape wraps it in the clip that lets
     * it rise out of the circle; without a picture the shape falls back to
     * the plain circle it is drawn on, with the default icon inside.
     */
    function fillShape(el, shape, url, imgClass, alt) {
        el.classList.remove("cutout", "cutout-circle", "cutout-ring");
        if (isCutout(shape)) {
            el.classList.add("cutout", shape === "cutout_ring" ? "cutout-ring" : "cutout-circle");
        }
        if (!url) return;

        var img = '<img src="' + url + '"' + (imgClass ? ' class="' + imgClass + '"' : "") + ' alt="' + alt + '">';
        el.innerHTML = isCutout(shape) ? '<span class="cutout-clip">' + img + '</span>' : img;
    }

    // The renderer is served in the same script as this widget. Should it
    // ever be missing, answers still arrive, just without formatting.
    var markdown = (typeof window !== "undefined" && window.__ChatbotMarkdown) || null;

    /**
     * Bot text is Markdown and becomes formatted HTML. Anything a visitor
     * typed stays literal, so their own words can never turn into markup.
     */
    function setBotText(bubble, text) {
        // Kept as written, so reading it aloud starts from the Markdown and
        // not from whatever chips and markers the bubble grows later.
        bubble._raw = text;
        if (!markdown) {
            bubble.textContent = text;
            return;
        }
        bubble.innerHTML = markdown.render(text);
        bubble.classList.add("rendered");
    }

    function appendMessage(sender, text) {
        var row = document.createElement("div");
        row.className = "message-row " + sender;

        if (sender === "bot") {
            var miniAvatar = document.createElement("div");
            miniAvatar.className = "bot-mini-avatar";
            if (botConfig.avatarShape === "transparent_fit") {
                miniAvatar.classList.add("shape-transparent-fit");
            } else if (botConfig.avatarShape === "circle_transparent") {
                miniAvatar.classList.add("shape-circle-transparent");
            }

            miniAvatar.innerHTML = DEFAULT_AVATAR_SVG;
            fillShape(miniAvatar, botConfig.avatarShape, botConfig.botAvatarUrl, "", "Bot");
            row.appendChild(miniAvatar);
        }

        var contentWrapper = document.createElement("div");
        contentWrapper.className = "message-content-wrapper";

        var bubble = document.createElement("div");
        bubble.className = "message-bubble";
        if (text) {
            if (sender === "bot") { setBotText(bubble, text); }
            else { bubble.textContent = text; }
        }

        var time = document.createElement("div");
        time.className = "message-time";
        var now = new Date();
        time.textContent = now.getHours().toString().padStart(2, "0") + ":" + now.getMinutes().toString().padStart(2, "0");

        contentWrapper.appendChild(bubble);
        contentWrapper.appendChild(time);

        // Read aloud on request. Hidden by CSS unless the bot speaks.
        if (sender === "bot") {
            var listen = document.createElement("button");
            listen.type = "button";
            listen.className = "listen-btn";
            listen.title = "Read aloud";
            listen.setAttribute("aria-label", "Read this answer aloud");
            listen.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>';
            listen.addEventListener("click", function () {
                if (playback.current === bubble && playback.speaking) { stopSpeaking(); return; }
                speakAll(bubble._raw || bubble.textContent, bubble);
            });
            time.appendChild(listen);
        }
        row.appendChild(contentWrapper);

        // Insert before the typing indicator
        chatMessages.insertBefore(row, typingIndicator);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return bubble;
    }

    // One mark per answering source, drawn at chip size. The names match the
    // engine's source kinds, so a kind it sends is a key here.
    var SOURCE_MARKS = {
        documents: {
            label: "Knowledge base",
            svg: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>' +
                 '<path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'
        },
        web: {
            label: "Web search",
            svg: '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>' +
                 '<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 ' +
                 '15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'
        },
        database: {
            label: "Database",
            svg: '<ellipse cx="12" cy="5" rx="9" ry="3"/>' +
                 '<path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>' +
                 '<path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>'
        }
    };

    /**
     * The mark for a source kind, or nothing for one this widget predates.
     * An engine too old to send a kind still names web results by their url,
     * which is the one case a visitor most needs told apart.
     */
    function sourceMark(kind, source) {
        if (!kind && source && source.url) kind = "web";

        var mark = SOURCE_MARKS[kind];
        if (!mark) return null;

        var holder = document.createElement("span");
        holder.className = "source-chip-mark";
        holder.setAttribute("aria-hidden", "true");
        holder.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" ' +
            'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
            'stroke-linejoin="round">' + mark.svg + '</svg>';

        return holder;
    }

    /**
     * Lists the material an answer drew on, under the bubble it belongs to.
     * The visitor sees titles, never chunk contents or ids.
     */
    function attachSources(bubble, sources, kind) {
        if (!bubble || !sources || !sources.length) return;

        var wrapper = bubble.parentNode;
        if (!wrapper || wrapper.querySelector(".message-sources")) return;

        var row = document.createElement("div");
        row.className = "message-sources";

        var heading = document.createElement("span");
        heading.className = "sources-label";
        heading.textContent = "Based on";
        row.appendChild(heading);

        for (var i = 0; i < sources.length; i++) {
            var title = sources[i].title || "Untitled";
            var url = sources[i].url;
            // A combined answer names the kind on each source, since the
            // knowledge base and the database answered it together.
            var own = sources[i].kind || kind;
            var known = SOURCE_MARKS[own];

            // A knowledge base source has no url and stays plain text. A web
            // result is something the visitor can and should go and check.
            var chip = document.createElement(url ? "a" : "span");
            chip.className = "source-chip";
            // The mark is decorative, so the kind is said in words here, where
            // a screen reader and a hover both reach it.
            chip.title = (known ? known.label + ": " : "") + (url || title);

            var mark = sourceMark(own, sources[i]);
            if (mark) chip.appendChild(mark);

            var text = document.createElement("span");
            text.className = "source-chip-text";
            text.textContent = title;
            chip.appendChild(text);

            if (url) {
                chip.href = url;
                chip.target = "_blank";
                chip.rel = "noopener noreferrer";
            }

            row.appendChild(chip);
        }

        // Above the timestamp, which is always the last child.
        wrapper.insertBefore(row, wrapper.lastChild);
    }

    /**
     * The narration group above a bubble, created on first use. Returns the
     * existing one afterwards so a stream keeps appending to one place.
     */
    function ensureThinkingGroup(bubble) {
        var wrapper = bubble.parentNode;
        var existing = wrapper.querySelector(".message-thinking");
        if (existing) return existing;

        var group = document.createElement("div");
        group.className = "message-thinking open";

        var toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "thinking-toggle";
        toggle.innerHTML = '<svg class="thinking-chevron" viewBox="0 0 24 24" fill="none" ' +
            'stroke="currentColor" stroke-width="3" stroke-linecap="round" ' +
            'stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';

        var label = document.createElement("span");
        label.className = "thinking-label";
        label.textContent = "Thinking...";
        toggle.appendChild(label);

        var body = document.createElement("div");
        body.className = "thinking-body";

        toggle.addEventListener("click", function () {
            group.classList.toggle("open");
        });

        group.appendChild(toggle);
        group.appendChild(body);
        wrapper.insertBefore(group, bubble);
        return group;
    }

    function formatDuration(ms) {
        if (ms < 1000) return Math.round(ms) + " ms";
        if (ms < 60000) return (Math.round(ms / 100) / 10) + " s";
        var minutes = Math.floor(ms / 60000);
        return minutes + " min " + Math.round((ms % 60000) / 1000) + " s";
    }

    /**
     * A quiet marker beside the timestamp saying what the answer cost.
     * Counts an endpoint did not report are left out rather than shown as
     * zeros, which would read as a real measurement.
     */
    function attachMeta(bubble, meta) {
        if (!bubble || !meta || !bubble.parentNode) return;

        var time = bubble.parentNode.querySelector(".message-time");
        if (!time || time.querySelector(".meta-info")) return;

        var lines = [];
        if (meta.elapsed_ms != null) lines.push("Time taken: " + formatDuration(meta.elapsed_ms));
        if (meta.tokens_in != null) lines.push("Tokens in: " + meta.tokens_in);
        if (meta.tokens_out != null) lines.push("Tokens out: " + meta.tokens_out);
        if (meta.model) lines.push("Model: " + meta.model);
        if (!lines.length) return;

        var holder = document.createElement("span");
        holder.className = "meta-info";
        holder.setAttribute("tabindex", "0");
        holder.setAttribute("aria-label", lines.join(", "));
        holder.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" ' +
            'stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"></circle>' +
            '<line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';

        var tip = document.createElement("span");
        tip.className = "meta-tip";
        lines.forEach(function (line) {
            var row = document.createElement("span");
            row.className = "meta-row";
            row.textContent = line;
            tip.appendChild(row);
        });
        holder.appendChild(tip);
        time.appendChild(holder);
    }


    // ---- Voice ---------------------------------------------------------
    // The bot's answers read aloud, and questions spoken. Where the sound
    // comes from is the install's choice (Admin settings, Voice): the
    // visitor's own device by default, or the engine's speech server. Either
    // way the widget asks for one of four voices, by language and gender.
    var voiceKit = (typeof window !== "undefined" && window.__ChatbotVoice) || null;
    var voicePrefsKey = "cb_voice_" + botId;
    var voicePrefs = { autoplay: false, language: "auto", gender: "female" };
    var deviceVoices = [];
    var playback = { token: 0, queue: [], audio: null, speaking: false, current: null };
    var listening = { active: false, recognizer: null, recorder: null, timer: null };

    function voiceOutput() { return !!(voiceKit && botConfig.voice && botConfig.voice.output); }
    function speaksInBrowser() { return !botConfig.voice || botConfig.voice.speak_with !== "server"; }
    function canBrowserSpeak() {
        return typeof window.speechSynthesis !== "undefined" && typeof window.SpeechSynthesisUtterance !== "undefined";
    }
    function browserRecognizer() { return window.SpeechRecognition || window.webkitSpeechRecognition || null; }

    function loadVoicePrefs() {
        var v = botConfig.voice || {};
        voicePrefs = { autoplay: !!v.autoplay, language: v.language || "auto", gender: v.gender || "female" };
        try {
            var saved = JSON.parse(localStorage.getItem(voicePrefsKey) || "null");
            if (saved) {
                ["autoplay", "language", "gender"].forEach(function (k) { if (k in saved) { voicePrefs[k] = saved[k]; } });
            }
        } catch (e) { /* storage blocked: the bot's defaults stand */ }
    }

    function saveVoicePrefs() {
        try { localStorage.setItem(voicePrefsKey, JSON.stringify(voicePrefs)); } catch (e) { /* not kept */ }
    }

    function refreshDeviceVoices() {
        if (canBrowserSpeak()) { deviceVoices = window.speechSynthesis.getVoices() || []; }
        renderVoiceNote();
    }

    function languageFor(text) {
        if (voicePrefs.language === "en" || voicePrefs.language === "ms") { return voicePrefs.language; }
        var local = String(navigator.language || "").toLowerCase().indexOf("ms") === 0 ? "ms" : "en";
        return voiceKit.detectLanguage(text, local);
    }

    function setSpeaking(on, bubble) {
        playback.speaking = on;
        if (!on && playback.current) {
            var old = playback.current.parentNode && playback.current.parentNode.querySelector(".listen-btn");
            if (old) { old.classList.remove("playing"); }
        }
        playback.current = on ? (bubble || playback.current) : null;
        if (on && playback.current && playback.current.parentNode) {
            var btn = playback.current.parentNode.querySelector(".listen-btn");
            if (btn) { btn.classList.add("playing"); }
        }
        wrapper.classList.toggle("voice-speaking", on);
    }

    function stopSpeaking() {
        playback.token++;
        playback.queue = [];
        if (playback.audio && playback.audio.pause) { try { playback.audio.pause(); } catch (e) { /* gone */ } }
        playback.audio = null;
        if (canBrowserSpeak()) { try { window.speechSynthesis.cancel(); } catch (e) { /* nothing playing */ } }
        setSpeaking(false);
    }

    // One sentence, said after the ones before it.
    function enqueueSpeech(text, language, bubble) {
        var said = voiceKit.speakable(text);
        if (!said) { return; }
        var token = playback.token;
        setSpeaking(true, bubble);

        if (speaksInBrowser()) {
            if (!canBrowserSpeak()) { setSpeaking(false); return; }
            var picked = voiceKit.pickVoice(deviceVoices, language, voicePrefs.gender);
            var utterance = new SpeechSynthesisUtterance(said);
            utterance.lang = language === "ms" ? "ms-MY" : "en-US";
            if (picked.voice) { utterance.voice = picked.voice; utterance.lang = picked.voice.lang; }
            utterance.onend = utterance.onerror = function () {
                if (token === playback.token && !window.speechSynthesis.speaking && !window.speechSynthesis.pending) {
                    setSpeaking(false);
                }
            };
            window.speechSynthesis.speak(utterance);
            return;
        }

        // The engine's voice: each sentence is fetched at once and played in
        // order, so the next is ready by the time the one before ends.
        var item = {
            ready: fetch(apiHost + "/api/v1/voice/speech", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ bot_id: botId, text: said.slice(0, 1200),
                                       voice: voiceKit.slotFor(language, voicePrefs.gender) })
            }).then(function (response) {
                if (!response.ok) { throw new Error("HTTP " + response.status); }
                return response.blob();
            }).then(function (blob) {
                return URL.createObjectURL(blob);
            }).catch(function (err) {
                console.warn("[ChatbotWidget] Voice unavailable:", err);
                return null;
            })
        };
        playback.queue.push(item);
        if (!playback.audio) { playNext(token); }
    }

    function playNext(token) {
        if (token !== playback.token) { return; }
        var item = playback.queue.shift();
        if (!item) { playback.audio = null; setSpeaking(false); return; }
        playback.audio = { pause: function () {} };
        item.ready.then(function (url) {
            if (token !== playback.token) { if (url) { URL.revokeObjectURL(url); } return; }
            if (!url) { playNext(token); return; }
            var audio = new Audio(url);
            playback.audio = audio;
            audio.onended = audio.onerror = function () { URL.revokeObjectURL(url); playNext(token); };
            audio.play().catch(function () { URL.revokeObjectURL(url); playNext(token); });
        });
    }

    // A whole answer, from a click on its speaker.
    function speakAll(markdownText, bubble) {
        if (!voiceOutput()) { return; }
        stopSpeaking();
        var text = voiceKit.speakable(markdownText);
        if (!text) { return; }
        var language = languageFor(text);
        var splitter = new voiceKit.SentenceSplitter(80);
        splitter.feed(text + "\n").concat(splitter.flush()).forEach(function (sentence) {
            enqueueSpeech(sentence, language, bubble);
        });
    }

    // An answer as it streams, a sentence at a time, when the visitor has
    // asked for answers to be read aloud.
    function answerSpeech(bubble) {
        if (!voiceOutput() || !voicePrefs.autoplay) { return null; }
        stopSpeaking();
        var token = playback.token;
        var splitter = new voiceKit.SentenceSplitter(60);
        var language = null;
        var inCode = false;

        function say(sentences) {
            sentences.forEach(function (sentence) {
                if (token !== playback.token) { return; }
                // A fenced code block is never read out, however it was split.
                var fences = (sentence.match(/```/g) || []).length;
                var wasInCode = inCode;
                if (fences % 2 === 1) { inCode = !inCode; }
                if (wasInCode || fences) { return; }
                if (!language) { language = languageFor(sentence); }
                enqueueSpeech(sentence, language, bubble);
            });
        }

        return {
            feed: function (text) { say(splitter.feed(text)); },
            finish: function () { say(splitter.flush()); },
            reset: function () {
                stopSpeaking();
                token = playback.token;
                splitter = new voiceKit.SentenceSplitter(60);
                language = null;
                inCode = false;
            }
        };
    }

    // The header menu: read aloud or not, which language, which voice.
    function setAutoplay(on) {
        voicePrefs.autoplay = !!on;
        if (!voicePrefs.autoplay) { stopSpeaking(); }
        shadowRoot.getElementById("voice-autoplay").checked = voicePrefs.autoplay;
        shadowRoot.getElementById("voice-tick").checked = voicePrefs.autoplay;
        saveVoicePrefs();
    }

    function renderVoiceMenu() {
        shadowRoot.getElementById("voice-autoplay").checked = !!voicePrefs.autoplay;
        shadowRoot.getElementById("voice-tick").checked = !!voicePrefs.autoplay;
        Array.prototype.forEach.call(voiceMenu.querySelectorAll(".voice-segment"), function (group) {
            var pref = group.getAttribute("data-pref");
            Array.prototype.forEach.call(group.querySelectorAll("button"), function (b) {
                b.setAttribute("aria-pressed", b.getAttribute("data-value") === voicePrefs[pref] ? "true" : "false");
            });
        });
        renderVoiceNote();
    }

    // In the browser, the voices are the device's; say plainly when the one
    // asked for is not there and what is used instead.
    function renderVoiceNote() {
        var note = shadowRoot.getElementById("voice-note");
        if (!note || !voiceKit || !botConfig.voice) { return; }
        if (!speaksInBrowser()) { note.textContent = ""; return; }
        if (!canBrowserSpeak()) { note.textContent = "This browser cannot read aloud."; return; }
        var languages = voicePrefs.language === "auto" ? ["en", "ms"] : [voicePrefs.language];
        var missing = [];
        languages.forEach(function (language) {
            var picked = voiceKit.pickVoice(deviceVoices, language, voicePrefs.gender);
            var name = language === "ms" ? "Malay" : "English";
            if (picked.match === "none") { missing.push("no " + name + " voice"); }
            else if (picked.match === "near") { missing.push("an Indonesian voice for Malay"); }
            else if (picked.match === "language") { missing.push("no " + voicePrefs.gender + " " + name + " voice, so another is used"); }
        });
        note.textContent = missing.length ? "This device has " + missing.join("; ") + "." : "";
    }

    btnVoice.addEventListener("click", function (event) {
        event.stopPropagation();
        var open = !voiceMenu.classList.contains("open");
        voiceMenu.classList.toggle("open", open);
        btnVoice.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) { refreshDeviceVoices(); renderVoiceMenu(); }
    });
    voiceMenu.addEventListener("click", function (event) {
        event.stopPropagation();
        var button = event.target.closest(".voice-segment button");
        if (button) {
            voicePrefs[button.parentNode.getAttribute("data-pref")] = button.getAttribute("data-value");
            saveVoicePrefs();
            renderVoiceMenu();
        }
    });
    shadowRoot.getElementById("voice-autoplay").addEventListener("change", function (event) {
        setAutoplay(event.target.checked);
    });
    shadowRoot.getElementById("voice-tick").addEventListener("change", function (event) {
        setAutoplay(event.target.checked);
    });
    shadowRoot.getElementById("voice-try").addEventListener("click", function () {
        var malay = voicePrefs.language === "ms" ||
            (voicePrefs.language === "auto" && String(navigator.language || "").toLowerCase().indexOf("ms") === 0);
        speakAll(malay ? "Helo! Beginilah bunyi suara saya apabila membaca jawapan."
                       : "Hello! This is how I will sound when I read answers aloud.");
    });
    shadowRoot.addEventListener("click", function () {
        if (voiceMenu.classList.contains("open")) {
            voiceMenu.classList.remove("open");
            btnVoice.setAttribute("aria-expanded", "false");
        }
    });

    // Spoken questions: the browser's own recognition, or a recording sent to
    // the engine's transcription server. What was heard goes into the box and
    // is sent, exactly as if it had been typed.
    function listenLanguage() {
        if (voicePrefs.language === "ms") { return "ms"; }
        if (voicePrefs.language === "en") { return "en"; }
        return String(navigator.language || "").toLowerCase().indexOf("ms") === 0 ? "ms" : "en";
    }

    function canListen() {
        if (!botConfig.voice || !botConfig.voice.input) { return false; }
        if (botConfig.voice.listen_with === "server") {
            return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
        }
        return !!browserRecognizer();
    }

    function setListening(on) {
        listening.active = on;
        micBtn.classList.toggle("recording", on);
        micBtn.title = on ? "Stop listening" : "Speak your question";
        micBtn.setAttribute("aria-label", micBtn.title);
        chatInput.placeholder = on ? "Listening..." : "Type a message...";
    }

    function heard(text) {
        text = String(text || "").trim();
        if (!text) { return; }
        chatInput.value = text;
        sendBtn.disabled = isStreaming;
        if (!isStreaming) { sendMessage(); }
    }

    function stopListening() {
        clearTimeout(listening.timer);
        if (listening.recognizer) { try { listening.recognizer.stop(); } catch (e) { /* stopped */ } }
        if (listening.recorder && listening.recorder.state !== "inactive") { listening.recorder.stop(); }
    }

    function startListening() {
        stopSpeaking();
        var language = listenLanguage();

        if (botConfig.voice.listen_with !== "server") {
            var Recognizer = browserRecognizer();
            var recognizer = new Recognizer();
            listening.recognizer = recognizer;
            recognizer.lang = language === "ms" ? "ms-MY" : "en-US";
            recognizer.interimResults = true;
            recognizer.maxAlternatives = 1;
            var finalText = "";
            recognizer.onresult = function (event) {
                var interim = "";
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) { finalText += event.results[i][0].transcript; }
                    else { interim += event.results[i][0].transcript; }
                }
                chatInput.value = (finalText + interim).trim();
            };
            recognizer.onerror = function (event) {
                if (event.error === "not-allowed" || event.error === "service-not-allowed") {
                    chatInput.placeholder = "Microphone blocked. Type a message...";
                }
            };
            recognizer.onend = function () {
                listening.recognizer = null;
                setListening(false);
                heard(finalText || chatInput.value);
            };
            setListening(true);
            recognizer.start();
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [];
            var recorder = new MediaRecorder(stream);
            listening.recorder = recorder;
            recorder.ondataavailable = function (event) { if (event.data && event.data.size) { chunks.push(event.data); } };
            recorder.onstop = function () {
                stream.getTracks().forEach(function (track) { track.stop(); });
                listening.recorder = null;
                setListening(false);
                if (!chunks.length) { return; }
                var blob = new Blob(chunks, { type: recorder.mimeType || "audio/webm" });
                var form = new FormData();
                form.append("bot_id", botId);
                form.append("language", language);
                form.append("audio", blob, "speech." + ((recorder.mimeType || "").indexOf("ogg") !== -1 ? "ogg" : "webm"));
                chatInput.placeholder = "Working out what you said...";
                fetch(apiHost + "/api/v1/voice/transcribe", { method: "POST", body: form })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                    .then(function (data) { chatInput.placeholder = "Type a message..."; heard(data.text); })
                    .catch(function () { chatInput.placeholder = "Could not hear that. Type a message..."; });
            };
            setListening(true);
            recorder.start();
            // One question, not a monologue.
            listening.timer = setTimeout(stopListening, 30000);
        }).catch(function () {
            chatInput.placeholder = "Microphone blocked. Type a message...";
        });
    }

    micBtn.addEventListener("click", function () {
        if (listening.active) { stopListening(); } else { startListening(); }
    });

    function setupVoice() {
        if (!voiceKit || !botConfig.voice) { return; }
        loadVoicePrefs();
        wrapper.classList.toggle("voice-on", voiceOutput());
        wrapper.classList.toggle("voice-listen", canListen());
        shadowRoot.getElementById("voice-tick").checked = voiceOutput() && !!voicePrefs.autoplay;
        if (voiceOutput() && speaksInBrowser() && canBrowserSpeak()) {
            refreshDeviceVoices();
            // Many browsers load their voices late. A listener, not the
            // onvoiceschanged property, so a host page's own handler stays.
            if (window.speechSynthesis.addEventListener) {
                window.speechSynthesis.addEventListener("voiceschanged", refreshDeviceVoices);
            }
        }
    }

    // Load Bot Configuration from Server
    function loadConfig() {
        fetch(apiHost + "/api/v1/bot/" + encodeURIComponent(botId) + "/config")
            .then(function (res) {
                // 404 = bot switched off or deleted: show nothing on the host page.
                if (res.status === 404) {
                    hostContainer.remove();
                    return null;
                }
                if (!res.ok) throw new Error("Status " + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data) return;
                hostContainer.style.display = "";
                botConfig.title = data.widget_title || data.name || botConfig.title;
                botConfig.greeting = data.widget_greeting || botConfig.greeting;
                botConfig.primaryColor = data.widget_primary_color || botConfig.primaryColor;
                botConfig.headerColor = data.widget_header_color || "";
                if (data.widget_header_text_color) {
                    chatHeader.style.setProperty("--header-text", data.widget_header_text_color);
                }
                botConfig.headerImageUrl = data.widget_header_image_url || "";
                botConfig.headerImageOpacity = readOpacity(data.widget_header_image_opacity);
                botConfig.backgroundImageOpacity = readOpacity(data.widget_background_image_opacity);
                botConfig.backgroundColor = data.widget_background_color || "";
                botConfig.backgroundImageUrl = data.widget_background_image_url || "";
                botConfig.position = data.widget_position || botConfig.position;
                botConfig.launcherIconUrl = data.launcher_icon_url || "";
                botConfig.botAvatarUrl = data.bot_avatar_url || "";
                botConfig.launcherShape = data.launcher_shape || "circle";
                botConfig.avatarShape = data.avatar_shape || "circle";
                botConfig.launcherSize = parseInt(data.launcher_size, 10) || 60;
                botConfig.closeIconUrl = data.close_icon_url || "";
                botConfig.closeShape = data.close_shape || "circle";
                botConfig.closeSize = parseInt(data.close_size, 10) || 52;
                // An offline bot has no voice: it cannot answer anything.
                botConfig.voice = data.offline ? null : (data.voice || null);
                setupVoice();

                // Switched off but set to show a message: the launcher opens a
                // small offline notice instead of the chat, and the corner
                // button can wear its own pictures and shapes. Empty follows
                // the online button.
                if (data.offline) {
                    wrapper.classList.add("is-offline");
                    var style = data.offline_style || {};
                    var offlineTitle = style.title || botConfig.title;
                    botConfig.position = style.position || botConfig.position;
                    shadowRoot.getElementById("offline-title").textContent = offlineTitle;
                    shadowRoot.getElementById("offline-text").textContent = data.offline_message;
                    [["offline-subtitle", data.offline_subtitle], ["offline-hours", data.offline_hours]].forEach(function (line) {
                        var el = shadowRoot.getElementById(line[0]);
                        el.textContent = line[1] || "";
                        el.hidden = !line[1];
                    });
                    var offlineBox = shadowRoot.getElementById("offline-box");
                    [["header", "#FFFFFF"], ["body", "#FFFFFF"], ["footer", "#FFFFFF"]].forEach(function (part) {
                        var bg = style[part[0] + "_bg"] || part[1];
                        offlineBox.style.setProperty("--off-" + part[0] + "-bg", bg);
                        if (style[part[0] + "_text"]) offlineBox.style.setProperty("--off-" + part[0] + "-text", style[part[0] + "_text"]);
                        if (style[part[0] + "_image"]) {
                            offlineBox.style.setProperty("--off-" + part[0] + "-image",
                                pictureLayers(style[part[0] + "_image"], bg, readOpacity(style[part[0] + "_image_opacity"])));
                        }
                    });
                    // What the "Show" choice picks: the chat avatar, its own picture, or an
                    // emoji or letter. Without a picture it falls back to the title's first letter.
                    var source = style.avatar_source || (style.avatar_image ? "image" : style.avatar_emoji ? "text" : "chat");
                    var offlineAvatar = shadowRoot.getElementById("offline-avatar");
                    offlineAvatar.className = "offline-avatar shape-" + (style.avatar_shape || "rounded").replace(/_/g, "-");
                    var avatarUrl = source === "image" ? style.avatar_image : source === "chat" ? botConfig.botAvatarUrl : "";
                    offlineAvatar.textContent = (source === "text" && style.avatar_emoji) || (offlineTitle || "?").trim().charAt(0).toUpperCase();
                    if (avatarUrl) {
                        var avatarImg = document.createElement("img");
                        avatarImg.src = avatarUrl;
                        avatarImg.alt = "";
                        offlineAvatar.replaceChildren(avatarImg);
                    }
                    botConfig.launcherIconUrl = data.offline_icon_url || botConfig.launcherIconUrl;
                    botConfig.launcherShape = data.offline_launcher_shape || botConfig.launcherShape;
                    botConfig.closeIconUrl = data.offline_close_icon_url || botConfig.closeIconUrl;
                    botConfig.closeShape = data.offline_close_shape || botConfig.closeShape;
                }

                botTitleEl.textContent = botConfig.title;
                updateColors(botConfig.primaryColor);

                // A picture at 100% is shown exactly as uploaded. Readability
                // is the operator's call: the opacity slider and the header
                // text colour are there for that.
                var headerColor = botConfig.headerColor || botConfig.primaryColor;
                if (botConfig.headerColor) {
                    chatHeader.style.backgroundColor = botConfig.headerColor;
                }
                if (botConfig.headerImageUrl) {
                    chatHeader.style.backgroundImage =
                        pictureLayers(botConfig.headerImageUrl, headerColor, botConfig.headerImageOpacity);
                    chatHeader.style.backgroundSize = "cover";
                    chatHeader.style.backgroundPosition = "center";
                }

                if (botConfig.backgroundColor) {
                    chatMessages.style.backgroundColor = botConfig.backgroundColor;
                }
                if (botConfig.backgroundImageUrl) {
                    chatMessages.style.backgroundImage = pictureLayers(
                        botConfig.backgroundImageUrl, botConfig.backgroundColor || "#FAFAFA", botConfig.backgroundImageOpacity);
                    chatMessages.style.backgroundSize = "cover";
                    chatMessages.style.backgroundPosition = "center";
                }

                // Set launcher image and shape if configured
                if (botConfig.launcherShape === "transparent_fit") {
                    launcherBtn.classList.add("shape-transparent-fit");
                } else if (botConfig.launcherShape === "circle_transparent") {
                    launcherBtn.classList.add("shape-circle-transparent");
                } else if (isCutout(botConfig.launcherShape)) {
                    launcherBtn.classList.add("shape-cutout");
                }

                fillShape(launcherInner, botConfig.launcherShape, botConfig.launcherIconUrl, "launcher-custom-img", "");

                // Close button: its own shape, size and optional artwork.
                launcherBtn.classList.add("close-shape-" + botConfig.closeShape.replace(/_/g, "-"));

                fillShape(closeInner, botConfig.closeShape, botConfig.closeIconUrl, "close-custom-img", "");

                wrapper.style.setProperty("--launcher-size", botConfig.launcherSize + "px");
                wrapper.style.setProperty("--close-size", botConfig.closeSize + "px");

                // Set header avatar and shape if configured
                if (botConfig.avatarShape === "transparent_fit") {
                    headerAvatar.classList.add("shape-transparent-fit");
                } else if (botConfig.avatarShape === "circle_transparent") {
                    headerAvatar.classList.add("shape-circle-transparent");
                }

                fillShape(headerAvatar, botConfig.avatarShape, botConfig.botAvatarUrl, "", "Avatar");

                if (botConfig.position === "bottom-left") {
                    wrapper.classList.add("position-bottom-left");
                }

                // Show greeting
                appendMessage("bot", botConfig.greeting);
            })
            .catch(function (err) {
                console.warn("[ChatbotWidget] Could not load remote bot config, using defaults:", err);
                hostContainer.style.display = "";
                appendMessage("bot", botConfig.greeting);
            });
    }

    // Toggle Chat visibility
    function toggleChat(open) {
        if (typeof open === "boolean") {
            isOpen = open;
        } else {
            isOpen = !isOpen;
        }

        if (isOpen) {
            wrapper.classList.add("widget-open");
            if (!wrapper.classList.contains("is-offline")) {
                setTimeout(function () { chatInput.focus(); }, 150);
            }
        } else {
            wrapper.classList.remove("widget-open");
            stopSpeaking();
            if (listening.active) { stopListening(); }
            // Reopening should always give the familiar size back.
            setExpanded(false);
        }
    }

    var isExpanded = false;

    function setExpanded(expanded) {
        isExpanded = expanded;
        wrapper.classList.toggle("expanded", expanded);
        btnExpand.title = expanded ? "Collapse chat" : "Expand chat";
        btnExpand.setAttribute("aria-label", btnExpand.title);
        btnExpand.setAttribute("aria-expanded", expanded ? "true" : "false");
    }

    btnExpand.addEventListener("click", function () { setExpanded(!isExpanded); });

    // Anywhere outside the panel is the backdrop, so this is the click-away.
    chatBackdrop.addEventListener("click", function () { setExpanded(false); });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") { return; }
        if (chatConfirm.classList.contains("open")) { closeClearConfirm(); return; }
        if (isExpanded) { setExpanded(false); }
    });

    launcherBtn.addEventListener("click", function () { toggleChat(); });
    btnCloseHeader.addEventListener("click", function () { toggleChat(false); });

    // Asked inside the panel rather than with the browser's confirm(), which
    // would carry the host site's address and look nothing like the widget.
    function openClearConfirm() {
        chatConfirm.classList.add("open");
        chatConfirm.setAttribute("aria-hidden", "false");
        chatConfirmCancel.focus();
    }

    function closeClearConfirm() {
        chatConfirm.classList.remove("open");
        chatConfirm.setAttribute("aria-hidden", "true");
        btnClear.focus();
    }

    btnClear.addEventListener("click", openClearConfirm);
    chatConfirmCancel.addEventListener("click", closeClearConfirm);
    chatConfirm.addEventListener("click", function (event) {
        if (event.target === chatConfirm) { closeClearConfirm(); }
    });
    chatConfirmOk.addEventListener("click", function () {
        stopSpeaking();
        messageHistory = [];
        // A fresh conversation, not only a fresh screen. The engine reads a
        // conversation from its own records by session, so keeping the old id
        // would have the bot remember what the visitor just cleared.
        sessionId = newSessionId();
        try { sessionStorage.setItem(sessionKey, sessionId); } catch (e) { /* private mode */ }
        while (chatMessages.firstChild && chatMessages.firstChild !== typingIndicator) {
            chatMessages.removeChild(chatMessages.firstChild);
        }
        appendMessage("bot", botConfig.greeting);
        closeClearConfirm();
    });

    // Input handling
    chatInput.addEventListener("input", function () {
        this.style.height = "auto";
        this.style.height = Math.min(this.scrollHeight, 96) + "px";
        sendBtn.disabled = !this.value.trim() || isStreaming;
    });

    chatInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            if (!sendBtn.disabled) {
                sendMessage();
            }
        }
    });

    sendBtn.addEventListener("click", function () {
        sendMessage();
    });

    // Send Message and Stream Response
    function sendMessage() {
        var text = chatInput.value.trim();
        if (!text || isStreaming) return;

        chatInput.value = "";
        chatInput.style.height = "auto";
        sendBtn.disabled = true;

        appendMessage("user", text);
        messageHistory.push({ role: "user", content: text });

        isStreaming = true;

        // The engine says which step it is on (reading the message, searching
        // a source, writing the reply), so the bubble shows that rather than
        // guessing. An engine too old to say keeps the first line throughout.
        var botBubble = appendMessage("bot", "");
        var speaker = answerSpeech(botBubble);
        botBubble.innerHTML = '<div class="thinking-box"><span class="thinking-pulse-ring"></span><span class="thinking-text fade-in">Reading your message...</span></div>';
        var thinkingTextEl = botBubble.querySelector(".thinking-text");
        var statusSwap = null;

        function showStatus(text) {
            if (!thinkingTextEl || !text) return;
            clearTimeout(statusSwap);
            thinkingTextEl.classList.remove("fade-in");
            thinkingTextEl.classList.add("fade-out");
            statusSwap = setTimeout(function () {
                if (thinkingTextEl) {
                    thinkingTextEl.textContent = text;
                    thinkingTextEl.classList.remove("fade-out");
                    thinkingTextEl.classList.add("fade-in");
                }
            }, 220);
        }

        function stopThinking() {
            clearTimeout(statusSwap);
            thinkingTextEl = null;
        }

        chatMessages.scrollTop = chatMessages.scrollHeight;

        var partialText = "";
        var firstChunk = true;
        var pendingSources = [];
        var pendingSourceKind = "";
        var thinkGroup = null;
        var thinkStartedAt = Date.now();
        var startedAt = Date.now();
        var pendingMeta = null;

        // The visitor's wait is measured here rather than on the server,
        // because the wait is what they actually experienced.
        function finishMeta() {
            if (!pendingMeta) return;
            pendingMeta.elapsed_ms = Date.now() - startedAt;
            attachMeta(botBubble, pendingMeta);
            pendingMeta = null;
        }

        function openThinkingGroup() {
            if (!thinkGroup) thinkGroup = ensureThinkingGroup(botBubble);
            return thinkGroup.querySelector(".thinking-body");
        }

        function foldThinking() {
            if (!thinkGroup) return;
            var seconds = Math.max(1, Math.round((Date.now() - thinkStartedAt) / 1000));
            thinkGroup.querySelector(".thinking-label").textContent = "Thought for " + seconds + "s";
            thinkGroup.classList.remove("open");
        }

        fetch(apiHost + "/api/v1/chat/stream", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                bot_id: botId,
                session_id: sessionId,
                message: text,
                history: messageHistory.slice(-10)
            })
        })
        .then(function (response) {
            if (!response.ok) {
                stopThinking();
                throw new Error("HTTP " + response.status + ": Failed to reach chat engine.");
            }

            var reader = response.body.getReader();
            var decoder = new TextDecoder("utf-8");
            var buffer = "";

            function processStream() {
                reader.read().then(function (result) {
                    if (result.done) {
                        if (speaker) { speaker.finish(); }
                        stopThinking();
                        isStreaming = false;
                        finishMeta();
                        if (partialText) {
                            messageHistory.push({ role: "assistant", content: partialText });
                        }
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split("\n");
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line.indexOf("data: ") === 0) {
                            var dataStr = line.substring(6).trim();
                            if (dataStr === "[DONE]") {
                                if (speaker) { speaker.finish(); }
                                stopThinking();
                                foldThinking();
                                isStreaming = false;
                                finishMeta();
                                if (partialText) {
                                    messageHistory.push({ role: "assistant", content: partialText });
                                    attachSources(botBubble, pendingSources, pendingSourceKind);
                                }
                                return;
                            }
                            try {
                                var parsed = JSON.parse(dataStr);
                                if (parsed.type === "status") {
                                    showStatus(parsed.text);
                                } else if (parsed.type === "sources") {
                                    pendingSources = parsed.sources || [];
                                    pendingSourceKind = parsed.kind || "";
                                } else if (parsed.type === "retract") {
                                    // The engine stopped a reply that began
                                    // reciting the bot's instructions. What was
                                    // shown is replaced, and no sources are named.
                                    stopThinking();
                                    foldThinking();
                                    botBubble.innerHTML = "";
                                    firstChunk = false;
                                    partialText = parsed.content || "";
                                    pendingSources = [];
                                    setBotText(botBubble, partialText);
                                    if (speaker) { stopSpeaking(); speaker = null; }
                                } else if (parsed.meta) {
                                    pendingMeta = parsed.meta;
                                } else if (parsed.error) {
                                    stopThinking();
                                    if (firstChunk) {
                                        botBubble.innerHTML = "";
                                        firstChunk = false;
                                    }
                                    partialText += "\n" + parsed.error;
                                    setBotText(botBubble, partialText);
                                } else if (parsed.reasoning) {
                                    var thinkBody = openThinkingGroup();
                                    thinkBody.textContent += parsed.reasoning;
                                    thinkBody.scrollTop = thinkBody.scrollHeight;
                                } else if (parsed.reclassify === "reasoning") {
                                    // A late closing tag revealed that what has
                                    // streamed into the bubble so far was the
                                    // model thinking aloud. Move it, and let the
                                    // real answer start the bubble over.
                                    var lateBody = openThinkingGroup();
                                    lateBody.textContent += partialText;
                                    lateBody.scrollTop = lateBody.scrollHeight;
                                    partialText = "";
                                    botBubble.textContent = "";
                                    firstChunk = true;
                                    if (speaker) { speaker.reset(); }
                                } else if (parsed.content) {
                                    if (firstChunk) {
                                        stopThinking();
                                        foldThinking();
                                        botBubble.innerHTML = "";
                                        firstChunk = false;
                                    }
                                    partialText += parsed.content;
                                    setBotText(botBubble, partialText);
                                    if (speaker) { speaker.feed(parsed.content); }
                                }
                                chatMessages.scrollTop = chatMessages.scrollHeight;
                            } catch (e) {
                                // Skip non-JSON
                            }
                        }
                    }

                    processStream();
                }).catch(function (streamErr) {
                    stopThinking();
                    console.error("[ChatbotWidget] Stream read error:", streamErr);
                    isStreaming = false;
                    if (botBubble) {
                        if (firstChunk) {
                            botBubble.innerHTML = "";
                            firstChunk = false;
                        }
                        partialText += "\n[Connection interrupted]";
                        setBotText(botBubble, partialText);
                    }
                });
            }

            processStream();
        })
        .catch(function (err) {
            stopThinking();
            isStreaming = false;
            if (botBubble) {
                botBubble.textContent = "" + (err.message || "Failed to communicate with chat server.");
            } else {
                appendMessage("bot", "" + (err.message || "Failed to communicate with chat server."));
            }
        });
    }

    // Initialize
    loadConfig();

})();
