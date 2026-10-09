export default [{
  files: ['**/*.js', '**/*.mjs'],
  languageOptions: { ecmaVersion: 2024, sourceType: 'module', globals: { window: 'readonly', document: 'readonly', navigator: 'readonly', fetch: 'readonly', URLSearchParams: 'readonly', AbortController: 'readonly', Event: 'readonly', Notification: 'readonly', Uint8Array: 'readonly', atob: 'readonly', setTimeout: 'readonly', clearTimeout: 'readonly', console: 'readonly', process: 'readonly', Buffer: 'readonly' } },
  rules: { 'no-unused-vars': ['error', { argsIgnorePattern: '^_' }], 'no-undef': 'error', 'eqeqeq': 'error', 'no-eval': 'error', 'no-implied-eval': 'error' },
}];
