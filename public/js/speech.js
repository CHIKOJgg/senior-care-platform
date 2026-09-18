/**
 * Speech Module: Web Speech API (STT Voice-to-Text & TTS Text-to-Speech)
 */
const SpeechHelper = {
    recognition: null,
    isListening: false,

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

    speak(text) {
        if (!('speechSynthesis' in window)) {
            console.warn('Синтез речи не поддерживается');
            return;
        }

        window.speechSynthesis.cancel(); // Stop current speech
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'ru-RU';
        utterance.rate = 0.95; // Slightly slower for elderly comprehension
        utterance.pitch = 1.0;
        window.speechSynthesis.speak(utterance);
    },

    speakPage() {
        const content = document.querySelector('main')?.innerText || document.body.innerText;
        const cleanText = content.substring(0, 600); // Read first meaningful paragraph
        this.speak('Читаю страницу: ' + cleanText);
    }
};
