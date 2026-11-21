












const SpeechHelper = {
    recognition: null,
    isListening: false,

    state: {
        enabled: true,
        current: null,
        currentPriority: 99,
        queue: [],
        lastText: '',
        lastAt: 0,
        profile: {
            forcedColors: false,
            highContrast: false,
            reducedMotion: false,
            darkTheme: false,
            seniorMode: false,
            largeText: false,
            blindMode: false,
            screenReaderLikely: false
        },
        autoAnnounced: false,
        voice: null
    },

    detectProfile() {
        const mq = (query) => {
            try {
                return !!(window.matchMedia && window.matchMedia(query).matches);
            } catch (e) {
                return false;
            }
        };

        const forcedColors = mq('(forced-colors: active)');
        const highContrast = mq('(prefers-contrast: more)');
        const reducedMotion = mq('(prefers-reduced-motion: reduce)');
        const darkTheme = mq('(prefers-color-scheme: dark)');

        const rootFontSize = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        const seniorMode = document.body.classList.contains('senior-mode')
            || localStorage.getItem('seniorMode') === 'true';
        const largeText = rootFontSize >= 18;




        const blindMode = forcedColors || highContrast || reducedMotion || seniorMode || largeText;
        const screenReaderLikely = forcedColors || highContrast;

        return {
            forcedColors,
            highContrast,
            reducedMotion,
            darkTheme,
            seniorMode,
            largeText,
            blindMode,
            screenReaderLikely
        };
    },

    refreshProfile({ announce = false } = {}) {
        const previous = this.state.profile;
        const profile = this.detectProfile();
        this.state.profile = profile;

        document.body.classList.toggle('blind-mode', profile.blindMode);

        const badge = document.getElementById('a11yModeBadge');
        if (badge) {
            badge.style.display = profile.blindMode ? 'inline-flex' : 'none';
            badge.textContent = profile.screenReaderLikely ? 'Экранный диктор' : 'Режим доступности';
        }

        this.updateUI();

        if (announce && profile.blindMode && !previous.blindMode) {
            this.announce('Включён режим доступности. Озвучка адаптирована.', 1);
        }
        if (announce && !profile.blindMode && previous.blindMode) {
            this.announce('Режим доступности выключен.', 1);
        }

        return profile;
    },




    initSmartNarration() {
        if (this._initialized) return;
        this._initialized = true;

        this.ensureLiveRegion();
        this.loadSettings();
        this.refreshProfile();

        if ('speechSynthesis' in window) {
            this.pickVoice();
            window.speechSynthesis.addEventListener?.('voiceschanged', () => {
                this.state.voice = null;
                this.pickVoice();
            });
        }

        const queries = [
            '(forced-colors: active)',
            '(prefers-contrast: more)',
            '(prefers-reduced-motion: reduce)',
            '(prefers-color-scheme: dark)'
        ];
        queries.forEach((query) => {
            try {
                window.matchMedia(query).addEventListener?.('change', () => this.refreshProfile({ announce: true }));
            } catch (e) {

            }
        });


        document.addEventListener('visibilitychange', () => {
            if (document.hidden) this.stop();
        });
        document.addEventListener('keydown', (e) => {
            if (e.altKey && (e.key === 's' || e.key === 'ы')) {
                e.preventDefault();
                this.stop();
                this.announce('Озвучка остановлена.', 1);
            }
        });

        this.bindToggleButton();
        this.announcePageOnLoad();


        window.addEventListener('beforeunload', () => this.stop());
    },

    ensureLiveRegion() {
        if (document.getElementById('a11yLiveRegion')) return;
        const region = document.createElement('div');
        region.id = 'a11yLiveRegion';
        region.className = 'sr-only';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        region.setAttribute('aria-atomic', 'true');
        document.body.appendChild(region);
    },

    loadSettings() {
        const stored = localStorage.getItem('a11y.narration');
        this.state.enabled = stored !== '0';
    },

    saveSettings() {
        localStorage.setItem('a11y.narration', this.state.enabled ? '1' : '0');
    },

    setEnabled(enabled) {
        this.state.enabled = !!enabled;
        this.saveSettings();
        if (!this.state.enabled) {
            this.stop();
        }
        this.updateUI();
    },

    toggle() {
        const next = !this.state.enabled;
        this.setEnabled(next);
        if (next) {
            this.speak('Озвучка включена. Нажмите «Озвучить», чтобы прослушать страницу.', { priority: 1 });
        } else {
            this.announce('Озвучка выключена.', 1);
        }
        return next;
    },

    bindToggleButton() {
        const btn = document.getElementById('toggleNarrationBtn');
        if (!btn || btn.dataset.bound === '1') {
            this.updateUI();
            return;
        }
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => this.toggle());
        this.updateUI();
    },

    updateUI() {
        const btn = document.getElementById('toggleNarrationBtn');
        if (btn) {
            btn.setAttribute('aria-pressed', this.state.enabled ? 'true' : 'false');
        }
        const label = document.getElementById('toggleNarrationLabel');
        if (label) {
            label.textContent = this.state.enabled ? 'Озвучка: вкл' : 'Озвучка: выкл';
        }
    },

    announcePageOnLoad() {
        if (!this.state.profile.blindMode) return;



        setTimeout(() => {
            if (this.state.autoAnnounced) return;
            this.state.autoAnnounced = true;
            if (this.state.profile.screenReaderLikely) {
                const heading = document.querySelector('h1')?.innerText?.trim() || document.title;
                this.announce(heading, 2);
            } else {
                this.speakPage({ automatic: true });
            }
        }, 900);
    },




    pickVoice() {
        if (!('speechSynthesis' in window)) return null;
        if (this.state.voice) return this.state.voice;
        const voices = window.speechSynthesis.getVoices() || [];
        const ru = voices.find((v) => /^ru[-_]RU/i.test(v.lang))
            || voices.find((v) => /^ru/i.test(v.lang));
        this.state.voice = ru || null;
        return this.state.voice;
    },




    announce(text, priority = 2) {
        const clean = String(text || '').replace(/\s+/g, ' ').trim();
        if (!clean) return;
        this.ensureLiveRegion();
        const region = document.getElementById('a11yLiveRegion');
        if (!region) return;
        region.setAttribute('aria-live', priority === 0 ? 'assertive' : 'polite');
        region.textContent = '';
        setTimeout(() => {
            region.textContent = clean;
        }, 30);
    },

    cleanText(text, maxLength = 450) {
        let clean = String(text || '')
            .replace(/\s+/g, ' ')
            .replace(/\s*([,.!?;:])\s*/g, '$1 ')
            .trim();
        if (clean.length > maxLength) {
            clean = clean.slice(0, maxLength);
            const cut = clean.lastIndexOf(' ');
            if (cut > maxLength * 0.6) clean = clean.slice(0, cut);
            clean += '…';
        }
        return clean;
    },

    speak(text, { priority = 1, maxLength = 450, force = false } = {}) {
        const clean = this.cleanText(text, maxLength);
        if (!clean) return;

        this.announce(clean, priority);

        if (!this.state.enabled && !force && priority !== 0) return;

        const now = Date.now();
        if (clean === this.state.lastText && now - this.state.lastAt < 2500 && priority > 0) {
            return;
        }
        this.state.lastText = clean;
        this.state.lastAt = now;

        if (!('speechSynthesis' in window)) return;


        if (priority === 0) {
            this.state.queue = [];
            this.cancelCurrent();
            this.speakNow(clean, 0);
            return;
        }

        if (this.state.current && this.state.currentPriority <= priority) {
            this.state.queue.push({ text: clean, priority });
            return;
        }


        this.state.queue = [];
        this.cancelCurrent();
        this.speakNow(clean, priority);
    },

    speakNow(text, priority) {
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'ru-RU';
        const voice = this.pickVoice();
        if (voice) utterance.voice = voice;
        utterance.rate = this.state.profile.blindMode || this.state.profile.seniorMode ? 0.86 : 0.98;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        this.state.current = utterance;
        this.state.currentPriority = priority;

        const done = () => {
            if (this.state.current === utterance) {
                this.state.current = null;
                this.state.currentPriority = 99;
            }
            this.drainQueue();
        };
        utterance.onend = done;
        utterance.onerror = done;

        try {
            window.speechSynthesis.speak(utterance);
        } catch (e) {
            done();
        }
    },

    drainQueue() {
        if (!this.state.queue.length) return;
        if (this.state.current) return;
        this.state.queue.sort((a, b) => a.priority - b.priority);
        const next = this.state.queue.shift();
        if (next) this.speakNow(next.text, next.priority);
    },

    cancelCurrent() {
        this.state.current = null;
        this.state.currentPriority = 99;
        if ('speechSynthesis' in window) {
            try {
                window.speechSynthesis.cancel();
            } catch (e) {

            }
        }
    },

    stop() {
        this.state.queue = [];
        this.cancelCurrent();
    },




    speakPage({ automatic = false } = {}) {
        const parts = [];
        const pageTitle = document.title.replace(/\s*[—-]\s*ЗаботаРядом\s*$/, '').trim();
        if (pageTitle) parts.push(pageTitle);

        const scope = document.querySelector('main') || document.body;
        const headings = [...scope.querySelectorAll('h1, h2, h3')]
            .filter((el) => el.offsetParent !== null)
            .map((el) => el.innerText.trim())
            .filter(Boolean)
            .slice(0, 4);
        if (headings.length) parts.push(headings.join('. '));

        const actions = [...scope.querySelectorAll('button, .btn')]
            .filter((el) => el.offsetParent !== null && !el.disabled && el.innerText.trim())
            .map((el) => el.innerText.replace(/\s+/g, ' ').trim())
            .slice(0, 4);
        if (actions.length) parts.push('Доступные действия: ' + actions.join(', '));

        const intro = automatic ? '' : 'Читаю страницу. ';
        this.speak(intro + parts.join('. '), { priority: automatic ? 2 : 1, maxLength: 700 });
    },

    initSTT(onResultCallback, onEndCallback) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            console.warn('Web Speech Recognition API не поддерживается данным браузером');
            return false;
        }

        this.recognition = new SpeechRecognition();
        this.recognition.lang = 'ru-RU';
        this.recognition.continuous = false;
        this.recognition.interimResults = false;

        this.recognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            if (this.checkEmergencyVoiceCommand(transcript)) {
                if (onEndCallback) onEndCallback();
                return;
            }
            if (onResultCallback) onResultCallback(transcript);
        };

        this.recognition.onerror = (event) => {
            console.error('Ошибка распознавания речи:', event.error);
            this.isListening = false;
            if (onEndCallback) onEndCallback();
        };

        this.recognition.onend = () => {
            this.isListening = false;
            if (onEndCallback) onEndCallback();
        };

        return true;
    },

    toggleListening(buttonElement, onResultCallback) {
        if (!this.recognition) {
            this.initSTT(onResultCallback, () => {
                if (buttonElement) buttonElement.classList.remove('listening');
            });
        }

        if (this.isListening) {
            this.recognition.stop();
            this.isListening = false;
            if (buttonElement) buttonElement.classList.remove('listening');
        } else {
            try {
                this.recognition.start();
                this.isListening = true;
                if (buttonElement) buttonElement.classList.add('listening');
                this.speak('Слушаю вас. Скажите, какая помощь вам нужна.');
            } catch (e) {
                console.error(e);
            }
        }
    },

    checkEmergencyVoiceCommand(transcript) {
        if (!transcript) return false;
        const clean = transcript.toLowerCase();
        const emergencyRegex = /(?:^|[^\p{L}\p{N}])(помощь|помогите|тревога|сос|sos|спасите|срочно помощь|вызовите помощь)(?=$|[^\p{L}\p{N}])/ui;
        if (emergencyRegex.test(clean)) {
            console.warn('Voice SOS command detected:', transcript);
            this.speak('Обнаружена голосовая команда тревоги. Передаю сигнал SOS координаторам и в службу 112!', { priority: 0 });
            if (typeof window.triggerSos === 'function') {
                window.triggerSos('Голосовой вызов тревоги: ' + transcript);
                return true;
            }
        }
        return false;
    }
};
