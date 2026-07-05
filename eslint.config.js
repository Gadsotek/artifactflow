import js from '@eslint/js';
import globals from 'globals';
import prettier from 'eslint-config-prettier';

// Flat config (ESLint 9). Lints the hand-written browser modules under
// resources/js and the Node-side build config. Prettier owns formatting, so
// eslint-config-prettier switches off every stylistic rule that would fight it.
export default [
    {
        ignores: ['node_modules/**', 'public/build/**', 'vendor/**', 'storage/**'],
    },
    js.configs.recommended,
    prettier,
    {
        files: ['resources/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            eqeqeq: ['error', 'smart'],
            'no-var': 'error',
            'prefer-const': 'error',
        },
    },
    {
        files: ['*.config.js'],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: 'module',
            globals: {
                ...globals.node,
            },
        },
    },
];
