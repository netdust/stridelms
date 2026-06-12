<div class="wrap">
    <div id="ntdst-assistant" x-data="ntdstAssistant()">

        <div class="assistant-container">

            <!-- Header -->
            <div class="assistant-header">
                <h2>Stride Assistent</h2>
                <button
                    @click="clear()"
                    class="button"
                    :disabled="loading || messages.length === 0"
                >
                    Gesprek wissen
                </button>
            </div>

            <!-- Message list -->
            <div class="assistant-messages" x-ref="messages">

                <!-- Empty state -->
                <div x-show="messages.length === 0 && !loading" class="assistant-empty">
                    <p>Stel een vraag over edities, inschrijvingen, of gebruikers.</p>
                    <p>Bijvoorbeeld: &ldquo;Hoeveel inschrijvingen heeft editie X?&rdquo;</p>
                </div>

                <template x-for="(msg, index) in messages" :key="msg.id">
                    <div
                        :class="{
                            'msg-group': true,
                            'msg-group-user': msg.type === 'user',
                            'msg-group-assistant': msg.type === 'assistant',
                            'msg-group-error': msg.type === 'error',
                            'msg-group-confirmation': msg.type === 'confirmation',
                        }"
                        :style="isFirstInGroup(index) ? '' : 'margin-top: -12px'"
                    >
                        <!-- User message -->
                        <div x-show="msg.type === 'user'" class="msg msg-user">
                            <div class="msg-content" x-text="msg.content"></div>
                        </div>

                        <!-- Assistant message -->
                        <div x-show="msg.type === 'assistant'" class="msg msg-assistant">
                            <div class="msg-row">
                                <template x-if="isFirstInGroup(index)">
                                    <div class="msg-avatar">S</div>
                                </template>
                                <template x-if="!isFirstInGroup(index)">
                                    <div class="msg-avatar-spacer"></div>
                                </template>
                                <div class="msg-content" x-html="msg.html"></div>
                                <template x-if="canCopy">
                                    <button class="msg-copy" @click.stop="copyMessage(msg)" title="Kopieer">
                                        <span x-show="copyTooltip !== msg.id">&#x1f4cb;</span>
                                        <span x-show="copyTooltip === msg.id" class="msg-copy-tooltip">Gekopieerd!</span>
                                    </button>
                                </template>
                            </div>

                            <!-- Download cards -->
                            <template x-for="dl in msg.downloads || []" :key="dl.filename">
                                <div class="download-card">
                                    <div class="download-info">
                                        <span class="download-icon">&#x1F4C4;</span>
                                        <div>
                                            <strong x-text="dl.filename"></strong>
                                            <span class="download-meta" x-text="dl.row_count + ' rijen &middot; CSV'"></span>
                                        </div>
                                    </div>
                                    <button @click="window.open(dl.url)" class="button">Downloaden</button>
                                </div>
                            </template>
                        </div>

                        <!-- Confirmation card -->
                        <div x-show="msg.type === 'confirmation'" class="msg msg-confirmation">
                            <div class="msg-row">
                                <div class="msg-avatar">S</div>
                                <div class="confirmation-card" :class="{ 'is-expired': msg.expired }">
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
                                    <div class="confirmation-actions" x-show="!msg.expired">
                                        <button @click="cancel()" class="button" :disabled="loading">
                                            Annuleren
                                        </button>
                                        <button @click="confirm()" class="button button-primary" :disabled="loading">
                                            Bevestigen
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Error message -->
                        <div x-show="msg.type === 'error'" class="msg msg-error">
                            <div class="msg-content" x-text="msg.message"></div>
                        </div>

                        <!-- Timestamp (shown on last message in group) -->
                        <template x-if="isLastInGroup(index) && msg.created_at">
                            <div class="msg-timestamp" x-text="relativeTime(msg.created_at)"></div>
                        </template>
                    </div>
                </template>

                <!-- Loading indicator -->
                <div x-show="loading" class="msg-loading">
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                    <span class="typing-dot"></span>
                </div>
            </div>

            <!-- Input area -->
            <div class="assistant-input">
                <textarea
                    x-ref="input"
                    x-model="input"
                    @keydown.enter.exact.prevent="send()"
                    @keydown.escape="$event.target.blur()"
                    @input="autoResize($event)"
                    placeholder="Stel een vraag of geef een opdracht..."
                    :disabled="loading || pending !== null"
                    rows="1"
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
