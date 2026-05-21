import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

const apiBase = import.meta.env.VITE_API_URL || '/api/v1';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    // Private channels (เช่น แชทกลุ่ม) ยืนยันสิทธิ์ผ่าน API ด้วย Bearer token
    authorizer: (channel) => ({
        authorize: (socketId, callback) => {
            window.axios
                .post(
                    `${apiBase}/broadcasting/auth`,
                    { socket_id: socketId, channel_name: channel.name },
                    {
                        headers: {
                            Authorization: `Bearer ${localStorage.getItem('auth_token') || ''}`,
                        },
                    },
                )
                .then((response) => callback(null, response.data))
                .catch((error) => callback(error));
        },
    }),
});
