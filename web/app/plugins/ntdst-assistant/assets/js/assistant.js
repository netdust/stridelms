document.addEventListener('alpine:init', () => {
    Alpine.data('ntdstAssistant', () => ({
        messages: [],
        input: '',
        loading: false,
        pending: null,
        messageId: 0,
        copyTooltip: null,
        canCopy: !!navigator.clipboard,
        timestampInterval: null,

        init() {
            this.timestampInterval = setInterval(() => {
                // Force reactivity update for relative timestamps
                this.messages = [...this.messages];
            }, 30000);
        },

        destroy() {
            if (this.timestampInterval) {
                clearInterval(this.timestampInterval);
            }
        },

        nextId() {
            return ++this.messageId;
        },

        async send() {
            const content = this.input.trim();
            if (!content || this.loading || this.pending) return;

            this.input = '';

            // Reset textarea height
            const textarea = this.$refs.input;
            if (textarea) {
                textarea.style.height = 'auto';
            }

            this.messages.push({
                id: this.nextId(),
                type: 'user',
                content,
                created_at: new Date().toISOString(),
            });
            this.loading = true;

            this.$nextTick(() => this.scrollToBottom());

            try {
                const data = await this.post('chat', { content });
                this.handleResponse(data);
            } catch (err) {
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: err.message || 'Onbekende fout.',
                    created_at: new Date().toISOString(),
                });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        async confirm() {
            if (!this.pending || this.loading) return;
            this.loading = true;

            try {
                const data = await this.post('confirm', {
                    confirm_token: this.pending.confirm_token,
                });
                this.pending = null;
                this.handleResponse(data);
            } catch (err) {
                // Mark last confirmation as expired
                this.markLastConfirmationExpired();
                this.pending = null;
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: err.message,
                    created_at: new Date().toISOString(),
                });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        async cancel() {
            if (!this.pending || this.loading) return;
            this.loading = true;

            try {
                const data = await this.post('cancel', {
                    confirm_token: this.pending.confirm_token,
                });
                this.pending = null;
                this.handleResponse(data);
            } catch (err) {
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: err.message,
                    created_at: new Date().toISOString(),
                });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        async clear() {
            if (this.loading) return;
            this.loading = true;

            try {
                await this.post('clear', {});
                this.messages = [];
                this.pending = null;
            } catch (err) {
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: err.message || 'Kon gesprek niet wissen.',
                    created_at: new Date().toISOString(),
                });
            } finally {
                this.loading = false;
            }
        },

        handleResponse(data) {
            if (data.type === 'response') {
                this.messages.push({
                    id: this.nextId(),
                    type: 'assistant',
                    content: data.content,
                    html: data.html || data.content,
                    downloads: data.downloads || [],
                    created_at: data.created_at || new Date().toISOString(),
                });
            } else if (data.type === 'confirmation') {
                this.pending = data;
                this.messages.push({
                    id: this.nextId(),
                    type: 'confirmation',
                    summary: data.summary,
                    expired: false,
                    created_at: data.created_at || new Date().toISOString(),
                });
            } else if (data.type === 'error') {
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: data.message || data.content || data.text || 'Onbekende fout.',
                    created_at: data.created_at || new Date().toISOString(),
                });
            }
        },

        markLastConfirmationExpired() {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                if (this.messages[i].type === 'confirmation') {
                    this.messages[i].expired = true;
                    break;
                }
            }
        },

        copyMessage(msg) {
            if (!this.canCopy) return;

            try {
                navigator.clipboard.writeText(msg.content || '');
                this.copyTooltip = msg.id;
                setTimeout(() => {
                    this.copyTooltip = null;
                }, 1500);
            } catch (e) {
                // Silently fail — clipboard may not be available
            }
        },

        relativeTime(iso) {
            if (!iso) return '';

            const now = Date.now();
            const then = new Date(iso).getTime();
            const diffSec = Math.floor((now - then) / 1000);

            if (diffSec < 30) return 'zojuist';
            if (diffSec < 60) return `${diffSec} sec geleden`;
            if (diffSec < 3600) {
                const min = Math.floor(diffSec / 60);
                return `${min} min geleden`;
            }
            if (diffSec < 86400) {
                const hr = Math.floor(diffSec / 3600);
                return `${hr} uur geleden`;
            }

            const d = new Date(iso);
            return d.toLocaleDateString('nl-BE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        },

        autoResize(event) {
            const el = event.target;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        },

        /**
         * Check if this message is the first in a consecutive group of same-type messages.
         */
        isFirstInGroup(index) {
            if (index === 0) return true;
            return this.messages[index].type !== this.messages[index - 1].type;
        },

        /**
         * Check if this message is the last in a consecutive group of same-type messages.
         */
        isLastInGroup(index) {
            if (index >= this.messages.length - 1) return true;
            return this.messages[index].type !== this.messages[index + 1].type;
        },

        async post(endpoint, body) {
            const response = await fetch(ntdstAssistantConfig.restUrl + endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ntdstAssistantConfig.nonce,
                },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                let errorMsg = `HTTP ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.message || errorData.data?.message || errorMsg;
                } catch (e) { /* ignore parse errors */ }
                throw new Error(errorMsg);
            }

            return response.json();
        },

        scrollToBottom() {
            const el = this.$refs.messages;
            if (el) el.scrollTop = el.scrollHeight;
        },
    }));
});
