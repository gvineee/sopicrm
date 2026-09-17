import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * Android/desktop-Chrome install-prompt capture + standalone/platform
 * detection, all via feature detection — never `navigator.userAgent`
 * sniffing for the install decision itself (spec section 17: "feature
 * detection გამოიყენე და არა მხოლოდ user-agent").
 *
 * `navigator.standalone` (iOS Safari) and the `display-mode: standalone`
 * media query are both feature-detected, not parsed from the UA string —
 * UA is only used, further down in IosInstallGuide.vue, to decide whether
 * to show the iOS-specific instructional copy vs. a generic "use your
 * browser's menu" fallback, never to grant/hide the Android prompt.
 */

// A BeforeInstallPromptEvent is non-standard (Chromium-only) and not in
// lib.dom.d.ts; declare the shape we actually use.
type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const installOutcome = ref<'accepted' | 'dismissed' | null>(null);
const isStandalone = ref(false);

function detectStandalone(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    // Feature-detected standalone checks — works across Chromium (matchMedia)
    // and iOS Safari (the non-standard but long-stable navigator.standalone).
    const matchesDisplayMode =
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(display-mode: standalone)').matches;

    const iosStandalone =
        'standalone' in window.navigator &&
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true;

    return Boolean(matchesDisplayMode || iosStandalone);
}

export function usePwaInstall() {
    const canInstall = computed(
        () => deferredPrompt.value !== null && !isStandalone.value,
    );

    function handleBeforeInstallPrompt(event: Event) {
        // Supported browsers fire this only when their own install
        // heuristics are satisfied — capturing it is the feature detection.
        event.preventDefault();
        deferredPrompt.value = event as BeforeInstallPromptEvent;
    }

    function handleAppInstalled() {
        deferredPrompt.value = null;
        isStandalone.value = true;
    }

    onMounted(() => {
        isStandalone.value = detectStandalone();

        window.addEventListener(
            'beforeinstallprompt',
            handleBeforeInstallPrompt,
        );
        window.addEventListener('appinstalled', handleAppInstalled);

        window
            .matchMedia('(display-mode: standalone)')
            .addEventListener?.('change', (event) => {
                isStandalone.value = event.matches || detectStandalone();
            });
    });

    onUnmounted(() => {
        window.removeEventListener(
            'beforeinstallprompt',
            handleBeforeInstallPrompt,
        );
        window.removeEventListener('appinstalled', handleAppInstalled);
    });

    async function promptInstall(): Promise<'accepted' | 'dismissed' | null> {
        if (!deferredPrompt.value) {
            return null;
        }

        await deferredPrompt.value.prompt();
        const choice = await deferredPrompt.value.userChoice;
        installOutcome.value = choice.outcome;
        deferredPrompt.value = null;

        return choice.outcome;
    }

    return {
        canInstall,
        isStandalone,
        installOutcome,
        promptInstall,
    };
}

/** Feature-detected iOS (Safari, no beforeinstallprompt support at all). */
export function isLikelyIosSafari(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    // No standard capability lets us ask "are you iOS Safari" directly; the
    // narrowest, most stable feature signal is the presence of
    // `navigator.standalone` (only ever defined on iOS WebKit) combined with
    // touch support. This is intentionally NOT used to grant/hide the
    // Android install button (that decision is beforeinstallprompt-only) —
    // it only picks which *instructional copy* (iOS guide vs. generic) to
    // show when no install-prompt event is available.
    const hasIosStandaloneProp = 'standalone' in navigator;
    const isTouch = navigator.maxTouchPoints > 0;

    return hasIosStandaloneProp && isTouch;
}
