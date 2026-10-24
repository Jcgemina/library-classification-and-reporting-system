(() => {
    const container = document.getElementById('appToastContainer');

    if (!container) {
        throw new Error('The shared toast container is missing.');
    }

    function show(message, type = 'success') {
        const isError = type === 'error';
        const isInfo = type === 'info';
        const tone = isError
            ? {
                border: 'border-red-300',
                background: 'bg-red-50',
                text: 'text-red-800',
                progress: 'bg-red-500',
                icon: 'circle-alert'
            }
            : isInfo
                ? {
                    border: 'border-blue-300',
                    background: 'bg-blue-50',
                    text: 'text-blue-800',
                    progress: 'bg-blue-500',
                    icon: 'circle-info'
                }
                : {
                    border: 'border-green-300',
                    background: 'bg-green-50',
                    text: 'text-green-800',
                    progress: 'bg-green-500',
                    icon: 'circle-check'
                };
        const notification = document.createElement('div');
        const icon = document.createElement('i');
        const text = document.createElement('span');
        const dismiss = document.createElement('button');
        const dismissIcon = document.createElement('i');
        const progress = document.createElement('span');
        const duration = 3000;

        notification.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${tone.border} ${tone.background} ${tone.text}`;
        notification.setAttribute('role', isError ? 'alert' : 'status');

        icon.dataset.lucide = tone.icon;
        icon.className = 'mt-0.5 h-4 w-4 flex-shrink-0';
        icon.setAttribute('aria-hidden', 'true');

        text.className = 'flex-1';
        text.textContent = String(message ?? '');

        dismiss.type = 'button';
        dismiss.className = 'text-current opacity-60 transition hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-current';
        dismiss.setAttribute('aria-label', 'Dismiss notification');
        dismissIcon.dataset.lucide = 'x';
        dismissIcon.className = 'h-4 w-4';
        dismissIcon.setAttribute('aria-hidden', 'true');
        dismiss.appendChild(dismissIcon);

        progress.className = `absolute bottom-0 left-0 h-1 w-full origin-left ${tone.progress}`;

        notification.append(icon, text, dismiss, progress);
        container.appendChild(notification);
        window.lucide?.createIcons({ nodes: [notification] });

        requestAnimationFrame(() => {
            progress.style.transition = `width ${duration}ms linear`;
            progress.style.width = '0%';
        });

        const timeoutId = window.setTimeout(dismissNotification, duration);
        function dismissNotification() {
            window.clearTimeout(timeoutId);
            notification.remove();
        }

        dismiss.addEventListener('click', dismissNotification);
    }

    window.AppToast = { show };
})();
