<div class="wrap">
    <div id="ntdst-assistant" x-data="ntdstAssistant()">

        <div class="assistant-container">

            <!-- Message list -->
            <div class="assistant-messages" x-ref="messages">
                <template x-for="msg in messages" :key="msg.id">
                    <div>
                        <!-- User message -->
                        <div x-show="msg.type === 'user'" class="msg msg-user">
                            <div class="msg-content" x-text="msg.content"></div>
                        </div>

                        <!-- Assistant message -->
                        <div x-show="msg.type === 'assistant'" class="msg msg-assistant">
                            <div class="msg-content" x-html="msg.html"></div>
                        </div>

                        <!-- Confirmation card -->
                        <div x-show="msg.type === 'confirmation'" class="msg msg-confirmation">
                            <div class="confirmation-card">
                                <h4 x-text="msg.summary?.title"></h4>
                                <p class="confirmation-desc" x-text="msg.summary?.description"></p>
                                <dl class="confirmation-details">
                                    <template x-for="detail in msg.summary?.details || []" :key="detail.label">
                                        <div class="confirmation-detail">
                                            <dt x-text="detail.label"></dt>
                                            <dd x-text="detail.value"></dd>
                                        </div>
                                    </template>
                                </dl>
                                <div class="confirmation-actions">
                                    <button @click="cancel()" class="button" :disabled="loading">
                                        Annuleren
                                    </button>
                                    <button @click="confirm()" class="button button-primary" :disabled="loading">
                                        Bevestigen
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Error message -->
                        <div x-show="msg.type === 'error'" class="msg msg-error">
                            <div class="msg-content" x-text="msg.message"></div>
                        </div>
                    </div>
                </template>

                <!-- Loading indicator -->
                <div x-show="loading" class="msg msg-loading">
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                </div>
            </div>

            <!-- Input area -->
            <div class="assistant-input">
                <textarea
                    x-model="input"
                    @keydown.enter.prevent="send()"
                    placeholder="Stel een vraag of geef een opdracht..."
                    :disabled="loading || pending !== null"
                    rows="2"
                ></textarea>
                <button
                    @click="send()"
                    class="button button-primary"
                    :disabled="loading || pending !== null || !input.trim()"
                >
                    Verstuur
                </button>
            </div>

        </div>

    </div>
</div>
