document.addEventListener('alpine:init', () => {
    Alpine.data('ntdstAssistant', () => ({
        messages: [],
        input: '',
        loading: false,
        pending: null,
        messageId: 0,

        nextId() {
            return ++this.messageId;
        },

        async send() {
            const content = this.input.trim();
            if (!content || this.loading || this.pending) return;

            this.input = '';
            this.messages.push({ id: this.nextId(), type: 'user', content });
            this.loading = true;

            this.$nextTick(() => this.scrollToBottom());

            try {
                const data = await this.post('chat', { content });
                this.handleResponse(data);
            } catch (err) {
                this.messages.push({ id: this.nextId(), type: 'error', message: err.message || 'Onbekende fout.' });
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
                this.messages.push({ id: this.nextId(), type: 'error', message: err.message });
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
                this.messages.push({ id: this.nextId(), type: 'error', message: err.message });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        handleResponse(data) {
            if (data.type === 'response') {
                this.messages.push({
                    id: this.nextId(),
                    type: 'assistant',
                    content: data.content,
                    html: data.html || data.content,
                });
            } else if (data.type === 'confirmation') {
                this.pending = data;
                this.messages.push({
                    id: this.nextId(),
                    type: 'confirmation',
                    summary: data.summary,
                });
            } else if (data.type === 'error') {
                this.messages.push({
                    id: this.nextId(),
                    type: 'error',
                    message: data.message || data.content || data.text || 'Onbekende fout.',
                });
            }
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
                // Try to parse error body for a message
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
