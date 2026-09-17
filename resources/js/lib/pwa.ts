import { ref } from 'vue';

/**
 * Service worker registration + safe update flow (spec section 17).
 *
 * The safe part: a new SW version is downloaded and put into the "waiting"
 * state by the browser automatically, but this app never force-activates it
 * behind the user's back. `updateAvailable` flips to true and the caller
 * (see components/pwa/UpdateAvailableBanner usage in AppSidebarLayout) shows
 * a dismissible banner. Only `applyUpdate()` — a deliberate user action —
 * tells the waiting worker to skip waiting, and the page reloads once the
 * new worker actually takes control. Nothing here ever clears IndexedDB
 * (where drafts/photos live, see lib/offlineQueue.ts), so an update can
 * never destroy an unsent draft — it can only, if the user chooses to
 * reload, refresh the shell around that still-intact local data.
 */

export const updateAvailable = ref(false);
let waitingWorker: ServiceWorker | null = null;

export function registerServiceWorker(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js')
            .then((registration) => {
                if (
                    registration.waiting &&
                    navigator.serviceWorker.controller
                ) {
                    waitingWorker = registration.waiting;
                    updateAvailable.value = true;
                }

                registration.addEventListener('updatefound', () => {
                    const installingWorker = registration.installing;

                    if (!installingWorker) {
                        return;
                    }

                    installingWorker.addEventListener('statechange', () => {
                        const hasExistingController = Boolean(
                            navigator.serviceWorker.controller,
                        );

                        if (
                            installingWorker.state === 'installed' &&
                            hasExistingController
                        ) {
                            // A controller already existed, so this is an
                            // update, not the very first install — surface
                            // the banner rather than auto-activating.
                            waitingWorker = installingWorker;
                            updateAvailable.value = true;
                        }
                    });
                });
            })
            .catch((error: unknown) => {
                // Registration failure must never block the app — the PWA
                // shell is an enhancement, not a requirement (spec 17:
                // "ინსტალაცია არ იყოს გამოყენების წინაპირობა").
                console.warn('Service worker registration failed:', error);
            });

        let hasReloaded = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (hasReloaded) {
                return;
            }
            hasReloaded = true;
            window.location.reload();
        });
    });
}

/** User-initiated: activate the already-downloaded update and reload. */
export function applyUpdate(): void {
    if (!waitingWorker) {
        return;
    }

    waitingWorker.postMessage({ type: 'SKIP_WAITING' });
    updateAvailable.value = false;
}
