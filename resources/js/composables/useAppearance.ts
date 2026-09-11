import { ref } from 'vue';

type Appearance = 'light';

export function updateTheme(_value: Appearance = 'light') {
    void _value;
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';
}

export function initializeTheme() {
    localStorage.setItem('appearance', 'light');
    updateTheme();
}

export function useAppearance() {
    const appearance = ref<Appearance>('light');

    function updateAppearance(_value: Appearance) {
        void _value;
        appearance.value = 'light';
        localStorage.setItem('appearance', 'light');
        updateTheme();
    }

    return {
        appearance,
        updateAppearance,
    };
}
