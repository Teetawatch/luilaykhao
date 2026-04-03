import { ref } from 'vue';

const toasts = ref([]);
let nextId = 0;

export function useToast() {
  function show({ message = '', type = 'info', duration = 3500 } = {}) {
    const id = ++nextId;
    toasts.value.push({ id, message, type });
    setTimeout(() => remove(id), duration);
  }

  function success(message, duration = 3500) {
    show({ message, type: 'success', duration });
  }

  function error(message, duration = 4000) {
    show({ message, type: 'error', duration });
  }

  function warning(message, duration = 3500) {
    show({ message, type: 'warning', duration });
  }

  function info(message, duration = 3000) {
    show({ message, type: 'info', duration });
  }

  function remove(id) {
    const idx = toasts.value.findIndex((t) => t.id === id);
    if (idx !== -1) toasts.value.splice(idx, 1);
  }

  return { toasts, show, success, error, warning, info, remove };
}

export default useToast;
