<template>
    <div class="w-full h-full relative" id="chat">
        <div class="messages" ref="messages">
            <div class="message" v-for="message in messages" :class="message.color">
                <a class="title" :href="'/players/' + message.license" target="_blank">{{ message.title }}:</a>
                <span class="text">{{ message.text }}</span>
            </div>

            <div class="message red" v-if="!socket">
                <span class="title">{{ t("staff_chat.voice_chat") }}:</span>
                <span class="text">{{ t("staff_chat.disconnected") }}</span>
            </div>

            <div class="message red" v-if="isLoading">
                <span class="title">{{ t("staff_chat.voice_chat") }}:</span>
                <span class="text">{{ t("staff_chat.connecting") }}</span>
            </div>

            <div ref="scrollTo"></div>
        </div>

        <div class="input-wrap" :class="{ 'opacity-75': isSendingChat }" v-if="!isLoading && socket">
            <div class="prefix">
                <i class="fas fa-spinner fa-spin" v-if="isSendingChat" ref="chat"></i>
                <span v-else>➤</span>
            </div>
            <input class="input !outline-none" v-model="chatInput" spellcheck="false" @keydown="keydown" :disabled="isSendingChat" />
        </div>
    </div>
</template>

<style>
@import url("https://fonts.googleapis.com/css2?family=Rubik:wght@400;500&display=swap");

html,
body {
    width: 100%;
    height: 100%;
}

#chat {
    background: url(/images/default.webp);
    background-size: cover;
    background-position: center;
    padding: 5vh;
    font-family: "Rubik", sans-serif;
    font-size: 2.15vh;
    color: white;
    line-height: 4vh;
    padding-bottom: 14vh;
}

.messages {
    display: flex;
    flex-wrap: wrap;
    grid-gap: 1.4vh;
    align-items: flex-start;
    align-content: flex-start;
    height: 100%;
    overflow-y: auto;
}

.message {
    padding: 1vh 2.2vh;
    border-radius: 1.2vh;
    overflow: hidden;
    max-width: 100%;
    word-wrap: break-word;

    .title {
        font-weight: 500;
    }
}

.purple {
    background: rgba(125, 63, 166, 0.85);
}

.green {
    background: rgba(50, 130, 35, 0.85);
}

.red {
    background: rgba(140, 50, 35, 0.85);
}

.input-wrap {
    position: absolute;
    bottom: 5vh;
    left: 5vh;
    right: 5vh;
    background: rgba(44, 62, 80, 0.7);
    border: 1px solid rgb(77, 144, 254);
    box-shadow: 0 0 2px rgb(77, 144, 254);
    margin-top: 2vh;
    padding: 1vh 2.2vh;
    border-radius: 1.2vh;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.input {
    flex-grow: 1;
    border: none;
    background: none;
    font-weight: 500;

    padding-left: 1.3vh;
    padding-right: 1.3vh;

    &:focus {
        outline: none;
    }
}
</style>

<script>
import DataCompressor from "./Map/DataCompressor";

import { io } from "socket.io-client";

export default {
    data() {
        return {
            chatInput: "",

            messages: [],

            isSendingChat: false,
            isLoading: false,
            error: false,

            socket: false
        };
    },
    methods: {
        keydown(e) {
            if (e.key !== "Enter") return;

            this.sendChat();
        },
        async sendChat() {
            if (this.isSendingChat) return;

            this.isSendingChat = true;

            try {
                await axios.post('/chat', {
                    message: this.chatInput
                });
            } catch (e) { }

            this.chatInput = "";
            this.isSendingChat = false;

            this.$refs.chat.focus();
        },
        chatKeyPress(event) {
            if (event.key === 'Enter') {
                this.sendChat();
            }
        },
        init() {
            if (this.socket) return;

            this.isLoading = true;

            const isDev = window.location.hostname === 'localhost',
                token = this.$page.auth.token,
                server = this.$page.auth.server,
                socketUrl = isDev ? 'ws://localhost:9999' : 'wss://' + window.location.host;

            this.socket = io(socketUrl, {
                reconnectionDelayMax: 5000,
                query: {
                    server: server,
                    token: token,
                    type: "staff",
                    license: this.$page.auth.player.licenseIdentifier
                }
            });

            this.socket.on("message", async (buffer) => {
                this.isLoading = false;

                try {
                    const messages = await DataCompressor.GUnZIP(buffer);

                    if (messages.length > 0) {
                        const latest = messages[messages.length - 1],
                            last = this.messages.length > 0 ? this.messages[this.messages.length - 1] : null;

                        if (latest.type === "report" && (!last || last.createdAt !== latest.createdAt)) {
                            this.notify();
                        }
                    }

                    this.messages = messages.map(message => {
                        const type = message.type,
                            user = message.user;

                        const text = message.message.trim()
                            .replace(/&lt;/g, "<")
                            .replace(/&gt;/g, ">")
                            .replace(/&quot;/g, '"');

                        return {
                            license: user.licenseIdentifier,
                            title: (type === "staff" ? "STAFF " : "REPORT ") + user.playerName + (type === "staff" ? "" : " (" + user.source + ")"),
                            text: text,
                            color: type === "staff" ? "purple" : "green",
                            createdAt: message.createdAt
                        };
                    });

                    this.scroll();

                    if (this.$refs.chat) {
                        this.$refs.chat.focus();
                    }
                } catch (e) {
                    console.error('Failed to parse socket message', e);
                }
            });

            this.socket.on("disconnect", () => {
                this.socket.close();
                this.socket = false;

                setTimeout(() => {
                    this.init();
                }, 5000);
            });
        },
        scroll() {
            const scrollTo = this.$refs.scrollTo,
                messages = this.$refs.messages,
                top = messages.scrollTopMax - messages.scrollTop;

            if (top > 20) return;

            this.$nextTick(() => {
                scrollTo.scrollIntoView({
                    behavior: "smooth"
                });
            });
        },
        notify() {
            const audio = new Audio("/images/notification_pop.ogg");

            audio.volume = 0.55;

            audio.play();
        }
    },
    mounted() {
        this.init();

        window.addEventListener("resize", () => {
            this.scroll();
        });
    }
}
</script>
