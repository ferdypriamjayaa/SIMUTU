import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/**
 * SIMUTU design tokens, taken from the RSU Syifa Medika identity:
 * magenta (brand), navy (secondary), green (logo accent) on light blue-gray.
 */

/** @type {import('tailwindcss').Config} */
export default {
    // Toggled by the `dark` class on <html>; see components/theme-script.blade.php.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    // Button variants are composed in components/button.blade.php ('btn-'.$variant).
    safelist: ['btn-primary', 'btn-navy', 'btn-secondary', 'btn-danger', 'btn-ghost', 'btn-sm'],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Neutral greys lean blue to match the hospital's light blue-gray backgrounds.
                gray: colors.slate,
                brand: {
                    50: '#fdf2fb',
                    100: '#fbe4f7',
                    200: '#f8c9ef',
                    300: '#f29fe2',
                    400: '#e864ce',
                    500: '#d500b9',
                    600: '#b8009f',
                    700: '#960082',
                    800: '#7a066b',
                    900: '#650a59',
                    950: '#400037',
                },
                navy: {
                    50: '#f2f6fb',
                    100: '#e3eaf4',
                    200: '#c2d1e6',
                    300: '#91aad0',
                    400: '#5a7db3',
                    500: '#385e98',
                    600: '#284a7e',
                    700: '#213c66',
                    800: '#1d3355',
                    900: '#172a46',
                    950: '#0d1a2e',
                },
                accent: {
                    50: '#effaf1',
                    100: '#d8f3dd',
                    200: '#b3e6be',
                    300: '#80d295',
                    400: '#4bb86a',
                    500: '#2e9e47',
                    600: '#228b3a',
                    700: '#1c6f30',
                    800: '#1a582a',
                    900: '#164925',
                },
                surface: '#f3f6fa',
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                raised: '0 10px 30px -12px rgb(23 42 70 / 0.25)',
            },
        },
    },

    plugins: [forms],
};
