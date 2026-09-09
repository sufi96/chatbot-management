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
    var sessionId = sessionStorage.getItem(sessionKey);
    if (!sessionId) {
        sessionId = "sess_" + Math.random().toString(36).substring(2, 11) + Date.now().toString(36);
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
            transition: opacity 0.25s ease, transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
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

        /* Header */
        .chat-header {
            background: var(--primary-color, #1f2937);
            color: #ffffff;
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

        .status-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.85);
            margin-top: 2px;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10B981;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-icon-btn {
            background: none;
            border: none;
            color: #ffffff;
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

        .message-time {
            font-size: 11px;
            color: #A1A1AA;
            margin-top: 3px;
            padding: 0 4px;
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
            text-align: center;
            color: #A1A1AA;
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
                    <button class="action-icon-btn" id="btn-clear" title="Clear conversation">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
                    </button>
                    <button class="action-icon-btn" id="btn-close-header" title="Close chat">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages">
                <!-- Initial greeting injected here -->
                <div class="typing-indicator" id="typing-indicator">
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                </div>
            </div>

            <div class="chat-footer">
                <div class="input-row">
                    <textarea class="chat-input" id="chat-input" rows="1" placeholder="Type a message..."></textarea>
                    <button class="send-btn" id="send-btn" disabled>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>
                </div>
                <div class="powered-by">Powered by Chatbot Management Hub</div>
            </div>
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
    var btnCloseHeader = shadowRoot.getElementById("btn-close-header");
    var btnClear = shadowRoot.getElementById("btn-clear");
    var chatMessages = shadowRoot.getElementById("chat-messages");
    var chatInput = shadowRoot.getElementById("chat-input");
    var sendBtn = shadowRoot.getElementById("send-btn");
    var typingIndicator = shadowRoot.getElementById("typing-indicator");
    var botTitleEl = shadowRoot.getElementById("bot-title");

    function updateColors(hex) {
        if (!hex) return;
        wrapper.style.setProperty("--primary-color", hex);
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

            if (botConfig.botAvatarUrl) {
                miniAvatar.innerHTML = '<img src="' + botConfig.botAvatarUrl + '" alt="Bot">';
            } else {
                miniAvatar.innerHTML = DEFAULT_AVATAR_SVG;
            }
            row.appendChild(miniAvatar);
        }

        var contentWrapper = document.createElement("div");
        contentWrapper.className = "message-content-wrapper";

        var bubble = document.createElement("div");
        bubble.className = "message-bubble";
        if (text) {
            bubble.textContent = text;
        }

        var time = document.createElement("div");
        time.className = "message-time";
        var now = new Date();
        time.textContent = now.getHours().toString().padStart(2, "0") + ":" + now.getMinutes().toString().padStart(2, "0");

        contentWrapper.appendChild(bubble);
        contentWrapper.appendChild(time);
        row.appendChild(contentWrapper);

        // Insert before the typing indicator
        chatMessages.insertBefore(row, typingIndicator);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return bubble;
    }

    // Load Bot Configuration from Server
    function loadConfig() {
        fetch(apiHost + "/api/v1/bot/" + encodeURIComponent(botId) + "/config")
            .then(function (res) {
                if (!res.ok) throw new Error("Status " + res.status);
                return res.json();
            })
            .then(function (data) {
                botConfig.title = data.widget_title || data.name || botConfig.title;
                botConfig.greeting = data.widget_greeting || botConfig.greeting;
                botConfig.primaryColor = data.widget_primary_color || botConfig.primaryColor;
                botConfig.position = data.widget_position || botConfig.position;
                botConfig.launcherIconUrl = data.launcher_icon_url || "";
                botConfig.botAvatarUrl = data.bot_avatar_url || "";
                botConfig.launcherShape = data.launcher_shape || "circle";
                botConfig.avatarShape = data.avatar_shape || "circle";
                botConfig.launcherSize = parseInt(data.launcher_size, 10) || 60;
                botConfig.closeIconUrl = data.close_icon_url || "";
                botConfig.closeShape = data.close_shape || "circle";
                botConfig.closeSize = parseInt(data.close_size, 10) || 52;

                botTitleEl.textContent = botConfig.title;
                updateColors(botConfig.primaryColor);

                // Set launcher image and shape if configured
                if (botConfig.launcherShape === "transparent_fit") {
                    launcherBtn.classList.add("shape-transparent-fit");
                } else if (botConfig.launcherShape === "circle_transparent") {
                    launcherBtn.classList.add("shape-circle-transparent");
                }

                if (botConfig.launcherIconUrl) {
                    launcherInner.innerHTML = '<img src="' + botConfig.launcherIconUrl + '" class="launcher-custom-img" alt="">';
                }

                // Close button: its own shape, size and optional artwork.
                launcherBtn.classList.add("close-shape-" + botConfig.closeShape.replace(/_/g, "-"));

                if (botConfig.closeIconUrl) {
                    closeInner.innerHTML = '<img src="' + botConfig.closeIconUrl + '" class="close-custom-img" alt="">';
                }

                wrapper.style.setProperty("--launcher-size", botConfig.launcherSize + "px");
                wrapper.style.setProperty("--close-size", botConfig.closeSize + "px");

                // Set header avatar and shape if configured
                if (botConfig.avatarShape === "transparent_fit") {
                    headerAvatar.classList.add("shape-transparent-fit");
                } else if (botConfig.avatarShape === "circle_transparent") {
                    headerAvatar.classList.add("shape-circle-transparent");
                }

                if (botConfig.botAvatarUrl) {
                    headerAvatar.innerHTML = '<img src="' + botConfig.botAvatarUrl + '" alt="Avatar">';
                }

                if (botConfig.position === "bottom-left") {
                    wrapper.classList.add("position-bottom-left");
                }

                // Show greeting
                appendMessage("bot", botConfig.greeting);
            })
            .catch(function (err) {
                console.warn("[ChatbotWidget] Could not load remote bot config, using defaults:", err);
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
            setTimeout(function () { chatInput.focus(); }, 150);
        } else {
            wrapper.classList.remove("widget-open");
        }
    }

    launcherBtn.addEventListener("click", function () { toggleChat(); });
    btnCloseHeader.addEventListener("click", function () { toggleChat(false); });

    btnClear.addEventListener("click", function () {
        if (confirm("Clear current conversation?")) {
            messageHistory = [];
            while (chatMessages.firstChild && chatMessages.firstChild !== typingIndicator) {
                chatMessages.removeChild(chatMessages.firstChild);
            }
            appendMessage("bot", botConfig.greeting);
        }
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

        // Dynamic thinking animation that rotates phrases every few seconds so user knows it's working
        var thinkingPhrases = [
            "Thinking...",
            "Analyzing request...",
            "Checking knowledge...",
            "Formulating answer...",
            "Crafting response..."
        ];
        var phraseIndex = 0;
        var thinkingInterval = null;

        var botBubble = appendMessage("bot", "");
        botBubble.innerHTML = '<div class="thinking-box"><span class="thinking-pulse-ring"></span><span class="thinking-text fade-in">Thinking...</span></div>';
        var thinkingTextEl = botBubble.querySelector(".thinking-text");

        thinkingInterval = setInterval(function () {
            phraseIndex = (phraseIndex + 1) % thinkingPhrases.length;
            if (thinkingTextEl) {
                thinkingTextEl.classList.remove("fade-in");
                thinkingTextEl.classList.add("fade-out");
                setTimeout(function () {
                    if (thinkingTextEl) {
                        thinkingTextEl.textContent = thinkingPhrases[phraseIndex];
                        thinkingTextEl.classList.remove("fade-out");
                        thinkingTextEl.classList.add("fade-in");
                    }
                }, 220);
            }
        }, 2500);

        function stopThinking() {
            if (thinkingInterval) {
                clearInterval(thinkingInterval);
                thinkingInterval = null;
            }
        }

        chatMessages.scrollTop = chatMessages.scrollHeight;

        var partialText = "";
        var firstChunk = true;

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
                        stopThinking();
                        isStreaming = false;
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
                                stopThinking();
                                isStreaming = false;
                                if (partialText) {
                                    messageHistory.push({ role: "assistant", content: partialText });
                                }
                                return;
                            }
                            try {
                                var parsed = JSON.parse(dataStr);
                                if (parsed.error) {
                                    stopThinking();
                                    if (firstChunk) {
                                        botBubble.innerHTML = "";
                                        firstChunk = false;
                                    }
                                    partialText += "\n⚠️ " + parsed.error;
                                    botBubble.textContent = partialText;
                                } else if (parsed.content) {
                                    if (firstChunk) {
                                        stopThinking();
                                        botBubble.innerHTML = "";
                                        firstChunk = false;
                                    }
                                    partialText += parsed.content;
                                    botBubble.textContent = partialText;
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
                        botBubble.textContent += "\n[Connection interrupted]";
                    }
                });
            }

            processStream();
        })
        .catch(function (err) {
            stopThinking();
            isStreaming = false;
            if (botBubble) {
                botBubble.textContent = "⚠️ " + (err.message || "Failed to communicate with chat server.");
            } else {
                appendMessage("bot", "⚠️ " + (err.message || "Failed to communicate with chat server."));
            }
        });
    }

    // Initialize
    loadConfig();

})();
