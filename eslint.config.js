import wpPlugin from '@wordpress/eslint-plugin';
import security from 'eslint-plugin-security';
import globals from 'globals';

export default [
    {
        ignores: [
            'vendor/**',
            'node_modules/**',
            'wp-plugin-tests/**',
            'obsidian/**',
            'context/**',
            'development/**',
            'languages/**',
            'assets/js/**/*.min.js',
        ],
    },
    ...wpPlugin.configs.recommended,
    security.configs.recommended,
    {
        files: ['assets/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                ...globals.jquery,
                wp: 'readonly',
                ajaxurl: 'readonly',
                wc: 'readonly',
                jQuery: 'readonly',
            },
        },
        rules: {
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            'no-alert': 'warn',
            'security/detect-object-injection': 'off',
            'security/detect-non-literal-regexp': 'warn',
            'security/detect-non-literal-fs-filename': 'off',
        },
    },
];
